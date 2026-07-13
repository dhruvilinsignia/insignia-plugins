<?php
/**
 * Uninstall handler for Insignia PageSpeed Dashboard.
 *
 * Runs when the plugin is DELETED from the WordPress admin (Plugins > Delete).
 * Removes all options and transients created by the plugin so no data is left behind.
 *
 * @package InsigniaPageSpeedDashboard
 */

// Safety check: WordPress must trigger this file, never a direct request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// 1. Remove plugin options
// -------------------------------------------------------------------------
$options_to_delete = array(
	'wpsd_api_key',       // Google PageSpeed API key stored by the plugin.
);

foreach ( $options_to_delete as $option ) {
	delete_option( $option );

	// Also clean up multisite (site-wide) options if running on a network.
	if ( is_multisite() ) {
		delete_site_option( $option );
	}
}

// -------------------------------------------------------------------------
// 2. Remove all transients (cached PageSpeed results)
//    Transients are stored as options with the prefix _transient_ and
//    _transient_timeout_. We match the wpsd_cache_* pattern used in WPSD_API.
// -------------------------------------------------------------------------
global $wpdb;

// Delete all transients whose option_name starts with _transient_wpsd_cache_
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_wpsd\_cache\_%'
	    OR option_name LIKE '_transient_timeout_wpsd\_cache\_%'"
);

// On multisite, also clean site-wide transients.
if ( is_multisite() ) {
	$wpdb->query(
		"DELETE FROM {$wpdb->sitemeta}
		 WHERE meta_key LIKE '_site_transient_wpsd\_cache\_%'
		    OR meta_key LIKE '_site_transient_timeout_wpsd\_cache\_%'"
	);
}
