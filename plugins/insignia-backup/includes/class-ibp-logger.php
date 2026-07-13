<?php
/**
 * Backup index + activity log stored in a custom table.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Logger {

        /**
         * Fully-qualified table name.
         *
         * @return string
         */
        public static function table() {
                global $wpdb;
                return $wpdb->prefix . 'ibp_backups';
        }

        /**
         * Create / migrate the backups index table.
         *
         * Uses dbDelta so new columns are added without dropping existing data.
         */
        public static function create_table() {
                global $wpdb;
                $table   = self::table();
                $charset = $wpdb->get_charset_collate();

                $sql = "CREATE TABLE $table (
                        id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        backup_id     varchar(60)  NOT NULL DEFAULT '',
                        name          varchar(191) NOT NULL DEFAULT '',
                        type          varchar(20)  NOT NULL DEFAULT 'full',
                        status        varchar(20)  NOT NULL DEFAULT 'pending',
                        phase         varchar(30)  NOT NULL DEFAULT '',
                        progress_pct  smallint(5)  unsigned NOT NULL DEFAULT 0,
                        archive_file  varchar(255) NOT NULL DEFAULT '',
                        installer_file varchar(255) NOT NULL DEFAULT '',
                        size_bytes    bigint(20) unsigned NOT NULL DEFAULT 0,
                        db_size       bigint(20) unsigned NOT NULL DEFAULT 0,
                        files_count   bigint(20) unsigned NOT NULL DEFAULT 0,
                        trigger_src   varchar(20)  NOT NULL DEFAULT 'manual',
                        storage       varchar(40)  NOT NULL DEFAULT 'local',
                        note          text         NULL,
                        created_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        completed_at  datetime     NULL,
                        PRIMARY KEY (id),
                        KEY backup_id (backup_id),
                        KEY status (status)
                ) $charset;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta( $sql );
        }

        /**
         * Insert a new backup record, return row ID.
         *
         * @param array $data Column => value.
         * @return int
         */
        public static function insert( array $data ) {
                global $wpdb;
                $defaults = [
                        'backup_id'    => '',
                        'name'         => '',
                        'type'         => 'full',
                        'status'       => 'pending',
                        'phase'        => '',
                        'progress_pct' => 0,
                        'trigger_src'  => 'manual',
                        'storage'      => 'local',
                        'created_at'   => ibp_now(),
                ];
                $data = wp_parse_args( $data, $defaults );
                $wpdb->insert( self::table(), $data );
                return (int) $wpdb->insert_id;
        }

        /**
         * Update a backup record by backup_id.
         *
         * @param string $backup_id Backup identifier.
         * @param array  $data      Columns to update.
         */
        public static function update( $backup_id, array $data ) {
                global $wpdb;
                $wpdb->update( self::table(), $data, [ 'backup_id' => $backup_id ] );
        }

        /**
         * Fetch one backup row.
         *
         * @param string $backup_id Identifier.
         * @return array|null
         */
        public static function get( $backup_id ) {
                global $wpdb;
                $row = $wpdb->get_row(
                        $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE backup_id = %s', $backup_id ),
                        ARRAY_A
                );
                return $row ?: null;
        }

        /**
         * List backups (newest first).
         *
         * @param int $limit  Max rows.
         * @param int $offset Offset.
         * @return array
         */
        public static function all( $limit = 50, $offset = 0 ) {
                global $wpdb;
                return $wpdb->get_results(
                        $wpdb->prepare(
                                'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
                                $limit,
                                $offset
                        ),
                        ARRAY_A
                );
        }

        /**
         * Count rows, optionally by status.
         *
         * @param string|null $status Status filter.
         * @return int
         */
        public static function count( $status = null ) {
                global $wpdb;
                if ( $status ) {
                        return (int) $wpdb->get_var(
                                $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', $status )
                        );
                }
                return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
        }

        /**
         * Delete a backup record.
         *
         * @param string $backup_id Identifier.
         */
        public static function delete( $backup_id ) {
                global $wpdb;
                $wpdb->delete( self::table(), [ 'backup_id' => $backup_id ] );
        }

        /**
         * Total size of all completed backups (bytes).
         *
         * @return int
         */
        public static function total_size() {
                global $wpdb;
                return (int) $wpdb->get_var(
                        "SELECT COALESCE(SUM(size_bytes),0) FROM " . self::table() . " WHERE status = 'complete'"
                );
        }
}