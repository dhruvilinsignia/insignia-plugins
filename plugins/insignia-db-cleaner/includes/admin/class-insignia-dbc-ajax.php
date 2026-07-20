<?php
/**
 * Registers and handles every wp_ajax_insignia_dbc_* endpoint used by
 * assets/js/insignia-admin.js.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Ajax {

	const NONCE_ACTION = 'insignia_dbc_nonce';

	public function hooks() {
		add_action( 'wp_ajax_insignia_dbc_scan', array( $this, 'scan' ) );
		add_action( 'wp_ajax_insignia_dbc_clean_batch', array( $this, 'clean_batch' ) );
		add_action( 'wp_ajax_insignia_dbc_optimize_table', array( $this, 'optimize_table' ) );
		add_action( 'wp_ajax_insignia_dbc_optimize_overview', array( $this, 'optimize_overview' ) );
		add_action( 'wp_ajax_insignia_dbc_save_settings', array( $this, 'save_settings' ) );
	}

	private function verify_request() {
		if ( ! current_user_can( INSIGNIA_DBC_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'insignia-db-cleaner' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public function scan() {
		$this->verify_request();

		$service = new Insignia_DBC_Scan_Service();
		wp_send_json_success(
			array(
				'report'   => $service->scan_all(),
				'database' => $service->database_overview(),
			)
		);
	}

	public function clean_batch() {
		$this->verify_request();

		$key   = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : Insignia_DBC_Clean_Service::DEFAULT_BATCH;

		if ( ! Insignia_DBC_Item_Registry::exists( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown item.', 'insignia-db-cleaner' ) ) );
		}

		$service = new Insignia_DBC_Clean_Service();
		$result  = $service->clean_batch( $key, $limit );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['size_human'] = Insignia_DBC_Format::bytes( Insignia_DBC_Item_Registry::size( $key ) );
		wp_send_json_success( $result );
	}

	public function optimize_overview() {
		$this->verify_request();
		wp_send_json_success( array( 'tables' => Insignia_DBC_DB_Helper::get_tables() ) );
	}

	public function optimize_table() {
		$this->verify_request();

		$table  = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
		$result = Insignia_DBC_DB_Helper::optimize_table( $table );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'table' => $table ) );
	}

	public function save_settings() {
		$this->verify_request();

		$raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		$clean = array(
			'keep_days'           => isset( $raw['keep_days'] ) ? max( 0, absint( $raw['keep_days'] ) ) : 0,
			'excluded_post_types' => isset( $raw['excluded_post_types'] ) && is_array( $raw['excluded_post_types'] )
				? array_map( 'sanitize_key', $raw['excluded_post_types'] )
				: array(),
			'automation_enabled'  => ! empty( $raw['automation_enabled'] ),
			'automation_freq'     => isset( $raw['automation_freq'] ) && in_array( $raw['automation_freq'], array( 'daily', 'weekly' ), true )
				? $raw['automation_freq']
				: 'weekly',
			'automation_items'    => isset( $raw['automation_items'] ) && is_array( $raw['automation_items'] )
				? array_values( array_filter( array_map( 'sanitize_key', $raw['automation_items'] ), array( 'Insignia_DBC_Item_Registry', 'exists' ) ) )
				: array(),
		);

		$saved = Insignia_DBC_Settings::update( $clean );
		wp_send_json_success( array( 'settings' => $saved ) );
	}
}
