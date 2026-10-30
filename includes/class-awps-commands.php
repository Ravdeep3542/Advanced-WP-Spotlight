<?php
/**
 * Built-in commands: navigation, safe (non-destructive) actions,
 * clipboard utilities, and context-aware suggestions.
 *
 * Phase 1 rule: nothing here deletes, trashes, or otherwise destroys data.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_Commands {

	/**
	 * Attach the built-in registration callbacks to the registry lifecycle.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	public static function hook( AWPS_Command_Registry $registry ) {
		add_action(
			'awps_register_commands',
			function ( AWPS_Command_Registry $registry ) {
				self::register_navigation( $registry );
				self::register_filtered_views( $registry );
				self::register_admin_menu( $registry );
				self::register_settings_search( $registry );
				self::register_plugin_commands( $registry );
				self::register_theme_commands( $registry );
				self::register_actions( $registry );
				self::register_developer_commands( $registry );
				self::register_woocommerce_commands( $registry );
				self::register_clipboard( $registry );
				self::register_palette_settings( $registry );
				self::register_contextual( $registry );
				self::register_destructive( $registry );
				self::register_action_handlers( $registry );
				self::register_destructive_handlers( $registry );
			},
			5
		);
	}

	/**
	 * Core navigation commands with rich aliases.
	 *
	 * The admin-menu harvest below covers most screens automatically; these
	 * hand-written entries add the aliases and "Add New X" style verbs that
	 * make fuzzy search feel natural ("add usr" → "Add New User").
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_navigation( AWPS_Command_Registry $registry ) {
		$nav_category = __( 'Navigation', 'advanced-wp-spotlight' );

		$items = array(
			array( 'nav:dashboard', __( 'Open Dashboard', 'advanced-wp-spotlight' ), 'index.php', 'read', array( 'home', 'admin' ), 'dashboard' ),
			array( 'nav:posts', __( 'Open Posts', 'advanced-wp-spotlight' ), 'edit.php', 'edit_posts', array( 'articles', 'blog' ), 'admin-post' ),
			array( 'nav:new-post', __( 'Create New Post', 'advanced-wp-spotlight' ), 'post-new.php', 'edit_posts', array( 'add post', 'write', 'new article' ), 'edit' ),
			array( 'nav:pages', __( 'Open Pages', 'advanced-wp-spotlight' ), 'edit.php?post_type=page', 'edit_pages', array(), 'admin-page' ),
			array( 'nav:new-page', __( 'Create New Page', 'advanced-wp-spotlight' ), 'post-new.php?post_type=page', 'edit_pages', array( 'add page' ), 'welcome-add-page' ),
			array( 'nav:media', __( 'Open Media Library', 'advanced-wp-spotlight' ), 'upload.php', 'upload_files', array( 'images', 'files', 'attachments' ), 'admin-media' ),
			array( 'nav:upload-media', __( 'Upload Media', 'advanced-wp-spotlight' ), 'media-new.php', 'upload_files', array( 'add image', 'add file' ), 'upload' ),
			array( 'nav:comments', __( 'Open Comments', 'advanced-wp-spotlight' ), 'edit-comments.php', 'edit_posts', array( 'moderation' ), 'admin-comments' ),
			array( 'nav:categories', __( 'Open Categories', 'advanced-wp-spotlight' ), 'edit-tags.php?taxonomy=category', 'manage_categories', array( 'taxonomy' ), 'category' ),
			array( 'nav:tags', __( 'Open Tags', 'advanced-wp-spotlight' ), 'edit-tags.php?taxonomy=post_tag', 'manage_categories', array( 'taxonomy' ), 'tag' ),
			array( 'nav:users', __( 'Open Users', 'advanced-wp-spotlight' ), 'users.php', 'list_users', array( 'members', 'accounts' ), 'admin-users' ),
			array( 'nav:new-user', __( 'Add New User', 'advanced-wp-spotlight' ), 'user-new.php', 'create_users', array( 'add usr', 'create account', 'invite' ), 'plus' ),
			array( 'nav:profile', __( 'Open Your Profile', 'advanced-wp-spotlight' ), 'profile.php', 'read', array( 'my account', 'me' ), 'id' ),
			array( 'nav:plugins', __( 'Open Plugins', 'advanced-wp-spotlight' ), 'plugins.php', 'activate_plugins', array( 'extensions' ), 'admin-plugins' ),
			array( 'nav:new-plugin', __( 'Add New Plugin', 'advanced-wp-spotlight' ), 'plugin-install.php', 'install_plugins', array( 'install plugin' ), 'plus-alt' ),
			array( 'nav:themes', __( 'Open Themes', 'advanced-wp-spotlight' ), 'themes.php', 'switch_themes', array( 'appearance' ), 'admin-appearance' ),
			array( 'nav:customizer', __( 'Open Customizer', 'advanced-wp-spotlight' ), 'customize.php', 'customize', array( 'site identity', 'logo', 'colors', 'header' ), 'admin-customizer' ),
			array( 'nav:menus', __( 'Open Menus', 'advanced-wp-spotlight' ), 'nav-menus.php', 'edit_theme_options', array( 'navigation menu' ), 'menu' ),
			array( 'nav:widgets', __( 'Open Widgets', 'advanced-wp-spotlight' ), 'widgets.php', 'edit_theme_options', array( 'sidebar' ), 'welcome-widgets-menus' ),
			array( 'nav:settings-general', __( 'Open General Settings', 'advanced-wp-spotlight' ), 'options-general.php', 'manage_options', array( 'site title', 'timezone', 'tagline' ), 'admin-settings' ),
			array( 'nav:settings-writing', __( 'Open Writing Settings', 'advanced-wp-spotlight' ), 'options-writing.php', 'manage_options', array(), 'admin-settings' ),
			array( 'nav:settings-reading', __( 'Open Reading Settings', 'advanced-wp-spotlight' ), 'options-reading.php', 'manage_options', array( 'homepage displays', 'front page' ), 'admin-settings' ),
			array( 'nav:settings-discussion', __( 'Open Discussion Settings', 'advanced-wp-spotlight' ), 'options-discussion.php', 'manage_options', array( 'comments settings' ), 'admin-settings' ),
			array( 'nav:settings-media', __( 'Open Media Settings', 'advanced-wp-spotlight' ), 'options-media.php', 'manage_options', array( 'thumbnail sizes' ), 'admin-settings' ),
			array( 'nav:settings-permalinks', __( 'Open Permalink Settings', 'advanced-wp-spotlight' ), 'options-permalink.php', 'manage_options', array( 'urls', 'rewrite' ), 'admin-links' ),
			array( 'nav:settings-privacy', __( 'Open Privacy Settings', 'advanced-wp-spotlight' ), 'options-privacy.php', 'manage_privacy_options', array( 'gdpr', 'policy' ), 'privacy' ),
			array( 'nav:tools', __( 'Open Tools', 'advanced-wp-spotlight' ), 'tools.php', 'edit_posts', array(), 'admin-tools' ),
			array( 'nav:site-health', __( 'Open Site Health', 'advanced-wp-spotlight' ), 'site-health.php', 'view_site_health_checks', array( 'status', 'diagnostics', 'php version' ), 'heart' ),
			array( 'nav:updates', __( 'Open Updates', 'advanced-wp-spotlight' ), 'update-core.php', 'update_core', array( 'upgrade', 'new version' ), 'update' ),
			array( 'nav:export', __( 'Open Export Tool', 'advanced-wp-spotlight' ), 'export.php', 'export', array( 'download content' ), 'download' ),
			array( 'nav:import', __( 'Open Import Tool', 'advanced-wp-spotlight' ), 'import.php', 'import', array(), 'upload' ),
		);

		foreach ( $items as $item ) {
			$registry->register(
				array(
					'id'         => $item[0],
					'title'      => $item[1],
					'category'   => $nav_category,
					'url'        => admin_url( $item[2] ),
					'capability' => $item[3],
					'keywords'   => $item[4],
					'icon'       => $item[5],
					'type'       => 'url',
				)
			);
		}

		// View the site frontend.
		$registry->register(
			array(
				'id'       => 'nav:view-site',
				'title'    => __( 'View Site (Frontend)', 'advanced-wp-spotlight' ),
				'category' => $nav_category,
				'url'      => home_url( '/' ),
				'keywords' => array( 'visit', 'homepage', 'front end' ),
				'icon'     => 'admin-site-alt3',
				'type'     => 'url',
			)
		);

		// WooCommerce navigation shortcuts (only when active).
		if ( class_exists( 'WooCommerce' ) ) {
			$wc = array(
				array( 'nav:wc-orders', __( 'Open WooCommerce Orders', 'advanced-wp-spotlight' ), 'admin.php?page=wc-orders', array( 'shop orders', 'sales' ), 'cart' ),
				array( 'nav:wc-products', __( 'Open Products', 'advanced-wp-spotlight' ), 'edit.php?post_type=product', array( 'shop', 'catalog' ), 'products' ),
				array( 'nav:wc-new-product', __( 'Create New Product', 'advanced-wp-spotlight' ), 'post-new.php?post_type=product', array( 'add product' ), 'plus' ),
				array( 'nav:wc-coupons', __( 'Open Coupons', 'advanced-wp-spotlight' ), 'edit.php?post_type=shop_coupon', array( 'discount' ), 'tickets-alt' ),
				array( 'nav:wc-settings', __( 'Open WooCommerce Settings', 'advanced-wp-spotlight' ), 'admin.php?page=wc-settings', array( 'shop settings' ), 'admin-generic' ),
				array( 'nav:wc-analytics', __( 'Open WooCommerce Analytics', 'advanced-wp-spotlight' ), 'admin.php?page=wc-admin&path=%2Fanalytics%2Foverview', array( 'reports', 'revenue' ), 'chart-bar' ),
			);
			foreach ( $wc as $item ) {
				$registry->register(
					array(
						'id'         => $item[0],
						'title'      => $item[1],
						'category'   => 'WooCommerce',
						'url'        => admin_url( $item[2] ),
						'capability' => 'manage_woocommerce',
						'keywords'   => $item[3],
						'icon'       => $item[4],
						'type'       => 'url',
					)
				);
			}
		}
	}

	/**
	 * Pre-filtered list views: "Draft Posts", "On Hold Orders",
	 * "In Stock Products"… Each command simply opens the native admin list
	 * screen with the basic filter already applied — no results are shown in
	 * the palette itself, and advanced filtering stays on the list screens.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_filtered_views( AWPS_Command_Registry $registry ) {
		$nav_category = __( 'Navigation', 'advanced-wp-spotlight' );

		// Basic status views for every UI-visible public post type.
		$statuses = array(
			/* translators: %s: plural post type label (e.g. Posts) */
			'publish' => __( 'Published %s', 'advanced-wp-spotlight' ),
			/* translators: %s: plural post type label (e.g. Posts) */
			'draft'   => __( 'Draft %s', 'advanced-wp-spotlight' ),
			/* translators: %s: plural post type label (e.g. Posts) */
			'pending' => __( 'Pending %s', 'advanced-wp-spotlight' ),
			/* translators: %s: plural post type label (e.g. Posts) */
			'future'  => __( 'Scheduled %s', 'advanced-wp-spotlight' ),
		);

		$post_types = get_post_types( array( 'show_ui' => true, 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			$label = $post_type->labels->name;
			$base  = 'edit.php?post_status=%s' . ( 'post' === $post_type->name ? '' : '&post_type=' . $post_type->name );

			foreach ( $statuses as $status => $pattern ) {
				$registry->register(
					array(
						'id'         => 'nav:view-' . $post_type->name . '-' . $status,
						'title'      => sprintf( $pattern, $label ),
						'category'   => $nav_category,
						'url'        => admin_url( sprintf( $base, $status ) ),
						'capability' => $post_type->cap->edit_posts,
						'keywords'   => array( $status . ' ' . strtolower( $label ), strtolower( $label ) . ' ' . $status ),
						'icon'       => 'filter',
						'type'       => 'url',
					)
				);
			}
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Order status views — basic statuses only, HPOS-aware URLs.
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

			$basic = array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' );

			foreach ( wc_get_order_statuses() as $status_key => $status_label ) {
				if ( ! in_array( $status_key, $basic, true ) ) {
					continue;
				}

				$url = $hpos
					? admin_url( 'admin.php?page=wc-orders&status=' . $status_key )
					: admin_url( 'edit.php?post_type=shop_order&post_status=' . $status_key );

				$registry->register(
					array(
						'id'         => 'nav:wc-orders-' . $status_key,
						'title'      => sprintf(
							/* translators: %s: order status label (e.g. On hold) */
							__( '%s Orders', 'advanced-wp-spotlight' ),
							$status_label
						),
						'category'   => 'WooCommerce',
						'url'        => $url,
						'capability' => 'manage_woocommerce',
						'keywords'   => array(
							strtolower( $status_label ) . ' orders',
							'orders ' . strtolower( $status_label ),
							str_replace( array( 'wc-', '-' ), array( '', ' ' ), $status_key ) . ' orders',
						),
						'icon'       => 'cart',
						'type'       => 'url',
					)
				);
			}
		}

		// Product stock views (the products list screen has a native
		// stock-status filter that reads this query arg).
		$stock_views = array(
			'instock'     => __( 'In Stock Products', 'advanced-wp-spotlight' ),
			'outofstock'  => __( 'Out of Stock Products', 'advanced-wp-spotlight' ),
			'onbackorder' => __( 'On Backorder Products', 'advanced-wp-spotlight' ),
		);
		foreach ( $stock_views as $stock => $title ) {
			$registry->register(
				array(
					'id'         => 'nav:wc-stock-' . $stock,
					'title'      => $title,
					'category'   => 'WooCommerce',
					'url'        => admin_url( 'edit.php?post_type=product&stock_status=' . $stock ),
					'capability' => 'edit_products',
					'keywords'   => array( 'stock', 'inventory', $stock ),
					'icon'       => 'archive',
					'type'       => 'url',
				)
			);
		}
	}

	/**
	 * Harvest the registered admin menu so every plugin/theme screen becomes
	 * a navigation command automatically — with the exact capability that the
	 * menu item itself declares (permission parity for free).
	 *
	 * Only available when the admin menu globals have been built (regular
	 * wp-admin page loads); REST/cron contexts simply skip this.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_admin_menu( AWPS_Command_Registry $registry ) {
		global $menu, $submenu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}

		$category = __( 'Admin Menu', 'advanced-wp-spotlight' );

		foreach ( $menu as $top ) {
			// Menu item layout: 0 title, 1 capability, 2 slug.
			if ( empty( $top[0] ) || empty( $top[2] ) ) {
				continue; // Separators.
			}

			$top_title = self::clean_menu_title( $top[0] );
			if ( '' === $top_title ) {
				continue;
			}

			$registry->register(
				array(
					'id'         => 'menu:' . sanitize_key( $top[2] ),
					'title'      => $top_title,
					'category'   => $category,
					'url'        => self::menu_slug_to_url( $top[2] ),
					'capability' => $top[1],
					'icon'       => 'admin-generic',
					'type'       => 'url',
				)
			);

			if ( empty( $submenu[ $top[2] ] ) ) {
				continue;
			}

			foreach ( $submenu[ $top[2] ] as $sub ) {
				if ( empty( $sub[0] ) || empty( $sub[2] ) ) {
					continue;
				}
				$sub_title = self::clean_menu_title( $sub[0] );
				if ( '' === $sub_title ) {
					continue;
				}
				$registry->register(
					array(
						'id'         => 'menu:' . sanitize_key( $top[2] ) . ':' . sanitize_key( $sub[2] ),
						'title'      => $top_title . ' › ' . $sub_title,
						'category'   => $category,
						'url'        => self::menu_slug_to_url( $sub[2], $top[2] ),
						'capability' => $sub[1],
						'keywords'   => array( $sub_title ),
						'icon'       => 'admin-generic',
						'type'       => 'url',
					)
				);
			}
		}
	}

	/**
	 * Strip update badges / screen-reader markup from a menu title.
	 *
	 * @param string $raw Raw menu title HTML.
	 * @return string
	 */
	private static function clean_menu_title( $raw ) {
		$title = wp_strip_all_tags( preg_replace( '/<span.*<\/span>/s', '', $raw ) );
		return trim( $title );
	}

	/**
	 * Resolve a menu slug to a full admin URL.
	 *
	 * @param string $slug        Menu slug (may be a .php file or a plugin page slug).
	 * @param string $parent_slug Parent slug for submenu items.
	 * @return string
	 */
	private static function menu_slug_to_url( $slug, $parent_slug = '' ) {
		if ( false !== strpos( $slug, '.php' ) || 0 === strpos( $slug, 'http' ) ) {
			return ( 0 === strpos( $slug, 'http' ) ) ? $slug : admin_url( $slug );
		}
		$parent_file = ( $parent_slug && false !== strpos( $parent_slug, '.php' ) ) ? $parent_slug : 'admin.php';
		return admin_url( add_query_arg( 'page', rawurlencode( $slug ), $parent_file ) );
	}

	/**
	 * Settings search: curated core settings plus everything third-party
	 * plugins register through the Settings API, each deep-linking to the
	 * screen where that setting lives.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_settings_search( AWPS_Command_Registry $registry ) {
		$category = __( 'Settings', 'advanced-wp-spotlight' );

		// Curated core settings with the aliases people actually type.
		$core = array(
			array( 'site-title', __( 'Site Title', 'advanced-wp-spotlight' ), 'options-general.php', array( 'blogname', 'site name' ) ),
			array( 'tagline', __( 'Tagline', 'advanced-wp-spotlight' ), 'options-general.php', array( 'site description', 'slogan' ) ),
			array( 'site-url', __( 'WordPress / Site Address (URL)', 'advanced-wp-spotlight' ), 'options-general.php', array( 'home url', 'siteurl', 'domain' ) ),
			array( 'admin-email', __( 'Administration Email Address', 'advanced-wp-spotlight' ), 'options-general.php', array( 'admin email' ) ),
			array( 'membership', __( 'Membership (Anyone can register)', 'advanced-wp-spotlight' ), 'options-general.php', array( 'registration', 'signup' ) ),
			array( 'timezone', __( 'Timezone', 'advanced-wp-spotlight' ), 'options-general.php', array( 'time zone', 'utc' ) ),
			array( 'date-format', __( 'Date Format', 'advanced-wp-spotlight' ), 'options-general.php', array() ),
			array( 'time-format', __( 'Time Format', 'advanced-wp-spotlight' ), 'options-general.php', array() ),
			array( 'language', __( 'Site Language', 'advanced-wp-spotlight' ), 'options-general.php', array( 'locale', 'translation' ) ),
			array( 'default-category', __( 'Default Post Category', 'advanced-wp-spotlight' ), 'options-writing.php', array() ),
			array( 'default-post-format', __( 'Default Post Format', 'advanced-wp-spotlight' ), 'options-writing.php', array() ),
			array( 'homepage-displays', __( 'Your Homepage Displays (Front Page)', 'advanced-wp-spotlight' ), 'options-reading.php', array( 'static front page', 'homepage settings' ) ),
			array( 'posts-per-page', __( 'Blog Pages Show at Most (Posts per Page)', 'advanced-wp-spotlight' ), 'options-reading.php', array( 'pagination' ) ),
			array( 'search-visibility', __( 'Search Engine Visibility', 'advanced-wp-spotlight' ), 'options-reading.php', array( 'discourage indexing', 'noindex', 'seo' ) ),
			array( 'comment-settings', __( 'Comment Settings', 'advanced-wp-spotlight' ), 'options-discussion.php', array( 'discussion', 'moderation' ) ),
			array( 'avatars', __( 'Avatar Settings', 'advanced-wp-spotlight' ), 'options-discussion.php', array( 'gravatar', 'profile picture' ) ),
			array( 'thumbnail-size', __( 'Image / Thumbnail Sizes', 'advanced-wp-spotlight' ), 'options-media.php', array( 'media sizes', 'image dimensions' ) ),
			array( 'permalink-structure', __( 'Permalink Structure', 'advanced-wp-spotlight' ), 'options-permalink.php', array( 'pretty urls', 'slugs', 'rewrite' ) ),
		);

		foreach ( $core as $item ) {
			$registry->register(
				array(
					'id'         => 'setting:' . $item[0],
					/* translators: %s: setting name */
					'title'      => sprintf( __( 'Setting: %s', 'advanced-wp-spotlight' ), $item[1] ),
					'category'   => $category,
					'type'       => 'url',
					'url'        => admin_url( $item[2] ),
					'capability' => 'manage_options',
					'keywords'   => $item[3],
					'icon'       => 'admin-settings',
				)
			);
		}

		// Harvest everything registered through the Settings API
		// (add_settings_field), including third-party plugin settings.
		global $wp_settings_fields;

		if ( ! is_array( $wp_settings_fields ) ) {
			return;
		}

		$core_pages = array( 'general', 'writing', 'reading', 'discussion', 'media', 'permalink' );

		foreach ( $wp_settings_fields as $page => $sections ) {
			// Resolve the page slug to a real admin URL.
			if ( in_array( $page, $core_pages, true ) ) {
				$url = admin_url( 'options-' . $page . '.php' );
			} else {
				$url = menu_page_url( $page, false );
				if ( ! $url ) {
					continue; // Not reachable as a screen — skip.
				}
			}

			foreach ( (array) $sections as $fields ) {
				foreach ( (array) $fields as $field_id => $field ) {
					$title = isset( $field['title'] ) ? trim( wp_strip_all_tags( $field['title'] ) ) : '';
					if ( '' === $title ) {
						continue;
					}
					$registry->register(
						array(
							'id'         => 'setting:' . sanitize_key( $page ) . ':' . sanitize_key( $field_id ),
							/* translators: %s: setting name */
							'title'      => sprintf( __( 'Setting: %s', 'advanced-wp-spotlight' ), $title ),
							'category'   => $category,
							'type'       => 'url',
							'url'        => $url,
							'capability' => 'manage_options',
							'keywords'   => array( $page ),
							'icon'       => 'admin-settings',
						)
					);
				}
			}
		}
	}

	/**
	 * Installed plugins as searchable commands with Activate / Deactivate.
	 *
	 * Activation runs directly (reversible); deactivation is confirm-gated
	 * and audit-logged via the destructive pipeline since it can take a
	 * feature or the whole storefront offline.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_plugin_commands( AWPS_Command_Registry $registry ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$category = __( 'Plugins', 'advanced-wp-spotlight' );
		$self     = plugin_basename( AWPS_PLUGIN_FILE );

		foreach ( get_plugins() as $file => $data ) {
			$name = wp_strip_all_tags( $data['Name'] );

			// Download the installed plugin as a zip (admin-only) —
			// registered for every plugin, including network-activated ones.
			$registry->register(
				array(
					'id'         => 'plugin:download:' . sanitize_key( $file ),
					/* translators: %s: plugin name */
					'title'      => sprintf( __( 'Download Plugin: %s', 'advanced-wp-spotlight' ), $name ),
					'category'   => $category,
					'type'       => 'js',
					'payload'    => 'download-package',
					'args'       => array(
						'kind' => 'plugin',
						'slug' => $file,
					),
					'capability' => 'manage_options',
					'keywords'   => array( $name, 'download', 'zip', 'export', 'backup plugin' ),
					'icon'       => 'download',
				)
			);

			// Network-activated plugins are managed from the network admin.
			if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
				continue;
			}

			if ( is_plugin_active( $file ) ) {
				// The palette must not deactivate itself.
				if ( $file === $self ) {
					continue;
				}
				$registry->register(
					array(
						'id'          => 'plugin:deactivate:' . sanitize_key( $file ),
						/* translators: %s: plugin name */
						'title'       => sprintf( __( 'Deactivate Plugin: %s', 'advanced-wp-spotlight' ), $name ),
						'category'    => $category,
						'type'        => 'rest',
						'action'      => 'toggle_plugin',
						'args'        => array(
							'plugin' => $file,
							'enable' => false,
						),
						'capability'  => 'activate_plugins',
						'keywords'    => array( $name, 'plugin', 'disable' ),
						'icon'        => 'admin-plugins',
						'destructive' => true,
						/* translators: %s: plugin name */
						'confirm'     => sprintf( __( 'Deactivate the plugin “%s”? Its features stop working until it is reactivated.', 'advanced-wp-spotlight' ), $name ),
					)
				);
			} else {
				$registry->register(
					array(
						'id'         => 'plugin:activate:' . sanitize_key( $file ),
						/* translators: %s: plugin name */
						'title'      => sprintf( __( 'Activate Plugin: %s', 'advanced-wp-spotlight' ), $name ),
						'category'   => $category,
						'type'       => 'rest',
						'action'     => 'toggle_plugin',
						'args'       => array(
							'plugin' => $file,
							'enable' => true,
						),
						'capability' => 'activate_plugins',
						'keywords'   => array( $name, 'plugin', 'enable', 'inactive' ),
						'icon'       => 'admin-plugins',
					)
				);
			}
		}
	}

	/**
	 * Installed themes as searchable "Switch Theme" commands (confirm-gated).
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_theme_commands( AWPS_Command_Registry $registry ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		$category = __( 'Themes', 'advanced-wp-spotlight' );
		$current  = get_stylesheet();

		foreach ( wp_get_themes( array( 'errors' => false ) ) as $stylesheet => $theme ) {
			$name = $theme->get( 'Name' );

			// Download the installed theme as a zip (admin-only) —
			// available for every theme, including the active one.
			$registry->register(
				array(
					'id'         => 'theme:download:' . sanitize_key( $stylesheet ),
					/* translators: %s: theme name */
					'title'      => sprintf( __( 'Download Theme: %s', 'advanced-wp-spotlight' ), $name ),
					'category'   => $category,
					'type'       => 'js',
					'payload'    => 'download-package',
					'args'       => array(
						'kind' => 'theme',
						'slug' => $stylesheet,
					),
					'capability' => 'manage_options',
					'keywords'   => array( $name, 'download', 'zip', 'export', 'backup theme' ),
					'icon'       => 'download',
				)
			);

			if ( $stylesheet === $current ) {
				continue;
			}

			$registry->register(
				array(
					'id'          => 'theme:switch:' . sanitize_key( $stylesheet ),
					/* translators: %s: theme name */
					'title'       => sprintf( __( 'Switch Theme: %s', 'advanced-wp-spotlight' ), $name ),
					'category'    => $category,
					'type'        => 'rest',
					'action'      => 'switch_theme',
					'args'        => array( 'stylesheet' => $stylesheet ),
					'capability'  => 'switch_themes',
					'keywords'    => array( $name, 'theme', 'appearance' ),
					'icon'        => 'admin-appearance',
					'destructive' => true,
					/* translators: %s: theme name */
					'confirm'     => sprintf( __( 'Switch the active theme to “%s”? The site frontend changes immediately.', 'advanced-wp-spotlight' ), $name ),
				)
			);
		}
	}

	/**
	 * Safe, non-destructive action commands executed headlessly via REST.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_actions( AWPS_Command_Registry $registry ) {
		$category = __( 'Actions', 'advanced-wp-spotlight' );

		$registry->register(
			array(
				'id'         => 'action:clear-cache',
				'title'      => __( 'Clear Cache', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'clear_cache',
				'capability' => 'manage_options',
				'keywords'   => array( 'flush cache', 'purge', 'object cache' ),
				'icon'       => 'update-alt',
			)
		);

		$registry->register(
			array(
				'id'         => 'action:flush-rewrite',
				'title'      => __( 'Flush Rewrite Rules', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'flush_rewrite',
				'capability' => 'manage_options',
				'keywords'   => array( 'permalinks', 'htaccess', 'rewrite rules' ),
				'icon'       => 'admin-links',
			)
		);

		$registry->register(
			array(
				'id'         => 'action:run-cron',
				'title'      => __( 'Run WP-Cron Now', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'run_cron',
				'capability' => 'manage_options',
				'keywords'   => array( 'scheduled tasks', 'cron jobs', 'wp cron' ),
				'icon'       => 'clock',
			)
		);

		$registry->register(
			array(
				'id'         => 'action:regenerate-css',
				'title'      => __( 'Regenerate CSS', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'regenerate_css',
				'capability' => 'manage_options',
				'keywords'   => array( 'rebuild styles', 'elementor css', 'global styles' ),
				'icon'       => 'art',
			)
		);

		$registry->register(
			array(
				'id'         => 'action:clear-expired-transients',
				'title'      => __( 'Clear Expired Transients', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'clear_expired_transients',
				'capability' => 'manage_options',
				'keywords'   => array( 'transients', 'cleanup', 'database' ),
				'icon'       => 'database-remove',
			)
		);

		$registry->register(
			array(
				'id'         => 'action:rebuild-index',
				'title'      => __( 'Rebuild Search Index', 'advanced-wp-spotlight' ),
				'category'   => $category,
				'type'       => 'rest',
				'action'     => 'rebuild_index',
				'capability' => 'manage_options',
				'keywords'   => array( 'reindex', 'sync search', 'spotlight index' ),
				'icon'       => 'database',
			)
		);
	}

	/**
	 * Developer & Site Health commands (Phase 3). All admin-only, all
	 * read-only info toasts except Clear Opcache. Debug mode is reported,
	 * not toggled — writing to wp-config.php from a palette keystroke is a
	 * risk we deliberately avoid.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_developer_commands( AWPS_Command_Registry $registry ) {
		$dev    = __( 'Developer', 'advanced-wp-spotlight' );
		$health = __( 'Site Health', 'advanced-wp-spotlight' );

		$items = array(
			array( 'dev:php-info', __( 'PHP Info', 'advanced-wp-spotlight' ), 'dev_php_info', $dev, array( 'php version', 'memory limit', 'server' ), 'editor-code' ),
			array( 'dev:db-size', __( 'Database Size', 'advanced-wp-spotlight' ), 'dev_db_size', $dev, array( 'mysql', 'disk usage' ), 'database' ),
			array( 'dev:object-cache', __( 'Object Cache Status', 'advanced-wp-spotlight' ), 'dev_object_cache', $dev, array( 'redis', 'memcached', 'persistent cache' ), 'performance' ),
			array( 'dev:clear-opcache', __( 'Clear Opcache', 'advanced-wp-spotlight' ), 'dev_clear_opcache', $dev, array( 'opcode cache', 'php cache' ), 'update-alt' ),
			array( 'dev:debug-status', __( 'Debug Mode Status', 'advanced-wp-spotlight' ), 'dev_debug_status', $dev, array( 'wp_debug', 'debugging' ), 'sos' ),
			array( 'dev:log-info', __( 'Debug Log Info', 'advanced-wp-spotlight' ), 'dev_log_info', $dev, array( 'error log', 'debug.log', 'view logs' ), 'media-text' ),
			array( 'health:updates', __( 'Updates Available', 'advanced-wp-spotlight' ), 'health_updates', $health, array( 'plugin updates', 'core update', 'upgrade' ), 'update' ),
			array( 'health:memory', __( 'Memory Usage', 'advanced-wp-spotlight' ), 'health_memory', $health, array( 'ram', 'memory limit' ), 'chart-area' ),
		);

		foreach ( $items as $item ) {
			$registry->register(
				array(
					'id'         => $item[0],
					'title'      => $item[1],
					'category'   => $item[3],
					'type'       => 'rest',
					'action'     => $item[2],
					'capability' => 'manage_options',
					'keywords'   => $item[4],
					'icon'       => $item[5],
				)
			);
		}

		// Debug toggles: these edit wp-config.php (with a backup and sanity
		// checks), so they are confirm-gated and audit-logged.
		$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$log_on   = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		$registry->register(
			array(
				'id'          => 'dev:toggle-debug',
				'title'       => $debug_on
					? __( 'Disable Debug Mode (WP_DEBUG)', 'advanced-wp-spotlight' )
					: __( 'Enable Debug Mode (WP_DEBUG)', 'advanced-wp-spotlight' ),
				'category'    => $dev,
				'type'        => 'rest',
				'action'      => 'toggle_debug_constant',
				'args'        => array( 'constant' => 'WP_DEBUG' ),
				'capability'  => 'manage_options',
				'keywords'    => array( 'wp_debug', 'debug mode', 'debugging', 'toggle debug' ),
				'icon'        => 'sos',
				'destructive' => true,
				'confirm'     => $debug_on
					? __( 'Turn OFF WP_DEBUG by editing wp-config.php? A backup (wp-config.awps-backup.php) is saved first.', 'advanced-wp-spotlight' )
					: __( 'Turn ON WP_DEBUG by editing wp-config.php? PHP notices may become visible. A backup (wp-config.awps-backup.php) is saved first.', 'advanced-wp-spotlight' ),
			)
		);

		$registry->register(
			array(
				'id'          => 'dev:toggle-debug-log',
				'title'       => $log_on
					? __( 'Disable Debug Logging (WP_DEBUG_LOG)', 'advanced-wp-spotlight' )
					: __( 'Enable Debug Logging (WP_DEBUG_LOG)', 'advanced-wp-spotlight' ),
				'category'    => $dev,
				'type'        => 'rest',
				'action'      => 'toggle_debug_constant',
				'args'        => array( 'constant' => 'WP_DEBUG_LOG' ),
				'capability'  => 'manage_options',
				'keywords'    => array( 'wp_debug_log', 'debug log', 'error log', 'toggle log' ),
				'icon'        => 'media-text',
				'destructive' => true,
				'confirm'     => $log_on
					? __( 'Turn OFF WP_DEBUG_LOG by editing wp-config.php? A backup (wp-config.awps-backup.php) is saved first.', 'advanced-wp-spotlight' )
					: __( 'Turn ON WP_DEBUG_LOG by editing wp-config.php? Errors will be written to debug.log. A backup (wp-config.awps-backup.php) is saved first.', 'advanced-wp-spotlight' ),
			)
		);

		// Download the debug log without touching the filesystem manually.
		$registry->register(
			array(
				'id'         => 'dev:download-log',
				'title'      => __( 'Download Debug Log', 'advanced-wp-spotlight' ),
				'category'   => $dev,
				'type'       => 'js',
				'payload'    => 'download-log',
				'capability' => 'manage_options',
				'keywords'   => array( 'debug.log', 'export log', 'error log download' ),
				'icon'       => 'download',
			)
		);

		// Audit log viewer (screen added by AWPS_Admin).
		$registry->register(
			array(
				'id'         => 'nav:audit-log',
				'title'      => __( 'Open Palette Audit Log', 'advanced-wp-spotlight' ),
				'category'   => $dev,
				'type'       => 'url',
				'url'        => admin_url( 'tools.php?page=awps-audit-log' ),
				'capability' => 'manage_options',
				'keywords'   => array( 'audit', 'history', 'destructive log' ),
				'icon'       => 'list-view',
			)
		);
	}

	/**
	 * WooCommerce deep integration (Phase 3). Order lookup by number lives
	 * in the REST search handler; this adds the contextual product
	 * visibility toggle on product editor screens.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_woocommerce_commands( AWPS_Command_Registry $registry ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || 'product' !== $screen->post_type || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$product_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$hidden = 'hidden' === $product->get_catalog_visibility();

		$registry->register(
			array(
				'id'         => 'wc:toggle-visibility',
				'title'      => $hidden
					? __( 'Show This Product in Catalog', 'advanced-wp-spotlight' )
					: __( 'Hide This Product from Catalog', 'advanced-wp-spotlight' ),
				'category'   => __( 'This Screen', 'advanced-wp-spotlight' ),
				'type'       => 'rest',
				'action'     => 'wc_toggle_product_visibility',
				'args'       => array( 'product_id' => $product_id ),
				'capability' => 'manage_woocommerce',
				'keywords'   => array( 'catalog visibility', 'hide product', 'show product' ),
				'icon'       => $hidden ? 'visibility' : 'hidden',
				'context'    => array( $screen->id ),
			)
		);
	}

	/**
	 * Clipboard utilities — executed entirely client-side.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_clipboard( AWPS_Command_Registry $registry ) {
		$category = __( 'Clipboard', 'advanced-wp-spotlight' );

		$items = array(
			array( 'copy:site-url', __( 'Copy Site URL', 'advanced-wp-spotlight' ), home_url( '/' ) ),
			array( 'copy:admin-url', __( 'Copy Admin URL', 'advanced-wp-spotlight' ), admin_url() ),
			array( 'copy:rest-url', __( 'Copy REST API URL', 'advanced-wp-spotlight' ), rest_url() ),
		);

		foreach ( $items as $item ) {
			$registry->register(
				array(
					'id'       => $item[0],
					'title'    => $item[1],
					'category' => $category,
					'type'     => 'copy',
					'payload'  => $item[2],
					'keywords' => array( 'clipboard', 'url' ),
					'icon'     => 'clipboard',
				)
			);
		}

		// Current page URL is only known client-side; JS substitutes location.href.
		$registry->register(
			array(
				'id'       => 'copy:current-url',
				'title'    => __( 'Copy Current Page URL', 'advanced-wp-spotlight' ),
				'category' => $category,
				'type'     => 'copy',
				'payload'  => '{current_url}',
				'keywords' => array( 'clipboard', 'this page' ),
				'icon'     => 'clipboard',
			)
		);
	}

	/**
	 * Palette self-configuration commands (handled in JS).
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_palette_settings( AWPS_Command_Registry $registry ) {
		$registry->register(
			array(
				'id'       => 'palette:change-shortcut',
				'title'    => __( 'Change Palette Keyboard Shortcut', 'advanced-wp-spotlight' ),
				'category' => __( 'Palette', 'advanced-wp-spotlight' ),
				'type'     => 'js',
				'payload'  => 'change-shortcut',
				'keywords' => array( 'keybinding', 'hotkey', 'rebind', 'shortcut' ),
				'icon'     => 'keyboard-hide',
			)
		);

		// One toggle command: flips between dark and light based on the
		// currently applied theme (the label is generic; the action is smart).
		$registry->register(
			array(
				'id'       => 'palette:theme-toggle',
				'title'    => __( 'Toggle Palette Dark / Light Mode', 'advanced-wp-spotlight' ),
				'category' => __( 'Palette', 'advanced-wp-spotlight' ),
				'type'     => 'js',
				'payload'  => 'theme-toggle',
				'keywords' => array( 'dark mode', 'light mode', 'dark theme', 'light theme', 'switch theme' ),
				'icon'     => 'lightbulb',
			)
		);

		$registry->register(
			array(
				'id'         => 'palette:settings',
				'title'      => __( 'Open Palette Settings', 'advanced-wp-spotlight' ),
				'category'   => __( 'Palette', 'advanced-wp-spotlight' ),
				'type'       => 'url',
				'url'        => admin_url( 'options-general.php?page=awps-settings' ),
				'capability' => 'manage_options',
				'keywords'   => array( 'spotlight settings', 'command palette settings' ),
				'icon'       => 'admin-generic',
			)
		);
	}

	/**
	 * Context-aware commands based on the current admin screen.
	 *
	 * These are built per page load so they can reference the object being
	 * edited (e.g. "Preview This Post" on a post editor screen).
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_contextual( AWPS_Command_Registry $registry ) {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$category = __( 'This Screen', 'advanced-wp-spotlight' );

		// Editing a single post/page/product.
		if ( 'post' === $screen->base && ! empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post    = get_post( $post_id );

			if ( $post && current_user_can( 'edit_post', $post_id ) ) {
				$type_obj  = get_post_type_object( $post->post_type );
				$type_name = $type_obj ? $type_obj->labels->singular_name : __( 'Post', 'advanced-wp-spotlight' );

				$registry->register(
					array(
						'id'         => 'context:view-frontend',
						/* translators: %s: post type singular name */
						'title'      => sprintf( __( 'View This %s on Frontend', 'advanced-wp-spotlight' ), $type_name ),
						'category'   => $category,
						'type'       => 'url',
						'url'        => get_permalink( $post_id ),
						'capability' => $type_obj ? $type_obj->cap->edit_posts : 'edit_posts',
						'context'    => array( $screen->id ),
						'icon'       => 'visibility',
					)
				);

				$registry->register(
					array(
						'id'         => 'context:preview',
						/* translators: %s: post type singular name */
						'title'      => sprintf( __( 'Preview This %s', 'advanced-wp-spotlight' ), $type_name ),
						'category'   => $category,
						'type'       => 'url',
						'url'        => get_preview_post_link( $post_id ),
						'capability' => $type_obj ? $type_obj->cap->edit_posts : 'edit_posts',
						'context'    => array( $screen->id ),
						'icon'       => 'search',
					)
				);

				$registry->register(
					array(
						'id'         => 'context:duplicate',
						/* translators: %s: post type singular name */
						'title'      => sprintf( __( 'Duplicate This %s (as Draft)', 'advanced-wp-spotlight' ), $type_name ),
						'category'   => $category,
						'type'       => 'rest',
						'action'     => 'duplicate_post',
						'args'       => array( 'post_id' => $post_id ),
						'capability' => $type_obj ? $type_obj->cap->edit_posts : 'edit_posts',
						'context'    => array( $screen->id ),
						'icon'       => 'admin-page',
					)
				);
			}
		}

		// Post list screens: quick "new" shortcut boosted for the current type.
		if ( 'edit' === $screen->base && $screen->post_type ) {
			$type_obj = get_post_type_object( $screen->post_type );
			if ( $type_obj && current_user_can( $type_obj->cap->create_posts ) ) {
				$registry->register(
					array(
						'id'         => 'context:new-' . $screen->post_type,
						/* translators: %s: post type singular name */
						'title'      => sprintf( __( 'Create New %s', 'advanced-wp-spotlight' ), $type_obj->labels->singular_name ),
						'category'   => $category,
						'type'       => 'url',
						'url'        => admin_url( 'post-new.php?post_type=' . $screen->post_type ),
						'capability' => $type_obj->cap->create_posts,
						'context'    => array( $screen->id ),
						'icon'       => 'plus',
					)
				);
			}
		}
	}

	/**
	 * Destructive commands (Phase 2).
	 *
	 * Design rules (see ROADMAP.md):
	 * - No destructive command ever guesses its scope: labels carry the exact
	 *   target and live counts ("Empty Trash for Products (14 items)").
	 * - The UI requires an explicit confirmation step; irreversible commands
	 *   additionally require typing a confirmation text.
	 * - The REST layer enforces confirm + rate limit + audit logging.
	 * - Reversible operations (trash) are preferred over permanent deletion
	 *   and return an Undo handle.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_destructive( AWPS_Command_Registry $registry ) {
		$category = __( 'Danger Zone', 'advanced-wp-spotlight' );
		$screen   = ( is_admin() && function_exists( 'get_current_screen' ) ) ? get_current_screen() : null;

		// --- Empty Trash, one explicitly-scoped command per post type with trashed items.
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type => $type_obj ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}

			$counts  = wp_count_posts( $post_type );
			$trashed = isset( $counts->trash ) ? (int) $counts->trash : 0;
			if ( $trashed < 1 ) {
				continue; // A type with nothing in the trash cannot be emptied.
			}

			$label = $type_obj->labels->name;

			$registry->register(
				array(
					'id'          => 'danger:empty-trash-' . $post_type,
					/* translators: 1: post type plural label, 2: number of trashed items */
					'title'       => sprintf( __( 'Empty Trash for %1$s (%2$d items)', 'advanced-wp-spotlight' ), $label, $trashed ),
					'category'    => $category,
					'type'        => 'rest',
					'action'      => 'empty_trash',
					'args'        => array(
						'post_type'      => $post_type,
						'expected_count' => $trashed,
					),
					'capability'  => $type_obj->cap->delete_posts,
					'keywords'    => array( 'empty trash', 'delete trashed', $post_type ),
					'icon'        => 'trash',
					'destructive' => true,
					/* translators: 1: number of trashed items, 2: post type plural label */
					'confirm'     => sprintf( __( 'Permanently delete %1$d trashed %2$s. This cannot be undone.', 'advanced-wp-spotlight' ), $trashed, $label ),
					'confirm_input' => 'I AGREE',
					'context'     => array( 'edit-' . $post_type ),
				)
			);
		}

		// --- Contextual: move the post being edited to trash (reversible tier).
		if ( $screen && 'post' === $screen->base && ! empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post    = get_post( $post_id );

			if ( $post && current_user_can( 'delete_post', $post_id ) && 'trash' !== $post->post_status ) {
				$type_obj  = get_post_type_object( $post->post_type );
				$type_name = $type_obj ? $type_obj->labels->singular_name : __( 'Post', 'advanced-wp-spotlight' );

				$registry->register(
					array(
						'id'          => 'danger:trash-current',
						/* translators: 1: post type singular name, 2: post title */
						'title'       => sprintf( __( 'Move This %1$s to Trash: “%2$s”', 'advanced-wp-spotlight' ), $type_name, wp_html_excerpt( $post->post_title, 40, '…' ) ),
						'category'    => $category,
						'type'        => 'rest',
						'action'      => 'trash_post',
						'args'        => array( 'post_id' => $post_id ),
						'capability'  => $type_obj ? $type_obj->cap->delete_posts : 'delete_posts',
						'icon'        => 'trash',
						'destructive' => true,
						/* translators: %s: post title */
						'confirm'     => sprintf( __( 'Move “%s” to the Trash? You can restore it afterwards.', 'advanced-wp-spotlight' ), $post->post_title ),
						'context'     => array( $screen->id ),
					)
				);
			}
		}

		// --- Delete ALL transients (they regenerate, but plugins may briefly slow down).
		$registry->register(
			array(
				'id'          => 'danger:delete-all-transients',
				'title'       => __( 'Delete All Transients', 'advanced-wp-spotlight' ),
				'category'    => $category,
				'type'        => 'rest',
				'action'      => 'delete_all_transients',
				'capability'  => 'manage_options',
				'keywords'    => array( 'transients', 'purge', 'database' ),
				'icon'        => 'database-remove',
				'destructive' => true,
				'confirm'     => __( 'Delete every transient (including unexpired ones)? They will be regenerated on demand, but the site may respond slightly slower until caches warm up again.', 'advanced-wp-spotlight' ),
			)
		);

		// --- Delete a User… (argument mode: the palette suggests real usernames).
		// User deletion is administrator-only: gated on manage_options in
		// addition to delete_users, per site policy.
		if ( current_user_can( 'manage_options' ) ) {
			$registry->register(
				array(
					'id'          => 'danger:delete-user',
					'title'       => __( 'Delete a User…', 'advanced-wp-spotlight' ),
					'category'    => $category,
					'type'        => 'js',
					'payload'     => 'delete-user',
					'capability'  => 'delete_users',
					'keywords'    => array( 'remove user', 'delete account' ),
					'icon'        => 'dismiss',
					'destructive' => true,
				)
			);
		}

		// --- Hidden commands backing inline result actions. Never listed in
		//     search; executable via REST with full destructive gating.
		$registry->register(
			array(
				'id'          => 'danger:trash-post',
				'title'       => __( 'Move to Trash', 'advanced-wp-spotlight' ),
				'category'    => $category,
				'type'        => 'rest',
				'action'      => 'trash_post',
				'capability'  => 'edit_posts', // Object-level delete_post re-checked in the handler.
				'destructive' => true,
				'hidden'      => true,
			)
		);

		$registry->register(
			array(
				'id'         => 'action:untrash-post',
				'title'      => __( 'Restore from Trash', 'advanced-wp-spotlight' ),
				'category'   => __( 'Actions', 'advanced-wp-spotlight' ),
				'type'       => 'rest',
				'action'     => 'untrash_post',
				'capability' => 'edit_posts',
				'hidden'     => true, // Restore is non-destructive: used by the Undo toast.
			)
		);

		// Also admin-only (mirrors the visible "Delete a User…" gate): its
		// presence in the localized index is what enables the inline Delete
		// action on user search results.
		if ( current_user_can( 'manage_options' ) ) {
			$registry->register(
				array(
					'id'          => 'danger:delete-user-exec',
					'title'       => __( 'Delete User', 'advanced-wp-spotlight' ),
					'category'    => $category,
					'type'        => 'rest',
					'action'      => 'delete_user',
					'capability'  => 'delete_users',
					'destructive' => true,
					'hidden'      => true,
				)
			);
		}

		// Terms (categories, tags, product categories, any show_ui taxonomy).
		$registry->register(
			array(
				'id'          => 'danger:delete-term',
				'title'       => __( 'Delete Term', 'advanced-wp-spotlight' ),
				'category'    => $category,
				'type'        => 'rest',
				'action'      => 'delete_term',
				'capability'  => 'manage_categories', // Taxonomy-specific cap re-checked in the handler.
				'destructive' => true,
				'hidden'      => true,
			)
		);

		// Navigation menus.
		$registry->register(
			array(
				'id'          => 'danger:delete-menu',
				'title'       => __( 'Delete Menu', 'advanced-wp-spotlight' ),
				'category'    => $category,
				'type'        => 'rest',
				'action'      => 'delete_menu',
				'capability'  => 'edit_theme_options',
				'destructive' => true,
				'hidden'      => true,
			)
		);
	}

	/**
	 * Handlers for destructive commands. The REST controller has already
	 * enforced confirm + capability + rate limit + audit logging before any
	 * of these run; they add object-level checks and server-side recounts.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_destructive_handlers( AWPS_Command_Registry $registry ) {

		$registry->register_action(
			'empty_trash',
			function ( $args ) {
				$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
				$expected  = isset( $args['expected_count'] ) ? (int) $args['expected_count'] : -1;

				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj ) {
					return new WP_Error( 'awps_bad_type', __( 'Unknown post type.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
				}

				$trashed_ids = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'trash',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);

				// Stale-UI protection: the client-claimed count must match reality.
				if ( $expected >= 0 && count( $trashed_ids ) !== $expected ) {
					return new WP_Error(
						'awps_stale_count',
						sprintf(
							/* translators: %d: current number of trashed items */
							__( 'The Trash contents changed (now %d items). Please reopen the palette and try again.', 'advanced-wp-spotlight' ),
							count( $trashed_ids )
						),
						array( 'status' => 409 )
					);
				}

				$deleted = 0;
				foreach ( $trashed_ids as $post_id ) {
					// Per-object permission parity, exactly like wp-admin's own Empty Trash.
					if ( ! current_user_can( 'delete_post', $post_id ) ) {
						continue;
					}
					if ( wp_delete_post( $post_id, true ) ) {
						$deleted++;
					}
				}

				return array(
					/* translators: 1: number deleted, 2: post type plural label */
					'message' => sprintf( __( 'Permanently deleted %1$d %2$s from the Trash.', 'advanced-wp-spotlight' ), $deleted, $type_obj->labels->name ),
				);
			}
		);

		$registry->register_action(
			'trash_post',
			function ( $args ) {
				$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
				$post    = get_post( $post_id );

				if ( ! $post ) {
					return new WP_Error( 'awps_not_found', __( 'Post not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'delete_post', $post_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to trash this item.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				if ( 'trash' === $post->post_status ) {
					return new WP_Error( 'awps_already_trashed', __( 'This item is already in the Trash.', 'advanced-wp-spotlight' ), array( 'status' => 409 ) );
				}
				// Without MEDIA_TRASH, attachments skip the Trash and would be
				// deleted permanently — refuse rather than mislead ("restore afterwards").
				if ( 'attachment' === $post->post_type && ! ( defined( 'MEDIA_TRASH' ) && MEDIA_TRASH ) ) {
					return new WP_Error(
						'awps_no_media_trash',
						__( 'Attachments skip the Trash on this site. Please delete them from the Media Library instead.', 'advanced-wp-spotlight' ),
						array( 'status' => 409 )
					);
				}

				if ( ! wp_trash_post( $post_id ) ) {
					return new WP_Error( 'awps_trash_failed', __( 'Could not move the item to the Trash.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
				}

				return array(
					/* translators: %s: post title */
					'message' => sprintf( __( '“%s” moved to Trash.', 'advanced-wp-spotlight' ), wp_html_excerpt( $post->post_title, 40, '…' ) ),
					// Undo handle: the toast offers to restore for ~10 seconds.
					'undo'    => array(
						'command' => 'action:untrash-post',
						'args'    => array( 'post_id' => $post_id ),
					),
				);
			}
		);

		$registry->register_action(
			'untrash_post',
			function ( $args ) {
				$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

				if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to restore this item.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				if ( ! wp_untrash_post( $post_id ) ) {
					return new WP_Error( 'awps_untrash_failed', __( 'Could not restore the item.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
				}

				return array( 'message' => __( 'Restored from Trash.', 'advanced-wp-spotlight' ) );
			}
		);

		$registry->register_action(
			'delete_user',
			function ( $args ) {
				$user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
				$reassign = isset( $args['reassign'] ) && 'me' === $args['reassign'] ? get_current_user_id() : null;

				$user = get_userdata( $user_id );
				if ( ! $user ) {
					return new WP_Error( 'awps_not_found', __( 'User not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( $user_id === get_current_user_id() ) {
					return new WP_Error( 'awps_self_delete', __( 'You cannot delete your own account from the palette.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				if ( ! current_user_can( 'delete_user', $user_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to delete this user.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				// Site policy: user deletion via the palette is administrator-only.
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'awps_admins_only', __( 'Only administrators can delete users from the palette.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				if ( is_multisite() && is_super_admin( $user_id ) ) {
					return new WP_Error( 'awps_super_admin', __( 'Super admins cannot be deleted from the palette.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}

				require_once ABSPATH . 'wp-admin/includes/user.php';

				if ( ! wp_delete_user( $user_id, $reassign ) ) {
					return new WP_Error( 'awps_delete_failed', __( 'Could not delete the user.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
				}

				return array(
					'message' => $reassign
						/* translators: %s: user login */
						? sprintf( __( 'User “%s” deleted. Their content was reassigned to you.', 'advanced-wp-spotlight' ), $user->user_login )
						/* translators: %s: user login */
						: sprintf( __( 'User “%s” and their content were deleted.', 'advanced-wp-spotlight' ), $user->user_login ),
				);
			}
		);

		$registry->register_action(
			'delete_all_transients',
			function () {
				global $wpdb;

				if ( wp_using_ext_object_cache() ) {
					return array( 'message' => __( 'Transients live in the external object cache on this site — use Clear Cache instead.', 'advanced-wp-spotlight' ) );
				}

				$deleted = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%'
					)
				);

				/* translators: %d: number of database rows removed */
				return array( 'message' => sprintf( __( 'All transients deleted (%d database rows removed).', 'advanced-wp-spotlight' ), $deleted ) );
			}
		);

		$registry->register_action(
			'delete_term',
			function ( $args ) {
				$term_id = isset( $args['term_id'] ) ? absint( $args['term_id'] ) : 0;
				$term    = get_term( $term_id );

				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'awps_not_found', __( 'Term not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}

				$taxonomy = get_taxonomy( $term->taxonomy );
				if ( ! $taxonomy ) {
					return new WP_Error( 'awps_bad_taxonomy', __( 'Unknown taxonomy.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
				}
				// Permission parity with the taxonomy's own delete capability,
				// including the per-term meta capability.
				if ( ! current_user_can( $taxonomy->cap->delete_terms ) || ! current_user_can( 'delete_term', $term_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to delete this term.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				// The default category cannot be deleted, same as wp-admin.
				if ( 'category' === $term->taxonomy && (int) get_option( 'default_category' ) === $term_id ) {
					return new WP_Error( 'awps_default_term', __( 'The default category cannot be deleted.', 'advanced-wp-spotlight' ), array( 'status' => 409 ) );
				}

				$result = wp_delete_term( $term_id, $term->taxonomy );
				if ( true !== $result ) {
					return new WP_Error( 'awps_delete_failed', __( 'Could not delete the term.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
				}

				return array(
					/* translators: 1: term name, 2: taxonomy singular label */
					'message' => sprintf( __( '“%1$s” (%2$s) was deleted. Assigned content was kept.', 'advanced-wp-spotlight' ), $term->name, $taxonomy->labels->singular_name ),
				);
			}
		);

		$registry->register_action(
			'delete_menu',
			function ( $args ) {
				global $wpdb;

				$menu_id = isset( $args['menu_id'] ) ? absint( $args['menu_id'] ) : 0;
				$menu    = wp_get_nav_menu_object( $menu_id );

				if ( ! $menu ) {
					return new WP_Error( 'awps_not_found', __( 'Menu not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_theme_options' ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to delete menus.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}

				if ( true !== wp_delete_nav_menu( $menu_id ) ) {
					return new WP_Error( 'awps_delete_failed', __( 'Could not delete the menu.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
				}

				// Menus are indexed under their own object_type; the generic
				// delete_term hook only cleans 'term' rows, so clean up here.
				$wpdb->delete(
					AWPS_Search_Index::table(),
					array(
						'object_type' => 'menu',
						'object_id'   => $menu_id,
					),
					array( '%s', '%d' )
				);

				return array(
					/* translators: %s: menu name */
					'message' => sprintf( __( 'Menu “%s” was deleted.', 'advanced-wp-spotlight' ), $menu->name ),
				);
			}
		);
	}

	/**
	 * Server-side handlers for the type=rest commands above.
	 *
	 * The REST controller re-checks the command capability before any of
	 * these run — handlers can still add object-level checks of their own.
	 *
	 * @param AWPS_Command_Registry $registry Registry.
	 */
	private static function register_action_handlers( AWPS_Command_Registry $registry ) {

		$registry->register_action(
			'clear_cache',
			function () {
				wp_cache_flush();
				/**
				 * Fires after the palette's Clear Cache command runs, so page-cache
				 * plugins can hook in and purge their own caches too.
				 */
				do_action( 'awps_clear_cache' );

				// Best-effort integration with common cache plugins.
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache(); // WP Super Cache.
				}
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain(); // WP Rocket.
				}
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all(); // W3 Total Cache.
				}

				return array( 'message' => __( 'Cache cleared.', 'advanced-wp-spotlight' ) );
			}
		);

		$registry->register_action(
			'flush_rewrite',
			function () {
				flush_rewrite_rules( false );
				return array( 'message' => __( 'Rewrite rules flushed.', 'advanced-wp-spotlight' ) );
			}
		);

		$registry->register_action(
			'run_cron',
			function () {
				$result = spawn_cron();
				return array(
					'message' => $result
						? __( 'WP-Cron triggered.', 'advanced-wp-spotlight' )
						: __( 'WP-Cron is already running or nothing is due.', 'advanced-wp-spotlight' ),
				);
			}
		);

		$registry->register_action(
			'regenerate_css',
			function () {
				// Elementor.
				if ( class_exists( '\Elementor\Plugin' ) ) {
					\Elementor\Plugin::instance()->files_manager->clear_cache();
				}
				// Core global styles / theme.json caches.
				if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
					wp_clean_theme_json_cache();
				}
				/**
				 * Fires when the palette's Regenerate CSS command runs, so builders
				 * and theme frameworks can rebuild their generated stylesheets.
				 */
				do_action( 'awps_regenerate_css' );

				return array( 'message' => __( 'CSS caches regenerated.', 'advanced-wp-spotlight' ) );
			}
		);

		$registry->register_action(
			'toggle_plugin',
			function ( $args ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$plugin = isset( $args['plugin'] ) ? (string) $args['plugin'] : '';
				$enable = ! empty( $args['enable'] );

				$valid = validate_plugin( $plugin );
				if ( is_wp_error( $valid ) ) {
					return new WP_Error( 'awps_bad_plugin', __( 'Plugin not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}

				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
				$name = wp_strip_all_tags( $data['Name'] );

				if ( $enable ) {
					if ( is_plugin_active( $plugin ) ) {
						return new WP_Error( 'awps_already_active', __( 'That plugin is already active.', 'advanced-wp-spotlight' ), array( 'status' => 409 ) );
					}
					$result = activate_plugin( $plugin );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					/* translators: %s: plugin name */
					return array( 'message' => sprintf( __( 'Plugin “%s” activated.', 'advanced-wp-spotlight' ), $name ) );
				}

				if ( plugin_basename( AWPS_PLUGIN_FILE ) === $plugin ) {
					return new WP_Error( 'awps_self_deactivate', __( 'The palette cannot deactivate itself — use the Plugins screen.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}
				if ( ! is_plugin_active( $plugin ) ) {
					return new WP_Error( 'awps_already_inactive', __( 'That plugin is already inactive.', 'advanced-wp-spotlight' ), array( 'status' => 409 ) );
				}

				deactivate_plugins( $plugin );
				/* translators: %s: plugin name */
				return array( 'message' => sprintf( __( 'Plugin “%s” deactivated.', 'advanced-wp-spotlight' ), $name ) );
			}
		);

		$registry->register_action(
			'switch_theme',
			function ( $args ) {
				$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';
				$theme      = wp_get_theme( $stylesheet );

				if ( ! $theme->exists() || ( is_multisite() && ! $theme->is_allowed() ) ) {
					return new WP_Error( 'awps_bad_theme', __( 'Theme not found or not allowed on this site.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( get_stylesheet() === $stylesheet ) {
					return new WP_Error( 'awps_already_active', __( 'That theme is already active.', 'advanced-wp-spotlight' ), array( 'status' => 409 ) );
				}

				switch_theme( $stylesheet );

				/* translators: %s: theme name */
				return array( 'message' => sprintf( __( 'Theme switched to “%s”.', 'advanced-wp-spotlight' ), $theme->get( 'Name' ) ) );
			}
		);

		$registry->register_action(
			'clear_expired_transients',
			function () {
				global $wpdb;

				if ( wp_using_ext_object_cache() ) {
					return array( 'message' => __( 'Transients live in the external object cache on this site — use Clear Cache instead.', 'advanced-wp-spotlight' ) );
				}

				$now     = time();
				$deleted = 0;

				foreach ( array( '_transient_', '_site_transient_' ) as $prefix ) {
					$timeout_prefix = str_replace( 'ient_', 'ient_timeout_', $prefix );
					// Delete each expired timeout row together with its value row.
					$deleted += (int) $wpdb->query(
						$wpdb->prepare(
							"DELETE a, b FROM {$wpdb->options} a
							 INNER JOIN {$wpdb->options} b
							   ON b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
							 WHERE a.option_name LIKE %s AND a.option_value < %d",
							$prefix,
							strlen( $timeout_prefix ) + 1,
							$wpdb->esc_like( $timeout_prefix ) . '%',
							$now
						)
					);
				}

				/* translators: %d: number of database rows removed */
				return array( 'message' => sprintf( __( 'Expired transients cleared (%d database rows removed).', 'advanced-wp-spotlight' ), $deleted ) );
			}
		);

		$registry->register_action(
			'dev_php_info',
			function () {
				return array(
					'message' => sprintf(
						/* translators: 1: PHP version, 2: memory limit, 3: max execution time, 4: server software */
						__( 'PHP %1$s — memory_limit %2$s, max_execution_time %3$ss, %4$s', 'advanced-wp-spotlight' ),
						PHP_VERSION,
						ini_get( 'memory_limit' ),
						ini_get( 'max_execution_time' ),
						isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : PHP_OS
					),
				);
			}
		);

		$registry->register_action(
			'dev_db_size',
			function () {
				global $wpdb;

				$bytes = (float) $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );

				return array(
					'message' => sprintf(
						/* translators: 1: database size, 2: MySQL server version */
						__( 'Database size: %1$s (server version %2$s).', 'advanced-wp-spotlight' ),
						size_format( $bytes, 1 ),
						$wpdb->db_version()
					),
				);
			}
		);

		$registry->register_action(
			'dev_object_cache',
			function () {
				$dropin = file_exists( WP_CONTENT_DIR . '/object-cache.php' );

				if ( wp_using_ext_object_cache() ) {
					$message = __( 'A persistent external object cache is active (object-cache.php drop-in installed).', 'advanced-wp-spotlight' );
				} elseif ( $dropin ) {
					$message = __( 'An object-cache.php drop-in exists but no external object cache reports active.', 'advanced-wp-spotlight' );
				} else {
					$message = __( 'No persistent object cache — WordPress is using the default in-memory cache per request.', 'advanced-wp-spotlight' );
				}

				return array( 'message' => $message );
			}
		);

		$registry->register_action(
			'dev_clear_opcache',
			function () {
				if ( ! function_exists( 'opcache_reset' ) ) {
					return array( 'message' => __( 'OPcache is not available on this server.', 'advanced-wp-spotlight' ) );
				}
				$done = opcache_reset();
				return array(
					'message' => $done
						? __( 'OPcache cleared.', 'advanced-wp-spotlight' )
						: __( 'OPcache could not be cleared (it may be disabled or restricted).', 'advanced-wp-spotlight' ),
				);
			}
		);

		$registry->register_action(
			'dev_debug_status',
			function () {
				$flag = function ( $name ) {
					return ( defined( $name ) && constant( $name ) ) ? __( 'on', 'advanced-wp-spotlight' ) : __( 'off', 'advanced-wp-spotlight' );
				};
				return array(
					'message' => sprintf(
						'WP_DEBUG: %1$s · WP_DEBUG_LOG: %2$s · WP_DEBUG_DISPLAY: %3$s · SCRIPT_DEBUG: %4$s',
						$flag( 'WP_DEBUG' ),
						$flag( 'WP_DEBUG_LOG' ),
						$flag( 'WP_DEBUG_DISPLAY' ),
						$flag( 'SCRIPT_DEBUG' )
					),
				);
			}
		);

		$registry->register_action(
			'dev_log_info',
			function () {
				$log = WP_CONTENT_DIR . '/debug.log';
				if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
					$log = WP_DEBUG_LOG;
				}

				if ( ! file_exists( $log ) ) {
					return array( 'message' => __( 'No debug log file found.', 'advanced-wp-spotlight' ) );
				}

				return array(
					'message' => sprintf(
						/* translators: 1: file size, 2: human time diff */
						__( 'debug.log: %1$s, last written %2$s ago.', 'advanced-wp-spotlight' ),
						size_format( (float) filesize( $log ), 1 ),
						human_time_diff( (int) filemtime( $log ) )
					),
				);
			}
		);

		$registry->register_action(
			'health_updates',
			function () {
				$data   = wp_get_update_data();
				$counts = $data['counts'];

				if ( empty( $counts['total'] ) ) {
					return array( 'message' => __( 'Everything is up to date.', 'advanced-wp-spotlight' ) );
				}

				return array(
					'message' => sprintf(
						/* translators: 1: total, 2: plugins, 3: themes, 4: core */
						__( '%1$d updates available — plugins: %2$d, themes: %3$d, WordPress core: %4$d.', 'advanced-wp-spotlight' ),
						(int) $counts['total'],
						(int) $counts['plugins'],
						(int) $counts['themes'],
						(int) $counts['wordpress']
					),
					'redirect' => '',
				);
			}
		);

		$registry->register_action(
			'health_memory',
			function () {
				return array(
					'message' => sprintf(
						/* translators: 1: current usage, 2: PHP memory limit, 3: WP memory limit */
						__( 'Current usage %1$s — PHP memory_limit %2$s, WP_MEMORY_LIMIT %3$s.', 'advanced-wp-spotlight' ),
						size_format( memory_get_usage( true ), 1 ),
						ini_get( 'memory_limit' ),
						defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—'
					),
				);
			}
		);

		$registry->register_action(
			'wc_toggle_product_visibility',
			function ( $args ) {
				if ( ! function_exists( 'wc_get_product' ) ) {
					return new WP_Error( 'awps_no_wc', __( 'WooCommerce is not active.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
				}

				$product_id = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
				$product    = wc_get_product( $product_id );

				if ( ! $product ) {
					return new WP_Error( 'awps_not_found', __( 'Product not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $product_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to edit this product.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}

				$hidden = 'hidden' === $product->get_catalog_visibility();
				$product->set_catalog_visibility( $hidden ? 'visible' : 'hidden' );
				$product->save();

				return array(
					'message' => $hidden
						/* translators: %s: product name */
						? sprintf( __( '“%s” is now visible in the catalog.', 'advanced-wp-spotlight' ), $product->get_name() )
						/* translators: %s: product name */
						: sprintf( __( '“%s” is now hidden from the catalog.', 'advanced-wp-spotlight' ), $product->get_name() ),
				);
			}
		);

		$registry->register_action(
			'rebuild_index',
			function () {
				AWPS_Search_Index::enqueue_full_rebuild();
				return array( 'message' => __( 'Search index rebuild queued — it runs in the background.', 'advanced-wp-spotlight' ) );
			}
		);

		$registry->register_action(
			'duplicate_post',
			function ( $args ) {
				$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
				$post    = get_post( $post_id );

				if ( ! $post ) {
					return new WP_Error( 'awps_not_found', __( 'Post not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to duplicate this item.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}

				$new_id = wp_insert_post(
					array(
						/* translators: %s: original post title */
						'post_title'   => sprintf( __( '%s (Copy)', 'advanced-wp-spotlight' ), $post->post_title ),
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_type'    => $post->post_type,
						'post_status'  => 'draft',
						'post_author'  => get_current_user_id(),
					),
					true
				);

				if ( is_wp_error( $new_id ) ) {
					return $new_id;
				}

				// Copy taxonomy terms.
				foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
					$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $terms ) && $terms ) {
						wp_set_object_terms( $new_id, $terms, $taxonomy );
					}
				}

				// Copy non-protected meta.
				$meta = get_post_meta( $post_id );
				foreach ( $meta as $key => $values ) {
					if ( is_protected_meta( $key, 'post' ) ) {
						continue;
					}
					foreach ( $values as $value ) {
						add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
					}
				}

				return array(
					'message'  => __( 'Duplicated as draft.', 'advanced-wp-spotlight' ),
					'redirect' => get_edit_post_link( $new_id, 'raw' ),
				);
			}
		);

		$registry->register_action(
			'toggle_debug_constant',
			function ( $args ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'awps_forbidden', __( 'You are not allowed to change debug settings.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
				}

				$constant = isset( $args['constant'] ) ? strtoupper( sanitize_key( $args['constant'] ) ) : '';
				$allowed  = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG' );
				if ( ! in_array( $constant, $allowed, true ) ) {
					return new WP_Error( 'awps_bad_constant', __( 'This constant cannot be toggled from the palette.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
				}

				$current = defined( $constant ) && constant( $constant );
				$result  = self::update_wp_config_constant( $constant, ! $current );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$message = sprintf(
					/* translators: 1: constant name, 2: on/off */
					__( '%1$s is now %2$s. A backup was saved as wp-config.awps-backup.php.', 'advanced-wp-spotlight' ),
					$constant,
					$current ? __( 'OFF', 'advanced-wp-spotlight' ) : __( 'ON', 'advanced-wp-spotlight' )
				);

				// WP_DEBUG_LOG only has an effect while WP_DEBUG is on.
				if ( 'WP_DEBUG_LOG' === $constant && ! $current && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
					$message .= ' ' . __( 'Note: logging only works while WP_DEBUG is also on.', 'advanced-wp-spotlight' );
				}

				return array( 'message' => $message );
			}
		);
	}

	/**
	 * Locate wp-config.php the same way WordPress core does.
	 *
	 * @return string|false Absolute path or false when not found.
	 */
	private static function locate_wp_config() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}

		// One directory above, as long as that is not another WP install.
		$above = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $above ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $above;
		}

		return false;
	}

	/**
	 * Safely set a boolean constant in wp-config.php.
	 *
	 * Takes a backup first, edits only the single define() line (or inserts
	 * one before the "stop editing" marker), verifies the result still loads
	 * wp-settings.php and writes atomically via a temp file + rename.
	 *
	 * @param string $constant Constant name (already whitelisted by caller).
	 * @param bool   $value    New boolean value.
	 * @return true|WP_Error
	 */
	private static function update_wp_config_constant( $constant, $value ) {
		$path = self::locate_wp_config();

		if ( ! $path ) {
			return new WP_Error( 'awps_no_config', __( 'wp-config.php could not be located.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}
		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) {
			return new WP_Error( 'awps_config_readonly', __( 'wp-config.php is not writable — change the constant manually or adjust file permissions.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents || false === strpos( $contents, 'wp-settings.php' ) ) {
			return new WP_Error( 'awps_config_unreadable', __( 'wp-config.php could not be read safely.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		$new_line = sprintf( "define( '%s', %s );", $constant, $value ? 'true' : 'false' );
		$pattern  = '/define\s*\(\s*([\'"])' . preg_quote( $constant, '/' ) . '\1\s*,[^;]*\)\s*;/';

		if ( preg_match( $pattern, $contents ) ) {
			$updated = preg_replace( $pattern, $new_line, $contents, 1 );
		} else {
			// Insert before the "stop editing" marker, or before the
			// wp-settings.php require as a fallback.
			$updated = preg_replace(
				'/(\/\*\s*That\'s all|require_once\s*\(?\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php)/',
				$new_line . "\n$1",
				$contents,
				1
			);
		}

		if ( empty( $updated ) || false === strpos( $updated, $new_line ) || false === strpos( $updated, 'wp-settings.php' ) ) {
			return new WP_Error( 'awps_config_edit_failed', __( 'wp-config.php could not be modified safely — no changes were made.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		// Backup, then write atomically (temp file + rename) so a partial
		// write can never corrupt wp-config.php.
		$backup = dirname( $path ) . '/wp-config.awps-backup.php';
		if ( ! copy( $path, $backup ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'awps_backup_failed', __( 'Could not create a wp-config.php backup — aborted, no changes were made.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		$tmp = $path . '.awps-tmp';
		if ( false === file_put_contents( $tmp, $updated, LOCK_EX ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( file_exists( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			return new WP_Error( 'awps_write_failed', __( 'Failed to write wp-config.php — the original file is untouched.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		// Make sure PHP does not keep serving the old file from opcache.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}

		return true;
	}
}
