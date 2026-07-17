<?php
declare(strict_types=1);

namespace Trackly\Includes\Service;

use Trackly\Includes\Exception\TracklyException;
use WP_Error;

/**
 * GoogleAnalyticsService handles GA4 OAuth2 tokens, report executions, and credentials encryption.
 * Implemented using strict types and modern PHP constructor promotion.
 */
class GoogleAnalyticsService {

	const TOKEN_KEY = 'trackly_access_token';
	const BATCH_PREFIX = 'trackly_b_';
	const REALTIME_KEY = 'trackly_realtime_cache';

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Flush all cached GA4 API transient data.
	 */
	public function flush_cache(): void {
		delete_transient( self::TOKEN_KEY );
		delete_transient( self::REALTIME_KEY );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . self::BATCH_PREFIX . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_' . self::BATCH_PREFIX . '%' ) );
	}

	/**
	 * Secure encryption key generation using site salts and unique dynamic secure salt option.
	 */
	private function get_encryption_key(): string {
		$key = '';
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key .= SECURE_AUTH_KEY;
		}
		if ( defined( 'NONCE_KEY' ) ) {
			$key .= NONCE_KEY;
		}
		
		$fallback_salt = get_option( 'trackly_secure_salt' );
		if ( $fallback_salt ) {
			$key .= $fallback_salt;
		} else {
			$key .= 'trackly-fallback-key-19028';
		}
		
		return substr( hash( 'sha256', $key ), 0, 32 );
	}

	/**
	 * Encrypt credentials before saving to DB using AES-256-GCM.
	 */
	public function encrypt_data( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$iv = openssl_random_pseudo_bytes( 12 );
		$tag = '';
		$encrypted = openssl_encrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
			return '';
		}
		return base64_encode( $encrypted . '::' . $iv . '::' . $tag );
	}

	/**
	 * Decrypt credentials from DB using AES-256-GCM.
	 */
	public function decrypt_data( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$decoded = base64_decode( $data );
		if ( false === $decoded ) {
			return '';
		}
		$parts = explode( '::', $decoded, 3 );
		if ( count( $parts ) === 3 ) {
			$encrypted = $parts[0];
			$iv = $parts[1];
			$tag = $parts[2];
			$decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return ( false !== $decrypted ) ? $decrypted : '';
		}
		return '';
	}

	/**
	 * Get Google Service Account JSON from multiple secure configuration sources.
	 * Checks constant, environment variables, filesystem secrets, and option table.
	 */
	private function get_credentials_json(): string {
		if ( defined( 'TRACKLY_GA_JSON' ) && ! empty( TRACKLY_GA_JSON ) ) {
			return TRACKLY_GA_JSON;
		}

		if ( isset( $_SERVER['TRACKLY_GA_JSON'] ) && ! empty( $_SERVER['TRACKLY_GA_JSON'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return wp_unslash( $_SERVER['TRACKLY_GA_JSON'] );
		}

		$env_val = getenv( 'TRACKLY_GA_JSON' );
		if ( $env_val && ! empty( $env_val ) ) {
			return $env_val;
		}

		if ( file_exists( '/etc/secrets/trackly.json' ) ) {
			$file_val = file_get_contents( '/etc/secrets/trackly.json' );
			if ( $file_val ) {
				return $file_val;
			}
		}

		$credentials_encrypted = get_option( 'trackly_credentials' );
		if ( ! empty( $credentials_encrypted ) ) {
			return $this->decrypt_data( (string) $credentials_encrypted );
		}

		return '';
	}

	/**
	 * Check if Demo Mode is active.
	 */
	public function is_demo_mode(): bool {
		$demo = get_option( 'trackly_demo_mode', 'yes' );
		if ( $demo === 'yes' ) {
			return true;
		}

		$credentials_json = $this->get_credentials_json();
		$property_id = get_option( 'trackly_property_id' );
		if ( empty( $credentials_json ) || empty( $property_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get GA4 Access Token using Service Account JSON.
	 */
	private function get_access_token(): string|WP_Error {
		$token = get_transient( self::TOKEN_KEY );
		if ( $token ) {
			return $token;
		}

		$credentials_json = $this->get_credentials_json();
		if ( empty( $credentials_json ) ) {
			return new WP_Error( 'no_credentials', __( 'Google Service Account credentials missing.', 'trackly' ) );
		}

		$creds = json_decode( $credentials_json, true );
		if ( ! is_array( $creds ) || empty( $creds['private_key'] ) || empty( $creds['client_email'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid Google Service Account JSON structure.', 'trackly' ) );
		}

		// Generate JWT
		$header = $this->base64url_encode( (string) json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );

		$now = time();
		$payload = $this->base64url_encode( (string) json_encode( array(
			'iss'   => $creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			'iat'   => $now,
		) ) );

		$header_payload = $header . '.' . $payload;

		$signature = '';
		$pkey = openssl_pkey_get_private( $creds['private_key'] );
		if ( ! $pkey ) {
			return new WP_Error( 'invalid_private_key', __( 'Could not parse private key.', 'trackly' ) );
		}

		if ( ! openssl_sign( $header_payload, $signature, $pkey, 'SHA256' ) ) {
			return new WP_Error( 'signature_failed', __( 'Failed to sign JWT.', 'trackly' ) );
		}

		$jwt = $header_payload . '.' . $this->base64url_encode( $signature );

		// Fetch access token with 15 second timeout limit
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'token_error', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown token error.', 'trackly' ) );
		}

		$access_token = $body['access_token'];
		set_transient( self::TOKEN_KEY, $access_token, 55 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Run a Batch of Reports to optimize quota usage and execution time.
	 */
	public function batch_run_reports( array $requests ): array|WP_Error {
		$cache_key = self::BATCH_PREFIX . md5( (string) json_encode( $requests ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		if ( $this->is_demo_mode() ) {
			$mock_reports = array();
			foreach ( $requests as $req ) {
				$mock_reports[] = $this->generate_mock_report( $req );
			}
			return $mock_reports;
		}

		$property_id = get_option( 'trackly_property_id', '' );
		if ( empty( $property_id ) ) {
			return new WP_Error( 'no_property', __( 'Google Analytics Property ID is missing.', 'trackly' ) );
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:batchRunReports";

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( array( 'requests' => $requests ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$body = json_decode( $body_raw, true );

		if ( 200 !== $status ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Failed to query GA4 Data API.', 'trackly' );
			return new WP_Error( 'ga_api_error', $msg );
		}

		$reports = isset( $body['reports'] ) ? $body['reports'] : array();
		set_transient( $cache_key, $reports, 10 * MINUTE_IN_SECONDS ); // Cache reports for 10 mins

		return $reports;
	}

	/**
	 * Retrieve a single report query.
	 */
	public function get_report( array $request_payload ): array|WP_Error {
		$batch = $this->batch_run_reports( array( $request_payload ) );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		return isset( $batch[0] ) ? $batch[0] : array();
	}

	/**
	 * Retrieve current active visitor count (Realtime query).
	 */
	public function get_realtime_users(): int {
		$cached = get_transient( self::REALTIME_KEY );
		if ( false !== $cached ) {
			return intval( $cached );
		}

		if ( $this->is_demo_mode() ) {
			$mock_users = wp_rand( 12, 35 );
			set_transient( self::REALTIME_KEY, $mock_users, 20 ); // Realtime cache 20 seconds
			return $mock_users;
		}

		$property_id = get_option( 'trackly_property_id', '' );
		if ( empty( $property_id ) ) {
			return 0;
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return 0;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runRealtimeReport";

		$payload = array(
			'metrics' => array( array( 'name' => 'activeUsers' ) ),
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$active_users = 0;
		if ( isset( $body['rows'][0]['metricValues'][0]['value'] ) ) {
			$active_users = intval( $body['rows'][0]['metricValues'][0]['value'] );
		}

		set_transient( self::REALTIME_KEY, $active_users, 20 );
		return $active_users;
	}

	/**
	 * Safe base64 URL encoder.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Fallback mockup GA4 data generator for demo/sandbox environments.
	 */
	private function generate_mock_report( array $request ): array {
		$dimensions = isset( $request['dimensions'] ) ? $request['dimensions'] : array();
		$metrics = isset( $request['metrics'] ) ? $request['metrics'] : array();

		$is_pagePath = false;
		$is_sessionSource = false;
		$is_device = false;
		$is_date = false;

		foreach ( $dimensions as $dim ) {
			if ( $dim['name'] === 'pagePath' ) {
				$is_pagePath = true;
			}
			if ( $dim['name'] === 'sessionSource' ) {
				$is_sessionSource = true;
			}
			if ( $dim['name'] === 'deviceCategory' ) {
				$is_device = true;
			}
			if ( $dim['name'] === 'date' ) {
				$is_date = true;
			}
		}

		$rows = array();

		if ( $is_date ) {
			// Mock past days sequence
			for ( $i = 7; $i >= 1; $i-- ) {
				$date_str = gmdate( 'Ymd', strtotime( "-{$i} days" ) );
				$views = wp_rand( 1500, 3000 );
				$users = wp_rand( 800, 1500 );
				
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $date_str ) ),
					'metricValues'    => array(
						array( 'value' => (string) $views ),
						array( 'value' => (string) $users ),
					),
				);
			}
		} elseif ( $is_pagePath ) {
			$pages = array( '/', '/about', '/blog', '/contact', '/services' );
			foreach ( $pages as $page ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $page ) ),
					'metricValues'    => array(
						array( 'value' => (string) wp_rand( 100, 1500 ) ),
						array( 'value' => (string) wp_rand( 50, 800 ) ),
						array( 'value' => (string) ( wp_rand( 20, 65 ) / 100 ) ),
						array( 'value' => (string) wp_rand( 30, 240 ) ),
					),
				);
			}
		} elseif ( $is_sessionSource ) {
			$sources = array( 'google', 'direct', 'bing', 'facebook', 'newsletter' );
			foreach ( $sources as $src ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $src ) ),
					'metricValues'    => array( array( 'value' => (string) wp_rand( 200, 2000 ) ) ),
				);
			}
		} elseif ( $is_device ) {
			$devices = array( 'desktop', 'mobile', 'tablet' );
			foreach ( $devices as $dev ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $dev ) ),
					'metricValues'    => array( array( 'value' => (string) wp_rand( 200, 3000 ) ) ),
				);
			}
		} else {
			// Mock dashboard totals row
			$rows[] = array(
				'dimensionValues' => array(),
				'metricValues'    => array(
					array( 'value' => '12480' ), // screenPageViews
					array( 'value' => '6850' ),  // activeUsers
					array( 'value' => '0.384' ), // bounceRate (38.4%)
					array( 'value' => '165' ),   // averageSessionDuration (165s)
				),
			);
		}

		return array(
			'dimensionHeaders' => array_map( function( $d ) { return array( 'name' => $d['name'] ); }, $dimensions ),
			'metricHeaders'    => array_map( function( $m ) { return array( 'name' => $m['name'] ); }, $metrics ),
			'rows'             => $rows,
			'rowCount'         => count( $rows ),
		);
	}
}
