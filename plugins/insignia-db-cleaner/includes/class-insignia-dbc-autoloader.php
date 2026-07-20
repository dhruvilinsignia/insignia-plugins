<?php
/**
 * Lightweight autoloader for the Insignia_DBC_* classes.
 *
 * Converts a class name such as Insignia_DBC_Scan_Service into
 * includes/services/class-insignia-dbc-scan-service.php and requires it
 * on first use, so we never need a big manual require list.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Autoloader {

	/**
	 * Map of "directory hint" => folder, used to locate a class file
	 * without having to scan the whole tree on every request.
	 *
	 * @var array
	 */
	private static $dirs = array(
		''         => 'includes/',
		'admin'    => 'includes/admin/',
		'service'  => 'includes/services/',
		'services' => 'includes/services/',
		'util'     => 'includes/utils/',
		'utils'    => 'includes/utils/',
	);

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'Insignia_DBC_' ) ) {
			return;
		}

		$relative = strtolower( str_replace( 'Insignia_DBC_', '', $class ) );
		$relative = str_replace( '_', '-', $relative );
		$filename = 'class-insignia-dbc-' . $relative . '.php';

		foreach ( self::$dirs as $hint => $dir ) {
			$path = INSIGNIA_DBC_PATH . $dir . $filename;
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
