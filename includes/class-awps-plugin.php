<?php
/**
 * Main plugin bootstrap.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWPS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AWPS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var AWPS_Command_Registry
	 */
	public $registry;

	/**
	 * @var AWPS_Search_Index
	 */
	public $index;

	/**
	 * @var AWPS_Admin
	 */
	public $admin;

	/**
	 * @var AWPS_REST_Controller
	 */
	public $rest;

	/**
	 * Get (or build) the singleton.
	 *
	 * @return AWPS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->registry = new AWPS_Command_Registry();
		$this->index    = new AWPS_Search_Index();
		$this->rest     = new AWPS_REST_Controller( $this->registry, $this->index );
		$this->admin    = new AWPS_Admin( $this->registry, $this->index );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );

		// Built-in commands are registered lazily when the registry is first read,
		// so that get_current_screen() and third-party plugin state are available.
		AWPS_Commands::hook( $this->registry );

		$this->index->hook();
		$this->rest->hook();

		if ( is_admin() ) {
			$this->admin->hook();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'advanced-wp-spotlight', false, dirname( plugin_basename( AWPS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: create tables and schedule the background sync.
	 */
	public static function activate() {
		AWPS_Search_Index::create_table();
		AWPS_Audit_Log::create_table();
		AWPS_Search_Index::schedule_events();
		AWPS_Search_Index::enqueue_full_rebuild();
		update_option( 'awps_db_version', AWPS_VERSION, false );
		set_transient( 'awps_activation_notice', 1, 60 );
	}

	/**
	 * Upgrade path for installs updated by file copy (activation hook does
	 * not re-fire): create any new tables and re-check schedules whenever
	 * the stored DB version lags behind the code version.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'awps_db_version' ) === AWPS_VERSION ) {
			return;
		}
		AWPS_Search_Index::create_table();
		AWPS_Audit_Log::create_table();
		AWPS_Search_Index::schedule_events();
		update_option( 'awps_db_version', AWPS_VERSION, false );
	}

	/**
	 * Deactivation: clear scheduled events (table is removed on uninstall only).
	 */
	public static function deactivate() {
		AWPS_Search_Index::unschedule_events();
	}
}
