<?php
/**
 * MetricPulse Uninstall Handler.
 * Fired when the plugin is deleted from the WordPress admin.
 *
 * Data is only removed for sites that explicitly opted in via the
 * "Delete all data on uninstall" setting. Multisite installs are handled per-site.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all MetricPulse data for the current site, but only if the site opted in.
 */
function trackly_uninstall_site() {
	global $wpdb;

	// Cron hooks are site-scoped; always clear them so no orphaned schedules remain.
	wp_clear_scheduled_hook( 'trackly_daily_cleanup' );
	wp_clear_scheduled_hook( 'trackly_weekly_ip_refresh' );

	// Respect the user's choice: preserve data unless deletion was explicitly enabled.
	if ( 'yes' !== get_option( 'trackly_delete_data', 'no' ) ) {
		return;
	}

	$trackly_options = array(
		'trackly_demo_mode',
		'trackly_property_id',
		'trackly_credentials',
		'trackly_sampling_rate',
		'trackly_secure_salt',
		'trackly_custom_events',
		'trackly_require_consent',
		'trackly_delete_data',
		'trackly_cf_proxies',
		'trackly_cleanup_lock',
		'trackly_ip_refresh_lock',
		'trackly_db_version',
		'trackly_cache_ver',
	);
	foreach ( $trackly_options as $trackly_option ) {
		delete_option( $trackly_option );
	}

	// Catch any remaining prefixed options (e.g. per-IP rate-limit transients).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'trackly\_%'" );

	// Clear transients.
	delete_transient( 'trackly_access_token' );
	delete_transient( 'trackly_realtime_cache' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_trackly\_%'" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_trackly\_%'" );

	// Drop the custom table for this site (table name uses the site's prefix).
	$trackly_table_name = $wpdb->prefix . 'trackly_clicks';
	if ( preg_match( '/^[a-zA-Z0-9_]+$/', $trackly_table_name ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS $trackly_table_name" );
	}
}

if ( is_multisite() ) {
	// Iterate every site so no sub-site is left with orphaned tables/options.
	$trackly_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $trackly_site_ids as $trackly_site_id ) {
		switch_to_blog( $trackly_site_id );
		trackly_uninstall_site();
		restore_current_blog();
	}
} else {
	trackly_uninstall_site();
}
