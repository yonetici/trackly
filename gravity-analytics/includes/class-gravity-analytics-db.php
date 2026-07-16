<?php
/**
 * Database and table management class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gravity_Analytics_DB {

	public static function init() {
		add_action( 'gravity_analytics_daily_cleanup', array( __CLASS__, 'daily_cleanup' ) );
	}

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gravity_clicks';
	}

	/**
	 * Create the custom table for click heatmap tracking.
	 * Includes v1.1 to v1.2 migration protection to prevent SQL Insert failures.
	 */
	public static function create_tables() {
		global $wpdb;
		$table_name = self::get_table_name();

		// Check if old columns exist (v1.1 migration protection)
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) ) {
			$column_check = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'screen_width'" );
			if ( ! empty( $column_check ) ) {
				// Rebuild the telemetry table if upgrading schema from v1.1
				$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
			}
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			page_url varchar(255) NOT NULL,
			element_tag varchar(50) NOT NULL,
			element_selector varchar(255) NOT NULL,
			click_x_pct float NOT NULL,
			click_y_pct float NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY page_url (page_url(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a click into the database.
	 */
	public static function log_click( $data ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->insert(
			$table_name,
			array(
				'page_url'         => esc_url_raw( $data['page_url'] ),
				'element_tag'      => sanitize_text_field( $data['element_tag'] ),
				'element_selector' => sanitize_text_field( $data['element_selector'] ),
				'click_x_pct'      => floatval( $data['click_x_pct'] ),
				'click_y_pct'      => floatval( $data['click_y_pct'] ),
			),
			array( '%s', '%s', '%s', '%f', '%f' )
		);
	}

	/**
	 * Fetch clicks for a specific page URL.
	 */
	public static function get_clicks_for_page( $page_url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$clean_url = esc_url_raw( $page_url );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT click_x_pct, click_y_pct, element_selector FROM $table_name WHERE page_url = %s ORDER BY created_at DESC LIMIT 1000",
				$clean_url
			),
			ARRAY_A
		);
	}

	/**
	 * Schedule the 30-day cleanup cron job.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'gravity_analytics_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'gravity_analytics_daily_cleanup' );
		}
	}

	/**
	 * Unschedule cleanup cron job.
	 */
	public static function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( 'gravity_analytics_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'gravity_analytics_daily_cleanup' );
		}
	}

	/**
	 * Clean up click data older than 30 days.
	 */
	public static function daily_cleanup() {
		global $wpdb;
		$table_name = self::get_table_name();
		
		$wpdb->query( "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
	}
}
