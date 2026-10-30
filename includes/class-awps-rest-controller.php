<?php
/**
 * REST API endpoints (namespace awps/v1).
 *
 *   GET  /search?term=…   Content search against the custom index table.
 *   POST /execute         Run a type=rest command (server re-verifies capability).
 *   POST /prefs           Save shortcut / toggle favorite / record usage / dismiss hint.
 *   GET  /log             Stream debug.log (manage_options, path-confined).
 *   GET  /download        Zip an installed plugin/theme (manage_options, path-confined).
 *
 * Execution-time capability verification is the security boundary: whatever
 * the client claims, nothing runs unless current_user_can() passes for the
 * registered command.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_REST_Controller {

	const NAMESPACE = 'awps/v1';

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
	 * @param AWPS_Search_Index     $index    Search index.
	 */
	public function __construct( AWPS_Command_Registry $registry, AWPS_Search_Index $index ) {
		$this->registry = $registry;
		$this->index    = $index;
	}

	/**
	 * Attach the rest_api_init hook.
	 */
	public function hook() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => array( $this, 'can_use_palette' ),
				'args'                => array(
					'term' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_execute' ),
				'permission_callback' => array( $this, 'can_use_palette' ),
				'args'                => array(
					'command' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'args'    => array(
						'required' => false,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_log_download' ),
				'permission_callback' => array( $this, 'can_download_log' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_package_download' ),
				'permission_callback' => array( $this, 'can_download_log' ),
				'args'                => array(
					'kind' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'plugin', 'theme' ),
					),
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/prefs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_prefs' ),
				'permission_callback' => array( $this, 'can_use_palette' ),
			)
		);
	}

	/**
	 * Base permission: any logged-in user with wp-admin access.
	 * Individual commands and index rows carry their own stricter checks.
	 *
	 * @return bool
	 */
	public function can_use_palette() {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * File downloads (debug log, plugin/theme zips) are admin-only.
	 *
	 * @return bool
	 */
	public function can_download_log() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * GET /log — stream debug.log as a file download.
	 *
	 * Bypasses the JSON serializer on purpose so the browser receives the
	 * raw file with attachment headers; errors still return normal JSON.
	 * The resolved path must stay inside wp-content (or a custom WP_DEBUG_LOG
	 * path that itself resolves inside wp-content) to prevent path disclosure.
	 *
	 * @return WP_Error|void
	 */
	public function handle_log_download() {
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && '1' !== WP_DEBUG_LOG ) {
			$log = WP_DEBUG_LOG;
		}

		$real = realpath( $log );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return new WP_Error( 'awps_no_log', __( 'No debug log file found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
		}

		// Confine reads to wp-content so a mis-set WP_DEBUG_LOG cannot exfiltrate
		// arbitrary server files through this admin-only endpoint.
		if ( ! $this->is_path_inside( $real, WP_CONTENT_DIR ) ) {
			return new WP_Error( 'awps_log_forbidden', __( 'The configured debug log path is outside wp-content and cannot be downloaded from the palette.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
		}

		$size = filesize( $real );
		if ( false === $size ) {
			return new WP_Error( 'awps_no_log', __( 'No debug log file found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
		}
		// Soft cap: avoid accidental multi-gigabyte transfers from a runaway log.
		if ( $size > 50 * MB_IN_BYTES ) {
			return new WP_Error( 'awps_log_too_large', __( 'The debug log is larger than 50 MB. Truncate or download it via FTP/SFTP instead.', 'advanced-wp-spotlight' ), array( 'status' => 413 ) );
		}

		$filename = 'debug-' . gmdate( 'Ymd-His' ) . '.log';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) $size );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * GET /download — zip an installed plugin or theme and stream it.
	 *
	 * The slug is validated strictly against the installed plugin/theme
	 * lists (never used as a raw path), so path traversal is impossible.
	 * Admin-only (same permission callback as the log download).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function handle_package_download( WP_REST_Request $request ) {
		$kind = (string) $request->get_param( 'kind' );
		$slug = (string) $request->get_param( 'slug' );

		if ( '' === $slug || false !== strpos( $slug, "\0" ) || 0 !== validate_file( $slug ) ) {
			return new WP_Error( 'awps_bad_slug', __( 'Invalid plugin or theme identifier.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'awps_no_zip', __( 'The ZipArchive PHP extension is not available on this server.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		$allowed_root = '';

		if ( 'plugin' === $kind ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			if ( ! isset( $plugins[ $slug ] ) ) {
				return new WP_Error( 'awps_not_found', __( 'Plugin not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
			}

			$dir = dirname( $slug );
			if ( '.' === $dir ) {
				// Single-file plugin (e.g. hello.php) — zip just the file.
				$source = WP_PLUGIN_DIR . '/' . $slug;
				$prefix = '';
				$base   = basename( $slug, '.php' );
			} else {
				$source = WP_PLUGIN_DIR . '/' . $dir;
				$prefix = $dir;
				$base   = $dir;
			}
			$version      = $plugins[ $slug ]['Version'];
			$allowed_root = WP_PLUGIN_DIR;
		} else {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return new WP_Error( 'awps_not_found', __( 'Theme not found.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
			}
			$source       = $theme->get_stylesheet_directory();
			$prefix       = $slug;
			$base         = $slug;
			$version      = (string) $theme->get( 'Version' );
			$allowed_root = get_theme_root( $slug );
		}

		$real_source = realpath( $source );
		if ( false === $real_source || ! file_exists( $real_source ) ) {
			return new WP_Error( 'awps_not_found', __( 'The files for this item could not be found on disk.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
		}

		// Block symlink / path tricks that resolve outside the plugins/themes tree.
		if ( ! $this->is_path_inside( $real_source, $allowed_root ) ) {
			return new WP_Error( 'awps_bad_slug', __( 'Invalid plugin or theme identifier.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
		}

		// wp_tempnam() lives in wp-admin/includes/file.php, which is NOT
		// loaded on REST requests — without this require the endpoint fatals.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filename = sanitize_file_name( $base . ( $version ? '-' . $version : '' ) . '.zip' );
		// Defend Content-Disposition against quote / CRLF injection if a slug
		// ever slips past sanitize_file_name with odd characters.
		$filename = str_replace( array( '"', "\r", "\n", '\\' ), '', $filename );
		if ( '' === $filename || ! preg_match( '/\.zip$/i', $filename ) ) {
			$filename = ( 'plugin' === $kind ? 'plugin' : 'theme' ) . '.zip';
		}

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'awps_zip_failed', __( 'Could not create a temporary file for the zip archive.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		// CREATE|OVERWRITE: wp_tempnam pre-creates the file and OVERWRITE
		// alone fails to open an existing file on some PHP builds.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'awps_zip_failed', __( 'Could not create the zip archive.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		if ( is_file( $real_source ) ) {
			$zip->addFile( $real_source, basename( $real_source ) );
		} else {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $real_source, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			$root_len = strlen( trailingslashit( $real_source ) );
			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}
				$absolute = $file_info->getPathname();
				$real_file = realpath( $absolute );
				if ( false === $real_file || ! $this->is_path_inside( $real_file, $real_source ) ) {
					continue;
				}
				$relative = str_replace( '\\', '/', substr( $real_file, $root_len ) );
				// Never allow zip-slip style entry names.
				if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
					continue;
				}
				$zip->addFile( $real_file, ( $prefix ? $prefix . '/' : '' ) . $relative );
			}
		}
		$zip->close();

		$zip_size = filesize( $tmp );
		if ( ! $zip_size ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'awps_zip_failed', __( 'Could not create the zip archive.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) $zip_size );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Whether $path resolves inside $root (after realpath normalisation).
	 *
	 * @param string $path Absolute path already passed through realpath(), or a candidate.
	 * @param string $root Root directory that must contain $path.
	 * @return bool
	 */
	private function is_path_inside( $path, $root ) {
		$real_path = realpath( $path );
		$real_root = realpath( $root );
		if ( false === $real_path || false === $real_root ) {
			return false;
		}

		$real_path = wp_normalize_path( $real_path );
		$real_root = wp_normalize_path( $real_root );

		if ( $real_path === $real_root ) {
			return true;
		}

		$real_root = trailingslashit( $real_root );
		return 0 === strpos( $real_path, $real_root );
	}

	/**
	 * GET /search — content search, permission-filtered inside the index.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_search( WP_REST_Request $request ) {
		$term    = $request->get_param( 'term' );
		$type    = (string) $request->get_param( 'type' );
		$results = array();

		// WooCommerce order lookup: "1042", "#1042" or "order 1042" jumps
		// straight to that order (capability-gated, HPOS-compatible).
		if ( '' === $type ) {
			$order_row = $this->maybe_order_lookup( $term );
			if ( $order_row ) {
				$results[] = $order_row;
			}
		}

		$results = array_merge( $results, $this->index->search( $term, 20, $type ) );

		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * Resolve an order-number-looking search term to a WooCommerce order row.
	 *
	 * @param string $term Search term.
	 * @return array|null Result row or null.
	 */
	private function maybe_order_lookup( $term ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null; // Permission parity with the Orders screen.
		}
		if ( ! preg_match( '/^(?:order\s*)?#?(\d{1,10})$/i', trim( $term ), $matches ) ) {
			return null;
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}

		$customer = trim( $order->get_formatted_billing_full_name() );
		$total    = wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total() ) );

		return array(
			'object_type' => 'order',
			'object_id'   => $order->get_id(),
			'title'       => sprintf(
				/* translators: 1: order number, 2: customer name */
				__( 'Order #%1$s%2$s', 'advanced-wp-spotlight' ),
				$order->get_order_number(),
				$customer ? ' — ' . $customer : ''
			),
			'subtitle'    => wc_get_order_status_name( $order->get_status() ) . ' — ' . $total,
			'url'         => $order->get_edit_order_url(),
			'view_url'    => '',
		);
	}

	/**
	 * POST /execute — run a registered rest-type command.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_execute( WP_REST_Request $request ) {
		$command_id = sanitize_text_field( (string) $request->get_param( 'command' ) );
		$command    = $this->registry->get( $command_id );

		if ( ! $command || 'rest' !== $command['type'] ) {
			return new WP_Error( 'awps_unknown_command', __( 'Unknown command.', 'advanced-wp-spotlight' ), array( 'status' => 404 ) );
		}

		$is_destructive = ! empty( $command['destructive'] );
		// Sanitize client-supplied args, then let registered command args win
		// for any colliding keys (clients cannot re-target a fixed command).
		$client_args = $this->sanitize_execute_args( $request->get_param( 'args' ) );
		$args        = array_merge( $client_args, (array) $command['args'] );
		$audit_id    = 0;

		if ( $is_destructive ) {
			// Rate limit destructive endpoints (accidental / scripted mass-deletion).
			if ( AWPS_Audit_Log::is_rate_limited() ) {
				AWPS_Audit_Log::log( $command_id, $args, 'rate_limited' );
				return new WP_Error(
					'awps_rate_limited',
					__( 'Too many destructive actions in a short time. Please wait a minute and try again.', 'advanced-wp-spotlight' ),
					array( 'status' => 429 )
				);
			}

			// No destructive action executes without an explicit confirmation step.
			if ( true !== $request->get_param( 'confirm' ) ) {
				return new WP_Error(
					'awps_confirmation_required',
					__( 'This command is destructive and requires confirmation.', 'advanced-wp-spotlight' ),
					array( 'status' => 428 )
				);
			}
		}

		// Execution-time capability re-verification — the check that matters.
		if ( ! current_user_can( $command['capability'] ) ) {
			if ( $is_destructive ) {
				AWPS_Audit_Log::log( $command_id, $args, 'forbidden' );
			}
			return new WP_Error( 'awps_forbidden', __( 'You are not allowed to run this command.', 'advanced-wp-spotlight' ), array( 'status' => 403 ) );
		}

		$handler = $this->registry->get_action( $command['action'] );
		if ( ! $handler || ! is_callable( $handler ) ) {
			return new WP_Error( 'awps_no_handler', __( 'No handler registered for this command.', 'advanced-wp-spotlight' ), array( 'status' => 500 ) );
		}

		// Audit trail: written before execution so a fatal mid-run still leaves a record.
		if ( $is_destructive ) {
			$audit_id = AWPS_Audit_Log::log( $command_id, $args, 'pending' );
		}

		$result = call_user_func( $handler, $args, $command );

		if ( is_wp_error( $result ) ) {
			if ( $is_destructive ) {
				AWPS_Audit_Log::resolve( $audit_id, 'failed', $result->get_error_message() );
			}
			return $result;
		}

		if ( $is_destructive ) {
			$message = isset( $result['message'] ) ? $result['message'] : '';
			AWPS_Audit_Log::resolve( $audit_id, 'success', $message );
		}

		AWPS_User_Preferences::record_usage( $command_id );

		return rest_ensure_response(
			wp_parse_args(
				(array) $result,
				array(
					'message'  => __( 'Done.', 'advanced-wp-spotlight' ),
					'redirect' => '',
				)
			)
		);
	}

	/**
	 * POST /prefs — personal preference updates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_prefs( WP_REST_Request $request ) {
		$action = sanitize_text_field( (string) $request->get_param( 'do' ) );

		switch ( $action ) {
			case 'shortcut':
				$saved = AWPS_User_Preferences::set_shortcut( (string) $request->get_param( 'value' ) );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				return rest_ensure_response(
					array(
						'message'  => __( 'Shortcut saved.', 'advanced-wp-spotlight' ),
						'shortcut' => AWPS_User_Preferences::get_shortcut(),
					)
				);

			case 'theme':
				$saved = AWPS_User_Preferences::set_theme( (string) $request->get_param( 'value' ) );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				return rest_ensure_response(
					array(
						'message' => __( 'Palette theme updated.', 'advanced-wp-spotlight' ),
						'theme'   => AWPS_User_Preferences::get_theme(),
					)
				);

			case 'favorite':
				$favorites = AWPS_User_Preferences::toggle_favorite( (string) $request->get_param( 'value' ) );
				return rest_ensure_response( array( 'favorites' => $favorites ) );

			case 'record':
				AWPS_User_Preferences::record_usage( (string) $request->get_param( 'value' ) );
				return rest_ensure_response( array( 'ok' => true ) );

			case 'dismiss_hint':
				AWPS_User_Preferences::dismiss_hint();
				return rest_ensure_response( array( 'ok' => true ) );
		}

		return new WP_Error( 'awps_bad_pref', __( 'Unknown preference action.', 'advanced-wp-spotlight' ), array( 'status' => 400 ) );
	}

	/**
	 * Flatten and sanitize client-supplied /execute args.
	 *
	 * Only scalar values are accepted (no nested arrays/objects) so handlers
	 * never receive unexpected structure from a forged request.
	 *
	 * @param mixed $raw Raw `args` request param.
	 * @return array
	 */
	private function sanitize_execute_args( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || strlen( $key ) > 64 ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				// Keep ids / short tokens; reject absurdly long payloads.
				if ( strlen( $value ) > 500 ) {
					$value = substr( $value, 0, 500 );
				}
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		return $clean;
	}
}
