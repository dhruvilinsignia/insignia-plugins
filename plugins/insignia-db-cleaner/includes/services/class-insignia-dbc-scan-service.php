<?php
/**
 * Builds the "what's in the database right now" report consumed by the
 * Dashboard and Cleanup tabs.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Scan_Service {

	/**
	 * @return array{items: array[], totals: array{count:int,size:int}}
	 */
	public function scan_all() {
		$report = array(
			'items'  => array(),
			'totals' => array(
				'count' => 0,
				'size'  => 0,
			),
		);

		foreach ( Insignia_DBC_Item_Registry::get_items() as $key => $meta ) {
			$row = $this->scan_item( $key, $meta );
			$report['items'][]       = $row;
			$report['totals']['count'] += $row['count'];
			$report['totals']['size']  += $row['size'];
		}

		return $report;
	}

	public function scan_item( $key, $meta = null ) {
		if ( null === $meta ) {
			$items = Insignia_DBC_Item_Registry::get_items();
			$meta  = isset( $items[ $key ] ) ? $items[ $key ] : array();
		}

		$count = Insignia_DBC_Item_Registry::count( $key );
		$size  = Insignia_DBC_Item_Registry::size( $key );

		return array(
			'key'         => $key,
			'label'       => isset( $meta['label'] ) ? $meta['label'] : $key,
			'description' => isset( $meta['description'] ) ? $meta['description'] : '',
			'group'       => isset( $meta['group'] ) ? $meta['group'] : 'other',
			'count'       => $count,
			'size'        => $size,
			'size_human'  => Insignia_DBC_Format::bytes( $size ),
		);
	}

	public function database_overview() {
		$tables     = Insignia_DBC_DB_Helper::get_tables();
		$total_size = 0;
		$overhead   = 0;

		foreach ( $tables as $table ) {
			$total_size += $table['data_size'];
			$overhead   += $table['overhead'];
		}

		return array(
			'table_count'      => count( $tables ),
			'total_size'       => $total_size,
			'total_size_human' => Insignia_DBC_Format::bytes( $total_size ),
			'overhead'         => $overhead,
			'overhead_human'   => Insignia_DBC_Format::bytes( $overhead ),
		);
	}
}
