<?php
/**
 * Plugin Name:       Advanced WP Spotlight — Universal Command Palette
 * Description:       A keyboard-first, VS Code / Raycast-style command palette for wp-admin. Search and execute admin tasks — navigation, safe actions, content search — from a single fuzzy-search overlay, with full permission parity.
 * Version:           1.9.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Advanced WP Spotlight
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AWPS_VERSION', '1.9.0' );
define( 'AWPS_PLUGIN_FILE', __FILE__ );
define( 'AWPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AWPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AWPS_PLUGIN_DIR . 'includes/class-awps-command-registry.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-commands.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-search-index.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-audit-log.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-user-preferences.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-rest-controller.php';
require_once AWPS_PLUGIN_DIR . 'includes/class-awps-plugin.php';
require_once AWPS_PLUGIN_DIR . 'admin/class-awps-admin.php';

register_activation_hook( __FILE__, array( 'AWPS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AWPS_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AWPS_Plugin', 'instance' ) );
