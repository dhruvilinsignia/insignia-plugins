<?php
/**
 * Static helper utilities.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 *  Indian Standard Time (IST) helpers.
 *  All timestamps in the plugin are stored and displayed in IST (UTC+5:30)
 *  regardless of the WordPress timezone setting.
 * ---------------------------------------------------------------------- */

/**
 * Return the cached DateTimeZone for Asia/Kolkata.
 *
 * @return DateTimeZone
 */
function ibp_ist_timezone() {
        static $tz = null;
        if ( null === $tz ) {
                $tz = new DateTimeZone( 'Asia/Kolkata' );
        }
        return $tz;
}

/**
 * Current date/time in IST, optionally formatted.
 *
 * @param string $format PHP date format string. Default 'Y-m-d H:i:s'.
 * @return string
 */
function ibp_now( $format = 'Y-m-d H:i:s' ) {
        $dt = new DateTime( 'now', ibp_ist_timezone() );
        return $dt->format( $format );
}

/**
 * Format a Unix timestamp in IST.
 *
 * @param string $format    PHP date format string.
 * @param int    $timestamp Unix timestamp. Default null = now.
 * @return string
 */
function ibp_format_timestamp( $format, $timestamp = null ) {
        $dt = new DateTime( '@' . ( null !== $timestamp ? (int) $timestamp : time() ) );
        $dt->setTimezone( ibp_ist_timezone() );
        return $dt->format( $format );
}

class IBP_Helpers {

        /**
         * Create the backup directory in the system temp folder and secure it.
         */
        public static function prepare_backup_directory() {
                $dir = IBP_BACKUP_DIR;

                if ( ! file_exists( $dir ) ) {
                        wp_mkdir_p( $dir );
                }

                // Deny direct browser access (Apache) — just in case the temp dir
                // is somehow web-accessible.
                $htaccess = trailingslashit( $dir ) . '.htaccess';
                if ( ! file_exists( $htaccess ) ) {
                        $rules = "Order Deny,Allow\nDeny from all\n";
                        @file_put_contents( $htaccess, $rules );
                }

                // Silence directory listing.
                $index = trailingslashit( $dir ) . 'index.php';
                if ( ! file_exists( $index ) ) {
                        @file_put_contents( $index, "<?php // Silence is golden.\n" );
                }

                // A random subfolder token is appended to filenames so URLs are
                // unguessable even if the host ignores .htaccess.
                if ( false === get_option( 'ibp_secret_token' ) ) {
                        update_option( 'ibp_secret_token', wp_generate_password( 16, false ) );
                }
        }

        /**
         * Secret token used to obfuscate archive filenames.
         *
         * @return string
         */
        public static function token() {
                $token = get_option( 'ibp_secret_token' );
                if ( ! $token ) {
                        $token = wp_generate_password( 16, false );
                        update_option( 'ibp_secret_token', $token );
                }
                return $token;
        }

        /**
         * Human-readable file size.
         *
         * @param int $bytes Size in bytes.
         * @return string
         */
        public static function format_size( $bytes ) {
                $bytes = (float) $bytes;
                $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
                $i     = 0;
                while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
                        $bytes /= 1024;
                        $i++;
                }
                return round( $bytes, 2 ) . ' ' . $units[ $i ];
        }

        /**
         * Recursively measure directory size (bytes), respecting excludes.
         *
         * @param string $path     Directory.
         * @param array  $excludes Relative path fragments to skip.
         * @return int
         */
        public static function directory_size( $path, $excludes = [] ) {
                $size = 0;
                if ( ! is_dir( $path ) ) {
                        return 0;
                }

                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                                RecursiveIteratorIterator::LEAVES_ONLY,
                                RecursiveIteratorIterator::CATCH_GET_CHILD // Skip unreadable folders instead of failing.
                        );

                        foreach ( $iterator as $file ) {
                                $relative = str_replace( trailingslashit( wp_normalize_path( ABSPATH ) ), '', wp_normalize_path( $file->getPathname() ) );
                                if ( self::is_excluded( $relative, $excludes ) ) {
                                        continue;
                                }
                                $size += (int) $file->getSize();
                        }
                } catch ( Exception $e ) {
                        // Unreadable root — report what we counted so far.
                }
                return $size;
        }

        /**
         * Approximate on-disk size of every table sharing this site's prefix.
         * Uses SHOW TABLE STATUS (data + index length) — instant, no table scans.
         *
         * @return int Bytes.
         */
        public static function database_size() {
                global $wpdb;

                $like = $wpdb->esc_like( $wpdb->prefix ) . '%';
                $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );

                $size = 0;
                foreach ( (array) $rows as $row ) {
                        $size += (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 );
                }
                return $size;
        }

        /**
         * Estimate the size of each backup type, cached in a transient so the
         * dashboard loads instantly on repeat visits.
         *
         * @param bool $force Skip the cache and re-scan.
         * @return array { database:int, files:int, full:int } bytes.
         */
        public static function estimate_backup_sizes( $force = false ) {
                $cache_key = 'ibp_size_estimates';

                if ( ! $force ) {
                        $cached = get_transient( $cache_key );
                        if ( is_array( $cached ) && isset( $cached['full'] ) ) {
                                return $cached;
                        }
                }

                self::raise_limits();
                $settings = self::get_settings();

                $database = self::database_size();
                $files    = self::directory_size(
                        untrailingslashit( wp_normalize_path( ABSPATH ) ),
                        $settings['exclude_patterns']
                );

                $estimates = [
                        'database' => $database,
                        'files'    => $files,
                        'full'     => $database + $files,
                ];

                set_transient( $cache_key, $estimates, 15 * MINUTE_IN_SECONDS );
                return $estimates;
        }

        /**
         * Size (bytes) of a single site-relative file or folder.
         * Folder sizes are cached per-path for 15 minutes.
         *
         * @param string $relative Site-relative path.
         * @param array  $excludes Exclude patterns.
         * @return int Bytes.
         */
        public static function path_size( $relative, $excludes = [] ) {
                $relative = trim( str_replace( '\\', '/', (string) $relative ), '/' );
                if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
                        return 0;
                }

                $base = trailingslashit( wp_normalize_path( ABSPATH ) );
                $abs  = wp_normalize_path( $base . $relative );
                if ( 0 !== strpos( $abs, $base ) ) {
                        return 0;
                }

                if ( is_file( $abs ) ) {
                        return (int) @filesize( $abs );
                }
                if ( ! is_dir( $abs ) ) {
                        return 0;
                }

                $cache_key = 'ibp_psize_' . md5( $relative );
                $cached    = get_transient( $cache_key );
                if ( false !== $cached ) {
                        return (int) $cached;
                }

                $size = self::directory_size( $abs, $excludes );
                set_transient( $cache_key, $size, 15 * MINUTE_IN_SECONDS );
                return $size;
        }

        /**
         * Determine whether a relative path matches any exclude pattern.
         * Supports plain substrings and simple "*" wildcards.
         *
         * @param string $relative Relative path.
         * @param array  $patterns Patterns.
         * @return bool
         */
        public static function is_excluded( $relative, $patterns ) {
                $relative = str_replace( '\\', '/', $relative );
                foreach ( $patterns as $pattern ) {
                        $pattern = trim( str_replace( '\\', '/', $pattern ) );
                        if ( '' === $pattern ) {
                                continue;
                        }
                        if ( false !== strpos( $pattern, '*' ) ) {
                                $regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
                                if ( preg_match( $regex, $relative ) ) {
                                        return true;
                                }
                        } elseif ( false !== stripos( $relative, $pattern ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * Get current plugin settings merged with defaults.
         *
         * @return array
         */
        public static function get_settings() {
                $defaults = IBP_Core::default_settings();
                $saved    = get_option( 'ibp_settings', [] );
                $settings = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );

                // Always exclude wp-content/backup from scans — whether it's a
                // leftover folder from another backup plugin or manual dumps,
                // it should never be bundled into an archive or counted toward
                // a backup's reported size. Enforced here (not just in the
                // defaults) so it applies to sites that saved settings before
                // this pattern existed too.
                $settings['exclude_patterns'] = (array) $settings['exclude_patterns'];
                if ( ! in_array( 'wp-content/backup', $settings['exclude_patterns'], true ) ) {
                        $settings['exclude_patterns'][] = 'wp-content/backup';
                }

                return $settings;
        }

        /**
         * Estimate whether the environment can ZipArchive natively.
         *
         * @return bool
         */
        public static function has_zip_archive() {
                return class_exists( 'ZipArchive' );
        }

        /**
         * Best-effort raise of PHP limits for long backup runs.
         */
        public static function raise_limits() {
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 0 );
                }
                @ini_set( 'memory_limit', '512M' );
                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                        wp_raise_memory_limit( 'admin' );
                }
        }

        /**
         * Generate a unique backup ID (timestamp + random token).
         *
         * @return string
         */
        public static function new_backup_id() {
                return ibp_now( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
        }

        /* --------------------------------------------------------------------
         *  Chunked backup state management
         * ----------------------------------------------------------------- */

        /**
         * Save the chunked backup state for a given backup ID.
         *
         * @param string $backup_id Backup identifier.
         * @param array  $state     State array.
         */
        public static function set_chunk_state( $backup_id, array $state ) {
                update_option( 'ibp_chunk_state_' . $backup_id, $state, false );
        }

        /**
         * Retrieve the chunked backup state.
         *
         * @param string $backup_id Backup identifier.
         * @return array|null State array or null if not found.
         */
        public static function get_chunk_state( $backup_id ) {
                $state = get_option( 'ibp_chunk_state_' . $backup_id, null );
                return is_array( $state ) ? $state : null;
        }

        /**
         * Delete the chunked backup state.
         *
         * @param string $backup_id Backup identifier.
         */
        public static function delete_chunk_state( $backup_id ) {
                delete_option( 'ibp_chunk_state_' . $backup_id );
        }

        /**
         * Scan all files under ABSPATH into a temp file (one relative path per line).
         * Respects exclude patterns and optional include_paths.
         *
         * @param string $archive_dir    Directory to write the file list to.
         * @param string $backup_id      Backup identifier (used for the temp file name).
         * @param array  $exclude_patterns Patterns to skip.
         * @param array  $include_paths  Optional relative paths to limit to.
         * @return string Path to the file list.
         * @throws Exception On failure.
         */
        public static function scan_files_to_list( $archive_dir, $backup_id, $exclude_patterns = [], $include_paths = [] ) {
                $list_path = trailingslashit( $archive_dir ) . "filelist-{$backup_id}.txt";
                $handle    = @fopen( $list_path, 'w' );
                if ( ! $handle ) {
                        throw new Exception( 'Could not create file list for scanning.' );
                }

                $source_dir = trailingslashit( wp_normalize_path( ABSPATH ) );
                $count      = 0;

                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
                                RecursiveIteratorIterator::SELF_FIRST
                        );

                        foreach ( $iterator as $item ) {
                                $path     = wp_normalize_path( $item->getPathname() );
                                $relative = ltrim( str_replace( $source_dir, '', $path ), '/\\' );
                                $relative = str_replace( '\\', '/', $relative );

                                if ( '' === $relative ) {
                                        continue;
                                }
                                if ( self::is_excluded( $relative, $exclude_patterns ) ) {
                                        continue;
                                }
                                if ( ! self::path_allowed( $relative, $include_paths ) ) {
                                        continue;
                                }

                                // Only files (not directories) go into the archive.
                                if ( ! $item->isFile() ) {
                                        continue;
                                }

                                fwrite( $handle, $relative . "\n" );
                                $count++;
                        }
                } catch ( Exception $e ) {
                        fclose( $handle );
                        @unlink( $list_path );
                        throw $e;
                }

                fclose( $handle );
                return [ 'path' => $list_path, 'total_files' => $count ];
        }

        /**
         * Whether a relative path falls inside one of the selected folders.
         *
         * @param string $relative      Relative path.
         * @param array  $include_paths Selected relative paths.
         * @return bool
         */
        public static function path_allowed( $relative, $include_paths ) {
                if ( empty( $include_paths ) ) {
                        return true;
                }
                foreach ( $include_paths as $inc ) {
                        $inc = trim( $inc, '/' );
                        if ( '' === $inc ) {
                                continue;
                        }
                        if ( $relative === $inc || 0 === strpos( $relative, $inc . '/' ) ) {
                                return true;
                        }
                }
                return false;
        }

        /* --------------------------------------------------------------------
         *  Chunked backup execution
         * ----------------------------------------------------------------- */

        /**
         * Kick off the first chunk of a backup via WP-Cron.
         *
         * @param string $backup_id Pre-created backup log ID.
         * @param string $type      full | database | files | custom.
         * @param string $name      Friendly label.
         * @param array  $folders   Optional relative folder paths.
         */
        public static function trigger_chunked_backup( $backup_id, $type, $name, $folders = [] ) {
                /*
                 * Defensive: clear any stale single-event for this same backup
                 * before scheduling the first chunk. WP-Cron's wp_schedule_single_event()
                 * silently no-ops if an event with the same hook+args is already
                 * scheduled within the next 10 minutes — clearing first guarantees
                 * the new event is actually registered, even if a previous run left
                 * a pending event behind (e.g. cron was disabled, page was closed
                 * mid-backup, or a PHP fatal interrupted the previous schedule).
                 */
                wp_clear_scheduled_hook( 'ibp_run_backup_chunk', [ $backup_id ] );

                wp_schedule_single_event(
                        time() + 2,
                        'ibp_run_backup_chunk',
                        [ $backup_id ]
                );

                if ( function_exists( 'spawn_cron' ) ) {
                        spawn_cron();
                }
        }

        /**
         * Schedule the next chunk to run immediately.
         *
         * @param string $backup_id Backup identifier.
         */
        public static function schedule_next_chunk( $backup_id ) {
                /*
                 * Defensive: clear any pending single-event for this same backup
                 * before scheduling the next chunk. Without this, WP-Cron's
                 * wp_schedule_single_event() may silently no-op if the previous
                 * chunk's event hasn't been cleaned up yet (e.g. we're being
                 * called from inside the very cron callback that WP hasn't
                 * finished reaping from its cron array). Clearing first
                 * guarantees the new "next chunk" event is always registered.
                 */
                wp_clear_scheduled_hook( 'ibp_run_backup_chunk', [ $backup_id ] );

                wp_schedule_single_event(
                        time() + 1,
                        'ibp_run_backup_chunk',
                        [ $backup_id ]
                );

                if ( function_exists( 'spawn_cron' ) ) {
                        spawn_cron();
                }
        }

        /**
         * Cron callback: process one chunk of a backup.
         * This is the heart of the chunked engine.
         *
         * @param string $backup_id Backup identifier.
         */
        public static function run_backup_chunk( $backup_id ) {
                if ( function_exists( 'ignore_user_abort' ) ) {
                        ignore_user_abort( true );
                }
                IBP_Helpers::raise_limits();

                $state = self::get_chunk_state( $backup_id );
                if ( ! $state ) {
                        return;
                }

                // Check if cancelled before doing anything.
                if ( 'cancelled' === $state['status'] ) {
                        self::cleanup_cancelled( $backup_id, $state );
                        return;
                }

                // Check if paused — don't schedule next chunk.
                if ( 'paused' === $state['status'] ) {
                        IBP_Logger::update( $backup_id, [ 'status' => 'paused' ] );
                        return;
                }

                try {
                        switch ( $state['phase'] ) {
                                case 'scanning':
                                        self::chunk_phase_scanning( $backup_id, $state );
                                        break;
                                case 'files':
                                        self::chunk_phase_files( $backup_id, $state );
                                        break;
                                case 'database':
                                        self::chunk_phase_database( $backup_id, $state );
                                        break;
                                case 'extras':
                                        self::chunk_phase_extras( $backup_id, $state );
                                        break;
                                case 'finalizing':
                                        self::chunk_phase_finalizing( $backup_id, $state );
                                        break;
                                // 'done' or any other — nothing to do.
                        }
                } catch ( Exception $e ) {
                        IBP_Logger::update(
                                $backup_id,
                                [
                                        'status' => 'failed',
                                        'note'   => $e->getMessage(),
                                ]
                        );
                        self::delete_chunk_state( $backup_id );
                        // Clean partial files.
                        if ( ! empty( $state['archive_path'] ) ) {
                                @unlink( $state['archive_path'] );
                        }
                        if ( ! empty( $state['sql_tmp'] ) ) {
                                @unlink( $state['sql_tmp'] );
                        }
                }
        }

        /**
         * Phase: Scan all files and build the file list.
         */
        private static function chunk_phase_scanning( $backup_id, &$state ) {
                IBP_Logger::update( $backup_id, [ 'status' => 'running', 'phase' => 'scanning', 'progress_pct' => 2 ] );

                $include_paths = $state['folders'] ?? [];

                if ( 'database' === $state['type'] ) {
                        // No files to scan.
                        $state['phase']         = 'database';
                        $state['files_total']   = 0;
                        $state['file_list_path'] = '';
                        $state['progress_pct']   = 5;
                        self::set_chunk_state( $backup_id, $state );
                        self::schedule_next_chunk( $backup_id );
                        return;
                }

                $scan = self::scan_files_to_list(
                        trailingslashit( IBP_BACKUP_DIR ),
                        $backup_id,
                        $state['exclude_patterns'],
                        $include_paths
                );

                $state['file_list_path'] = is_array( $scan ) ? $scan['path'] : $scan;
                $state['files_total']    = is_array( $scan ) ? $scan['total_files'] : 0;
                $state['files_processed'] = 0;
                $state['phase']          = 'files';
                $state['progress_pct']   = 5;

                self::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'phase' => 'files', 'progress_pct' => 5 ] );
                self::schedule_next_chunk( $backup_id );
        }

        /**
         * Phase: Archive files in chunks.
         */
        private static function chunk_phase_files( $backup_id, &$state ) {
                // Check pause/cancel.
                if ( ! self::check_state( $backup_id, $state ) ) {
                        return;
                }

                $archiver = new IBP_Archiver( $state['exclude_patterns'], $state['compression_level'] );

                $result = $archiver->process_chunk(
                        $state['archive_path'],
                        $state['file_list_path'],
                        $state['files_processed'],
                        IBP_CHUNK_SIZE,
                        $state['compression_level']
                );

                $state['files_processed'] += $result['files_processed'];
                $state['files_bytes']      = $result['total_bytes'] ?? 0;

                // Calculate progress: files phase is 5-75%.
                if ( $state['files_total'] > 0 ) {
                        $file_pct        = ( $state['files_processed'] / $state['files_total'] ) * 70;
                        $state['progress_pct'] = (int) min( 75, 5 + $file_pct );
                }

                IBP_Logger::update( $backup_id, [
                        'progress_pct'  => $state['progress_pct'],
                        'files_count'   => $state['files_processed'],
                ] );

                if ( $result['is_complete'] ) {
                        // All files done, move to database phase.
                        $state['phase'] = in_array( $state['type'], [ 'full', 'custom' ], true ) ? 'database' : 'extras';
                        $state['progress_pct'] = 75;
                }

                self::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'phase' => $state['phase'], 'progress_pct' => $state['progress_pct'] ] );

                // Check pause/cancel after processing.
                if ( ! self::check_state( $backup_id, $state ) ) {
                        return;
                }

                self::schedule_next_chunk( $backup_id );
        }

        /**
         * Phase: Export database tables one by one.
         */
        private static function chunk_phase_database( $backup_id, &$state ) {
                if ( ! self::check_state( $backup_id, $state ) ) {
                        return;
                }

                $db    = new IBP_Database();

                // Open SQL file if not already open (tracked by state).
                if ( empty( $state['sql_file_open'] ) ) {
                        $state['sql_file_open'] = true;
                        $handle = @fopen( $state['sql_tmp'], 'w' );
                        if ( ! $handle ) {
                                throw new Exception( 'Could not open SQL file for writing.' );
                        }
                        $db->write_header( $handle );
                        fclose( $handle );
                        $state['db_tables'] = $db->get_site_tables();
                        $state['db_table_idx'] = 0;
                }

                // Process one table per chunk (tables can be large).
                $tables    = $state['db_tables'] ?? [];
                $table_idx = $state['db_table_idx'] ?? 0;
                $total_tables = count( $tables );

                $handle = @fopen( $state['sql_tmp'], 'a' );
                if ( ! $handle ) {
                        throw new Exception( 'Could not open SQL file for appending.' );
                }

                if ( $table_idx < $total_tables ) {
                        $table = $tables[ $table_idx ];
                        $db->export_table_chunk( $handle, $table, 0 === $table_idx );
                        $state['db_table_idx'] = $table_idx + 1;

                        // Progress: 75-95% for database phase.
                        $db_pct = $total_tables > 0 ? ( $state['db_table_idx'] / $total_tables ) * 20 : 20;
                        $state['progress_pct'] = (int) min( 95, 75 + $db_pct );
                }

                fclose( $handle );

                if ( $state['db_table_idx'] >= $total_tables ) {
                        // All tables done — write footer.
                        $handle = @fopen( $state['sql_tmp'], 'a' );
                        if ( $handle ) {
                                $db->write_footer( $handle );
                                fclose( $handle );
                        }
                        $state['db_size']     = file_exists( $state['sql_tmp'] ) ? filesize( $state['sql_tmp'] ) : 0;
                        $state['phase']       = 'extras';
                        $state['progress_pct'] = 95;
                }

                self::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [
                        'phase'        => $state['phase'],
                        'progress_pct' => $state['progress_pct'],
                        'db_size'      => $state['db_size'] ?? 0,
                ] );

                if ( ! self::check_state( $backup_id, $state ) ) {
                        return;
                }

                self::schedule_next_chunk( $backup_id );
        }

        /**
         * Phase: Add extra files (manifest, installer, database.sql) to the archive.
         */
        private static function chunk_phase_extras( $backup_id, &$state ) {
                if ( ! self::check_state( $backup_id, $state ) ) {
                        return;
                }

                $extra_files = [];

                // Add database.sql if it exists.
                if ( ! empty( $state['sql_tmp'] ) && file_exists( $state['sql_tmp'] ) ) {
                        $extra_files[ $state['sql_tmp'] ] = 'database.sql';
                }

                // Manifest.
                $manifest_path = trailingslashit( IBP_BACKUP_DIR ) . "manifest-{$backup_id}.json";
                file_put_contents( $manifest_path, self::build_manifest( $backup_id, $state ) );
                $extra_files[ $manifest_path ] = 'ibp-manifest.json';
                $state['extra_tmps'][] = $manifest_path;

                // Installer (full backups only) — written as its own standalone
                // file alongside the archive rather than bundled inside the zip,
                // so it downloads as a separate file the user can place next to
                // the extracted archive on the destination server.
                if ( 'full' === $state['type'] ) {
                        $installer_name = str_replace( '.zip', '', $state['archive_name'] ) . '-installer.php';
                        $installer_path = trailingslashit( IBP_BACKUP_DIR ) . $installer_name;
                        $template       = IBP_PATH . 'includes/installer-template.php';
                        file_put_contents( $installer_path, file_exists( $template ) ? file_get_contents( $template ) : "<?php // Installer missing.\n" );
                        $state['installer_path'] = $installer_path;
                        $state['installer_name'] = $installer_name;
                }

                // For database-only backups, we need to create the ZIP first.
                if ( 'database' === $state['type'] && ! file_exists( $state['archive_path'] ) ) {
                        $archiver = new IBP_Archiver( [], $state['compression_level'] );
                        $archiver->create_empty( $state['archive_path'] );
                }

                $archiver = new IBP_Archiver( [], $state['compression_level'] );
                $archiver->add_extra_files( $state['archive_path'], $extra_files );

                $state['phase']        = 'finalizing';
                $state['progress_pct'] = 97;

                self::set_chunk_state( $backup_id, $state );
                IBP_Logger::update( $backup_id, [ 'phase' => 'finalizing', 'progress_pct' => 97 ] );
                self::schedule_next_chunk( $backup_id );
        }

        /**
         * Phase: Clean up temp files and finalize.
         */
        private static function chunk_phase_finalizing( $backup_id, &$state ) {
                $archive_path = $state['archive_path'];
                $archive_size = file_exists( $archive_path ) ? filesize( $archive_path ) : 0;
                $archive_name = basename( $archive_path );

                // Clean temp files.
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

                // Finalize the backup record.
                IBP_Logger::update(
                        $backup_id,
                        [
                                'status'         => 'complete',
                                'phase'          => 'done',
                                'progress_pct'   => 100,
                                'archive_file'   => $archive_name,
                                'installer_file' => $state['installer_name'] ?? '',
                                'size_bytes'     => $archive_size,
                                'db_size'        => $state['db_size'] ?? 0,
                                'files_count'    => $state['files_processed'] ?? 0,
                                'completed_at'   => ibp_now(),
                        ]
                );

                // Delete chunk state.
                self::delete_chunk_state( $backup_id );

                // Retention + notification.
                $settings = IBP_Helpers::get_settings();
                $backup   = new IBP_Backup();
                $backup->enforce_retention( $settings['max_local_backups'] );
                $backup->maybe_notify( $settings, $state['name'], $archive_size );
        }

        /**
         * Check if the backup has been paused or cancelled.
         * Returns true if the backup should continue, false otherwise.
         *
         * @param string $backup_id Backup identifier.
         * @param array  &$state    State reference (updated if needed).
         * @return bool
         */
        private static function check_state( $backup_id, &$state ) {
                // Re-read from DB in case user clicked pause/cancel since last check.
                $fresh = self::get_chunk_state( $backup_id );
                if ( $fresh ) {
                        $state = $fresh;
                }

                if ( 'cancelled' === $state['status'] ) {
                        self::cleanup_cancelled( $backup_id, $state );
                        return false;
                }

                if ( 'paused' === $state['status'] ) {
                        IBP_Logger::update( $backup_id, [ 'status' => 'paused' ] );
                        return false;
                }

                return true;
        }

        /**
         * Clean up after a cancelled backup.
         *
         * @param string $backup_id Backup identifier.
         * @param array  $state     State.
         */
        private static function cleanup_cancelled( $backup_id, $state ) {
                // Delete partial archive.
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

                IBP_Logger::update(
                        $backup_id,
                        [
                                'status' => 'failed',
                                'note'   => 'Cancelled by user.',
                        ]
                );

                self::delete_chunk_state( $backup_id );
        }

        /**
         * Build the JSON manifest for a chunked backup.
         *
         * @param string $backup_id Backup identifier.
         * @param array  $state     State array.
         * @return string JSON.
         */
        private static function build_manifest( $backup_id, $state ) {
                global $wpdb;
                $data = [
                        'generator'    => 'Insignia Backup',
                        'version'      => IBP_VERSION,
                        'backup_id'    => $backup_id,
                        'name'         => $state['name'] ?? '',
                        'type'         => $state['type'] ?? 'full',
                        'created'      => ibp_now(),
                        'site'         => [
                                'home_url'   => home_url(),
                                'site_url'   => site_url(),
                                'wp_version' => get_bloginfo( 'version' ),
                                'php'        => PHP_VERSION,
                                'multisite'  => is_multisite(),
                        ],
                        'database'     => [
                                'name'      => DB_NAME,
                                'prefix'    => $wpdb->prefix,
                                'charset'   => DB_CHARSET,
                                'dump_size' => $state['db_size'] ?? 0,
                        ],
                ];
                return wp_json_encode( $data, JSON_PRETTY_PRINT );
        }

        /* --------------------------------------------------------------------
         *  Legacy helpers (kept for restore, tree picker, etc.)
         * ----------------------------------------------------------------- */

        /**
         * List the immediate contents (both files and folders) of a directory
         * under ABSPATH, for the dashboard's "choose files & folders" tree
         * picker.
         *
         * @param string $relative  Relative path from ABSPATH ('' = site root).
         * @param array  $excludes Exclude patterns.
         * @return array
         */
        public static function list_tree( $relative, $excludes = [] ) {
                $relative = trim( str_replace( '\\', '/', (string) $relative ), '/' );

                if ( false !== strpos( $relative, '..' ) ) {
                        return [];
                }

                $base = trailingslashit( wp_normalize_path( ABSPATH ) );
                $abs  = '' === $relative ? $base : trailingslashit( $base . $relative );
                $abs  = wp_normalize_path( $abs );

                if ( 0 !== strpos( $abs, $base ) || ! is_dir( $abs ) ) {
                        return [];
                }

                $items = [];
                $dh    = @opendir( $abs );
                if ( ! $dh ) {
                        return [];
                }

                while ( false !== ( $entry = readdir( $dh ) ) ) {
                        if ( '.' === $entry || '..' === $entry ) {
                                continue;
                        }
                        $entry_abs = $abs . $entry;
                        $entry_rel = '' === $relative ? $entry : $relative . '/' . $entry;
                        if ( self::is_excluded( $entry_rel, $excludes ) ) {
                                continue;
                        }

                        $is_dir = is_dir( $entry_abs );
                        $bytes  = $is_dir
                                ? self::path_size( $entry_rel, $excludes )
                                : (int) @filesize( $entry_abs );

                        $items[] = [
                                'name'         => $entry,
                                'path'         => $entry_rel,
                                'type'         => $is_dir ? 'dir' : 'file',
                                'has_children' => $is_dir ? self::has_children( $entry_abs ) : false,
                                'size'         => $bytes,
                                'size_h'       => self::format_size( $bytes ),
                        ];
                }
                closedir( $dh );

                usort(
                        $items,
                        static function ( $a, $b ) {
                                if ( $a['type'] !== $b['type'] ) {
                                        return 'dir' === $a['type'] ? -1 : 1;
                                }
                                return strcasecmp( $a['name'], $b['name'] );
                        }
                );
                return $items;
        }

        /**
         * Quick check for whether a directory has any contents.
         *
         * @param string $abs_dir Absolute directory path.
         * @return bool
         */
        private static function has_children( $abs_dir ) {
                $dh = @opendir( $abs_dir );
                if ( ! $dh ) {
                        return false;
                }
                $found = false;
                while ( false !== ( $entry = readdir( $dh ) ) ) {
                        if ( '.' === $entry || '..' === $entry ) {
                                continue;
                        }
                        $found = true;
                        break;
                }
                closedir( $dh );
                return $found;
        }

        /**
         * Sanitize a list of user-selected relative folder paths.
         *
         * @param mixed $raw Raw value.
         * @return array Clean relative paths.
         */
        public static function sanitize_folder_list( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                        $raw     = is_array( $decoded ) ? $decoded : [];
                }
                if ( ! is_array( $raw ) ) {
                        return [];
                }

                $clean = [];
                foreach ( $raw as $path ) {
                        $path = str_replace( '\\', '/', (string) $path );
                        $path = trim( sanitize_text_field( $path ), '/' );
                        if ( '' === $path || false !== strpos( $path, '..' ) ) {
                                continue;
                        }
                        $clean[] = $path;
                }
                return array_values( array_unique( $clean ) );
        }

        /**
         * Auto-cleanup old temp archives (called on admin_init).
         * Removes archives older than IBP_ARCHIVE_TTL from the temp directory.
         */
        public static function cleanup_old_archives() {
                $dir = IBP_BACKUP_DIR;
                if ( ! is_dir( $dir ) ) {
                        return;
                }

                $threshold = time() - IBP_ARCHIVE_TTL;
                $dh        = @opendir( $dir );
                if ( ! $dh ) {
                        return;
                }

                // Also check the database for any stale "running" backups that
                // haven't been updated in the last 2 hours — mark them as failed.
                global $wpdb;
                $stale_threshold = ibp_format_timestamp( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query(
                        $wpdb->prepare(
                                "UPDATE " . IBP_Logger::table() . " SET status = 'failed', note = 'Backup timed out.'
                                 WHERE status IN ('running','pending') AND created_at < %s",
                                $stale_threshold
                        )
                );

                while ( false !== ( $entry = readdir( $dh ) ) ) {
                        if ( '.' === $entry || '..' === $entry || '.htaccess' === $entry || 'index.php' === $entry ) {
                                continue;
                        }
                        $path = $dir . '/' . $entry;
                        if ( is_file( $path ) && filemtime( $path ) < $threshold ) {
                                // Don't delete archives that are still referenced by a complete backup.
                                $is_referenced = $wpdb->get_var(
                                        $wpdb->prepare(
                                                "SELECT COUNT(*) FROM " . IBP_Logger::table() . " WHERE archive_file = %s AND status = 'complete'",
                                                $entry
                                        )
                                );
                                if ( ! $is_referenced ) {
                                        @unlink( $path );
                                }
                        }
                }
                closedir( $dh );
        }
}