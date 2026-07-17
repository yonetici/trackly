<?php
/**
 * Plugin Name:       MetricPulse
 * Plugin URI:        https://www.ridvanbilgin.com/2026/07/metricpulse-wordpress-ga4-analytics-plugin.html
 * Description:       A modern Google Analytics 4 dashboard and page-level statistics client for WordPress with local click heatmaps and statistical anomaly insights.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Rıdvan Bilgin
 * Author URI:        https://ridvanbilgin.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       metricpulse
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'METRICPULSE_VERSION', '1.0.0' );
define( 'METRICPULSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'METRICPULSE_URL', plugin_dir_url( __FILE__ ) );

// 1. PSR-4 Class Loader (Supports Composer autoloader, defaults to fallback PSR-4 registered autoloader)
if ( file_exists( METRICPULSE_PATH . 'vendor/autoload.php' ) ) {
	require_once METRICPULSE_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register( function ( $class ) {
		$prefix = 'MetricPulse\\';
		$base_dir = METRICPULSE_PATH;

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
}

function metricpulse_activate() {
	// Dynamically register the weekly interval filter so wp_schedule_event can recognize it on activation (Step 1: Cron registration order)
	add_filter( 'cron_schedules', array( 'MetricPulse\Includes\ProxyRegistry', 'add_cron_intervals' ) );

	// Trigger DB table creation
	MetricPulse\Includes\Database::create_tables();
	MetricPulse\Includes\Database::schedule_cleanup();

	// Generate a unique dynamic fallback encryption key if not exists (Enterprise Security)
	if ( ! get_option( 'metricpulse_secure_salt' ) ) {
		$secure_key = wp_generate_password( 64, true, true );
		update_option( 'metricpulse_secure_salt', $secure_key, 'no' ); // Saved with autoload=no
	}

	// Grant custom capabilities for dashboards access control (Step 4: Capabilities control)
	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->add_cap( 'metricpulse_view_dashboard' );
	}
	$editor_role = get_role( 'editor' );
	if ( $editor_role ) {
		$editor_role->add_cap( 'metricpulse_view_dashboard' );
	}
}
register_activation_hook( __FILE__, 'metricpulse_activate' );

function metricpulse_deactivate() {
	MetricPulse\Includes\Database::unschedule_cleanup();

	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->remove_cap( 'metricpulse_view_dashboard' );
	}
	$editor_role = get_role( 'editor' );
	if ( $editor_role ) {
		$editor_role->remove_cap( 'metricpulse_view_dashboard' );
	}
}
register_deactivation_hook( __FILE__, 'metricpulse_deactivate' );

// Run the plugin
function metricpulse_run() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain( 'metricpulse', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// One-time rebrand migration (trackly_* -> metricpulse_*) for existing installs.
	MetricPulse\Includes\Migration::maybe_migrate();

	$plugin = new MetricPulse\Includes\Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'metricpulse_run' );

