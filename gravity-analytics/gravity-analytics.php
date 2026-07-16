<?php
/**
 * Plugin Name:       Gravity Analytics
 * Plugin URI:        https://gravity.io/analytics
 * Description:       A modern, stunning Google Analytics 4 dashboard and page-level statistics client for WordPress with Heatmaps and AI Insights.
 * Version:           1.0.0
 * Author:            Antigravity Team
 * Author URI:        https://gravity.io
 * License:           GPLv2 or later
 * Text Domain:       gravity-analytics
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'GRAVITY_ANALYTICS_VERSION', '1.0.0' );
define( 'GRAVITY_ANALYTICS_PATH', plugin_dir_path( __FILE__ ) );
define( 'GRAVITY_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );

// 1. PSR-4 style Class Autoloader to remove procedural require_once calls
spl_autoload_register( function ( $class_name ) {
	// Only load classes belonging to this plugin
	if ( strpos( $class_name, 'Gravity_Analytics' ) === 0 ) {
		$class_slug = strtolower( str_replace( '_', '-', $class_name ) );
		$file_name = 'class-' . $class_slug . '.php';

		// Determine module directory based on naming slug
		if ( strpos( $class_slug, 'gravity-analytics-admin' ) === 0 ) {
			$file = GRAVITY_ANALYTICS_PATH . 'admin/' . $file_name;
		} elseif ( strpos( $class_slug, 'gravity-analytics-public' ) === 0 ) {
			$file = GRAVITY_ANALYTICS_PATH . 'public/' . $file_name;
		} else {
			$file = GRAVITY_ANALYTICS_PATH . 'includes/' . $file_name;
		}

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
} );

// Activate / Deactivate hooks
function activate_gravity_analytics() {
	// Trigger DB table creation
	Gravity_Analytics_DB::create_tables();
	Gravity_Analytics_DB::schedule_cleanup();

	// Generate a unique dynamic fallback encryption key if not exists (Enterprise Security)
	if ( ! get_option( 'gravity_analytics_secure_salt' ) ) {
		$secure_key = wp_generate_password( 64, true, true );
		update_option( 'gravity_analytics_secure_salt', $secure_key, 'no' ); // Saved with autoload=no
	}
}
register_activation_hook( __FILE__, 'activate_gravity_analytics' );

function deactivate_gravity_analytics() {
	Gravity_Analytics_DB::unschedule_cleanup();
}
register_deactivation_hook( __FILE__, 'deactivate_gravity_analytics' );

// Run the plugin
function run_gravity_analytics() {
	$plugin = new Gravity_Analytics();
	$plugin->run();
}
run_gravity_analytics();

// Load textdomain for i18n support
function load_gravity_analytics_textdomain() {
	load_plugin_textdomain( 'gravity-analytics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'load_gravity_analytics_textdomain' );
