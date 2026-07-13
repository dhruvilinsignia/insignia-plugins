<?php
/**
 * Database export (SQL dump) engine.
 *
 * Streams a full mysqldump-style .sql file using $wpdb only,
 * so it works on hosts without shell/exec access.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Database {

        /** @var int Rows fetched per batch to keep memory low. */
        const BATCH = 500;

        /**
         * Export all (or selected) tables to a .sql file.
         *
         * @param string $output_file Absolute path to write to.
         * @param array  $tables      Optional table list; defaults to all WP-prefixed.
         * @return array { bytes, table_count } on success.
         * @throws Exception On failure.
         */
        public function export( $output_file, $tables = [] ) {
                global $wpdb;

                $handle = @fopen( $output_file, 'w' );
                if ( ! $handle ) {
                        throw new Exception( 'Unable to open SQL output file for writing.' );
                }

                if ( empty( $tables ) ) {
                        $tables = $this->get_site_tables();
                }

                // Header.
                $this->write( $handle, $this->header() );

                foreach ( $tables as $table ) {
                        $this->dump_table( $handle, $table );
                }

                // Footer.
                $this->write( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
                fclose( $handle );

                return [
                        'bytes'       => filesize( $output_file ),
                        'table_count' => count( $tables ),
                ];
        }

        /**
         * All tables that share the current site's table prefix.
         *
         * @return array
         */
        public function get_site_tables() {
                global $wpdb;
                $like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
                $tables = $wpdb->get_col(
                        $wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
                );
                return $tables ?: [];
        }

        /**
         * Export a single table to an already-open file handle (for chunked mode).
         * Call once per table during the database phase of a chunked backup.
         *
         * @param resource $handle  Open file handle.
         * @param string   $table   Table name.
         * @param bool     $is_first Whether this is the first table (skip extra header).
         * @return int Bytes written (approximate).
         * @throws Exception
         */
        public function export_table_chunk( $handle, $table, $is_first = false ) {
                global $wpdb;

                $this->write( $handle, "\n--\n-- Table structure for `$table`\n--\n\n" );
                $this->write( $handle, "DROP TABLE IF EXISTS `$table`;\n" );

                $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
                if ( isset( $create[1] ) ) {
                        $this->write( $handle, $create[1] . ";\n\n" );
                }

                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
                if ( 0 === $count ) {
                        return 0;
                }

                $this->write( $handle, "--\n-- Data for `$table`\n--\n\n" );

                $columns = $wpdb->get_col( "DESC `$table`", 0 );
                $col_sql = '`' . implode( '`, `', $columns ) . '`';

                $offset = 0;
                $bytes  = 0;
                while ( $offset < $count ) {
                        $rows = $wpdb->get_results(
                                $wpdb->prepare( "SELECT * FROM `$table` LIMIT %d OFFSET %d", self::BATCH, $offset ),
                                ARRAY_A
                        );

                        if ( empty( $rows ) ) {
                                break;
                        }

                        foreach ( $rows as $row ) {
                                $values = [];
                                foreach ( $row as $value ) {
                                        if ( null === $value ) {
                                                $values[] = 'NULL';
                                        } else {
                                                $values[] = "'" . esc_sql( $value ) . "'";
                                        }
                                }
                                $sql_line = "INSERT INTO `$table` ($col_sql) VALUES (" . implode( ', ', $values ) . ");\n";
                                $bytes   += strlen( $sql_line );
                                $this->write( $handle, $sql_line );
                        }

                        $offset += self::BATCH;
                }

                $this->write( $handle, "\n" );
                return $bytes;
        }

        /**
         * Write the SQL file header/preamble.
         *
         * @param resource $handle Open file handle.
         */
        public function write_header( $handle ) {
                $this->write( $handle, $this->header() );
        }

        /**
         * Write the SQL file footer.
         *
         * @param resource $handle Open file handle.
         */
        public function write_footer( $handle ) {
                $this->write( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
        }

        /**
         * Dump structure + data for a single table (legacy full-export mode).
         *
         * @param resource $handle File handle.
         * @param string   $table  Table name.
         */
        private function dump_table( $handle, $table ) {
                global $wpdb;

                // --- Structure ---
                $this->write( $handle, "\n--\n-- Table structure for `$table`\n--\n\n" );
                $this->write( $handle, "DROP TABLE IF EXISTS `$table`;\n" );

                $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
                if ( isset( $create[1] ) ) {
                        $this->write( $handle, $create[1] . ";\n\n" );
                }

                // --- Data ---
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
                if ( 0 === $count ) {
                        return;
                }

                $this->write( $handle, "--\n-- Data for `$table`\n--\n\n" );

                $columns = $wpdb->get_col( "DESC `$table`", 0 );
                $col_sql = '`' . implode( '`, `', $columns ) . '`';

                $offset = 0;
                while ( $offset < $count ) {
                        // Safe: BATCH and offset are integers we control.
                        $rows = $wpdb->get_results(
                                $wpdb->prepare( "SELECT * FROM `$table` LIMIT %d OFFSET %d", self::BATCH, $offset ),
                                ARRAY_A
                        );

                        if ( empty( $rows ) ) {
                                break;
                        }

                        foreach ( $rows as $row ) {
                                $values = [];
                                foreach ( $row as $value ) {
                                        if ( null === $value ) {
                                                $values[] = 'NULL';
                                        } else {
                                                // Escape every value via wpdb for injection-safety.
                                                $values[] = "'" . esc_sql( $value ) . "'";
                                        }
                                }
                                $this->write(
                                        $handle,
                                        "INSERT INTO `$table` ($col_sql) VALUES (" . implode( ', ', $values ) . ");\n"
                                );
                        }

                        $offset += self::BATCH;
                }

                $this->write( $handle, "\n" );
        }

        /**
         * SQL file preamble.
         *
         * @return string
         */
        private function header() {
                global $wpdb;
                $now = ibp_now();
                return "-- Insignia Backup SQL Dump\n"
                        . "-- Generated: $now\n"
                        . '-- Host: ' . DB_HOST . "\n"
                        . '-- Database: ' . DB_NAME . "\n"
                        . '-- Table Prefix: ' . $wpdb->prefix . "\n"
                        . "-- Site URL: " . home_url() . "\n"
                        . "-- ------------------------------------------------------\n\n"
                        . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
                        . "SET FOREIGN_KEY_CHECKS=0;\n"
                        . "SET NAMES utf8mb4;\n\n";
        }

        /**
         * Write with basic failure guard.
         *
         * @param resource $handle File handle.
         * @param string   $data   Content.
         */
        private function write( $handle, $data ) {
                if ( false === fwrite( $handle, $data ) ) {
                        throw new Exception( 'Failed writing to SQL dump file.' );
                }
        }
}