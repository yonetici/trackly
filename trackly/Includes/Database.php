<?php
namespace Trackly\Includes;

/**
 * Facade class for Database operations.
 * Delegates actual queries and table modifications to EventRepository.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	/**
	 * Get an instance of EventRepository.
	 */
	private static function get_repository(): \Trackly\Includes\Repository\EventRepository {
		global $wpdb;
		$table_name = $wpdb->prefix . 'trackly_clicks';
		return new \Trackly\Includes\Repository\EventRepository( $wpdb, $table_name );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'trackly_daily_cleanup', array( __CLASS__, 'daily_cleanup' ) );
		add_action( 'trackly_weekly_ip_refresh', array( 'Trackly\Includes\ProxyRegistry', 'refresh_cf_ips' ) );
		add_filter( 'cron_schedules', array( 'Trackly\Includes\ProxyRegistry', 'add_cron_intervals' ) );
	}

	/**
	 * Get Custom Table Name.
	 */
	public static function get_table_name(): string {
		return self::get_repository()->get_table_name();
	}

	/**
	 * Create Custom Tables.
	 */
	public static function create_tables(): void {
		self::get_repository()->create_tables();
	}

	/**
	 * Log Clicks.
	 */
	public static function log_click( array $data ): bool {
		return self::get_repository()->log_click( $data );
	}

	/**
	 * Retrieve Clicks.
	 */
	public static function get_clicks_for_page( string $page_url ): array {
		return self::get_repository()->get_clicks_for_page( $page_url );
	}

	/**
	 * Schedule the 30-day cleanup and weekly IP refresh cron jobs.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'trackly_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'trackly_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'trackly_weekly_ip_refresh' ) ) {
			wp_schedule_event( time() + 60, 'weekly', 'trackly_weekly_ip_refresh' );
		}
	}

	/**
	 * Unschedule cleanup and IP refresh cron jobs.
	 */
	public static function unschedule_cleanup(): void {
		$timestamp = wp_next_scheduled( 'trackly_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'trackly_daily_cleanup' );
		}
		$weekly_timestamp = wp_next_scheduled( 'trackly_weekly_ip_refresh' );
		if ( $weekly_timestamp ) {
			wp_unschedule_event( $weekly_timestamp, 'trackly_weekly_ip_refresh' );
		}
	}

	/**
	 * Clean up click data older than 30 days.
	 */
	public static function daily_cleanup(): void {
		self::get_repository()->daily_cleanup();
	}
}
