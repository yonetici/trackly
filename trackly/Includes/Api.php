<?php
namespace Trackly\Includes;

/**
 * Facade class for Google Analytics API integration.
 * Delegates report queries, authentication token fetches, and cryptography to GoogleAnalyticsService.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api {

	/**
	 * Get an instance of GoogleAnalyticsService.
	 */
	private static function get_service(): \Trackly\Includes\Service\GoogleAnalyticsService {
		return new \Trackly\Includes\Service\GoogleAnalyticsService();
	}

	/**
	 * Flush all cached GA4 API transient data.
	 */
	public static function flush_cache(): void {
		self::get_service()->flush_cache();
	}

	/**
	 * Encrypt credentials before saving to DB using AES-256-GCM.
	 */
	public static function encrypt_data( string $data ): string {
		return self::get_service()->encrypt_data( $data );
	}

	/**
	 * Decrypt credentials from DB using AES-256-GCM.
	 */
	public static function decrypt_data( string $data ): string {
		return self::get_service()->decrypt_data( $data );
	}

	/**
	 * Check if Demo Mode is active.
	 */
	public static function is_demo_mode(): bool {
		return self::get_service()->is_demo_mode();
	}

	/**
	 * Run a Batch of Reports.
	 */
	public static function batch_run_reports( array $requests ) {
		return self::get_service()->batch_run_reports( $requests );
	}

	/**
	 * Retrieve a single report query.
	 */
	public static function get_report( array $request_payload ) {
		return self::get_service()->get_report( $request_payload );
	}

	/**
	 * Retrieve current active visitor count (Realtime query).
	 */
	public static function get_realtime_users(): int {
		return self::get_service()->get_realtime_users();
	}
}
