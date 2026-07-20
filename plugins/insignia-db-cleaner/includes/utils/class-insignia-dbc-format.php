<?php
/**
 * Small presentation helpers shared by the admin views.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Format {

	/**
	 * Turns a raw byte count into a human readable string (1.2 MB, etc).
	 */
	public static function bytes( $bytes ) {
		$bytes = max( 0, (float) $bytes );
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$i = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}

		return round( $bytes, $i === 0 ? 0 : 2 ) . ' ' . $units[ $i ];
	}

	public static function number( $number ) {
		return number_format_i18n( (int) $number );
	}
}
