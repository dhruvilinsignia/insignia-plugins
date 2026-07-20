<?php
/**
 * Registers the top-level "Insignia DB Cleaner" admin menu and loads the
 * CSS/JS only on that one screen.
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insignia_DBC_Admin_Menu {

	/** @var string Full hook suffix of our page, set once added. */
	private $page_hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . INSIGNIA_DBC_BASENAME, array( $this, 'add_settings_link' ) );
	}

	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( 'Insignia DB Cleaner', 'insignia-db-cleaner' ),
			__( 'DB Cleaner', 'insignia-db-cleaner' ),
			INSIGNIA_DBC_CAPABILITY,
			INSIGNIA_DBC_SLUG,
			array( $this, 'render_page' ),
			'dashicons-shield',
			80
		);
	}

	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=' . INSIGNIA_DBC_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'insignia-db-cleaner' ) . '</a>' );
		return $links;
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'insignia-dbc-admin',
			INSIGNIA_DBC_URL . 'assets/css/insignia-admin.css',
			array(),
			INSIGNIA_DBC_VERSION
		);

		wp_enqueue_script(
			'insignia-dbc-admin',
			INSIGNIA_DBC_URL . 'assets/js/insignia-admin.js',
			array(),
			INSIGNIA_DBC_VERSION,
			true
		);

		wp_localize_script(
			'insignia-dbc-admin',
			'InsigniaDBC',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( Insignia_DBC_Ajax::NONCE_ACTION ),
				'settings' => Insignia_DBC_Settings::get(),
				'groups'   => Insignia_DBC_Item_Registry::get_groups(),
				'i18n'     => array(
					'confirmClean'   => __( 'Delete these items? This cannot be undone.', 'insignia-db-cleaner' ),
					'cleaning'       => __( 'Cleaning…', 'insignia-db-cleaner' ),
					'cleaned'        => __( 'Cleaned', 'insignia-db-cleaner' ),
					'nothingToClean' => __( 'Nothing to clean here.', 'insignia-db-cleaner' ),
					'optimizing'     => __( 'Optimizing…', 'insignia-db-cleaner' ),
					'optimized'      => __( 'Optimized', 'insignia-db-cleaner' ),
					'saved'          => __( 'Settings saved.', 'insignia-db-cleaner' ),
					'error'          => __( 'Something went wrong. Please try again.', 'insignia-db-cleaner' ),
				),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( INSIGNIA_DBC_CAPABILITY ) ) {
			return;
		}
		include INSIGNIA_DBC_PATH . 'includes/admin/views/page-dashboard.php';
	}
}
