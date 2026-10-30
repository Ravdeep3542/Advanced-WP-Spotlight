<?php
/**
 * Admin integration: asset loading, palette bootstrap data, admin-bar
 * entry point, settings screen and per-user profile field.
 *
 * The command index is built fresh on every admin page load (in-memory,
 * no DB hits) so it is always permission-correct for the current user,
 * and mirrored into sessionStorage client-side keyed by a hash so the
 * palette can render instantly.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_Admin {

	const SETTINGS_OPTION = 'awps_settings';
	const SETTINGS_SLUG   = 'awps-settings';

	/**
	 * @var AWPS_Command_Registry
	 */
	private $registry;

	/**
	 * @var AWPS_Search_Index
	 */
	private $index;

	/**
	 * @param AWPS_Command_Registry $registry Registry.
	 * @param AWPS_Search_Index     $index    Index.
	 */
	public function __construct( AWPS_Command_Registry $registry, AWPS_Search_Index $index ) {
		$this->registry = $registry;
		$this->index    = $index;
	}

	/**
	 * Attach admin hooks.
	 */
	public function hook() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_entry' ), 100 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'show_user_profile', array( $this, 'profile_shortcut_field' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_shortcut_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_shortcut_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_shortcut_field' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AWPS_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Enqueue the palette on every admin screen and localize its bootstrap data.
	 *
	 * Runs after admin_menu, so the registry's admin-menu harvest and the
	 * current screen are both available.
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'awps-palette',
			AWPS_PLUGIN_URL . 'assets/css/palette.css',
			array( 'dashicons' ),
			AWPS_VERSION
		);

		wp_enqueue_script(
			'awps-palette',
			AWPS_PLUGIN_URL . 'assets/js/palette.js',
			array(),
			AWPS_VERSION,
			true
		);

		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$commands = $this->registry->get_for_current_user();

		$recents_map = AWPS_User_Preferences::get_recents();

		$data = array(
			'restUrl'        => esc_url_raw( rest_url( AWPS_REST_Controller::NAMESPACE ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'commands'       => $commands,
			'indexHash'      => md5( wp_json_encode( wp_list_pluck( $commands, 'id' ) ) . AWPS_VERSION ),
			'screen'         => $screen ? $screen->id : '',
			'shortcut'       => AWPS_User_Preferences::get_shortcut(),
			'theme'          => AWPS_User_Preferences::get_theme(),
			'indexPending'   => (bool) get_option( AWPS_Search_Index::QUEUE_OPTION ),
			'riskyShortcuts' => AWPS_User_Preferences::risky_shortcuts(),
			'currentUserId'  => get_current_user_id(),
			'favorites'      => AWPS_User_Preferences::get_favorites(),
			'recents'        => $recents_map,
			'showHint'       => ! AWPS_User_Preferences::hint_seen(),
			'i18n'           => array(
				'placeholder'      => __( 'Type a command or search…', 'advanced-wp-spotlight' ),
				'noResults'        => __( 'No matching commands or content.', 'advanced-wp-spotlight' ),
				'favorites'        => __( 'Favorites', 'advanced-wp-spotlight' ),
				'recent'           => __( 'Recent', 'advanced-wp-spotlight' ),
				'suggested'        => __( 'Suggested', 'advanced-wp-spotlight' ),
				'content'          => __( 'Content', 'advanced-wp-spotlight' ),
				'searching'        => __( 'Searching…', 'advanced-wp-spotlight' ),
				'copied'           => __( 'Copied to clipboard.', 'advanced-wp-spotlight' ),
				'copyFailed'       => __( 'Could not copy to clipboard.', 'advanced-wp-spotlight' ),
				'working'          => __( 'Working…', 'advanced-wp-spotlight' ),
				'error'            => __( 'Something went wrong.', 'advanced-wp-spotlight' ),
				'edit'             => __( 'Edit', 'advanced-wp-spotlight' ),
				'view'             => __( 'View', 'advanced-wp-spotlight' ),
				'pin'              => __( 'Pin', 'advanced-wp-spotlight' ),
				'unpin'            => __( 'Unpin', 'advanced-wp-spotlight' ),
				'recordShortcut'   => __( 'Press the key combination you want, then Enter to save. Esc cancels.', 'advanced-wp-spotlight' ),
				'shortcutRisky'    => __( 'Heads up: this combination usually belongs to your browser or OS and may not always reach WordPress. You can still use it.', 'advanced-wp-spotlight' ),
				'shortcutSaved'    => __( 'Shortcut saved:', 'advanced-wp-spotlight' ),
				/* translators: %s: keyboard shortcut */
				'hint'             => __( 'Tip: press %s to open the command palette anywhere in wp-admin.', 'advanced-wp-spotlight' ),
				'actionsHint'      => __( '↑↓ navigate · Enter run · Tab actions · Esc close', 'advanced-wp-spotlight' ),
				'dangerZone'       => __( 'Danger Zone', 'advanced-wp-spotlight' ),
				'confirmTitle'     => __( 'Are you sure?', 'advanced-wp-spotlight' ),
				'confirmButton'    => __( 'Yes, do it', 'advanced-wp-spotlight' ),
				'cancel'           => __( 'Cancel', 'advanced-wp-spotlight' ),
				/* translators: %s: confirmation text the user must type */
				'typeToConfirm'    => __( 'Type “%s” to enable the confirm button:', 'advanced-wp-spotlight' ),
				'trashAction'      => __( 'Move to Trash', 'advanced-wp-spotlight' ),
				'deleteAction'     => __( 'Delete', 'advanced-wp-spotlight' ),
				'undo'             => __( 'Undo', 'advanced-wp-spotlight' ),
				'searchUsers'      => __( 'Type a user’s name to select who to delete…', 'advanced-wp-spotlight' ),
				'noUsersFound'     => __( 'No user found with that name.', 'advanced-wp-spotlight' ),
				'back'             => __( 'Back to search (Esc)', 'advanced-wp-spotlight' ),
				'themeSaved'       => __( 'Palette theme updated.', 'advanced-wp-spotlight' ),
				'logDownloaded'    => __( 'Debug log downloaded.', 'advanced-wp-spotlight' ),
				'packageDownloaded' => __( 'Zip downloaded.', 'advanced-wp-spotlight' ),
				'indexBuilding'    => __( 'The search index is still building in the background — content results may be incomplete for a few minutes.', 'advanced-wp-spotlight' ),
				/* translators: %s: user display name */
				'deleteUserMsg'    => __( 'Permanently delete user “%s”? This cannot be undone.', 'advanced-wp-spotlight' ),
				/* translators: %s: term name */
				'deleteTermMsg'    => __( 'Permanently delete “%s”? Content assigned to it will be kept, but the term itself cannot be restored.', 'advanced-wp-spotlight' ),
				/* translators: %s: menu name */
				'deleteMenuMsg'    => __( 'Permanently delete the menu “%s”? This cannot be undone.', 'advanced-wp-spotlight' ),
				'reassignMe'       => __( 'Reassign their content to me', 'advanced-wp-spotlight' ),
				'reassignNone'     => __( 'Delete their content too', 'advanced-wp-spotlight' ),
				/* translators: %s: post title */
				'trashPostMsg'     => __( 'Move “%s” to the Trash? You can restore it afterwards.', 'advanced-wp-spotlight' ),
			),
		);

		wp_add_inline_script( 'awps-palette', 'window.AWPS_DATA = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Persistent fallback entry point in the admin bar (discoverability).
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_admin_bar_entry( $admin_bar ) {
		if ( ! is_admin() ) {
			return;
		}

		$shortcut = strtoupper( str_replace( '+', ' + ', AWPS_User_Preferences::get_shortcut() ) );

		$admin_bar->add_node(
			array(
				'id'    => 'awps-launcher',
				'title' => '<span class="ab-icon dashicons dashicons-search" style="margin-top:2px"></span><span class="ab-label">' . esc_html__( 'Palette', 'advanced-wp-spotlight' ) . '</span>',
				'href'  => '#',
				'meta'  => array(
					/* translators: %s: keyboard shortcut */
					'title' => sprintf( __( 'Open command palette (%s)', 'advanced-wp-spotlight' ), $shortcut ),
					'class' => 'awps-admin-bar-launcher',
				),
			)
		);
	}

	/**
	 * Settings page (Settings → Command Palette).
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Command Palette', 'advanced-wp-spotlight' ),
			__( 'Command Palette', 'advanced-wp-spotlight' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		add_management_page(
			__( 'Palette Audit Log', 'advanced-wp-spotlight' ),
			__( 'Palette Audit Log', 'advanced-wp-spotlight' ),
			'manage_options',
			'awps-audit-log',
			array( $this, 'render_audit_log_page' )
		);
	}

	/**
	 * Audit log viewer: the last 200 destructive-command entries.
	 */
	public function render_audit_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . AWPS_Audit_Log::table() . ' ORDER BY id DESC LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palette Audit Log', 'advanced-wp-spotlight' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every destructive command attempt made through the command palette — including forbidden and rate-limited ones. Showing the most recent 200 entries.', 'advanced-wp-spotlight' ); ?>
			</p>

			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date (UTC)', 'advanced-wp-spotlight' ); ?></th>
						<th><?php esc_html_e( 'User', 'advanced-wp-spotlight' ); ?></th>
						<th><?php esc_html_e( 'Command', 'advanced-wp-spotlight' ); ?></th>
						<th><?php esc_html_e( 'Targets', 'advanced-wp-spotlight' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-wp-spotlight' ); ?></th>
						<th><?php esc_html_e( 'Outcome', 'advanced-wp-spotlight' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No destructive commands have been executed yet.', 'advanced-wp-spotlight' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $user = get_userdata( (int) $row['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td><?php echo esc_html( $user ? $user->user_login : ( '#' . $row['user_id'] ) ); ?></td>
								<td><code><?php echo esc_html( $row['command_id'] ); ?></code></td>
								<td style="max-width:280px;overflow-wrap:anywhere;"><code><?php echo esc_html( (string) $row['targets'] ); ?></code></td>
								<td>
									<?php
									$status = $row['status'];
									$color  = 'success' === $status ? '#46b450' : ( 'pending' === $status ? '#996800' : '#dc3232' );
									echo '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</strong>';
									?>
								</td>
								<td><?php echo esc_html( (string) $row['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Register the settings option.
	 */
	public function register_settings() {
		register_setting(
			'awps_settings_group',
			self::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$clean = array();

		// The form always submits the key (hidden marker field), so an empty
		// result means the admin deliberately unchecked everything.
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$valid               = get_post_types( array( 'show_ui' => true ), 'names' );
			$clean['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', array_filter( $input['post_types'] ) ), $valid ) );
		}

		// A change in indexed types warrants a rebuild.
		AWPS_Search_Index::enqueue_full_rebuild();

		return $clean;
	}

	/**
	 * Render the settings screen.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = get_option( self::SETTINGS_OPTION, array() );
		$status     = $this->index->status();
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		unset( $post_types['attachment'] );

		// Until the admin saves a choice, every type is checked (and indexed).
		if ( isset( $settings['post_types'] ) ) {
			$selected = (array) $settings['post_types'];
		} else {
			$selected = array_values(
				array_diff(
					array_keys( $post_types ),
					array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' )
				)
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Command Palette', 'advanced-wp-spotlight' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %s: keyboard shortcut */
					esc_html__( 'Your personal activation shortcut is %s — change it from the palette itself ("Change Palette Keyboard Shortcut") or on your profile page.', 'advanced-wp-spotlight' ),
					'<code>' . esc_html( AWPS_User_Preferences::get_shortcut() ) . '</code>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'awps_settings_group' ); ?>

				<h2><?php esc_html_e( 'Content Index', 'advanced-wp-spotlight' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'All post types are indexed and searchable by default. Uncheck a type and save to remove it from search — its already-indexed entries are cleaned up automatically in the background. Media, users, taxonomies and menus are always indexed.', 'advanced-wp-spotlight' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Indexed post types', 'advanced-wp-spotlight' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[post_types][]" value="" />
							<?php foreach ( $post_types as $name => $object ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox"
										name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[post_types][]"
										value="<?php echo esc_attr( $name ); ?>"
										<?php checked( in_array( $name, $selected, true ) ); ?> />
									<?php echo esc_html( $object->labels->name ); ?>
									<code><?php echo esc_html( $name ); ?></code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Index status', 'advanced-wp-spotlight' ); ?></th>
						<td>
							<p>
								<?php
								printf(
									/* translators: 1: row count, 2: pending job count */
									esc_html__( '%1$s items indexed · %2$s background jobs pending', 'advanced-wp-spotlight' ),
									'<strong>' . esc_html( number_format_i18n( $status['rows'] ) ) . '</strong>',
									'<strong>' . esc_html( number_format_i18n( $status['pending'] ) ) . '</strong>'
								);
								?>
							</p>
							<p class="description">
								<?php
								if ( $status['last_sync'] ) {
									printf(
										/* translators: %s: human time diff */
										esc_html__( 'Last full sync completed %s ago.', 'advanced-wp-spotlight' ),
										esc_html( human_time_diff( $status['last_sync'] ) )
									);
								} else {
									esc_html_e( 'Initial sync has not completed yet — it runs in the background via WP-Cron.', 'advanced-wp-spotlight' );
								}
								?>
								<?php esc_html_e( 'You can also run the "Rebuild Search Index" command from the palette.', 'advanced-wp-spotlight' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One-time activation / discoverability notice.
	 */
	public function activation_notice() {
		if ( ! get_transient( 'awps_activation_notice' ) ) {
			return;
		}
		delete_transient( 'awps_activation_notice' );

		$shortcut = strtoupper( str_replace( '+', ' + ', AWPS_User_Preferences::get_shortcut() ) );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Command Palette is ready.', 'advanced-wp-spotlight' ); ?></strong>
				<?php
				printf(
					/* translators: %s: keyboard shortcut */
					esc_html__( 'Press %s anywhere in wp-admin to open it, or click the Palette icon in the admin bar.', 'advanced-wp-spotlight' ),
					'<code>' . esc_html( $shortcut ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Per-user shortcut field on the profile screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function profile_shortcut_field( $user ) {
		?>
		<h2><?php esc_html_e( 'Command Palette', 'advanced-wp-spotlight' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="awps_shortcut"><?php esc_html_e( 'Activation shortcut', 'advanced-wp-spotlight' ); ?></label>
				</th>
				<td>
					<input type="text" name="awps_shortcut" id="awps_shortcut" class="regular-text"
						value="<?php echo esc_attr( AWPS_User_Preferences::get_shortcut( $user->ID ) ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Personal keyboard shortcut that opens the palette, e.g. ctrl+/, ctrl+shift+p or meta+k (meta = Cmd on macOS).', 'advanced-wp-spotlight' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the profile shortcut field.
	 *
	 * @param int $user_id User id being saved.
	 */
	public function save_profile_shortcut_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['awps_shortcut'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core profile form nonce already verified.
			return;
		}
		AWPS_User_Preferences::set_shortcut( sanitize_text_field( wp_unslash( $_POST['awps_shortcut'] ) ), $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Settings link on the plugins list row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'advanced-wp-spotlight' ) . '</a>'
		);
		return $links;
	}
}
