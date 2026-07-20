<?php
/**
 * Reads table-level information (size, engine, overhead) via
 * `SHOW TABLE STATUS`, so the Optimize tab lists every table that
 * belongs to this WordPress installation's table prefix.
 *
 * Deliberately NOT using information_schema.TABLES here: a lot of
 * shared-hosting DB users (Hostinger among them) don't have SELECT
 * privileges on information_schema, and that query just silently
 * returns zero rows instead of erroring — which looked like "nothing
 * shows up". SHOW TABLE STATUS only needs privileges on the table's
 * own database, which every WP DB user always has.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_DB_Helper {

	/**
	 * @return array[] List of ['name'=>, 'engine'=>, 'rows'=>, 'data_size'=>, 'overhead'=>, 'optimizable'=>bool]
	 */
	public static function get_tables() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );

		// Extremely locked-down hosts occasionally disallow even SHOW
		// TABLE STATUS. Fall back to SHOW TABLES so the list still
		// renders (just without size/engine/overhead figures) rather
		// than silently showing nothing.
		if ( empty( $rows ) ) {
			$fallback_names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			$rows           = array();
			foreach ( $fallback_names as $name ) {
				$rows[] = array(
					'Name'       => $name,
					'Engine'     => '',
					'Rows'       => 0,
					'Data_length' => 0,
					'Index_length' => 0,
					'Data_free'  => 0,
				);
			}
		}

		$tables = array();

		foreach ( (array) $rows as $row ) {
			$tables[] = array(
				'name'        => $row['Name'],
				'engine'      => ! empty( $row['Engine'] ) ? $row['Engine'] : '—',
				'rows'        => (int) $row['Rows'],
				'data_size'   => (int) $row['Data_length'] + (int) $row['Index_length'],
				'overhead'    => (int) $row['Data_free'],
				// MyISAM/Aria report free space as reclaimable overhead; InnoDB
				// with a shared tablespace usually reports 0, so we still let
				// the user run OPTIMIZE TABLE manually regardless.
				'optimizable' => true,
			);
		}

		usort( $tables, function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );

		return $tables;
	}

	public static function optimize_table( $table_name ) {
		global $wpdb;

		$safe = self::sanitize_table_name( $table_name );
		if ( ! $safe ) {
			return new WP_Error( 'invalid_table', __( 'Invalid table name.', 'insignia-db-cleaner' ) );
		}

		$result = $wpdb->query( "OPTIMIZE TABLE `{$safe}`" ); // phpcs:ignore -- table name whitelisted below.

		if ( false === $result ) {
			return new WP_Error( 'optimize_failed', __( 'Could not optimize this table.', 'insignia-db-cleaner' ) );
		}

		return true;
	}

	/**
	 * Only allow tables that actually belong to this site's prefix, to
	 * avoid ever touching an unrelated table via a crafted request.
	 */
	private static function sanitize_table_name( $table_name ) {
		global $wpdb;

		$table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );

		$known = wp_list_pluck( self::get_tables(), 'name' );
		if ( in_array( $table_name, $known, true ) ) {
			return $table_name;
		}

		return '';
	}
}
