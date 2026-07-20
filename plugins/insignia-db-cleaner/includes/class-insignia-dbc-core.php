<?php
/**
 * Core orchestrator. Boots the admin UI, AJAX handlers and the
 * scheduled-cleanup cron hook.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Core {

	/** @var Insignia_DBC_Core|null */
	private static $instance = null;

	/** @var Insignia_DBC_Admin_Menu */
	public $admin_menu;

	/** @var Insignia_DBC_Ajax */
	public $ajax;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function run() {
		load_plugin_textdomain( 'insignia-db-cleaner', false, dirname( INSIGNIA_DBC_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			$this->admin_menu = new Insignia_DBC_Admin_Menu();
			$this->admin_menu->hooks();

			$this->ajax = new Insignia_DBC_Ajax();
			$this->ajax->hooks();
		}

		add_action( INSIGNIA_DBC_CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
	}

	/**
	 * Adds a weekly schedule (WP core only ships hourly/twicedaily/daily).
	 */
	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'insignia-db-cleaner' ),
			);
		}
		return $schedules;
	}

	/**
	 * Callback fired by wp-cron when automated cleanup is enabled in Settings.
	 */
	public function run_scheduled_cleanup() {
		$settings = Insignia_DBC_Settings::get();

		if ( empty( $settings['automation_enabled'] ) || empty( $settings['automation_items'] ) ) {
			return;
		}

		$cleaner = new Insignia_DBC_Clean_Service();

		foreach ( (array) $settings['automation_items'] as $item_key ) {
			// Run in batches until nothing is left, capped so a single cron
			// tick can never run away on a huge, never-cleaned database.
			$safety = 0;
			do {
				$result = $cleaner->clean_batch( $item_key, 500 );
				$safety++;
			} while ( ! is_wp_error( $result ) && $result['remaining'] > 0 && $safety < 40 );
		}

		Insignia_DBC_Settings::update( array( 'last_automation_run' => current_time( 'mysql' ) ) );
	}
}
