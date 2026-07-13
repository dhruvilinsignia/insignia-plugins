<?php
/**
 * Uninstall routine — runs when the plugin is deleted from the Plugins screen.
 *
 * @package InsigniaBackup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
}

// Remove options.
delete_option( 'ibp_settings' );
delete_option( 'ibp_schedule' );
delete_option( 'ibp_db_version' );
delete_option( 'ibp_secret_token' );

// Drop the backups index table.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ibp_backups" );

// Clear any scheduled cron. Both the recurring schedule hook and the
// per-chunk backup hook are cleared so uninstall never leaves orphaned
// cron events that would fire after the plugin is gone (and produce
// "call to undefined function" fatals in the error log).
wp_clear_scheduled_hook( 'ibp_scheduled_backup' );
wp_clear_scheduled_hook( 'ibp_run_backup_chunk' );

/**
 * Optionally remove stored archives. We keep them by default so users don't
 * lose backups by accident; uncomment to wipe the backup directory entirely.
 *
 * NOTE: As of v2.1+, archives live in the system temp directory (e.g.
 * /tmp/ibp-{hash}/), NEVER inside wp-content. The constant IBP_BACKUP_DIR
 * is defined by the plugin at runtime, but it isn't available during
 * uninstall because the plugin's main file never loads — so we recompute
 * the default path here. Custom filtered paths are intentionally NOT
 * cleaned up automatically (we don't know what filter was applied).
 */
/*
$ibp_hash     = substr( md5( ABSPATH ), 0, 10 );
$backup_dir   = trailingslashit( sys_get_temp_dir() ) . 'ibp-' . $ibp_hash;
if ( is_dir( $backup_dir ) ) {
        $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $backup_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $f ) {
                $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
        }
        @rmdir( $backup_dir );
}
*/
