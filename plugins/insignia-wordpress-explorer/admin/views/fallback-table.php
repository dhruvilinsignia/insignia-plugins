<?php
/**
 * PHP fallback table — shown when the React build hasn't been compiled.
 * Reuses the same nonce-secured direct download approach from the URL.
 *
 * @package WPTD
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_plugins' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$all_plugins = get_plugins();
$all_themes  = wp_get_themes();

/**
 * Build a nonce-secured download URL.
 */
$make_url = function ( string $type, string $slug ): string {
    return wp_nonce_url(
        add_query_arg(
            [
                'wptd_download' => rawurlencode( $slug ),
                'wptd_type'     => $type,
            ],
            admin_url( 'tools.php?page=' . \WPTD\Admin\Menu::PAGE_SLUG )
        ),
        'wptd_download_action',
        'wptd_nonce'
    );
};
?>

<style>
.wptd-table { border-collapse: collapse; width: 100%; margin-bottom: 40px; }
.wptd-table th, .wptd-table td { border: 1px solid #ddd; padding: 8px 12px; }
.wptd-table th { background: #f0f0f1; text-align: left; }
.wptd-table tr:nth-child(even) { background: #fafafa; }
.wptd-badge { display:inline-block; padding:2px 7px; border-radius:9px; font-size:11px; font-weight:600; }
.wptd-badge.active { background:#d1fae5; color:#065f46; }
.wptd-badge.inactive { background:#fee2e2; color:#991b1b; }
.wptd-btn { display:inline-block; padding:4px 12px; background:#2271b1; color:#fff;
            border-radius:3px; text-decoration:none; font-size:13px; }
.wptd-btn:hover { background:#135e96; color:#fff; }
</style>

<!-- ── Plugins ── -->
<h2><?php esc_html_e( 'Installed Plugins', 'wptd' ); ?></h2>
<table class="wptd-table widefat">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Name', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Version', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Status', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Download', 'wptd' ); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $all_plugins as $plugin_file => $plugin_data ) :
        $parts  = explode( '/', $plugin_file );
        $slug   = count( $parts ) > 1 ? $parts[0] : pathinfo( $parts[0], PATHINFO_FILENAME );
        $active = is_plugin_active( $plugin_file );
    ?>
        <tr>
            <td>
                <strong><?php echo esc_html( $plugin_data['Name'] ); ?></strong><br>
                <small><?php echo esc_html( $slug ); ?></small>
            </td>
            <td><?php echo esc_html( $plugin_data['Version'] ); ?></td>
            <td>
                <span class="wptd-badge <?php echo $active ? 'active' : 'inactive'; ?>">
                    <?php echo $active ? esc_html__( 'Active', 'wptd' ) : esc_html__( 'Inactive', 'wptd' ); ?>
                </span>
            </td>
            <td>
                <a class="wptd-btn" href="<?php echo esc_url( $make_url( 'plugin', $slug ) ); ?>">
                    ⬇ <?php esc_html_e( 'Download ZIP', 'wptd' ); ?>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- ── Themes ── -->
<h2><?php esc_html_e( 'Installed Themes', 'wptd' ); ?></h2>
<table class="wptd-table widefat">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Name', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Version', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Status', 'wptd' ); ?></th>
            <th><?php esc_html_e( 'Download', 'wptd' ); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $all_themes as $slug => $theme ) :
        $active = ( get_stylesheet() === $slug );
    ?>
        <tr>
            <td>
                <strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong><br>
                <small><?php echo esc_html( $slug ); ?></small>
            </td>
            <td><?php echo esc_html( $theme->get( 'Version' ) ); ?></td>
            <td>
                <span class="wptd-badge <?php echo $active ? 'active' : 'inactive'; ?>">
                    <?php echo $active ? esc_html__( 'Active', 'wptd' ) : esc_html__( 'Inactive', 'wptd' ); ?>
                </span>
            </td>
            <td>
                <a class="wptd-btn" href="<?php echo esc_url( $make_url( 'theme', $slug ) ); ?>">
                    ⬇ <?php esc_html_e( 'Download ZIP', 'wptd' ); ?>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
// Also handle the actual download request when React isn't present.
add_action( 'admin_init', function () {
    if ( empty( $_GET['wptd_download'] ) || empty( $_GET['wptd_type'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized.', 'wptd' ) );
    }
    check_admin_referer( 'wptd_download_action', 'wptd_nonce' );

    $type   = sanitize_key( $_GET['wptd_type'] );
    $slug   = sanitize_file_name( rawurldecode( $_GET['wptd_download'] ) );
    $zipper = new \WPTD\Download\Zipper();

    $zip_path = ( 'theme' === $type )
        ? $zipper->zip_theme( $slug )
        : $zipper->zip_plugin( $slug );

    if ( is_wp_error( $zip_path ) ) {
        wp_die( esc_html( $zip_path->get_error_message() ) );
    }

    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $slug ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'Pragma: no-cache' );
    while ( ob_get_level() ) { ob_end_clean(); }
    readfile( $zip_path ); // phpcs:ignore
    unlink( $zip_path );
    exit;
} );
