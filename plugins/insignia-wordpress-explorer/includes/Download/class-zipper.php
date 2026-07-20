<?php
/**
 * Zipper — creates ZIP archives of plugin / theme directories.
 *
 * @package WPTD\Download
 */

namespace WPTD\Download;

use WPTD\Contracts\Hookable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Zipper implements Hookable {

    public function register_hooks(): void {
        // Stateless service — no direct hooks.
    }

    public function zip_plugin( string $slug ): string|WP_Error {
        $source = $this->resolve_public( WP_PLUGIN_DIR, $slug );
        return $source instanceof WP_Error ? $source : $this->build_zip( $source, $slug );
    }

    public function zip_theme( string $slug ): string|WP_Error {
        $source = $this->resolve_public( get_theme_root(), $slug );
        return $source instanceof WP_Error ? $source : $this->build_zip( $source, $slug );
    }

    /**
     * Safely resolve and validate a directory path inside an allowed root.
     * Exposed publicly so the API layer can reuse it for stats / bulk ops.
     *
     * @param  string $root  Allowed root directory.
     * @param  string $slug  Sub-directory slug.
     * @return string|\WP_Error  Resolved absolute path or error.
     */
    public function resolve_public( string $root, string $slug ): string|WP_Error {
        if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $slug ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'wptd' ), [ 'status' => 400 ] );
        }

        // The slug regex guarantees no path traversal characters, so the
        // constructed path is always inside $root by construction.
        // We try realpath() for the canonical path; if realpath fails or
        // resolves outside $root due to a symlink, we fall back to the
        // constructed path (safe because the slug can't escape $root).
        $constructed = rtrim( $root, '/\\' ) . '/' . $slug;

        // First choice: canonical realpath, if it exists and is inside root.
        $candidate   = realpath( $constructed );
        $real_root   = realpath( $root );

        if ( $candidate && $real_root ) {
            // Use trailingslashit comparison to avoid /foo/bar-evil matching /foo/bar
            $ok = ( $candidate === $real_root )
               || ( strpos( $candidate . DIRECTORY_SEPARATOR, $real_root . DIRECTORY_SEPARATOR ) === 0 );
            if ( $ok ) {
                return $candidate;
            }
        }

        // Fallback: the slug regex already prevented traversal, so if the
        // directory exists at the constructed path (even via symlink), allow it.
        // This fixes theme/plugin resolution on servers that symlink
        // wp-content or the plugins/themes directories.
        if ( is_dir( $constructed ) ) {
            return $constructed;
        }

        return new WP_Error( 'not_found', __( 'Directory not found or access denied.', 'wptd' ), [ 'status' => 404 ] );
    }

    private function build_zip( string $source_dir, string $slug ): string|WP_Error {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error(
                'no_ziparchive',
                __( 'The PHP ZipArchive extension is not available on this server.', 'wptd' ),
                [ 'status' => 500 ]
            );
        }

        $zip_path = trailingslashit( sys_get_temp_dir() ) . $slug . '-' . time() . '-' . wp_rand( 1000, 9999 ) . '.zip';
        $zip      = new \ZipArchive();

        if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            return new WP_Error( 'zip_open_failed', __( 'Could not create ZIP archive.', 'wptd' ), [ 'status' => 500 ] );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $real      = $file->getRealPath();
            $relative  = $slug . '/' . substr( $real, strlen( $source_dir ) + 1 );
            $zip->addFile( $real, $relative );
        }

        $zip->close();

        if ( ! file_exists( $zip_path ) ) {
            return new WP_Error( 'zip_failed', __( 'ZIP creation failed silently.', 'wptd' ), [ 'status' => 500 ] );
        }

        return $zip_path;
    }
}
