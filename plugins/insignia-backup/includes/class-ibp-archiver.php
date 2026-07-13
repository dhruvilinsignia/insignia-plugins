<?php
/**
 * File archiver — packages WordPress files + SQL dump into a ZIP.
 * Supports both legacy (single-pass) and chunked (resumable) modes.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Archiver {

        /** @var array Exclude patterns. */
        private $excludes;

        /** @var int Compression 0-9. */
        private $compression;

        /**
         * @param array $excludes    Relative path patterns to skip.
         * @param int   $compression 0 (store) – 9 (max).
         */
        public function __construct( $excludes = [], $compression = 6 ) {
                $this->excludes    = $excludes;
                $this->compression = max( 0, min( 9, (int) $compression ) );
        }

        /**
         * Build a ZIP archive of $source_dir into $archive_path (legacy single-pass).
         *
         * @param string $source_dir   Directory to archive.
         * @param string $archive_path Destination .zip path.
         * @param array  $extra_files  [ path_on_disk => name_in_archive ].
         * @param array  $include_paths Optional relative paths to limit to.
         * @return array { bytes, files }
         * @throws Exception
         */
        public function create( $source_dir, $archive_path, $extra_files = [], $include_paths = [] ) {
                if ( ! IBP_Helpers::has_zip_archive() ) {
                        throw new Exception( 'ZipArchive PHP extension is not available on this server.' );
                }

                $zip = new ZipArchive();
                if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                        throw new Exception( 'Could not create the ZIP archive.' );
                }

                $source_dir = trailingslashit( $source_dir );
                $file_count = 0;

                $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
                        RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ( $iterator as $item ) {
                        $path     = $item->getPathname();
                        $relative = ltrim( str_replace( $source_dir, '', $path ), '/\\' );
                        $relative = str_replace( '\\', '/', $relative );

                        if ( '' === $relative ) {
                                continue;
                        }
                        if ( IBP_Helpers::is_excluded( $relative, $this->excludes ) ) {
                                continue;
                        }
                        if ( ! IBP_Helpers::path_allowed( $relative, $include_paths ) ) {
                                continue;
                        }

                        if ( $item->isDir() ) {
                                $zip->addEmptyDir( $relative );
                        } elseif ( $item->isFile() && is_readable( $path ) ) {
                                $zip->addFile( $path, $relative );
                                if ( method_exists( $zip, 'setCompressionName' ) ) {
                                        $method = ( 0 === $this->compression )
                                                ? ZipArchive::CM_STORE
                                                : ZipArchive::CM_DEFLATE;
                                        $zip->setCompressionName( $relative, $method );
                                }
                                $file_count++;
                        }
                }

                foreach ( $extra_files as $disk_path => $archive_name ) {
                        if ( file_exists( $disk_path ) ) {
                                $zip->addFile( $disk_path, $archive_name );
                                $file_count++;
                        }
                }

                $zip->close();

                if ( ! file_exists( $archive_path ) ) {
                        throw new Exception( 'ZIP archive was not written to disk.' );
                }

                return [
                        'bytes' => filesize( $archive_path ),
                        'files' => $file_count,
                ];
        }

        /**
         * Create an empty ZIP archive (for database-only backups).
         *
         * @param string $archive_path Destination path.
         * @throws Exception
         */
        public function create_empty( $archive_path ) {
                if ( ! IBP_Helpers::has_zip_archive() ) {
                        throw new Exception( 'ZipArchive PHP extension is not available.' );
                }
                $zip = new ZipArchive();
                if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                        throw new Exception( 'Could not create the ZIP archive.' );
                }
                $zip->close();
        }

        /**
         * Process a chunk of files from the file list and add them to the archive.
         *
         * Opens the existing ZIP in append mode, adds up to $chunk_size files,
         * and returns how many were processed and whether all files are done.
         *
         * @param string $archive_path    Path to the ZIP file.
         * @param string $file_list_path  Path to the file list (one path per line).
         * @param int    $start_offset    Line number to start from.
         * @param int    $chunk_size      Max files to process this chunk.
         * @param int    $compression     Compression level.
         * @return array {
         *     @type int  $files_processed  Number of files added this chunk.
         *     @type int  $next_offset      Line offset for the next chunk.
         *     @type bool $is_complete      Whether all files have been processed.
         *     @type int  $total_bytes      Total bytes of files in the archive (approximate).
         * }
         * @throws Exception
         */
        public function process_chunk( $archive_path, $file_list_path, $start_offset, $chunk_size, $compression ) {
                if ( ! IBP_Helpers::has_zip_archive() ) {
                        throw new Exception( 'ZipArchive PHP extension is not available.' );
                }

                // Open for modification (CREATE opens existing for append, or creates new).
                $zip = new ZipArchive();
                if ( true !== $zip->open( $archive_path, ZipArchive::CREATE ) ) {
                        throw new Exception( 'Could not open ZIP archive for chunk processing.' );
                }

                $source_dir      = trailingslashit( wp_normalize_path( ABSPATH ) );
                $files_processed = 0;
                $current_line    = $start_offset;
                $total_bytes     = 0;

                $handle = @fopen( $file_list_path, 'r' );
                if ( ! $handle ) {
                        $zip->close();
                        throw new Exception( 'Could not read file list for chunk processing.' );
                }

                // Skip to start_offset.
                $line_num = 0;
                while ( $line_num < $start_offset && ! feof( $handle ) ) {
                        fgets( $handle );
                        $line_num++;
                }

                $method = ( 0 === $compression ) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;

                while ( ! feof( $handle ) && $files_processed < $chunk_size ) {
                        $relative = trim( fgets( $handle ) );
                        $current_line++;

                        if ( '' === $relative ) {
                                continue;
                        }

                        $abs_path = $source_dir . $relative;
                        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
                                continue;
                        }

                        $zip->addFile( $abs_path, $relative );
                        if ( method_exists( $zip, 'setCompressionName' ) ) {
                                $zip->setCompressionName( $relative, $method );
                        }

                        $total_bytes += filesize( $abs_path );
                        $files_processed++;
                }

                $is_complete = feof( $handle );
                fclose( $handle );

                $zip->close();

                return [
                        'files_processed' => $files_processed,
                        'next_offset'     => $current_line,
                        'is_complete'     => $is_complete,
                        'total_bytes'     => $total_bytes,
                ];
        }

        /**
         * Add extra files (database.sql, manifest, installer) to an existing archive.
         *
         * @param string $archive_path Path to the ZIP.
         * @param array  $extra_files  [disk_path => archive_name].
         * @return int Number of files added.
         * @throws Exception
         */
        public function add_extra_files( $archive_path, $extra_files ) {
                if ( ! IBP_Helpers::has_zip_archive() ) {
                        throw new Exception( 'ZipArchive PHP extension is not available.' );
                }

                $zip = new ZipArchive();
                if ( true !== $zip->open( $archive_path, ZipArchive::CREATE ) ) {
                        throw new Exception( 'Could not open ZIP archive for adding extra files.' );
                }

                $count = 0;
                foreach ( $extra_files as $disk_path => $archive_name ) {
                        if ( file_exists( $disk_path ) ) {
                                $zip->addFile( $disk_path, $archive_name );
                                $count++;
                        }
                }

                $zip->close();
                return $count;
        }

        /**
         * Whether a relative path falls inside one of the selected folders.
         *
         * @param string $relative      Relative path.
         * @param array  $include_paths Selected paths.
         * @return bool
         */
        private function path_allowed( $relative, $include_paths ) {
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
}