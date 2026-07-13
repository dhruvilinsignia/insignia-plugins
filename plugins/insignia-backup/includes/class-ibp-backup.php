<?php
/**
 * Backup orchestrator — chunked engine with pause/resume/cancel.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Backup {

        /**
         * Pause a running backup.
         *
         * @param string $backup_id Backup identifier.
         * @return bool
         */
        public function pause( $backup_id ) {
                $state = IBP_Helpers::get_chunk_state( $backup_id );
                if ( ! $state ) {
                        return false;
                }
                if ( ! in_array( $state['status'], [ 'running', 'pending' ], true ) ) {
                        return false;
                }

                $state['status'] = 'paused';
                IBP_Helpers::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'status' => 'paused' ] );
                return true;
        }

        /**
         * Resume a paused backup.
         *
         * @param string $backup_id Backup identifier.
         * @return bool
         */
        public function resume( $backup_id ) {
                $state = IBP_Helpers::get_chunk_state( $backup_id );
                if ( ! $state ) {
                        return false;
                }
                if ( 'paused' !== $state['status'] ) {
                        return false;
                }

                $state['status'] = 'running';
                IBP_Helpers::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'status' => 'running' ] );
                IBP_Helpers::schedule_next_chunk( $backup_id );
                return true;
        }

        /**
         * Cancel a running or paused backup.
         *
         * @param string $backup_id Backup identifier.
         * @return bool
         */
        public function cancel( $backup_id ) {
                $state = IBP_Helpers::get_chunk_state( $backup_id );
                if ( ! $state ) {
                        return false;
                }
                if ( ! in_array( $state['status'], [ 'running', 'paused', 'pending' ], true ) ) {
                        return false;
                }

                $state['status'] = 'cancelled';
                IBP_Helpers::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'status' => 'failed', 'note' => 'Cancelled by user.' ] );

                // Clean up partial files immediately.
                if ( ! empty( $state['archive_path'] ) ) {
                        @unlink( $state['archive_path'] );
                }
                if ( ! empty( $state['sql_tmp'] ) ) {
                        @unlink( $state['sql_tmp'] );
                }
                if ( ! empty( $state['file_list_path'] ) ) {
                        @unlink( $state['file_list_path'] );
                }
                if ( ! empty( $state['extra_tmps'] ) ) {
                        foreach ( $state['extra_tmps'] as $tmp ) {
                                @unlink( $tmp );
                        }
                }
                if ( ! empty( $state['installer_path'] ) ) {
                        @unlink( $state['installer_path'] );
                }

                IBP_Helpers::delete_chunk_state( $backup_id );
                return true;
        }

        /**
         * Initialize a new chunked backup: create DB row, build initial state,
         * create the archive file, and kick off the first chunk.
         *
         * @param array $args {
         *     @type string $type      full | database | files | custom.
         *     @type string $name      Friendly label.
         *     @type string $trigger   manual | schedule | api.
         *     @type array  $folders   Optional relative folder paths.
         * }
         * @return array { backup_id, name, type }
         * @throws Exception
         */
        public function init_chunked( array $args = [] ) {
                IBP_Helpers::raise_limits();
                IBP_Helpers::prepare_backup_directory();

                $settings = IBP_Helpers::get_settings();

                $type    = $args['type'] ?? 'full';
                $trigger = $args['trigger'] ?? 'manual';
                $name    = $args['name'] ?? sprintf( 'Backup %s', ibp_format_timestamp( 'M j, Y g:i a' ) );
                $folders = IBP_Helpers::sanitize_folder_list( $args['folders'] ?? [] );

                $backup_id = IBP_Helpers::new_backup_id();
                $token     = IBP_Helpers::token();
                $base      = trailingslashit( IBP_BACKUP_DIR );

                $archive_name = "ibp-{$backup_id}-{$token}.zip";
                $archive_path = $base . $archive_name;
                $sql_tmp      = $base . "db-{$backup_id}.sql";

                // Create the logger row.
                IBP_Logger::insert(
                        [
                                'backup_id'    => $backup_id,
                                'name'         => $name,
                                'type'         => $type,
                                'status'       => 'pending',
                                'phase'        => 'pending',
                                'progress_pct' => 0,
                                'trigger_src'  => $trigger,
                                'storage'      => 'local',
                        ]
                );

                // Build the initial chunk state.
                $state = [
                        'backup_id'        => $backup_id,
                        'type'             => $type,
                        'name'             => $name,
                        'trigger'          => $trigger,
                        'folders'          => $folders,
                        'status'           => 'running',
                        'phase'            => 'scanning',
                        'archive_path'     => $archive_path,
                        'archive_name'     => $archive_name,
                        'sql_tmp'          => $sql_tmp,
                        'files_total'      => 0,
                        'files_processed'  => 0,
                        'files_bytes'      => 0,
                        'file_list_path'   => '',
                        'exclude_patterns' => $settings['exclude_patterns'],
                        'compression_level' => $settings['compression_level'],
                        'db_size'          => 0,
                        'db_table_idx'     => 0,
                        'db_tables'        => [],
                        'sql_file_open'    => false,
                        'extra_tmps'       => [],
                        'progress_pct'     => 0,
                ];

                IBP_Helpers::set_chunk_state( $backup_id, $state );

                // Kick off the first chunk.
                IBP_Helpers::trigger_chunked_backup( $backup_id, $type, $name, $folders );

                return [
                        'backup_id' => $backup_id,
                        'name'      => $name,
                        'type'      => $type,
                ];
        }

        /**
         * Keep only the newest N completed backups; delete older archives.
         *
         * @param int $max Retention limit (0 = unlimited).
         */
        public function enforce_retention( $max ) {
                $max = (int) $max;
                if ( $max <= 0 ) {
                        return;
                }
                $all       = IBP_Logger::all( 1000, 0 );
                $completed = array_filter( $all, static fn( $r ) => 'complete' === $r['status'] );
                $completed = array_values( $completed );

                if ( count( $completed ) <= $max ) {
                        return;
                }

                $to_delete = array_slice( $completed, $max );
                foreach ( $to_delete as $row ) {
                        $this->delete( $row['backup_id'] );
                }
        }

        /**
         * Delete a backup: archive file + index row.
         *
         * @param string $backup_id Identifier.
         * @return bool
         */
        public function delete( $backup_id ) {
                $row = IBP_Logger::get( $backup_id );
                if ( ! $row ) {
                        return false;
                }
                $file = trailingslashit( IBP_BACKUP_DIR ) . $row['archive_file'];
                if ( $row['archive_file'] && file_exists( $file ) ) {
                        @unlink( $file );
                }
                if ( ! empty( $row['installer_file'] ) ) {
                        $installer_file = trailingslashit( IBP_BACKUP_DIR ) . $row['installer_file'];
                        if ( file_exists( $installer_file ) ) {
                                @unlink( $installer_file );
                        }
                }
                IBP_Logger::delete( $backup_id );
                return true;
        }

        /**
         * Optionally email an admin when a backup completes.
         *
         * @param array  $settings Settings.
         * @param string $name     Backup name.
         * @param int    $bytes    Size.
         */
        public function maybe_notify( $settings, $name, $bytes ) {
                $to = trim( $settings['email_on_complete'] ?? '' );
                if ( ! $to || ! is_email( $to ) ) {
                        return;
                }
                $subject = sprintf( '[%s] Backup complete: %s', get_bloginfo( 'name' ), $name );
                $body    = sprintf(
                        "A new backup was created.\n\nName: %s\nSize: %s\nTime: %s\nSite: %s",
                        $name,
                        IBP_Helpers::format_size( $bytes ),
                        ibp_now(),
                        home_url()
                );
                wp_mail( $to, $subject, $body );
        }

        /**
         * Absolute path to a backup archive, or empty string.
         *
         * @param string $backup_id Identifier.
         * @return string
         */
        public function archive_path( $backup_id ) {
                $row = IBP_Logger::get( $backup_id );
                if ( ! $row || ! $row['archive_file'] ) {
                        return '';
                }
                return trailingslashit( IBP_BACKUP_DIR ) . $row['archive_file'];
        }
}