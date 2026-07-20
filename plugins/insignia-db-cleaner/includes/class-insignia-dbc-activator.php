<?php
/**
 * Handles plugin activation and deactivation side-effects.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Activator {

	public static function activate() {
		if ( false === get_option( INSIGNIA_DBC_OPTION_SETTINGS ) ) {
			add_option( INSIGNIA_DBC_OPTION_SETTINGS, Insignia_DBC_Settings::defaults() );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( INSIGNIA_DBC_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, INSIGNIA_DBC_CRON_HOOK );
		}
	}
}
