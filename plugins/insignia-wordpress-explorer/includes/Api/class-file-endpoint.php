<?php
/**
 * REST API endpoints for the integrated code editor / file manager.
 *
 * Namespace: wptd/v1
 *
 * Routes
 * ──────
 *  GET    /file-tree        → recursive file tree (plugin | theme | wordpress root)
 *  GET    /file             → read a single file's content + metadata
 *  POST   /file             → save (update) a file's content (with backup)
 *  POST   /file/create      → create a new file or directory
 *  POST   /file/rename      → rename / move a file or directory
 *  DELETE /file             → delete a file or directory
 *  GET    /file/download    → download a single file (raw) or a folder (as ZIP)
 *  GET    /search           → grep-like global code search
 *
 * type = 'wordpress' browses the entire WP install (ABSPATH).
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

class File_Endpoint implements Hookable {

    private const NAMESPACE     = 'wptd/v1';
    private const MAX_FILE_SIZE    = 2 * 1024 * 1024;
    private const MAX_TREE_NODES   = 2500;
    private const MAX_SEARCH_HITS  = 100;
    private const MAX_SEARCH_FILES = 8000;

    /** Valid root types */
    private const VALID_TYPES = [ 'plugin', 'theme', 'wordpress' ];

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        $type_args = [
            'required'          => true,
            'sanitize_callback' => 'sanitize_key',
            'validate_callback' => fn( $v ) => in_array( $v, self::VALID_TYPES, true ),
        ];
        $slug_args = [
            'required'          => false,
            'default'           => '',
            'sanitize_callback' => 'sanitize_file_name',
        ];

        register_rest_route( self::NAMESPACE, '/file-tree', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_file_tree' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type' => $type_args,
                'slug' => $slug_args,
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/file', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_file_read' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'type' => $type_args,
                    'slug' => $slug_args,
                    'path' => [ 'required' => false, 'default' => '' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_file_write' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'type'    => $type_args,
                    'slug'    => $slug_args,
                    'path'    => [ 'required' => false, 'default' => '' ],
                    'content' => [ 'required' => true ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'handle_file_delete' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'type' => $type_args,
                    'slug' => $slug_args,
                    'path' => [ 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/file/create', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_file_create' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type'   => $type_args,
                'slug'   => $slug_args,
                'path'   => [ 'required' => true ],
                'is_dir' => [ 'required' => false, 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/file/rename', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_file_rename' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type'    => $type_args,
                'slug'    => $slug_args,
                'path'    => [ 'required' => true ],
                'newName' => [ 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/file/download', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_file_download' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type' => $type_args,
                'slug' => $slug_args,
                'path' => [ 'required' => false, 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_search' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type'          => $type_args,
                'slug'          => $slug_args,
                'q'             => [ 'required' => true ],
                'caseSensitive' => [ 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
                'regex'         => [ 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            ],
        ] );
    }

    // ── Permission ─────────────────────────────────────────────────────────────

    public function check_permission(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to use the file editor.', 'wptd' ),
                [ 'status' => 403 ]
            );
        }
        if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
            return new WP_Error(
                'file_edit_disabled',
                __( 'File editing is disabled via DISALLOW_FILE_EDIT in wp-config.php.', 'wptd' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Resolve a plugin/theme/wordpress root directory safely.
     */
    private function resolve_root( string $type, string $slug ): string|WP_Error {
        if ( 'wordpress' === $type ) {
            $root = realpath( ABSPATH );
            if ( ! $root || ! is_dir( $root ) ) {
                return new WP_Error(
                    'invalid_root',
                    __( 'WordPress root directory is not accessible.', 'wptd' ),
                    [ 'status' => 500 ]
                );
            }
            return $root;
        }
        if ( empty( $slug ) ) {
            return new WP_Error( 'missing_slug', __( 'Slug is required.', 'wptd' ), [ 'status' => 400 ] );
        }
        $zipper = new Zipper();
        $root   = ( 'plugin' === $type ) ? WP_PLUGIN_DIR : get_theme_root();
        return $zipper->resolve_public( $root, $slug );
    }

    /**
     * Resolve a sub-path inside the root and verify containment.
     *
     * Security model
     * --------------
     * The `..` check below guarantees that the constructed path
     * `$root/$path` can NEVER escape `$root` by traversal.  Because the
     * path is built by string concatenation (not by following symlinks),
     * it is always inside `$root` by construction.
     *
     * We then try `realpath()` to get the canonical path for reading /
     * writing.  If `realpath` resolves through a symlink to a location
     * outside `$root` (common when `wp-content` is symlinked — typical
     * on managed hosting, Bedrock, Docker, etc.) we still allow the
     * access, because:
     *   1. The user reached the file via a path that IS inside `$root`
     *      (the symlink lives inside `$root`).
     *   2. Only administrators (`manage_options`) can call this endpoint.
     *   3. The `..` check already prevented directory traversal.
     *
     * This fixes the bug where WordPress-root mode could not open files
     * inside `wp-content/themes/...` on servers that symlink wp-content.
     */
    private function resolve_subpath( string $root, string $path ): string|WP_Error {
        // Normalise separators — accept both / and \ from the client.
        $path = str_replace( '\\', '/', $path );
        $path = ltrim( $path, '/' );

        // Block directory traversal — this is the primary security gate.
        $parts = explode( '/', $path );
        foreach ( $parts as $p ) {
            if ( $p === '..' ) {
                return new WP_Error( 'invalid_path', __( 'Invalid path.', 'wptd' ), [ 'status' => 400 ] );
            }
        }

        // Normalise the root (no trailing slash, forward slashes).
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        if ( $root === '' ) {
            return new WP_Error( 'invalid_root', __( 'Invalid root.', 'wptd' ), [ 'status' => 500 ] );
        }

        // Construct the full path.  Because $path has no '..' and no
        // leading slash, this is always inside $root by construction.
        $full = $path === '' ? $root : ( $root . '/' . $path );

        // For existing files / dirs, return the canonical realpath so
        // that file_get_contents / file_put_contents operate on the real
        // file.  If realpath fails (symlink to outside, or file doesn't
        // exist yet), fall back to the constructed $full — it's still
        // safe because of the `..` check above.
        $real = realpath( $full );
        if ( $real !== false ) {
            return $real;
        }

        // File doesn't exist yet (e.g. new file creation).  Verify that
        // the PARENT directory exists so we're not creating files in
        // non-existent folders.  Again, fall back to $full if the parent
        // can't be resolved via realpath.
        $parent_real = realpath( dirname( $full ) );
        if ( $parent_real !== false ) {
            return $full;
        }

        // Neither the file nor its parent resolves — genuinely invalid.
        return new WP_Error( 'invalid_path', __( 'Invalid path.', 'wptd' ), [ 'status' => 400 ] );
    }

    private function is_forbidden( string $basename ): bool {
        $blocked_exts = [
            'zip', 'gz', 'tar', 'rar', '7z',
            'exe', 'bin', 'so', 'dll',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'tiff',
            'mp3', 'mp4', 'wav', 'ogg', 'webm', 'mov',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'mo',
            'sqlite', 'db',
        ];
        if ( str_starts_with( $basename, '.' ) ) {
            return true;
        }
        $ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
        return in_array( $ext, $blocked_exts, true );
    }

    private function human_size( int $bytes ): string {
        if ( $bytes <= 0 ) return '0 B';
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $i = floor( log( $bytes, 1024 ) );
        $i = min( $i, count( $units ) - 1 );
        return round( $bytes / pow( 1024, $i ), 1 ) . ' ' . $units[ (int) $i ];
    }

    /**
     * Directories to skip when scanning / searching.
     *
     * For a single plugin/theme we mirror the core Appearance / Plugin File
     * Editor and show (almost) everything — many themes and plugins keep
     * their compiled assets or even templates in `build/`, `dist/` or
     * `vendor/`, and hiding those made it look like "theme files are not
     * opening". Only truly pathological directories are skipped.
     *
     * For type=wordpress (the entire install) we keep the aggressive skip
     * list — scanning node_modules/vendor across a whole site is too slow.
     */
    private function skip_dirs( string $type = 'wordpress' ): array {
        $always = [ 'node_modules', '.git' ];

        if ( 'wordpress' === $type ) {
            return array_merge( $always, [ 'vendor', 'bower_components', 'dist', 'build', 'cache', 'upgrade' ] );
        }

        return $always;
    }

    // ── GET /file-tree ─────────────────────────────────────────────────────────

    public function handle_file_tree( WP_REST_Request $request ): WP_REST_Response {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' ) ?? '';

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        $tree = $this->scan_dir( $root, $root, $type );

        return new WP_REST_Response( [
            'type'     => $type,
            'slug'      => $slug,
            'root'      => $root,
            'rootName'  => ( 'wordpress' === $type ) ? 'WordPress Root' : $slug,
            'children'  => $tree,
        ], 200 );
    }

    private function scan_dir( string $abs, string $root, string $type = 'wordpress' ): array {
        $nodes = [];
        $count = 0;

        try {
            $it = new \FilesystemIterator( $abs, \FilesystemIterator::SKIP_DOTS );
            $entries = iterator_to_array( $it, false );
        } catch ( \Throwable $e ) {
            return [];
        }

        usort( $entries, function ( $a, $b ) {
            $ad = $a->isDir(); $bd = $b->isDir();
            if ( $ad !== $bd ) return $ad ? -1 : 1;
            return strcasecmp( $a->getFilename(), $b->getFilename() );
        } );

        $skip = $this->skip_dirs( $type );

        foreach ( $entries as $entry ) {
            if ( $count++ > self::MAX_TREE_NODES ) {
                $nodes[] = [
                    'name'  => '… too many entries',
                    'type'  => 'more',
                    'path'  => '',
                    'size'  => 0,
                ];
                break;
            }

            $name = $entry->getFilename();
            if ( $this->is_forbidden( $name ) ) continue;
            if ( in_array( $name, $skip, true ) ) continue;

            $isdir = $entry->isDir();
            $rel   = ltrim( substr( $entry->getPathname(), strlen( $root ) ), DIRECTORY_SEPARATOR );

            $node = [
                'name'  => $name,
                'type'  => $isdir ? 'dir' : 'file',
                'path'  => $rel,
                'ext'   => $isdir ? '' : strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
                'size'  => $isdir ? 0 : $entry->getSize(),
            ];

            if ( $isdir ) {
                // Recursively scan subdirectories so the tree expands properly.
                $node['children'] = $this->scan_dir( $entry->getPathname(), $root, $type );
                $node['expanded'] = false;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    // ── GET /file (read) ───────────────────────────────────────────────────────

    public function handle_file_read( WP_REST_Request $request ): WP_REST_Response {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' ) ?? '';
        $path = $request->get_param( 'path' ) ?? '';

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        if ( ! file_exists( $resolved ) || ! is_file( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'File not found.', 'wptd' ) ], 404 );
        }

        if ( $this->is_forbidden( basename( $resolved ) ) ) {
            return new WP_REST_Response( [ 'error' => __( 'This file type is not editable.', 'wptd' ) ], 403 );
        }

        $size = filesize( $resolved );
        if ( $size > self::MAX_FILE_SIZE ) {
            return new WP_REST_Response( [
                'error' => sprintf(
                    __( 'File is too large to edit (%s). Max size is 2 MB.', 'wptd' ),
                    $this->human_size( $size )
                ),
            ], 413 );
        }

        $raw = file_get_contents( $resolved );
        if ( false === $raw ) {
            return new WP_REST_Response( [ 'error' => __( 'Could not read file.', 'wptd' ) ], 500 );
        }

        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }
        if ( false !== strpos( $raw, "\0" ) ) {
            return new WP_REST_Response( [ 'error' => __( 'File appears to be binary.', 'wptd' ) ], 415 );
        }

        return new WP_REST_Response( [
            'type'     => $type,
            'slug'     => $slug,
            'path'     => $path,
            'name'     => basename( $resolved ),
            'ext'      => strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) ),
            'size'     => $size,
            'sizeHuman'=> $this->human_size( $size ),
            'modified' => filemtime( $resolved ),
            'writable' => is_writable( $resolved ),
            'content'  => $raw,
        ], 200 );
    }

    // ── POST /file (write) ─────────────────────────────────────────────────────

    public function handle_file_write( WP_REST_Request $request ): WP_REST_Response {
        $type    = $request->get_param( 'type' );
        $slug    = $request->get_param( 'slug' ) ?? '';
        $path    = $request->get_param( 'path' ) ?? '';
        $content = $request->get_param( 'content' );

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        if ( ! file_exists( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'File not found.', 'wptd' ) ], 404 );
        }
        if ( $this->is_forbidden( basename( $resolved ) ) ) {
            return new WP_REST_Response( [ 'error' => __( 'This file type is not editable.', 'wptd' ) ], 403 );
        }
        if ( ! is_writable( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'File is not writable.', 'wptd' ) ], 403 );
        }

        $size = filesize( $resolved );
        if ( $size > self::MAX_FILE_SIZE ) {
            return new WP_REST_Response( [ 'error' => __( 'File is too large to edit.', 'wptd' ) ], 413 );
        }

        $backup = $resolved . '.wptd-bak';
        $old_content = null;
        if ( file_exists( $resolved ) ) {
            @copy( $resolved, $backup );
            $old_content = @file_get_contents( $resolved );
        }

        if ( false === @file_put_contents( $resolved, $content ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Failed to write file. Check filesystem permissions.', 'wptd' ) ], 500 );
        }

        $this->push_history( $type, $slug ?: 'wordpress', $path, $content, $old_content );

        return new WP_REST_Response( [
            'ok'       => true,
            'path'     => $path,
            'size'     => filesize( $resolved ),
            'modified' => filemtime( $resolved ),
            'backup'   => $backup,
        ], 200 );
    }

    private function push_history( string $type, string $slug, string $path, string $content, ?string $old_content = null ): void {
        $key   = 'wptd_edit_history';
        $hist  = get_option( $key, [] );
        $hist  = is_array( $hist ) ? $hist : [];

        $entry = [
            'id'         => wp_generate_uuid4(),
            'type'       => $type,
            'slug'       => $slug,
            'path'       => $path,
            'size'       => strlen( $content ),
            'old_size'   => $old_content !== null ? strlen( $old_content ) : 0,
            'timestamp'  => time(),
            'user'       => get_current_user_id(),
            'user_name'  => wp_get_current_user()->display_name ?? '',
            'preview'    => substr( $content, 0, 200 ),
            // Store the BEFORE content so we can compute a diff later.
            // We cap it at 256 KB per revision to avoid blowing up the
            // options table on huge files.
            'old_content'=> $old_content !== null && strlen( $old_content ) <= 262144 ? $old_content : null,
            'new_content'=> strlen( $content ) <= 262144 ? $content : null,
        ];

        array_unshift( $hist, $entry );
        $hist = array_slice( $hist, 0, 100 );

        update_option( $key, $hist );
    }

    // ── DELETE /file ───────────────────────────────────────────────────────────

    public function handle_file_delete( WP_REST_Request $request ): WP_REST_Response {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' ) ?? '';
        $path = $request->get_param( 'path' );

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }
        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        if ( ! file_exists( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Not found.', 'wptd' ) ], 404 );
        }

        if ( $resolved === $root ) {
            return new WP_REST_Response( [ 'error' => __( 'Cannot delete the root folder.', 'wptd' ) ], 400 );
        }

        if ( is_dir( $resolved ) ) {
            $ok = $this->rrmdir( $resolved );
        } else {
            $ok = @unlink( $resolved );
        }

        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => __( 'Failed to delete.', 'wptd' ) ], 500 );
        }

        return new WP_REST_Response( [ 'ok' => true, 'path' => $path ], 200 );
    }

    private function rrmdir( string $dir ): bool {
        $files = array_diff( (array) @scandir( $dir ), [ '.', '..' ] );
        foreach ( $files as $file ) {
            $p = $dir . DIRECTORY_SEPARATOR . $file;
            if ( is_dir( $p ) ) {
                $this->rrmdir( $p );
            } else {
                @unlink( $p );
            }
        }
        return @rmdir( $dir );
    }

    // ── POST /file/create ──────────────────────────────────────────────────────

    public function handle_file_create( WP_REST_Request $request ): WP_REST_Response {
        $type   = $request->get_param( 'type' );
        $slug   = $request->get_param( 'slug' ) ?? '';
        $path   = $request->get_param( 'path' );
        $is_dir = (bool) $request->get_param( 'is_dir' );

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        if ( file_exists( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Already exists.', 'wptd' ) ], 409 );
        }

        if ( $this->is_forbidden( basename( $resolved ) ) ) {
            return new WP_REST_Response( [ 'error' => __( 'This file type is not allowed.', 'wptd' ) ], 403 );
        }

        if ( $is_dir ) {
            $ok = wp_mkdir_p( $resolved );
        } else {
            if ( ! is_dir( dirname( $resolved ) ) ) {
                wp_mkdir_p( dirname( $resolved ) );
            }
            $ok = false !== @file_put_contents( $resolved, '' );
        }

        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => __( 'Failed to create.', 'wptd' ) ], 500 );
        }

        return new WP_REST_Response( [
            'ok'   => true,
            'path' => $path,
            'type' => $is_dir ? 'dir' : 'file',
            'name' => basename( $resolved ),
        ], 201 );
    }

    // ── POST /file/rename ──────────────────────────────────────────────────────

    public function handle_file_rename( WP_REST_Request $request ): WP_REST_Response {
        $type    = $request->get_param( 'type' );
        $slug    = $request->get_param( 'slug' ) ?? '';
        $path    = $request->get_param( 'path' );
        $newName = $request->get_param( 'newName' );

        if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+(\.[a-zA-Z0-9]+)?$/', $newName ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Invalid new name.', 'wptd' ) ], 400 );
        }
        if ( $this->is_forbidden( $newName ) ) {
            return new WP_REST_Response( [ 'error' => __( 'This file type is not allowed.', 'wptd' ) ], 403 );
        }

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }
        if ( ! file_exists( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Not found.', 'wptd' ) ], 404 );
        }

        $target = dirname( $resolved ) . DIRECTORY_SEPARATOR . $newName;
        if ( file_exists( $target ) ) {
            return new WP_REST_Response( [ 'error' => __( 'A file with that name already exists.', 'wptd' ) ], 409 );
        }

        if ( ! @rename( $resolved, $target ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Rename failed.', 'wptd' ) ], 500 );
        }

        $newPath = ltrim( substr( $target, strlen( $root ) ), DIRECTORY_SEPARATOR );

        return new WP_REST_Response( [
            'ok'      => true,
            'oldPath' => $path,
            'newPath' => $newPath,
            'name'    => $newName,
        ], 200 );
    }

    // ── GET /file/download ─────────────────────────────────────────────────────
    //
    // If the resolved path is a file → stream it raw with a sensible
    // Content-Type and a Content-Disposition: attachment header so the
    // browser downloads it directly (no ZIP wrapping).
    //
    // If the resolved path is a directory → build a ZIP on the fly and
    // stream that. The ZIP preserves the folder name as its top-level
    // entry so the user gets a clean archive when extracted.
    public function handle_file_download( WP_REST_Request $request ): never {
        $type = $request->get_param( 'type' );
        $slug = $request->get_param( 'slug' ) ?? '';
        $path = $request->get_param( 'path' ) ?? '';

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            wp_send_json_error(
                [ 'message' => $root->get_error_message() ],
                $root->get_error_data()['status'] ?? 404
            );
        }

        $resolved = $this->resolve_subpath( $root, $path );
        if ( is_wp_error( $resolved ) ) {
            wp_send_json_error(
                [ 'message' => $resolved->get_error_message() ],
                $resolved->get_error_data()['status'] ?? 400
            );
        }

        // Block path traversal beyond root.
        if ( $resolved === $root ) {
            wp_send_json_error(
                [ 'message' => __( 'Cannot download the root folder.', 'wptd' ) ],
                400
            );
        }

        if ( ! file_exists( $resolved ) ) {
            wp_send_json_error(
                [ 'message' => __( 'Not found.', 'wptd' ) ],
                404
            );
        }

        // ── Directory → ZIP on the fly ────────────────────────────────────────
        if ( is_dir( $resolved ) ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                wp_send_json_error(
                    [ 'message' => __( 'ZipArchive not available on this server.', 'wptd' ) ],
                    500
                );
            }

            $folder_name = basename( $resolved );
            $zip_path   = trailingslashit( sys_get_temp_dir() )
                . 'wptd-folder-' . sanitize_file_name( $folder_name ) . '-' . time() . '-' . wp_rand( 1000, 9999 ) . '.zip';

            $zip = new \ZipArchive();
            if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
                wp_send_json_error(
                    [ 'message' => __( 'Could not create ZIP archive.', 'wptd' ) ],
                    500
                );
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $resolved, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $added = 0;
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }
                $real     = $file->getRealPath();
                $relative = $folder_name . '/' . substr( $real, strlen( $resolved ) + 1 );
                $zip->addFile( $real, $relative );
                $added++;
            }

            $zip->close();

            if ( 0 === $added ) {
                // Empty folder — ZipArchive may not have written anything.
                if ( ! file_exists( $zip_path ) ) {
                    // Force-create an empty archive with just the folder entry.
                    $zip2 = new \ZipArchive();
                    $zip2->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
                    $zip2->addEmptyDir( $folder_name );
                    $zip2->close();
                }
            }

            $filename = sanitize_file_name( $folder_name ) . '.zip';

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
            @unlink( $zip_path );
            exit;
        }

        // ── File → stream raw ────────────────────────────────────────────────
        $filename = sanitize_file_name( basename( $resolved ) );
        $size     = filesize( $resolved );

        // Pick a sensible Content-Type. Falls back to application/octet-stream.
        $mime = function_exists( 'mime_content_type' ) ? @mime_content_type( $resolved ) : false;
        if ( ! $mime || $mime === 'directory' || $mime === 'application/x-empty' ) {
            $mime = 'application/octet-stream';
        }

        // Always force download — never let the browser inline-render PHP etc.
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        if ( $size > 0 ) {
            header( 'Content-Length: ' . $size );
        }
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        flush();
        readfile( $resolved ); // phpcs:ignore
        exit;
    }

    // ── GET /search (grep-like) ────────────────────────────────────────────────

    public function handle_search( WP_REST_Request $request ): WP_REST_Response {
        $type           = $request->get_param( 'type' );
        $slug           = $request->get_param( 'slug' ) ?? '';
        $query          = $request->get_param( 'q' );
        $case_sensitive = (bool) $request->get_param( 'caseSensitive' );
        $use_regex      = (bool) $request->get_param( 'regex' );

        if ( '' === trim( $query ) ) {
            return new WP_REST_Response( [ 'error' => __( 'Empty query.', 'wptd' ) ], 400 );
        }

        $root = $this->resolve_root( $type, $slug );
        if ( is_wp_error( $root ) ) {
            return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
        }

        if ( $use_regex ) {
            $pattern = '/' . $query . '/u';
            if ( ! $case_sensitive ) $pattern .= 'i';
            if ( false === @preg_match( $pattern, '' ) ) {
                return new WP_REST_Response( [ 'error' => __( 'Invalid regular expression.', 'wptd' ) ], 400 );
            }
        } else {
            $escaped = preg_quote( $query, '/' );
            $pattern = '/' . $escaped . '/u' . ( $case_sensitive ? '' : 'i' );
        }

        $results       = [];
        $hits          = 0;
        $files_scanned = 0;
        $skip          = $this->skip_dirs( $type );

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'error' => __( 'Could not scan directory.', 'wptd' ) ], 500 );
        }

        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) continue;
            $name = $file->getFilename();
            if ( $this->is_forbidden( $name ) ) continue;
            if ( $file->getSize() > self::MAX_FILE_SIZE ) continue;

            $full = $file->getPathname();
            // Skip directories excluded for this browse type (heavy list for
            // WordPress-root mode, minimal list for a single plugin/theme).
            $skip_pattern = '#[/\\\\](' . implode( '|', array_map( fn( $d ) => preg_quote( $d, '#' ), $skip ) ) . ')[/\\\\]#';
            if ( preg_match( $skip_pattern, $full ) ) continue;

            if ( $files_scanned >= self::MAX_SEARCH_FILES ) break;

            $files_scanned++;

            $contents = @file_get_contents( $full );
            if ( false === $contents ) continue;
            if ( false !== strpos( $contents, "\0" ) ) continue;

            $lines  = preg_split( '/\r\n|\r|\n/', $contents );
            $matches_in_file = [];

            foreach ( $lines as $idx => $line ) {
                if ( preg_match_all( $pattern, $line, $m, PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $m[0] as $occ ) {
                        $matches_in_file[] = [
                            'line'    => $idx + 1,
                            'col'     => $occ[1] + 1,
                            'preview' => mb_substr( $line, 0, 200 ),
                            'match'   => $occ[0],
                        ];
                        $hits++;
                        if ( $hits >= self::MAX_SEARCH_HITS ) break 3;
                    }
                }
            }

            if ( ! empty( $matches_in_file ) ) {
                $rel = ltrim( substr( $full, strlen( $root ) ), DIRECTORY_SEPARATOR );
                $results[] = [
                    'path'     => $rel,
                    'name'     => basename( $full ),
                    'ext'      => strtolower( pathinfo( $full, PATHINFO_EXTENSION ) ),
                    'matches'  => $matches_in_file,
                    'count'    => count( $matches_in_file ),
                ];
            }
        }

        return new WP_REST_Response( [
            'query'        => $query,
            'caseSensitive'=> $case_sensitive,
            'regex'        => $use_regex,
            'filesScanned' => $files_scanned,
            'hits'         => $hits,
            'truncated'    => $hits >= self::MAX_SEARCH_HITS || $files_scanned >= self::MAX_SEARCH_FILES,
            'results'      => $results,
        ], 200 );
    }
}
