<?php
/**
 * AJAX endpoints (all admin-only, nonce + capability protected).
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Ajax {

        /** @var IBP_Backup */
        private $backup;

        /** @var IBP_Restore */
        private $restore;

        const NONCE = 'ibp_admin_nonce';

        /**
         * @param IBP_Backup  $backup  Backup engine.
         * @param IBP_Restore $restore Restore engine.
         */
        public function __construct( IBP_Backup $backup, IBP_Restore $restore ) {
                $this->backup  = $backup;
                $this->restore = $restore;
        }

        /**
         * Register all wp_ajax_ hooks.
         */
        public function register() {
                $actions = [
                        'ibp_create_backup',
                        'ibp_delete_backup',
                        'ibp_restore_backup',
                        'ibp_list_backups',
                        'ibp_save_settings',
                        'ibp_save_schedule',
                        'ibp_get_stats',
                        'ibp_backup_status',
                        'ibp_get_folder_tree',
                        'ibp_estimate_sizes',
                        'ibp_pause_backup',
                        'ibp_resume_backup',
                        'ibp_cancel_backup',
                ];
                foreach ( $actions as $action ) {
                        add_action( "wp_ajax_$action", [ $this, str_replace( 'ibp_', 'handle_', $action ) ] );
                }
        }

        /**
         * Shared guard: capability + nonce.
         */
        private function guard() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'insignia-backup' ) ], 403 );
                }
                check_ajax_referer( self::NONCE, 'nonce' );
        }

        /**
         * Create a backup (chunked mode).
         * Sets status to "pending" (not "queued") and kicks off the first chunk.
         */
        public function handle_create_backup() {
                $this->guard();

                $type    = sanitize_key( $_POST['type'] ?? 'full' );
                $name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
                $folders = IBP_Helpers::sanitize_folder_list( wp_unslash( $_POST['folders'] ?? '[]' ) );

                if ( ! in_array( $type, [ 'full', 'database', 'files', 'custom' ], true ) ) {
                        $type = 'full';
                }
                if ( '' === $name ) {
                        $name = sprintf( '%s Backup %s', ucfirst( $type ), ibp_format_timestamp( 'M j, Y g:i a' ) );
                }
                if ( 'custom' !== $type ) {
                        $folders = [];
                } elseif ( empty( $folders ) ) {
                        wp_send_json_error( [ 'message' => __( 'Choose at least one file or folder for a Custom backup.', 'insignia-backup' ) ] );
                }
                if ( $folders ) {
                        $name .= ' ' . sprintf( __( '(%d items)', 'insignia-backup' ), count( $folders ) );
                }

                try {
                        $result = $this->backup->init_chunked(
                                [
                                        'type'    => $type,
                                        'name'    => $name,
                                        'trigger' => 'manual',
                                        'folders' => $folders,
                                ]
                        );

                        wp_send_json_success(
                                [
                                        'message'   => __( 'Backup started — building now.', 'insignia-backup' ),
                                        'backup_id' => $result['backup_id'],
                                ]
                        );
                } catch ( Exception $e ) {
                        wp_send_json_error( [ 'message' => $e->getMessage() ] );
                }
        }

        /**
         * Pause a running backup.
         */
        public function handle_pause_backup() {
                $this->guard();
                $backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );

                if ( ! $backup_id ) {
                        wp_send_json_error( [ 'message' => __( 'Missing backup ID.', 'insignia-backup' ) ] );
                }

                $ok = $this->backup->pause( $backup_id );
                $ok
                        ? wp_send_json_success( [ 'message' => __( 'Backup paused.', 'insignia-backup' ) ] )
                        : wp_send_json_error( [ 'message' => __( 'Could not pause this backup.', 'insignia-backup' ) ] );
        }

        /**
         * Resume a paused backup.
         */
        public function handle_resume_backup() {
                $this->guard();
                $backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );

                if ( ! $backup_id ) {
                        wp_send_json_error( [ 'message' => __( 'Missing backup ID.', 'insignia-backup' ) ] );
                }

                $ok = $this->backup->resume( $backup_id );
                $ok
                        ? wp_send_json_success( [ 'message' => __( 'Backup resumed.', 'insignia-backup' ) ] )
                        : wp_send_json_error( [ 'message' => __( 'Could not resume this backup.', 'insignia-backup' ) ] );
        }

        /**
         * Cancel a running or paused backup.
         */
        public function handle_cancel_backup() {
                $this->guard();
                $backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );

                if ( ! $backup_id ) {
                        wp_send_json_error( [ 'message' => __( 'Missing backup ID.', 'insignia-backup' ) ] );
                }

                $ok = $this->backup->cancel( $backup_id );
                $ok
                        ? wp_send_json_success( [ 'message' => __( 'Backup cancelled.', 'insignia-backup' ) ] )
                        : wp_send_json_error( [ 'message' => __( 'Could not cancel this backup.', 'insignia-backup' ) ] );
        }

        /**
         * Return one level of the site's folder tree.
         */
        public function handle_get_folder_tree() {
                $this->guard();
                IBP_Helpers::raise_limits(); // Folder sizes can require a deep scan on first load.

                $path     = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
                $settings = IBP_Helpers::get_settings();
                $items    = IBP_Helpers::list_tree( $path, $settings['exclude_patterns'] );

                wp_send_json_success( [ 'items' => $items ] );
        }

        /**
         * Return estimated archive-input sizes for each backup type,
         * so the type cards can display them.
         */
        public function handle_estimate_sizes() {
                $this->guard();

                $force = ! empty( $_POST['force'] );
                $est   = IBP_Helpers::estimate_backup_sizes( $force );

                wp_send_json_success(
                        [
                                'full'       => $est['full'],
                                'database'   => $est['database'],
                                'files'      => $est['files'],
                                'full_h'     => IBP_Helpers::format_size( $est['full'] ),
                                'database_h' => IBP_Helpers::format_size( $est['database'] ),
                                'files_h'    => IBP_Helpers::format_size( $est['files'] ),
                        ]
                );
        }

        /**
         * Poll the status of a backup. Returns status, phase, progress_pct,
         * and the started_at timestamp (Unix seconds, server time) so the
         * front-end can compute a live ETA.
         */
        public function handle_backup_status() {
                $this->guard();
                $backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
                $row       = $backup_id ? IBP_Logger::get( $backup_id ) : null;

                if ( ! $row ) {
                        wp_send_json_error( [ 'message' => __( 'Backup not found.', 'insignia-backup' ) ] );
                }

                // created_at is stored as an IST string; convert to Unix seconds.
                $started_ts = $row['created_at'] ? strtotime( $row['created_at'] ) : 0;

                wp_send_json_success(
                        [
                                'status'        => $row['status'],
                                'phase'         => $row['phase'] ?? '',
                                'progress_pct'  => (int) ( $row['progress_pct'] ?? 0 ),
                                'note'          => $row['note'],
                                'started_at'    => $started_ts,
                                'server_time'   => time(),
                        ]
                );
        }

        /**
         * Delete a backup.
         */
        public function handle_delete_backup() {
                $this->guard();
                $backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
                if ( ! $backup_id ) {
                        wp_send_json_error( [ 'message' => __( 'Missing backup ID.', 'insignia-backup' ) ] );
                }
                $ok = $this->backup->delete( $backup_id );
                $ok
                        ? wp_send_json_success( [ 'message' => __( 'Backup deleted.', 'insignia-backup' ) ] )
                        : wp_send_json_error( [ 'message' => __( 'Backup not found.', 'insignia-backup' ) ] );
        }

        /**
         * Restore a backup in place.
         */
        public function handle_restore_backup() {
                $this->guard();
                $backup_id     = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
                $restore_db    = ! empty( $_POST['restore_db'] );
                $restore_files = ! empty( $_POST['restore_files'] );

                if ( ! $backup_id ) {
                        wp_send_json_error( [ 'message' => __( 'Missing backup ID.', 'insignia-backup' ) ] );
                }

                try {
                        $summary = $this->restore->run(
                                $backup_id,
                                [ 'restore_db' => $restore_db, 'restore_files' => $restore_files ]
                        );
                        wp_send_json_success(
                                [
                                        'message' => __( 'Restore completed.', 'insignia-backup' ),
                                        'summary' => $summary,
                                ]
                        );
                } catch ( Exception $e ) {
                        wp_send_json_error( [ 'message' => $e->getMessage() ] );
                }
        }

        /**
         * Return the current backup list as HTML rows.
         */
        public function handle_list_backups() {
                $this->guard();
                $rows = IBP_Logger::all( 100, 0 );
                wp_send_json_success( [ 'rows' => $this->render_rows( $rows ) ] );
        }

        /**
         * Save plugin settings.
         */
        public function handle_save_settings() {
                $this->guard();

                $incoming = isset( $_POST['settings'] ) ? (array) $_POST['settings'] : [];
                $clean    = [];

                $clean['archive_format']    = in_array( ( $incoming['archive_format'] ?? 'zip' ), [ 'zip', 'gzip' ], true ) ? $incoming['archive_format'] : 'zip';
                $clean['split_size_mb']     = absint( $incoming['split_size_mb'] ?? 0 );
                $clean['max_local_backups'] = absint( $incoming['max_local_backups'] ?? 10 );
                $clean['compression_level'] = max( 0, min( 9, absint( $incoming['compression_level'] ?? 6 ) ) );
                $clean['email_on_complete'] = sanitize_email( $incoming['email_on_complete'] ?? '' );
                $clean['db_charset_fix']    = ! empty( $incoming['db_charset_fix'] );

                $raw_excludes              = sanitize_textarea_field( wp_unslash( $incoming['exclude_patterns'] ?? '' ) );
                $lines                     = array_filter( array_map( 'trim', explode( "\n", $raw_excludes ) ) );
                $clean['exclude_patterns'] = array_values( $lines );

                update_option( 'ibp_settings', $clean );

                if ( $clean['max_local_backups'] > 0 ) {
                        $this->backup->enforce_retention( $clean['max_local_backups'] );
                }

                wp_send_json_success( [ 'message' => __( 'Settings saved.', 'insignia-backup' ) ] );
        }

        /**
         * Save schedule.
         */
        public function handle_save_schedule() {
                $this->guard();
                $frequency = sanitize_key( $_POST['frequency'] ?? 'off' );
                $type      = sanitize_key( $_POST['schedule_type'] ?? 'full' );

                $allowed = [ 'off', 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'custom' ];
                if ( ! in_array( $frequency, $allowed, true ) ) {
                        $frequency = 'off';
                }

                $custom_seconds = 0;
                if ( 'custom' === $frequency ) {
                        $custom_value = max( 1, (int) ( $_POST['custom_value'] ?? 1 ) );
                        $custom_unit  = sanitize_key( $_POST['custom_unit'] ?? 'hours' );
                        $unit_seconds = ( 'days' === $custom_unit ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
                        $custom_seconds = $custom_value * $unit_seconds;
                }

                $scheduler = new IBP_Scheduler();
                $info      = $scheduler->set_schedule( $frequency, $type, $custom_seconds );

                wp_send_json_success(
                        [
                                'message' => __( 'Schedule updated.', 'insignia-backup' ),
                                'label'   => $scheduler->next_run_label(),
                                'info'    => $info,
                        ]
                );
        }

        /**
         * Return dashboard stats JSON.
         */
        public function handle_get_stats() {
                $this->guard();
                wp_send_json_success( $this->collect_stats() );
        }

        /* --------------------------------------------------------------------
         *  Shared rendering / stats
         * ----------------------------------------------------------------- */

        /**
         * Dashboard statistics.
         *
         * @return array
         */
        public function collect_stats() {
                $settings   = IBP_Helpers::get_settings();
                $files_size = IBP_Helpers::directory_size( ABSPATH, $settings['exclude_patterns'] );

                return [
                        'total_backups' => IBP_Logger::count( 'complete' ),
                        'total_size'    => IBP_Helpers::format_size( IBP_Logger::total_size() ),
                        'site_size'     => IBP_Helpers::format_size( $files_size ),
                        'last_backup'   => $this->last_backup_label(),
                        'zip_ok'        => IBP_Helpers::has_zip_archive(),
                ];
        }

        /**
         * Label for the most recent completed backup.
         *
         * @return string
         */
        private function last_backup_label() {
                $rows = IBP_Logger::all( 1, 0 );
                if ( empty( $rows ) ) {
                        return __( 'Never', 'insignia-backup' );
                }
                return human_time_diff( strtotime( $rows[0]['created_at'] ), time() ) . ' ' . __( 'ago', 'insignia-backup' );
        }

        /**
         * Render table rows for the backups list.
         *
         * @param array $rows DB rows.
         * @return string HTML.
         */
        public function render_rows( $rows ) {
                if ( empty( $rows ) ) {
                        return '<tr class="ibp-empty"><td colspan="6">' . esc_html__( 'No backups yet. Create your first backup above.', 'insignia-backup' ) . '</td></tr>';
                }

                $token = IBP_Helpers::token();
                $html  = '';

                foreach ( $rows as $r ) {
                        $status = esc_attr( $r['status'] );
                        $pct    = (int) ( $r['progress_pct'] ?? 0 );

                        // Status badge — never show "queued".
                        $status_label = ucfirst( $status );
                        if ( 'pending' === $status ) {
                                $status_class = 'ibp-badge ibp-badge--pending';
                        } elseif ( 'running' === $status ) {
                                $status_class = 'ibp-badge ibp-badge--running';
                                $status_label = $pct > 0 ? "Running &middot; {$pct}%" : 'Running';
                        } elseif ( 'paused' === $status ) {
                                $status_class = 'ibp-badge ibp-badge--paused';
                        } elseif ( 'failed' === $status ) {
                                $status_class = 'ibp-badge ibp-badge--failed';
                        } elseif ( 'complete' === $status ) {
                                $status_class = 'ibp-badge ibp-badge--complete';
                        } else {
                                $status_class = 'ibp-badge ibp-badge--' . $status;
                        }

                        $dl_url = '';
                        if ( 'complete' === $r['status'] && $r['archive_file'] ) {
                                $dl_url = admin_url( 'admin-ajax.php?action=ibp_download&backup_id=' . rawurlencode( $r['backup_id'] ) . '&nonce=' . wp_create_nonce( 'ibp_download' ) );
                        }

                        $dl_installer_url = '';
                        if ( 'complete' === $r['status'] && ! empty( $r['installer_file'] ) ) {
                                $dl_installer_url = admin_url( 'admin-ajax.php?action=ibp_download&backup_id=' . rawurlencode( $r['backup_id'] ) . '&file=installer&nonce=' . wp_create_nonce( 'ibp_download' ) );
                        }

                        $type_icon = [
                                'full'     => '&#9673;',
                                'database' => '&#9636;',
                                'files'    => '&#9783;',
                                'custom'   => '&#9881;',
                        ][ $r['type'] ] ?? '&#9673;';

                        $html .= '<tr data-id="' . esc_attr( $r['backup_id'] ) . '" data-status="' . $status . '" data-pct="' . $pct . '">';
                        $html .= '<td><div class="ibp-bk-name"><span class="ibp-bk-icon">' . $type_icon . '</span><div><strong>' . esc_html( $r['name'] ) . '</strong><span class="ibp-bk-id">' . esc_html( $r['backup_id'] ) . '</span></div></div></td>';
                        $html .= '<td><span class="ibp-type">' . esc_html( ucfirst( $r['type'] ) ) . '</span></td>';
                        $html .= '<td>' . esc_html( IBP_Helpers::format_size( $r['size_bytes'] ) ) . '</td>';
                        $html .= '<td><span class="' . $status_class . '">' . $status_label . '</span></td>';
                        $html .= '<td>' . esc_html( ibp_format_timestamp( 'M j, Y g:i a', strtotime( $r['created_at'] ) ) ) . '</td>';
                        $html .= '<td class="ibp-actions-cell">';

                        // Pause / Resume / Cancel buttons for running/paused backups.
                        if ( 'running' === $r['status'] ) {
                                $html .= '<button class="ibp-act ibp-act--pause" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Pause', 'insignia-backup' ) . '">&#9208;</button>';
                                $html .= '<button class="ibp-act ibp-act--cancel" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Cancel', 'insignia-backup' ) . '">&#10005;</button>';
                        } elseif ( 'paused' === $r['status'] ) {
                                $html .= '<button class="ibp-act ibp-act--resume" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Resume', 'insignia-backup' ) . '">&#9654;</button>';
                                $html .= '<button class="ibp-act ibp-act--cancel" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Cancel', 'insignia-backup' ) . '">&#10005;</button>';
                        } elseif ( 'pending' === $r['status'] ) {
                                $html .= '<button class="ibp-act ibp-act--cancel" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Cancel', 'insignia-backup' ) . '">&#10005;</button>';
                        }

                        // Download + Restore for complete backups.
                        if ( $dl_url ) {
                                if ( $dl_installer_url ) {
                                        $html .= '<div class="ibp-dl-dropdown">';
                                        $html .=         '<button type="button" class="ibp-act ibp-act--dl-toggle" title="' . esc_attr__( 'Download', 'insignia-backup' ) . '">&#8681;</button>';
                                        $html .=         '<div class="ibp-dl-menu" hidden>';
                                        $html .=                 '<button type="button" class="ibp-dl-menu-item ibp-dl-both" data-zip="' . esc_url( $dl_url ) . '" data-installer="' . esc_url( $dl_installer_url ) . '">' . esc_html__( 'Download Both', 'insignia-backup' ) . '</button>';
                                        $html .=                 '<a class="ibp-dl-menu-item" href="' . esc_url( $dl_url ) . '">' . esc_html__( 'Download ZIP', 'insignia-backup' ) . '</a>';
                                        $html .=                 '<a class="ibp-dl-menu-item" href="' . esc_url( $dl_installer_url ) . '">' . esc_html__( 'Download Installer', 'insignia-backup' ) . '</a>';
                                        $html .=         '</div>';
                                        $html .= '</div>';
                                } else {
                                        $html .= '<a class="ibp-act ibp-act--dl" href="' . esc_url( $dl_url ) . '" title="' . esc_attr__( 'Download', 'insignia-backup' ) . '">&#8681;</a>';
                                }
                                $html .= '<button class="ibp-act ibp-act--restore" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Restore', 'insignia-backup' ) . '">&#8635;</button>';
                        }

                        // Delete for any non-active backup.
                        if ( ! in_array( $r['status'], [ 'running', 'pending' ], true ) ) {
                                $html .= '<button class="ibp-act ibp-act--del" data-id="' . esc_attr( $r['backup_id'] ) . '" title="' . esc_attr__( 'Delete', 'insignia-backup' ) . '">&#10005;</button>';
                        }

                        $html .= '</td></tr>';
                }
                return $html;
        }
}

/* -------------------------------------------------------------------------
 *  Secure download endpoint (streams from temp dir).
 * ---------------------------------------------------------------------- */
add_action( 'wp_ajax_ibp_download', static function () {
        if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Permission denied.', 'insignia-backup' ) );
        }
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'ibp_download' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'insignia-backup' ) );
        }

        $backup_id = sanitize_text_field( wp_unslash( $_GET['backup_id'] ?? '' ) );
        $which     = sanitize_key( $_GET['file'] ?? 'archive' );
        $row       = IBP_Logger::get( $backup_id );
        if ( ! $row || empty( $row['archive_file'] ) ) {
                wp_die( esc_html__( 'Backup not found.', 'insignia-backup' ) );
        }

        if ( 'installer' === $which ) {
                if ( empty( $row['installer_file'] ) ) {
                        wp_die( esc_html__( 'No installer available for this backup.', 'insignia-backup' ) );
                }
                $filename = $row['installer_file'];
        } else {
                $filename = $row['archive_file'];
        }

        // Resolve path inside IBP_BACKUP_DIR (now in system temp).
        $base = trailingslashit( wp_normalize_path( IBP_BACKUP_DIR ) );
        $path = wp_normalize_path( $base . basename( $filename ) );
        if ( strpos( $path, $base ) !== 0 || ! is_file( $path ) || ! is_readable( $path ) ) {
                wp_die( esc_html__( 'File missing on disk.', 'insignia-backup' ) );
        }

        $size = (int) filesize( $path );

        while ( ob_get_level() > 0 ) {
                ob_end_clean();
        }
        if ( function_exists( 'apache_setenv' ) ) {
                @apache_setenv( 'no-gzip', '1' );
        }
        @ini_set( 'zlib.output_compression', 'Off' );
        @ini_set( 'display_errors', '0' );
        if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
        }

        nocache_headers();
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Accept-Ranges: none' );
        header( 'Content-Length: ' . $size );

        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) {
                wp_die( esc_html__( 'Could not open the archive for reading.', 'insignia-backup' ) );
        }
        while ( ! feof( $handle ) ) {
                echo fread( $handle, 1024 * 1024 );
                flush();
        }
        fclose( $handle );
        exit;
} );