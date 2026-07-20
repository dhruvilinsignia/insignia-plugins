<?php
/**
 * Single source of truth for every "thing we can clean". Each entry
 * knows how to report its own count + approximate reclaimable size.
 * The actual deletion logic lives in Insignia_DBC_Clean_Service, keyed
 * off the same item key.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Item_Registry {

	/**
	 * Static catalogue of every supported item: label + description only.
	 * Counting/sizing is done live against the database, see count()/size().
	 */
	public static function get_items() {
		return array(
			'revisions'           => array(
				'group'       => 'content',
				'label'       => __( 'Post Revisions', 'insignia-db-cleaner' ),
				'description' => __( 'Old saved revisions of posts and pages.', 'insignia-db-cleaner' ),
			),
			'auto_drafts'         => array(
				'group'       => 'content',
				'label'       => __( 'Auto Drafts', 'insignia-db-cleaner' ),
				'description' => __( 'Empty drafts WordPress creates automatically while editing.', 'insignia-db-cleaner' ),
			),
			'trashed_posts'       => array(
				'group'       => 'content',
				'label'       => __( 'Trashed Posts', 'insignia-db-cleaner' ),
				'description' => __( 'Posts and pages sitting in the Trash.', 'insignia-db-cleaner' ),
			),
			'pending_comments'    => array(
				'group'       => 'comments',
				'label'       => __( 'Unapproved Comments', 'insignia-db-cleaner' ),
				'description' => __( 'Comments awaiting moderation.', 'insignia-db-cleaner' ),
			),
			'spam_comments'       => array(
				'group'       => 'comments',
				'label'       => __( 'Spam Comments', 'insignia-db-cleaner' ),
				'description' => __( 'Comments flagged as spam.', 'insignia-db-cleaner' ),
			),
			'trashed_comments'    => array(
				'group'       => 'comments',
				'label'       => __( 'Trashed Comments', 'insignia-db-cleaner' ),
				'description' => __( 'Comments sitting in the Trash.', 'insignia-db-cleaner' ),
			),
			'pingbacks'           => array(
				'group'       => 'comments',
				'label'       => __( 'Pingbacks', 'insignia-db-cleaner' ),
				'description' => __( 'Pingback notifications stored as comments.', 'insignia-db-cleaner' ),
			),
			'trackbacks'          => array(
				'group'       => 'comments',
				'label'       => __( 'Trackbacks', 'insignia-db-cleaner' ),
				'description' => __( 'Trackback notifications stored as comments.', 'insignia-db-cleaner' ),
			),
			'expired_transients'  => array(
				'group'       => 'transients',
				'label'       => __( 'Expired Transients', 'insignia-db-cleaner' ),
				'description' => __( 'Cached values whose expiration time has already passed.', 'insignia-db-cleaner' ),
			),
			'oembed_cache'        => array(
				'group'       => 'cache',
				'label'       => __( 'oEmbed Cache', 'insignia-db-cleaner' ),
				'description' => __( 'Cached oEmbed responses stored as post meta.', 'insignia-db-cleaner' ),
			),
			'orphan_postmeta'     => array(
				'group'       => 'meta',
				'label'       => __( 'Orphaned Post Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Post meta rows whose post no longer exists.', 'insignia-db-cleaner' ),
			),
			'orphan_commentmeta'  => array(
				'group'       => 'meta',
				'label'       => __( 'Orphaned Comment Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Comment meta rows whose comment no longer exists.', 'insignia-db-cleaner' ),
			),
			'orphan_usermeta'     => array(
				'group'       => 'meta',
				'label'       => __( 'Orphaned User Meta', 'insignia-db-cleaner' ),
				'description' => __( 'User meta rows whose user no longer exists.', 'insignia-db-cleaner' ),
			),
			'orphan_termmeta'     => array(
				'group'       => 'meta',
				'label'       => __( 'Orphaned Term Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Term meta rows whose term no longer exists.', 'insignia-db-cleaner' ),
			),
			'duplicate_postmeta'  => array(
				'group'       => 'meta',
				'label'       => __( 'Duplicate Post Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Repeated meta_key/meta_value pairs on the same post.', 'insignia-db-cleaner' ),
			),
			'duplicate_commentmeta' => array(
				'group'       => 'meta',
				'label'       => __( 'Duplicate Comment Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Repeated meta_key/meta_value pairs on the same comment.', 'insignia-db-cleaner' ),
			),
			'duplicate_usermeta'  => array(
				'group'       => 'meta',
				'label'       => __( 'Duplicate User Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Repeated meta_key/meta_value pairs on the same user.', 'insignia-db-cleaner' ),
			),
			'duplicate_termmeta'  => array(
				'group'       => 'meta',
				'label'       => __( 'Duplicate Term Meta', 'insignia-db-cleaner' ),
				'description' => __( 'Repeated meta_key/meta_value pairs on the same term.', 'insignia-db-cleaner' ),
			),
		);
	}

	public static function get_groups() {
		return array(
			'content'    => __( 'Posts & Pages', 'insignia-db-cleaner' ),
			'comments'   => __( 'Comments', 'insignia-db-cleaner' ),
			'transients' => __( 'Transients', 'insignia-db-cleaner' ),
			'meta'       => __( 'Metadata', 'insignia-db-cleaner' ),
			'cache'      => __( 'Cache', 'insignia-db-cleaner' ),
		);
	}

	public static function exists( $key ) {
		$items = self::get_items();
		return isset( $items[ $key ] );
	}

	/* ---------------------------------------------------------------
	 * Counting / sizing — one clause set per item key.
	 * ------------------------------------------------------------- */

	public static function count( $key ) {
		list( $count_sql, $size_sql ) = self::build_queries( $key );
		global $wpdb;
		return $count_sql ? (int) $wpdb->get_var( $count_sql ) : 0;
	}

	public static function size( $key ) {
		list( $count_sql, $size_sql ) = self::build_queries( $key );
		global $wpdb;
		return $size_sql ? (int) $wpdb->get_var( $size_sql ) : 0;
	}

	/**
	 * Returns [count_sql, size_sql] for a given item key, applying the
	 * "keep last X days" setting where it makes sense to.
	 */
	public static function build_queries( $key ) {
		global $wpdb;
		$settings  = Insignia_DBC_Settings::get();
		$keep_days = (int) $settings['keep_days'];
		$age_posts = $keep_days > 0
			? $wpdb->prepare( ' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)', $keep_days )
			: '';
		$age_comments = $keep_days > 0
			? $wpdb->prepare( ' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)', $keep_days )
			: '';

		switch ( $key ) {

			case 'revisions':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'{$age_posts}",
					"SELECT COALESCE(SUM(LENGTH(post_content)+LENGTH(post_title)+LENGTH(post_excerpt)),0) FROM {$wpdb->posts} WHERE post_type = 'revision'{$age_posts}",
				);

			case 'auto_drafts':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'{$age_posts}",
					"SELECT COALESCE(SUM(LENGTH(post_content)+LENGTH(post_title)+LENGTH(post_excerpt)),0) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'{$age_posts}",
				);

			case 'trashed_posts':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'{$age_posts}",
					"SELECT COALESCE(SUM(LENGTH(post_content)+LENGTH(post_title)+LENGTH(post_excerpt)),0) FROM {$wpdb->posts} WHERE post_status = 'trash'{$age_posts}",
				);

			case 'pending_comments':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_type IN ('comment',''){$age_comments}",
					"SELECT COALESCE(SUM(LENGTH(comment_content)),0) FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_type IN ('comment',''){$age_comments}",
				);

			case 'spam_comments':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'{$age_comments}",
					"SELECT COALESCE(SUM(LENGTH(comment_content)),0) FROM {$wpdb->comments} WHERE comment_approved = 'spam'{$age_comments}",
				);

			case 'trashed_comments':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'{$age_comments}",
					"SELECT COALESCE(SUM(LENGTH(comment_content)),0) FROM {$wpdb->comments} WHERE comment_approved = 'trash'{$age_comments}",
				);

			case 'pingbacks':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'pingback'{$age_comments}",
					"SELECT COALESCE(SUM(LENGTH(comment_content)),0) FROM {$wpdb->comments} WHERE comment_type = 'pingback'{$age_comments}",
				);

			case 'trackbacks':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'trackback'{$age_comments}",
					"SELECT COALESCE(SUM(LENGTH(comment_content)),0) FROM {$wpdb->comments} WHERE comment_type = 'trackback'{$age_comments}",
				);

			case 'expired_transients':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->options} o WHERE o.option_name LIKE '\\_transient\\_timeout\\_%' AND o.option_value < UNIX_TIMESTAMP()",
					"SELECT COALESCE((SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < UNIX_TIMESTAMP())
					  + (SELECT SUM(LENGTH(o2.option_value)) FROM {$wpdb->options} o2 WHERE o2.option_name LIKE '\\_transient\\_%' AND o2.option_name NOT LIKE '\\_transient\\_timeout\\_%'
					     AND REPLACE(o2.option_name,'_transient_','_transient_timeout_') IN (SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < UNIX_TIMESTAMP())),0)",
				);

			case 'oembed_cache':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_oembed\\_%'",
					"SELECT COALESCE(SUM(LENGTH(meta_value)),0) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_oembed\\_%'",
				);

			case 'orphan_postmeta':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
					"SELECT COALESCE(SUM(LENGTH(pm.meta_value)),0) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
				);

			case 'orphan_commentmeta':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
					"SELECT COALESCE(SUM(LENGTH(cm.meta_value)),0) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
				);

			case 'orphan_usermeta':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL",
					"SELECT COALESCE(SUM(LENGTH(um.meta_value)),0) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL",
				);

			case 'orphan_termmeta':
				return array(
					"SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL",
					"SELECT COALESCE(SUM(LENGTH(tm.meta_value)),0) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL",
				);

			case 'duplicate_postmeta':
				return self::duplicate_queries( $wpdb->postmeta, 'post_id', 'meta_id' );

			case 'duplicate_commentmeta':
				return self::duplicate_queries( $wpdb->commentmeta, 'comment_id', 'meta_id' );

			case 'duplicate_usermeta':
				return self::duplicate_queries( $wpdb->usermeta, 'user_id', 'umeta_id' );

			case 'duplicate_termmeta':
				return self::duplicate_queries( $wpdb->termmeta, 'term_id', 'meta_id' );
		}

		return array( '', '' );
	}

	/**
	 * Builds the shared "keep the lowest primary key per duplicate group,
	 * count/size the rest" queries used by every duplicate_* item.
	 */
	private static function duplicate_queries( $table, $owner_col, $pk_col ) {
		$count_sql = "SELECT COUNT(*) FROM {$table} t
			WHERE t.{$pk_col} > (
				SELECT MIN(t2.{$pk_col}) FROM {$table} t2
				WHERE t2.{$owner_col} = t.{$owner_col}
				  AND t2.meta_key = t.meta_key
				  AND t2.meta_value = t.meta_value
			)";

		$size_sql = "SELECT COALESCE(SUM(LENGTH(t.meta_value)),0) FROM {$table} t
			WHERE t.{$pk_col} > (
				SELECT MIN(t2.{$pk_col}) FROM {$table} t2
				WHERE t2.{$owner_col} = t.{$owner_col}
				  AND t2.meta_key = t.meta_key
				  AND t2.meta_value = t.meta_value
			)";

		return array( $count_sql, $size_sql );
	}
}
