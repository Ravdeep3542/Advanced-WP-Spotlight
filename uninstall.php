<?php
/**
 * Uninstall cleanup: remove custom tables, options and user meta.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Custom tables.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}awps_search_index" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}awps_audit_log" );

// Options.
delete_option( 'awps_settings' );
delete_option( 'awps_index_queue' );
delete_option( 'awps_index_meta' );
delete_option( 'awps_db_version' );

// Per-user preferences.
delete_metadata( 'user', 0, 'awps_shortcut', '', true );
delete_metadata( 'user', 0, 'awps_favorites', '', true );
delete_metadata( 'user', 0, 'awps_recents', '', true );
delete_metadata( 'user', 0, 'awps_hint_seen', '', true );
delete_metadata( 'user', 0, 'awps_theme', '', true );

// Scheduled events.
wp_clear_scheduled_hook( 'awps_full_index_sync' );
wp_clear_scheduled_hook( 'awps_process_index_batch' );

// Activation notice transient (if still set).
delete_transient( 'awps_activation_notice' );

// Note: any wp-config.awps-backup.php created by debug toggles is left in place
// on purpose (it may be the only rollback copy). Delete it manually if unwanted.
