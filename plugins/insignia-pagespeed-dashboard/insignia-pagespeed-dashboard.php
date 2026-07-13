<?php
/**
 * Plugin Name:       Insignia PageSpeed Dashboard
 * Plugin URI:        https://insigniatechnolabs.com/
 * Description:       A powerful Page Speed analyzer dashboard with Google PageSpeed Insights integration. Works standalone and integrates with WP Tool Box.
 * Version:           1.0.2
 * Author:            Insignia Technolabs
 * Author URI:        https://insigniatechnolabs.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       insignia-pagespeed-dashboard
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Tested up to:      6.5
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'IPSD_VERSION',    '1.0.2' );
define( 'IPSD_PATH',       plugin_dir_path( __FILE__ ) );
define( 'IPSD_URL',        plugin_dir_url( __FILE__ ) );
define( 'IPSD_BASENAME',   plugin_basename( __FILE__ ) );
define( 'IPSD_TEXTDOMAIN', 'insignia-pagespeed-dashboard' );

// Legacy aliases so includes (class-wpsd-*.php) work without any modification.
define( 'WPSD_PATH',       IPSD_PATH );
define( 'WPSD_URL',        IPSD_URL );
define( 'WPSD_VERSION',    IPSD_VERSION );
define( 'WPSD_TEXTDOMAIN', IPSD_TEXTDOMAIN );

// Include core classes.
require_once IPSD_PATH . 'includes/class-wpsd-admin.php';
require_once IPSD_PATH . 'includes/class-wpsd-api.php';
require_once IPSD_PATH . 'includes/class-wpsd-suggestions.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function ipsd_init() {
	new WPSD_Admin();
}
add_action( 'plugins_loaded', 'ipsd_init' );

// AJAX handlers — kept exactly as original.
add_action( 'wp_ajax_wpsd_analyze',      array( 'WPSD_API',   'handle_analyze_ajax' ) );
add_action( 'wp_ajax_wpsd_save_api_key', array( 'WPSD_Admin', 'save_api_key' ) );
add_action( 'wp_ajax_wpsd_clear_cache',  array( 'WPSD_Admin', 'clear_cache' ) );

// ---------------------------------------------------------------------------
// Activation – flush rewrite rules.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, 'ipsd_activate' );
function ipsd_activate() {
	flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// Deactivation – flush rewrite rules.
// ---------------------------------------------------------------------------
register_deactivation_hook( __FILE__, 'ipsd_deactivate' );
function ipsd_deactivate() {
	flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// Plugin action links (Plugins list screen).
// ---------------------------------------------------------------------------
add_filter( 'plugin_action_links_' . IPSD_BASENAME, 'ipsd_plugin_action_links' );
function ipsd_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wp-pagespeed-dashboard' ) ),
		esc_html__( 'Settings', 'insignia-pagespeed-dashboard' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
