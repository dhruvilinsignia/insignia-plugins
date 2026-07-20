<?php
/**
 * Core plugin bootstrap — singleton pattern.
 *
 * @package WPTD
 */

namespace WPTD;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    private static ?Plugin $instance = null;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private array $services = [];

    public function __get( string $key ): mixed {
        return $this->services[ $key ] ?? null;
    }

    private function init(): void {
        $this->load_textdomain();
        $this->register_services();
        $this->register_hooks();
    }

    private function load_textdomain(): void {
        add_action( 'init', function () {
            load_plugin_textdomain(
                'wptd',
                false,
                dirname( WPTD_BASENAME ) . '/languages'
            );
        } );
    }

    private function register_services(): void {
        $this->services['assets']   = new Assets();
        $this->services['api']      = new Api\Download_Endpoint();
        $this->services['files']    = new Api\File_Endpoint();
        $this->services['lint']     = new Api\Lint_Endpoint();
        $this->services['revisions']= new Api\Revision_Endpoint();
        $this->services['admin']    = new Admin\Menu();
        $this->services['download'] = new Download\Zipper();
    }

    private function register_hooks(): void {
        foreach ( $this->services as $service ) {
            if ( $service instanceof Contracts\Hookable ) {
                $service->register_hooks();
            }
        }

        register_activation_hook( WPTD_FILE, [ $this, 'on_activate' ] );
        register_deactivation_hook( WPTD_FILE, [ $this, 'on_deactivate' ] );
    }

    public function on_activate(): void {
        update_option( 'wptd_version', WPTD_VERSION );
        // Default settings.
        if ( false === get_option( 'wptd_settings', false ) ) {
            add_option( 'wptd_settings', [
                'default_view'    => 'grid',
                'default_sort'    => 'name',
                'theme_mode'      => 'auto',
                'show_inactive'   => true,
                'remember_layout' => true,
            ] );
        }
    }

    public function on_deactivate(): void {
        // Soft cleanup: keep settings & history in case the user re-activates.
    }
}
