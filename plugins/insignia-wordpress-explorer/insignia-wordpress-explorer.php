<?php
/**
 * Plugin Name:       Insignia WordPress Explorer
 * Plugin URI:        https://example.com/insignia-wordpress-explorer
 * Description:       Explore, download and edit any plugin, theme, or WordPress root file — VS Code-style editor with global search, file manager, ZIP export, and bulk operations, wrapped in a polished admin interface.
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Garvin
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wptd
 * Domain Path:       /languages
 *
 * @package WPTD
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────────
define( 'WPTD_VERSION',   '1.0.4' );
define( 'WPTD_FILE',      __FILE__ );
define( 'WPTD_DIR',       plugin_dir_path( __FILE__ ) );
define( 'WPTD_URL',       plugin_dir_url( __FILE__ ) );
define( 'WPTD_BASENAME',  plugin_basename( __FILE__ ) );

// ── Autoloader ─────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ) {
    if ( strpos( $class, 'WPTD\\' ) !== 0 ) {
        return;
    }

    $relative = str_replace( 'WPTD\\', '', $class );
    $parts    = explode( '\\', $relative );
    $filename = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
    $path     = WPTD_DIR . 'includes/' . implode( '/', $parts ) . '/' . $filename;

    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require_once WPTD_DIR . 'includes/class-plugin.php';

/**
 * Returns the single plugin instance (lazy singleton).
 *
 * @return WPTD\Plugin
 */
function wptd(): WPTD\Plugin {
    return WPTD\Plugin::instance();
}

// Fire it up.
wptd();
