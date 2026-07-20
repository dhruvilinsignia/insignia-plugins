<?php
/**
 * REST API endpoints consumed by the React admin app.
 *
 * Namespace: wptd/v1
 *
 * Routes
 * ──────
 *  GET   /wptd/v1/list              → all plugins + themes (with metadata)
 *  GET   /wptd/v1/details           → details for one item (file count, size, mtime)
 *  GET   /wptd/v1/download          → streams a single ZIP
 *  POST  /wptd/v1/bulk-download     → streams a ZIP containing multiple items
 *  GET   /wptd/v1/settings          → read plugin settings
 *  POST  /wptd/v1/settings          → update plugin settings
 *  GET   /wptd/v1/history           → read recent download history
 *  POST  /wptd/v1/history           → record a successful download
 *  DELETE /wptd/v1/history          → clear history
 *
 * @package WPTD\Api
 */

namespace WPTD\Api;

use WPTD\Contracts\Hookable;
use WPTD\Download\Zipper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Download_Endpoint implements Hookable {

    private const NAMESPACE = 'wptd/v1';
    private const HISTORY_KEY   = 'wptd_download_history';
    private const HISTORY_LIMIT = 50;
    private const SETTINGS_KEY  = 'wptd_settings';

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/list', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_list' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/details', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_details' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => fn( $v ) => in_array( $v, [ 'plugin', 'theme' ], true ),
                ],
                'slug' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_file_name',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/download', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_download' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => fn( $v ) => in_array( $v, [ 'plugin', 'theme' ], true ),
                ],
                'slug' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_file_name',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/bulk-download', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_bulk_download' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_get_settings' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_update_settings' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'default_view'    => [ 'sanitize_callback' => 'sanitize_key' ],
                    'default_sort'    => [ 'sanitize_callback' => 'sanitize_key' ],
                    'theme_mode'      => [ 'sanitize_callback' => 'sanitize_key' ],
                    'show_inactive'   => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
                    'remember_layout' => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/history', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_get_history' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_add_history' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'type' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => fn( $v ) => in_array( $v, [ 'plugin', 'theme' ], true ),
                    ],
                    'slug' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_file_name',
                    ],
                    'name'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
                    'version' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                    'size'    => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'handle_clear_history' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
        ] );
    }

    // ── Permission ─────────────────────────────────────────────────────────────

    public function check_permission(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to use Insignia Explorer.', 'wptd' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Compute folder size + file count recursively. Cheap and safe.
     */
    private function folder_stats( string $dir ): array {
        $size  = 0;
        $count = 0;
        $mtime = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }
                $size  += $file->getSize();
                $count++;
                $m     = $file->getMTime();
                if ( $m > $mtime ) {
                    $mtime = $m;
                }
            }
        } catch ( \Throwable $e ) {
            // ignore — return zeros
        }

        return [
            'size'       => $size,
            'file_count' => $count,
            'modified'   => $mtime,
        ];
    }

    private function human_size( int $bytes ): string {
        if ( $bytes <= 0 ) {
            return '0 B';
        }
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $i     = floor( log( $bytes, 1024 ) );
        $i     = min( $i, count( $units ) - 1 );
        return round( $bytes / pow( 1024, $i ), 1 ) . ' ' . $units[ (int) $i ];
    }

    // ── GET /list ──────────────────────────────────────────────────────────────

    public function handle_list( WP_REST_Request $request ): WP_REST_Response {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = [];
        foreach ( get_plugins() as $file => $data ) {
            $parts = explode( '/', $file );
            $slug  = count( $parts ) > 1 ? $parts[0] : pathinfo( $parts[0], PATHINFO_FILENAME );

            // Cheap stat: only the directory mtime, to avoid expensive recursive scans on every list call.
            $path  = trailingslashit( WP_PLUGIN_DIR ) . $slug;
            $stats = is_dir( $path ) ? $this->folder_stats( $path ) : [ 'size' => 0, 'file_count' => 0, 'modified' => 0 ];

            $plugins[] = [
                'slug'        => $slug,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'description' => wp_trim_words( $data['Description'], 18 ),
                'author'      => wp_strip_all_tags( $data['Author'] ),
                'author_uri'  => $data['AuthorURI'] ?? '',
                'plugin_uri'  => $data['PluginURI'] ?? '',
                'active'      => is_plugin_active( $file ),
                'network'     => is_plugin_active_for_network( $file ),
                'path'        => $file,
                'size'        => $stats['size'],
                'size_human'  => $this->human_size( $stats['size'] ),
                'file_count'  => $stats['file_count'],
                'modified'    => $stats['modified'],
            ];
        }

        $themes = [];
        foreach ( wp_get_themes() as $slug => $theme ) {
            $path  = trailingslashit( get_theme_root() ) . $slug;
            $stats = is_dir( $path ) ? $this->folder_stats( $path ) : [ 'size' => 0, 'file_count' => 0, 'modified' => 0 ];

            $themes[] = [
                'slug'        => $slug,
                'name'        => $theme->get( 'Name' ),
                'version'     => $theme->get( 'Version' ),
                'description' => wp_trim_words( $theme->get( 'Description' ), 18 ),
                'author'      => wp_strip_all_tags( $theme->get( 'Author' ) ),
                'author_uri'  => $theme->get( 'AuthorURI' ),
                'theme_uri'   => $theme->get( 'ThemeURI' ),
                'active'      => ( get_stylesheet() === $slug ),
                'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
                'template'    => $theme->get( 'Template' ),
                'size'        => $stats['size'],
                'size_human'  => $this->human_size( $stats['size'] ),
                'file_count'  => $stats['file_count'],
                'modified'    => $stats['modified'],
            ];
        }

        return new WP_REST_Response(
            [
                'plugins' => $plugins,
                'themes'  => $themes,
                'server'  => [
                    'php_version'    => PHP_VERSION,
                    'wp_version'     => get_bloginfo( 'version' ),
                    'has_ziparchive' => class_exists( 'ZipArchive' ),
                    'temp_dir'       => sys_get_temp_dir(),
                    'disk_free'      => function_exists( 'disk_free_space' ) ? @disk_free_space( sys_get_temp_dir() ) : false,
                ],
            ],
            200
        );
    }

    // ── GET /details ───────────────────────────────────────────────────────────

    public function handle_details( WP_REST_Request $request ): WP_REST_Response {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' );

        $zipper   = new Zipper();
        $resolved = ( 'plugin' === $type )
            ? $zipper->resolve_public( WP_PLUGIN_DIR, $slug )
            : $zipper->resolve_public( get_theme_root(), $slug );

        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 404 );
        }

        $stats = $this->folder_stats( $resolved );

        // Build a small file tree (depth-limited) for the modal preview.
        $tree = $this->build_tree( $resolved, $slug );

        return new WP_REST_Response( [
            'slug'       => $slug,
            'type'       => $type,
            'path'       => $resolved,
            'size'       => $stats['size'],
            'size_human' => $this->human_size( $stats['size'] ),
            'file_count' => $stats['file_count'],
            'modified'   => $stats['modified'],
            'tree'       => $tree,
        ], 200 );
    }

    /**
     * Build a depth-limited tree of files inside the directory.
     */
    private function build_tree( string $dir, string $base_slug ): array {
        $nodes = [];

        try {
            $it = new \FilesystemIterator( $dir, \FilesystemIterator::SKIP_DOTS );
            foreach ( $it as $entry ) {
                $name  = $entry->getFilename();
                $isdir = $entry->isDir();

                $node = [
                    'name'  => $name,
                    'type'  => $isdir ? 'dir' : 'file',
                    'size'  => $isdir ? 0 : $entry->getSize(),
                    'ext'   => $isdir ? '' : pathinfo( $name, PATHINFO_EXTENSION ),
                ];

                // Only expand one level of sub-directories to keep payload small.
                if ( $isdir ) {
                    $children = [];
                    try {
                        $sub = new \FilesystemIterator( $entry->getPathname(), \FilesystemIterator::SKIP_DOTS );
                        $count = 0;
                        foreach ( $sub as $child ) {
                            if ( $count++ >= 50 ) {
                                $children[] = [
                                    'name' => '…',
                                    'type' => 'more',
                                    'size' => 0,
                                    'ext'  => '',
                                ];
                                break;
                            }
                            $children[] = [
                                'name'  => $child->getFilename(),
                                'type'  => $child->isDir() ? 'dir' : 'file',
                                'size'  => $child->isDir() ? 0 : $child->getSize(),
                                'ext'   => $child->isDir() ? '' : pathinfo( $child->getFilename(), PATHINFO_EXTENSION ),
                            ];
                        }
                    } catch ( \Throwable $e ) {}
                    $node['children'] = $children;
                    $node['count']    = count( $children );
                }

                $nodes[] = $node;
            }
        } catch ( \Throwable $e ) {}

        // Sort: dirs first, then files, alphabetical.
        usort( $nodes, function ( $a, $b ) {
            if ( $a['type'] !== $b['type'] ) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $nodes;
    }

    // ── GET /download ──────────────────────────────────────────────────────────

    public function handle_download( WP_REST_Request $request ): never {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' );

        $zipper = new Zipper();

        $zip_path = ( 'plugin' === $type )
            ? $zipper->zip_plugin( $slug )
            : $zipper->zip_theme( $slug );

        if ( is_wp_error( $zip_path ) ) {
            wp_send_json_error(
                [ 'message' => $zip_path->get_error_message() ],
                $zip_path->get_error_data()['status'] ?? 500
            );
        }

        $filename = sanitize_file_name( $slug ) . '.zip';

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        flush();
        readfile( $zip_path ); // phpcs:ignore
        unlink( $zip_path );
        exit;
    }

    // ── POST /bulk-download ────────────────────────────────────────────────────

    /**
     * Body: { "items": [ { "type": "plugin", "slug": "akismet" }, ... ] }
     * Streams a single ZIP named "wptd-bulk-<timestamp>.zip" containing one
     * top-level folder per item.
     */
    public function handle_bulk_download( WP_REST_Request $request ): never {
        $items = $request->get_json_params();
        $items = $items['items'] ?? $items;

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'No items provided.', 'wptd' ) ], 400 );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( [ 'message' => __( 'ZipArchive not available.', 'wptd' ) ], 500 );
        }

        $zipper = new Zipper();
        $zip_path = trailingslashit( sys_get_temp_dir() ) . 'wptd-bulk-' . time() . '.zip';
        $zip      = new \ZipArchive();

        if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not create bulk ZIP.', 'wptd' ) ], 500 );
        }

        $added = 0;
        $errors = [];

        foreach ( $items as $item ) {
            $type = sanitize_key( $item['type'] ?? '' );
            $slug = sanitize_file_name( $item['slug'] ?? '' );

            if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || '' === $slug ) {
                $errors[] = sprintf( __( 'Skipped invalid item: %s/%s', 'wptd' ), $type, $slug );
                continue;
            }

            $source = ( 'plugin' === $type )
                ? $zipper->resolve_public( WP_PLUGIN_DIR, $slug )
                : $zipper->resolve_public( get_theme_root(), $slug );

            if ( is_wp_error( $source ) ) {
                $errors[] = sprintf( __( 'Skip %s: %s', 'wptd' ), $slug, $source->get_error_message() );
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }
                $real     = $file->getRealPath();
                $relative = $slug . '/' . substr( $real, strlen( $source ) + 1 );
                $zip->addFile( $real, $relative );
            }
            $added++;
        }

        $zip->close();

        if ( 0 === $added ) {
            @unlink( $zip_path );
            wp_send_json_error( [ 'message' => __( 'No items could be added to the ZIP.', 'wptd' ) ], 422 );
        }

        $filename = 'wptd-bulk-' . wp_date( 'Y-m-d-His' ) . '.zip';

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        flush();
        readfile( $zip_path ); // phpcs:ignore
        unlink( $zip_path );
        exit;
    }

    // ── Settings ───────────────────────────────────────────────────────────────

    public function handle_get_settings( WP_REST_Request $request ): WP_REST_Response {
        $defaults = [
            'default_view'    => 'grid',
            'default_sort'    => 'name',
            'theme_mode'      => 'auto',
            'show_inactive'   => true,
            'remember_layout' => true,
        ];
        $stored  = get_option( self::SETTINGS_KEY, [] );
        $stored  = is_array( $stored ) ? $stored : [];
        return new WP_REST_Response( array_merge( $defaults, $stored ), 200 );
    }

    public function handle_update_settings( WP_REST_Request $request ): WP_REST_Response {
        $current = get_option( self::SETTINGS_KEY, [] );
        $current = is_array( $current ) ? $current : [];

        $next = array_merge( $current, [
            'default_view'    => in_array( $request->get_param( 'default_view' ), [ 'grid', 'table' ], true )
                ? $request->get_param( 'default_view' ) : ( $current['default_view'] ?? 'grid' ),
            'default_sort'    => in_array( $request->get_param( 'default_sort' ), [ 'name', 'size', 'modified', 'status' ], true )
                ? $request->get_param( 'default_sort' ) : ( $current['default_sort'] ?? 'name' ),
            'theme_mode'      => in_array( $request->get_param( 'theme_mode' ), [ 'auto', 'light', 'dark' ], true )
                ? $request->get_param( 'theme_mode' ) : ( $current['theme_mode'] ?? 'auto' ),
            'show_inactive'   => $request->has_param( 'show_inactive' )
                ? rest_sanitize_boolean( $request->get_param( 'show_inactive' ) ) : ( $current['show_inactive'] ?? true ),
            'remember_layout' => $request->has_param( 'remember_layout' )
                ? rest_sanitize_boolean( $request->get_param( 'remember_layout' ) ) : ( $current['remember_layout'] ?? true ),
        ] );

        update_option( self::SETTINGS_KEY, $next );
        return new WP_REST_Response( $next, 200 );
    }

    // ── History ────────────────────────────────────────────────────────────────

    public function handle_get_history( WP_REST_Request $request ): WP_REST_Response {
        $history = get_option( self::HISTORY_KEY, [] );
        $history = is_array( $history ) ? $history : [];
        return new WP_REST_Response( $history, 200 );
    }

    public function handle_add_history( WP_REST_Request $request ): WP_REST_Response {
        $history = get_option( self::HISTORY_KEY, [] );
        $history = is_array( $history ) ? $history : [];

        $entry = [
            'id'        => wp_generate_uuid4(),
            'type'      => $request->get_param( 'type' ),
            'slug'      => $request->get_param( 'slug' ),
            'name'      => $request->get_param( 'name' ) ?? $request->get_param( 'slug' ),
            'version'   => $request->get_param( 'version' ) ?? '',
            'size'      => (int) $request->get_param( 'size' ),
            'size_human' => $this->human_size( (int) $request->get_param( 'size' ) ),
            'timestamp' => time(),
            'user'      => get_current_user_id(),
        ];

        array_unshift( $history, $entry );
        $history = array_slice( $history, 0, self::HISTORY_LIMIT );

        update_option( self::HISTORY_KEY, $history );
        return new WP_REST_Response( $entry, 201 );
    }

    public function handle_clear_history( WP_REST_Request $request ): WP_REST_Response {
        delete_option( self::HISTORY_KEY );
        return new WP_REST_Response( [ 'cleared' => true ], 200 );
    }
}
