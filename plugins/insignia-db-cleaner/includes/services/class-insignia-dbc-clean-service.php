<?php
/**
 * Performs the real deletions. Everything runs in small batches so a
 * single AJAX/cron tick can never run long enough to hit a PHP or
 * server timeout on a large, never-cleaned database.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Clean_Service {

	const DEFAULT_BATCH = 200;

	/**
	 * Deletes up to $limit rows/objects for the given item key.
	 *
	 * @return array{deleted:int, remaining:int}|WP_Error
	 */
	public function clean_batch( $key, $limit = self::DEFAULT_BATCH ) {
		if ( ! Insignia_DBC_Item_Registry::exists( $key ) ) {
			return new WP_Error( 'unknown_item', __( 'Unknown cleanup item.', 'insignia-db-cleaner' ) );
		}

		$limit   = max( 1, min( 1000, (int) $limit ) );
		$deleted = 0;

		switch ( $key ) {
			case 'revisions':
			case 'auto_drafts':
			case 'trashed_posts':
				$deleted = $this->clean_posts( $key, $limit );
				break;

			case 'pending_comments':
			case 'spam_comments':
			case 'trashed_comments':
			case 'pingbacks':
			case 'trackbacks':
				$deleted = $this->clean_comments( $key, $limit );
				break;

			case 'expired_transients':
				$deleted = $this->clean_expired_transients( $limit );
				break;

			case 'oembed_cache':
				$deleted = $this->clean_by_ids( 'postmeta', 'meta_id', $this->ids_oembed_cache( $limit ) );
				break;

			case 'orphan_postmeta':
				$deleted = $this->clean_by_ids( 'postmeta', 'meta_id', $this->ids_orphan( 'postmeta', 'post_id', 'meta_id', 'posts', 'ID', $limit ) );
				break;

			case 'orphan_commentmeta':
				$deleted = $this->clean_by_ids( 'commentmeta', 'meta_id', $this->ids_orphan( 'commentmeta', 'comment_id', 'meta_id', 'comments', 'comment_ID', $limit ) );
				break;

			case 'orphan_usermeta':
				$deleted = $this->clean_by_ids( 'usermeta', 'umeta_id', $this->ids_orphan( 'usermeta', 'user_id', 'umeta_id', 'users', 'ID', $limit ) );
				break;

			case 'orphan_termmeta':
				$deleted = $this->clean_by_ids( 'termmeta', 'meta_id', $this->ids_orphan( 'termmeta', 'term_id', 'meta_id', 'terms', 'term_id', $limit ) );
				break;

			case 'duplicate_postmeta':
				$deleted = $this->clean_by_ids( 'postmeta', 'meta_id', $this->ids_duplicate( 'postmeta', 'post_id', 'meta_id', $limit ) );
				break;

			case 'duplicate_commentmeta':
				$deleted = $this->clean_by_ids( 'commentmeta', 'meta_id', $this->ids_duplicate( 'commentmeta', 'comment_id', 'meta_id', $limit ) );
				break;

			case 'duplicate_usermeta':
				$deleted = $this->clean_by_ids( 'usermeta', 'umeta_id', $this->ids_duplicate( 'usermeta', 'user_id', 'umeta_id', $limit ) );
				break;

			case 'duplicate_termmeta':
				$deleted = $this->clean_by_ids( 'termmeta', 'meta_id', $this->ids_duplicate( 'termmeta', 'term_id', 'meta_id', $limit ) );
				break;
		}

		return array(
			'deleted'   => (int) $deleted,
			'remaining' => Insignia_DBC_Item_Registry::count( $key ),
		);
	}

	/* ---------------------------------------------------------------
	 * Posts (revisions / auto-drafts / trash) — go through wp_delete_post()
	 * so related postmeta, term relationships and caches are cleaned up
	 * the "WordPress" way instead of a raw DELETE.
	 * ------------------------------------------------------------- */

	private function clean_posts( $key, $limit ) {
		global $wpdb;

		$status_map = array(
			'revisions'     => "post_type = 'revision'",
			'auto_drafts'   => "post_status = 'auto-draft'",
			'trashed_posts' => "post_status = 'trash'",
		);

		$settings  = Insignia_DBC_Settings::get();
		$keep_days = (int) $settings['keep_days'];
		$age       = $keep_days > 0
			? $wpdb->prepare( ' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)', $keep_days )
			: '';

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$status_map[ $key ]}{$age} LIMIT {$limit}" );

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/* ---------------------------------------------------------------
	 * Comments — go through wp_delete_comment() for the same reason.
	 * ------------------------------------------------------------- */

	private function clean_comments( $key, $limit ) {
		global $wpdb;

		$where_map = array(
			'pending_comments' => "comment_approved = '0' AND comment_type IN ('comment','')",
			'spam_comments'    => "comment_approved = 'spam'",
			'trashed_comments' => "comment_approved = 'trash'",
			'pingbacks'        => "comment_type = 'pingback'",
			'trackbacks'       => "comment_type = 'trackback'",
		);

		$settings  = Insignia_DBC_Settings::get();
		$keep_days = (int) $settings['keep_days'];
		$age       = $keep_days > 0
			? $wpdb->prepare( ' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)', $keep_days )
			: '';

		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE {$where_map[ $key ]}{$age} LIMIT {$limit}" );

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/* ---------------------------------------------------------------
	 * Transients — delete the value + its matching _timeout_ option.
	 * ------------------------------------------------------------- */

	private function clean_expired_transients( $limit ) {
		global $wpdb;

		$timeout_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < UNIX_TIMESTAMP()
				 LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$limit
			)
		);

		$deleted = 0;
		foreach ( $timeout_names as $timeout_name ) {
			$transient_name = str_replace( '_transient_timeout_', '', $timeout_name );
			// delete_transient() also fires the right actions/removes any
			// object-cache mirror, which a raw DELETE would miss.
			if ( delete_transient( $transient_name ) ) {
				$deleted++;
			} else {
				// Transient API can no-op if it thinks the value is already
				// gone; fall back to removing the leftover option directly.
				$wpdb->delete( $wpdb->options, array( 'option_name' => $timeout_name ) );
				$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $transient_name ) );
				$deleted++;
			}
		}

		return $deleted;
	}

	/* ---------------------------------------------------------------
	 * Generic ID collectors + raw batch delete for the meta tables,
	 * where there is no higher-level WordPress API to defer to.
	 * ------------------------------------------------------------- */

	private function ids_oembed_cache( $limit ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT %d",
				$wpdb->esc_like( '_oembed_' ) . '%',
				$limit
			)
		);
	}

	private function ids_orphan( $table_key, $owner_col, $pk_col, $parent_table_key, $parent_pk, $limit ) {
		global $wpdb;
		$table  = $wpdb->{$table_key};
		$parent = $wpdb->{$parent_table_key};

		$sql = "SELECT t.{$pk_col} FROM {$table} t
			LEFT JOIN {$parent} p ON p.{$parent_pk} = t.{$owner_col}
			WHERE p.{$parent_pk} IS NULL
			LIMIT %d";

		return $wpdb->get_col( $wpdb->prepare( $sql, $limit ) );
	}

	private function ids_duplicate( $table_key, $owner_col, $pk_col, $limit ) {
		global $wpdb;
		$table = $wpdb->{$table_key};

		$sql = "SELECT t.{$pk_col} FROM {$table} t
			WHERE t.{$pk_col} > (
				SELECT MIN(t2.{$pk_col}) FROM {$table} t2
				WHERE t2.{$owner_col} = t.{$owner_col}
				  AND t2.meta_key = t.meta_key
				  AND t2.meta_value = t.meta_value
			)
			LIMIT %d";

		return $wpdb->get_col( $wpdb->prepare( $sql, $limit ) );
	}

	private function clean_by_ids( $table_key, $pk_col, array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = $wpdb->{$table_key};
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "DELETE FROM {$table} WHERE {$pk_col} IN ($placeholders)";
		$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore -- placeholders built above.

		return count( $ids );
	}
}
