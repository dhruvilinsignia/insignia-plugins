<?php
/**
 * Plugin Name:       Insignia Backup
 * Plugin URI:        https://insigniatechnolabs.com/insignia-backup
 * Description:       A complete site backup, migration and cloning toolkit — full & granular backups (DB + files), one-click restore, scheduled automatic backups, remote storage, downloadable installer packages, and a polished dashboard. A Duplicator-style toolkit, built better.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Insignia Techno Labs
 * Author URI:        https://insigniatechnolabs.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       insignia-backup
 * Domain Path:       /languages
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit; // Prevent direct access.

/* -------------------------------------------------------------------------
 *  Constants
 * ---------------------------------------------------------------------- */
define( 'IBP_VERSION', '1.0.0' );
define( 'IBP_FILE', __FILE__ );
define( 'IBP_PATH', plugin_dir_path( __FILE__ ) );
define( 'IBP_URL', plugin_dir_url( __FILE__ ) );
define( 'IBP_BASENAME', plugin_basename( __FILE__ ) );

// Slug used for the admin menu and option prefixes.
define( 'IBP_SLUG', 'insignia-backup' );

// Chunk size: number of files to process per cron chunk.
define( 'IBP_CHUNK_SIZE', 300 );

// Maximum age (seconds) for temp archives before auto-cleanup.
define( 'IBP_ARCHIVE_TTL', 24 * HOUR_IN_SECONDS );

/* -------------------------------------------------------------------------
 *  Storage location for backup archives.
 *
 *  By default, archives are written to a unique subfolder of PHP's system
 *  temp directory (e.g. /tmp/ibp-1a2b3c4d5e/). This is INTENTIONAL —
 *  backups must NEVER live inside the WordPress installation (wp-content/
 *  or anywhere under ABSPATH), because:
 *    1. A backup stored under wp-content would be bundled into the NEXT
 *       full backup, causing unbounded growth and infinite recursion.
 *    2. Web-accessible backup archives are a serious security risk.
 *
 *  The location is filterable via the `ibp_backup_dir` filter for hosts
 *  that want to redirect archives to a dedicated mount (e.g. /mnt/backups,
 *  an EBS volume, etc.), BUT we hard-refuse any candidate path that lies
 *  inside wp-content or anywhere under ABSPATH — that safety check is
 *  non-negotiable and applies even if a filter returns such a path.
 *
 *  Auto-cleanup removes archives older than IBP_ARCHIVE_TTL.
 * ---------------------------------------------------------------------- */
if ( ! defined( 'IBP_BACKUP_DIR' ) ) {
        $ibp_hash     = substr( md5( ABSPATH ), 0, 10 );
        $ibp_temp_dir = trailingslashit( sys_get_temp_dir() ) . 'ibp-' . $ibp_hash;

        /**
         * Filter the backup storage directory.
         *
         * Use this to redirect archives to a custom location (e.g. a
         * dedicated backup volume). The returned path MUST be outside
         * wp-content / ABSPATH — if it isn't, the plugin will fall back
         * to the system temp directory and raise a doing_it_wrong notice.
         *
         * @param string $ibp_temp_dir Absolute path (no trailing slash required).
         */
        $ibp_filter_dir = (string) apply_filters( 'ibp_backup_dir', $ibp_temp_dir );

        // Safety: refuse any path inside wp-content or anywhere under ABSPATH.
        $ibp_filter_norm    = wp_normalize_path( untrailingslashit( $ibp_filter_dir ) );
        $ibp_abspath_norm   = wp_normalize_path( untrailingslashit( ABSPATH ) );
        $ibp_wpcontent_norm = wp_normalize_path( untrailingslashit( trailingslashit( ABSPATH ) . 'wp-content' ) );

        $ibp_is_inside_wp = (
                0 === strpos( $ibp_filter_norm . '/', $ibp_abspath_norm . '/' ) ||
                0 === strpos( $ibp_filter_norm . '/', $ibp_wpcontent_norm . '/' )
        );

        if ( $ibp_is_inside_wp && $ibp_filter_norm !== $ibp_abspath_norm ) {
                // Filtered path is inside ABSPATH / wp-content — fall back to temp dir.
                if ( function_exists( '_doing_it_wrong' ) ) {
                        _doing_it_wrong(
                                'ibp_backup_dir filter',
                                sprintf(
                                        /* translators: %s: filtered path. */
                                        __( 'The ibp_backup_dir filter returned a path inside the WordPress installation (%s). Backups cannot be stored in wp-content or anywhere under ABSPATH — falling back to the system temp directory.', 'insignia-backup' ),
                                        esc_html( $ibp_filter_dir )
                                ),
                                '1.0.0'
                        );
                }
                // Keep $ibp_temp_dir (system temp) as-is.
        } else {
                $ibp_temp_dir = $ibp_filter_dir;
        }

        define( 'IBP_BACKUP_DIR', $ibp_temp_dir );
        define( 'IBP_BACKUP_URL', '' ); // No direct URL — served via AJAX endpoint.
}

/* -------------------------------------------------------------------------
 *  Activation / Deactivation / Uninstall
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'ibp_activate' );
register_deactivation_hook( __FILE__, 'ibp_deactivate' );

/**
 * Runs on activation: creates tables, secure backup dir, default settings.
 */
function ibp_activate() {
        require_once IBP_PATH . 'includes/class-ibp-core.php';
        IBP_Core::activate();
}

/**
 * Runs on deactivation: clears scheduled cron events.
 *
 * Both the recurring `ibp_scheduled_backup` hook and the per-chunk
 * `ibp_run_backup_chunk` hook are cleared so deactivation never leaves
 * a pending chunk that would fire after the plugin is asleep.
 */
function ibp_deactivate() {
        require_once IBP_PATH . 'includes/class-ibp-scheduler.php';
        IBP_Scheduler::clear_all_schedules();
        wp_clear_scheduled_hook( 'ibp_run_backup_chunk' );
}

/* -------------------------------------------------------------------------
 *  Bootstrap
 * ---------------------------------------------------------------------- */
require_once IBP_PATH . 'includes/class-ibp-core.php';

add_action( 'plugins_loaded', static function () {
        IBP_Core::get_instance();
} );