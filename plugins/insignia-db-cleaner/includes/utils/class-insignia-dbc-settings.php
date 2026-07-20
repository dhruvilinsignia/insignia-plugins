<?php
/**
 * Thin wrapper around the plugin's single settings option.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Settings {

	public static function defaults() {
		return array(
			'keep_days'           => 0,   // 0 = no age restriction.
			'excluded_post_types' => array(),
			'automation_enabled'  => false,
			'automation_freq'     => 'weekly',
			'automation_items'    => array( 'revisions', 'expired_transients' ),
			'last_automation_run' => '',
		);
	}

	public static function get() {
		$stored = get_option( INSIGNIA_DBC_OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function update( array $partial ) {
		$current = self::get();
		$merged  = array_merge( $current, $partial );
		update_option( INSIGNIA_DBC_OPTION_SETTINGS, $merged );

		// Keep the wp-cron schedule in sync with the automation setting.
		$next = wp_next_scheduled( INSIGNIA_DBC_CRON_HOOK );

		if ( ! empty( $merged['automation_enabled'] ) ) {
			if ( ! $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $merged['automation_freq'], INSIGNIA_DBC_CRON_HOOK );
			}
		} elseif ( $next ) {
			wp_unschedule_event( $next, INSIGNIA_DBC_CRON_HOOK );
		}

		return $merged;
	}
}
