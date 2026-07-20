<?php
/**
 * Fires only when the plugin is deleted from the Plugins screen (not on
 * simple deactivation), so we use it to remove our own data.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'insignia_dbc_settings' );

$timestamp = wp_next_scheduled( 'insignia_dbc_scheduled_cleanup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'insignia_dbc_scheduled_cleanup' );
}
