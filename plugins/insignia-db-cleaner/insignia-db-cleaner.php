<?php
/**
 * Plugin Name:       Insignia DB Cleaner
 * Plugin URI:        https://example.com/insignia-db-cleaner
 * Description:       Scan and clean database clutter (revisions, drafts, trash, spam, expired transients, orphaned & duplicate metadata, oEmbed cache) and optimize your WordPress tables.
 * Version:           1.1.1
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Insignia
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       insignia-db-cleaner
 * Domain Path:       /languages
 *
 * @package Insignia_DB_Cleaner
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -----------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------
 */
define( 'INSIGNIA_DBC_VERSION', '1.1.1' );
define( 'INSIGNIA_DBC_FILE', __FILE__ );
define( 'INSIGNIA_DBC_PATH', plugin_dir_path( __FILE__ ) );
define( 'INSIGNIA_DBC_URL', plugin_dir_url( __FILE__ ) );
define( 'INSIGNIA_DBC_BASENAME', plugin_basename( __FILE__ ) );
define( 'INSIGNIA_DBC_SLUG', 'insignia-db-cleaner' );
define( 'INSIGNIA_DBC_OPTION_SETTINGS', 'insignia_dbc_settings' );
define( 'INSIGNIA_DBC_CRON_HOOK', 'insignia_dbc_scheduled_cleanup' );
define( 'INSIGNIA_DBC_CAPABILITY', 'manage_options' );

/*
 * -----------------------------------------------------------------------
 * Class loader
 * -----------------------------------------------------------------------
 */
require_once INSIGNIA_DBC_PATH . 'includes/class-insignia-dbc-autoloader.php';
Insignia_DBC_Autoloader::register();

/*
 * -----------------------------------------------------------------------
 * Activation / Deactivation
 * -----------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( 'Insignia_DBC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Insignia_DBC_Activator', 'deactivate' ) );

/*
 * -----------------------------------------------------------------------
 * Boot the plugin once WordPress core is fully loaded.
 * -----------------------------------------------------------------------
 */
function insignia_dbc_boot() {
	Insignia_DBC_Core::instance()->run();
}
add_action( 'plugins_loaded', 'insignia_dbc_boot' );
