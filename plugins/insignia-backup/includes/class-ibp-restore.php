<?php
/**
 * In-place restore engine.
 *
 * Restores an existing archive back onto the SAME WordPress install
 * (useful for rolling back). For migrating to a NEW server, the
 * downloadable installer.php + archive is the recommended path.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Restore {

	/**
	 * Restore a backup archive in place.
	 *
	 * @param string $backup_id   Identifier.
	 * @param array  $args {
	 *     @type bool $restore_db    Import database.sql.
	 *     @type bool $restore_files Extract files over wp install.
	 * }
	 * @return array Result summary.
	 * @throws Exception On failure.
	 */
	public function run( $backup_id, array $args = [] ) {
		IBP_Helpers::raise_limits();

		$restore_db    = $args['restore_db'] ?? true;
		$restore_files = $args['restore_files'] ?? false;

		$row = IBP_Logger::get( $backup_id );
		if ( ! $row ) {
			throw new Exception( 'Backup record not found.' );
		}

		$archive = trailingslashit( IBP_BACKUP_DIR ) . $row['archive_file'];
		if ( ! file_exists( $archive ) ) {
			throw new Exception( 'Archive file is missing on disk.' );
		}
		if ( ! IBP_Helpers::has_zip_archive() ) {
			throw new Exception( 'ZipArchive extension is required to restore.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new Exception( 'Could not open the archive.' );
		}

		$work = trailingslashit( IBP_BACKUP_DIR ) . 'restore-' . $backup_id;
		wp_mkdir_p( $work );

		$summary = [ 'db' => false, 'files' => 0 ];

		// --- Database ---
		if ( $restore_db ) {
			$sql_index = $zip->locateName( 'database.sql' );
			if ( false !== $sql_index ) {
				$zip->extractTo( $work, [ 'database.sql' ] );
				$sql_path = trailingslashit( $work ) . 'database.sql';
				$this->import_sql( $sql_path );
				@unlink( $sql_path );
				$summary['db'] = true;
			}
		}

		// --- Files ---
		if ( $restore_files ) {
			$zip->extractTo( ABSPATH );
			$summary['files'] = $zip->numFiles;
		}

		$zip->close();
		$this->rrmdir( $work );

		return $summary;
	}

	/**
	 * Import a .sql file statement-by-statement using $wpdb.
	 *
	 * @param string $sql_path File path.
	 * @throws Exception On read failure.
	 */
	private function import_sql( $sql_path ) {
		global $wpdb;

		$handle = @fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			throw new Exception( 'Could not read SQL file.' );
		}

		$buffer = '';
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			// Skip comments and empty lines.
			if ( '' === trim( $line ) || 0 === strpos( ltrim( $line ), '--' ) ) {
				continue;
			}

			$buffer .= $line;

			// A statement ends when the trimmed line ends with ";".
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$statement = trim( $buffer );
				if ( '' !== $statement ) {
					// Direct query: statements come from our own trusted dump.
					$wpdb->query( $statement );
				}
				$buffer = '';
			}
		}
		fclose( $handle );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
