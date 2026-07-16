<?php
/**
 * Gravity Analytics Uninstall Template.
 * Fired when the plugin is deleted from the WordPress admin.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Delete Options
$options = array(
	'gravity_analytics_demo_mode',
	'gravity_analytics_property_id',
	'gravity_analytics_credentials',
	'gravity_analytics_custom_events',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// 2. Clear Transients
delete_transient( 'gravity_analytics_access_token' );
// Any other transient keys we might generate for reports
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gravity_analytics_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_gravity_analytics_%'" );

// 3. Drop Custom Table
$table_name = $wpdb->prefix . 'gravity_clicks';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
