<?php
/**
 * Trackly Uninstall Template.
 * Fired when the plugin is deleted from the WordPress admin.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Unschedule cron hooks (Step 3: Zero Footprint Policy)
wp_clear_scheduled_hook( 'trackly_daily_cleanup' );
wp_clear_scheduled_hook( 'trackly_weekly_ip_refresh' );

// 2. Delete Options & Locks
$options = array(
	'trackly_demo_mode',
	'trackly_property_id',
	'trackly_credentials',
	'trackly_sampling_rate',
	'trackly_secure_salt',
	'trackly_custom_events',
	'trackly_require_consent',
	'trackly_cf_proxies',
	'trackly_cleanup_lock',
	'trackly_ip_refresh_lock',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

global $wpdb;
// Clean up all options matching trackly_
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'trackly_%'" );

// 3. Clear Transients
delete_transient( 'trackly_access_token' );
delete_transient( 'trackly_realtime_cache' );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_trackly_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_trackly_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_trackly_b_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_trackly_b_%'" );

// 4. Drop Custom Table
$table_name = $wpdb->prefix . 'trackly_clicks';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
