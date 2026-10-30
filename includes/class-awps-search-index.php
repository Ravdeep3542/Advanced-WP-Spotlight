<?php
/**
 * Content search index.
 *
 * Searchable entities (posts, pages, CPTs, media, users, terms, menus) are
 * stored in a dedicated custom table instead of bloating wp_options or
 * running heavy WP_Query lookups per keystroke. A background cron job
 * synchronises the index in batches (default 500 rows) so large sites never
 * time out, and real-time hooks keep individual rows fresh between syncs.
 *
 * Every row stores the WordPress capability required to see it in wp-admin;
 * search results are filtered against current_user_can() before they leave
 * the server (permission parity).
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_Search_Index {

	const BATCH_SIZE   = 500;
	const CRON_BATCH   = 'awps_process_index_batch';
	const CRON_SYNC    = 'awps_full_index_sync';
	const QUEUE_OPTION = 'awps_index_queue';
	const META_OPTION  = 'awps_index_meta';

	/**
	 * Table name (unprefixed).
	 */
	const TABLE = 'awps_search_index';

	/**
	 * Full table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Attach cron + real-time sync hooks.
	 */
	public function hook() {
		add_action( self::CRON_BATCH, array( $this, 'process_batch' ) );
		add_action( self::CRON_SYNC, array( __CLASS__, 'enqueue_full_rebuild' ) );

		// Real-time single-row sync.
		add_action( 'save_post', array( $this, 'sync_post' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'remove_post' ) );
		add_action( 'attachment_updated', array( $this, 'sync_post_by_id' ) );
		add_action( 'add_attachment', array( $this, 'sync_post_by_id' ) );
		add_action( 'user_register', array( $this, 'sync_user' ) );
		add_action( 'profile_update', array( $this, 'sync_user' ) );
		add_action( 'deleted_user', array( $this, 'remove_user' ) );
		add_action( 'created_term', array( $this, 'sync_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'sync_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'remove_term' ) );
	}

	/* ---------------------------------------------------------------------
	 * Table / scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Create (or upgrade) the index table.
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(40) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			title TEXT NOT NULL,
			subtitle VARCHAR(255) NOT NULL DEFAULT '',
			url VARCHAR(2000) NOT NULL DEFAULT '',
			view_url VARCHAR(2000) NOT NULL DEFAULT '',
			capability VARCHAR(64) NOT NULL DEFAULT 'read',
			keywords TEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object (object_type, object_id),
			KEY object_type (object_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule the recurring full sync.
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::CRON_SYNC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_SYNC );
		}
	}

	/**
	 * Remove scheduled events.
	 */
	public static function unschedule_events() {
		wp_clear_scheduled_hook( self::CRON_SYNC );
		wp_clear_scheduled_hook( self::CRON_BATCH );
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Queue a full rebuild, processed in background batches.
	 */
	public static function enqueue_full_rebuild() {
		$jobs = array();

		// First purge rows of post types that are no longer selected in the
		// settings, so deselecting a type actually removes it from search.
		$jobs[] = array( 'kind' => 'cleanup' );

		foreach ( self::indexed_post_types() as $post_type ) {
			$jobs[] = array(
				'kind'      => 'posts',
				'post_type' => $post_type,
				'offset'    => 0,
			);
		}
		$jobs[] = array(
			'kind'   => 'users',
			'offset' => 0,
		);
		$jobs[] = array(
			'kind'   => 'terms',
			'offset' => 0,
		);
		$jobs[] = array( 'kind' => 'menus' );

		update_option( self::QUEUE_OPTION, $jobs, false );
		self::schedule_next_batch( 1 );
	}

	/**
	 * Schedule the next batch run.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function schedule_next_batch( $delay = 10 ) {
		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_BATCH );
		}
	}

	/**
	 * Post types included in the content index.
	 *
	 * @return string[]
	 */
	public static function indexed_post_types() {
		$settings = get_option( 'awps_settings', array() );

		// A saved (possibly empty) selection is respected as-is; only a
		// never-saved option falls back to the all-types default.
		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			$types = $settings['post_types'];
		} else {
			// Sensible defaults: every UI-visible post type except a few noisy ones.
			$types = array();
			foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $name ) {
				if ( in_array( $name, array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) ) {
					continue;
				}
				$types[] = $name;
			}
		}

		// Attachments handled as their own job below (always on, cheap).
		$types[] = 'attachment';

		/**
		 * Filter the post types included in the palette content index.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_unique( apply_filters( 'awps_indexed_post_types', $types ) );
	}

	/* ---------------------------------------------------------------------
	 * Batch processing
	 * ------------------------------------------------------------------ */

	/**
	 * Cron worker: process one batch from the queue, then reschedule
	 * itself until the queue is drained.
	 */
	public function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			update_option( self::META_OPTION, array( 'last_sync' => time() ), false );
			return;
		}

		$job  = array_shift( $queue );
		$done = true;

		switch ( $job['kind'] ) {
			case 'cleanup':
				$this->cleanup_deselected_post_types();
				break;

			case 'posts':
				$done = $this->index_posts_batch( $job['post_type'], (int) $job['offset'] );
				if ( ! $done ) {
					$job['offset'] += self::BATCH_SIZE;
					array_unshift( $queue, $job );
				}
				break;

			case 'users':
				$done = $this->index_users_batch( (int) $job['offset'] );
				if ( ! $done ) {
					$job['offset'] += self::BATCH_SIZE;
					array_unshift( $queue, $job );
				}
				break;

			case 'terms':
				$done = $this->index_terms_batch( (int) $job['offset'] );
				if ( ! $done ) {
					$job['offset'] += self::BATCH_SIZE;
					array_unshift( $queue, $job );
				}
				break;

			case 'menus':
				$this->index_menus();
				break;
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! empty( $queue ) ) {
			self::schedule_next_batch( 10 );
		} else {
			update_option( self::META_OPTION, array( 'last_sync' => time() ), false );
		}
	}

	/**
	 * Remove index rows for posts whose type is not (or no longer) indexed.
	 * The index stores object_type='post' generically, so the post type is
	 * resolved by joining against wp_posts.
	 */
	private function cleanup_deselected_post_types() {
		global $wpdb;

		$types = self::indexed_post_types();
		if ( empty( $types ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'DELETE idx FROM ' . self::table() . " idx
				 INNER JOIN {$wpdb->posts} p ON p.ID = idx.object_id
				 WHERE idx.object_type = 'post' AND p.post_type NOT IN ({$placeholders})",
				$types
			)
		);
	}

	/**
	 * Index one batch of posts of a given type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $offset    Offset.
	 * @return bool True when this type is finished.
	 */
	private function index_posts_batch( $post_type, $offset ) {
		$statuses = ( 'attachment' === $post_type )
			? array( 'inherit' )
			: array( 'publish', 'future', 'draft', 'pending', 'private' );

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => $statuses,
				'posts_per_page'   => self::BATCH_SIZE,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		foreach ( $posts as $post ) {
			$this->upsert_post_row( $post );
		}

		return count( $posts ) < self::BATCH_SIZE;
	}

	/**
	 * Index one batch of users.
	 *
	 * @param int $offset Offset.
	 * @return bool True when finished.
	 */
	private function index_users_batch( $offset ) {
		$users = get_users(
			array(
				'number'  => self::BATCH_SIZE,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);

		foreach ( $users as $user ) {
			$this->upsert_user_row( $user );
		}

		return count( $users ) < self::BATCH_SIZE;
	}

	/**
	 * Index one batch of taxonomy terms.
	 *
	 * @param int $offset Offset.
	 * @return bool True when finished.
	 */
	private function index_terms_batch( $offset ) {
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'number'     => self::BATCH_SIZE,
				'offset'     => $offset,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return true;
		}

		foreach ( $terms as $term ) {
			$this->upsert_term_row( $term );
		}

		return count( $terms ) < self::BATCH_SIZE;
	}

	/**
	 * Index registered navigation menus (always a small set).
	 */
	private function index_menus() {
		global $wpdb;

		// Menus live in the term table under 'nav_menu'; re-index all of them.
		$wpdb->delete( self::table(), array( 'object_type' => 'menu' ), array( '%s' ) );

		foreach ( wp_get_nav_menus() as $menu ) {
			$this->upsert_row(
				array(
					'object_type' => 'menu',
					'object_id'   => $menu->term_id,
					'title'       => $menu->name,
					'subtitle'    => __( 'Navigation Menu', 'advanced-wp-spotlight' ),
					'url'         => admin_url( 'nav-menus.php?action=edit&menu=' . $menu->term_id ),
					'view_url'    => '',
					'capability'  => 'edit_theme_options',
					'keywords'    => 'menu navigation',
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Row builders (real-time + batch share these)
	 * ------------------------------------------------------------------ */

	/**
	 * Real-time: sync a post when it is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function sync_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::indexed_post_types(), true ) ) {
			// Deselected type: make sure no stale row lingers for this post.
			$this->remove_post( $post_id );
			return;
		}
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$this->remove_post( $post_id );
			return;
		}
		$this->upsert_post_row( $post );
	}

	/**
	 * Real-time: sync a post/attachment by id.
	 *
	 * @param int $post_id Post ID.
	 */
	public function sync_post_by_id( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			$this->sync_post( $post_id, $post );
		}
	}

	/**
	 * Remove a post row.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'object_type' => 'post', 'object_id' => (int) $post_id ), array( '%s', '%d' ) );
	}

	/**
	 * Real-time: sync a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function sync_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$this->upsert_user_row( $user );
		}
	}

	/**
	 * Remove a user row.
	 *
	 * @param int $user_id User ID.
	 */
	public function remove_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'object_type' => 'user', 'object_id' => (int) $user_id ), array( '%s', '%d' ) );
	}

	/**
	 * Real-time: sync a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function sync_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$term = get_term( $term_id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->upsert_term_row( $term );
		}
	}

	/**
	 * Remove a term row.
	 *
	 * @param int $term_id Term ID.
	 */
	public function remove_term( $term_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'object_type' => 'term', 'object_id' => (int) $term_id ), array( '%s', '%d' ) );
	}

	/**
	 * Build + store the row for a post.
	 *
	 * @param WP_Post $post Post.
	 */
	private function upsert_post_row( $post ) {
		$type_obj = get_post_type_object( $post->post_type );
		$label    = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
		$subtitle = $label;

		if ( 'publish' !== $post->post_status && 'inherit' !== $post->post_status ) {
			$subtitle .= ' — ' . $post->post_status;
		}

		// WooCommerce products get their price in the subtitle for recognisability.
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product && '' !== $product->get_price() ) {
				$subtitle .= ' — ' . html_entity_decode( wp_strip_all_tags( get_option( 'woocommerce_currency' ) . ' ' . $product->get_price() ) );
			}
		}

		$this->upsert_row(
			array(
				'object_type' => 'post',
				'object_id'   => $post->ID,
				'title'       => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'advanced-wp-spotlight' ),
				'subtitle'    => $subtitle,
				'url'         => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
				'view_url'    => (string) get_permalink( $post->ID ),
				'capability'  => $type_obj ? $type_obj->cap->edit_posts : 'edit_posts',
				'keywords'    => $post->post_type . ' ' . $post->post_name,
			)
		);
	}

	/**
	 * Build + store the row for a user.
	 *
	 * @param WP_User $user User.
	 */
	private function upsert_user_row( $user ) {
		$role = ! empty( $user->roles ) ? implode( ', ', $user->roles ) : '';

		$this->upsert_row(
			array(
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'title'       => $user->display_name ? $user->display_name : $user->user_login,
				'subtitle'    => trim( __( 'User', 'advanced-wp-spotlight' ) . ( $role ? ' — ' . $role : '' ) ),
				'url'         => admin_url( 'user-edit.php?user_id=' . $user->ID ),
				'view_url'    => '',
				'capability'  => 'list_users',
				// Role included so searching "editor" surfaces all editors.
				'keywords'    => $user->user_login . ' ' . $user->user_email . ' ' . $role,
			)
		);
	}

	/**
	 * Build + store the row for a term.
	 *
	 * @param WP_Term $term Term.
	 */
	private function upsert_term_row( $term ) {
		$tax_obj = get_taxonomy( $term->taxonomy );
		if ( ! $tax_obj || ! $tax_obj->show_ui ) {
			return;
		}

		$this->upsert_row(
			array(
				'object_type' => 'term',
				'object_id'   => $term->term_id,
				'title'       => $term->name,
				'subtitle'    => $tax_obj->labels->singular_name,
				'url'         => admin_url( 'term.php?taxonomy=' . $term->taxonomy . '&tag_ID=' . $term->term_id ),
				'view_url'    => (string) get_term_link( $term ),
				'capability'  => $tax_obj->cap->manage_terms,
				'keywords'    => $term->taxonomy . ' ' . $term->slug,
			)
		);
	}

	/**
	 * Insert or update one index row.
	 *
	 * @param array $row Row values.
	 */
	private function upsert_row( array $row ) {
		global $wpdb;

		$wpdb->replace(
			self::table(),
			array(
				'object_type' => $row['object_type'],
				'object_id'   => $row['object_id'],
				'title'       => wp_strip_all_tags( $row['title'] ),
				'subtitle'    => self::clip( wp_strip_all_tags( $row['subtitle'] ), 255 ),
				'url'         => esc_url_raw( $row['url'] ),
				'view_url'    => esc_url_raw( $row['view_url'] ),
				'capability'  => $row['capability'],
				'keywords'    => self::clip( (string) $row['keywords'], 500 ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Search
	 * ------------------------------------------------------------------ */

	/**
	 * Search the index, capability-filtered for the current user.
	 *
	 * @param string $query Search term.
	 * @param int    $limit Max results returned.
	 * @param string $type  Optional object_type filter (post|user|term|menu).
	 * @return array[]
	 */
	public function search( $query, $limit = 20, $type = '' ) {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$like       = '%' . $wpdb->esc_like( $query ) . '%';
		$like_first = $wpdb->esc_like( $query ) . '%';

		$type_clause = '';
		$params      = array( $like, $like );
		if ( $type ) {
			$type_clause = ' AND object_type = %s';
			$params[]    = $type;
		}
		$params[] = $like_first;
		$params[] = $limit * 4;

		// Over-fetch, then capability-filter and score in PHP.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_type, object_id, title, subtitle, url, view_url, capability
				 FROM ' . self::table() . '
				 WHERE (title LIKE %s OR keywords LIKE %s)' . $type_clause . '
				 ORDER BY (title LIKE %s) DESC, CHAR_LENGTH(title) ASC
				 LIMIT %d',
				$params
			),
			ARRAY_A
		);

		$results = array();
		foreach ( (array) $rows as $row ) {
			// Permission parity: never return rows the user couldn't reach natively.
			if ( ! current_user_can( $row['capability'] ) ) {
				continue;
			}
			// Object-level check for posts (e.g. private posts of other authors).
			if ( 'post' === $row['object_type'] && ! current_user_can( 'edit_post', (int) $row['object_id'] ) ) {
				continue;
			}

			unset( $row['capability'] );
			$results[] = $row;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Index status for the settings screen.
	 *
	 * @return array
	 */
	public function status() {
		global $wpdb;

		$meta  = get_option( self::META_OPTION, array() );
		$queue = get_option( self::QUEUE_OPTION, array() );
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'      => $count,
			'last_sync' => isset( $meta['last_sync'] ) ? (int) $meta['last_sync'] : 0,
			'pending'   => count( $queue ),
		);
	}

	/**
	 * Multibyte-safe string clip with a plain substr() fallback when the
	 * mbstring extension is unavailable.
	 *
	 * @param string $value  Input.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function clip( $value, $length ) {
		$value  = (string) $value;
		$length = (int) $length;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}
