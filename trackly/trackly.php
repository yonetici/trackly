<?php
/**
 * Plugin Name:       Trackly
 * Plugin URI:        https://trackly.io
 * Description:       A modern, stunning Google Analytics 4 dashboard and page-level statistics client for WordPress with Heatmaps and AI Insights.
 * Version:           1.0.0
 * Author:            Trackly Team
 * Author URI:        https://trackly.io
 * License:           GPLv2 or later
 * Text Domain:       trackly
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'TRACKLY_VERSION', '1.0.0' );
define( 'TRACKLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRACKLY_URL', plugin_dir_url( __FILE__ ) );

// 1. PSR-4 style Class Autoloader to support namespace class loading
spl_autoload_register( function ( $class ) {
	$prefix = 'Trackly\\';
	$base_dir = TRACKLY_PATH;

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Activate / Deactivate hooks
function activate_trackly() {
	// Trigger DB table creation
	Trackly\Includes\Database::create_tables();
	Trackly\Includes\Database::schedule_cleanup();

	// Generate a unique dynamic fallback encryption key if not exists (Enterprise Security)
	if ( ! get_option( 'trackly_secure_salt' ) ) {
		$secure_key = wp_generate_password( 64, true, true );
		update_option( 'trackly_secure_salt', $secure_key, 'no' ); // Saved with autoload=no
	}
}
register_activation_hook( __FILE__, 'activate_trackly' );

function deactivate_trackly() {
	Trackly\Includes\Database::unschedule_cleanup();
}
register_deactivation_hook( __FILE__, 'deactivate_trackly' );

// Run the plugin
function run_trackly() {
	$plugin = new Trackly\Includes\Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'run_trackly' );

