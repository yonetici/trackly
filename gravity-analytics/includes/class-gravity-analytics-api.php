<?php
/**
 * GA4 API Client and Mock Data Engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gravity_Analytics_API {

	private static $token_transient_key = 'g_token'; // Shortened transient key (previously gravity_analytics_access_token)

	/**
	 * Secure encryption key generation using site salts and unique dynamic secure salt option.
	 */
	private static function get_encryption_key() {
		$key = '';
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key .= SECURE_AUTH_KEY;
		}
		if ( defined( 'NONCE_KEY' ) ) {
			$key .= NONCE_KEY;
		}
		
		// Load dynamic fallback secure salt generated during activation
		$fallback_salt = get_option( 'gravity_analytics_secure_salt' );
		if ( $fallback_salt ) {
			$key .= $fallback_salt;
		} else {
			$key .= 'gravity-analytics-fallback-key-19028';
		}
		
		return substr( hash( 'sha256', $key ), 0, 32 ); // 32 bytes for AES-256-CBC
	}

	/**
	 * Encrypt credentials before saving to DB.
	 */
	public static function encrypt_data( $data ) {
		if ( empty( $data ) ) {
			return '';
		}
		$key = self::get_encryption_key();
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv = openssl_random_pseudo_bytes( $iv_len );
		$encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, 0, $iv );
		return base64_encode( $encrypted . '::' . $iv );
	}

	/**
	 * Decrypt credentials from DB.
	 */
	public static function decrypt_data( $data ) {
		if ( empty( $data ) ) {
			return '';
		}
		$key = self::get_encryption_key();
		$decoded = base64_decode( $data );
		if ( false === $decoded ) {
			return '';
		}
		$parts = explode( '::', $decoded, 2 );
		if ( count( $parts ) === 2 ) {
			$encrypted = $parts[0];
			$iv = $parts[1];
			return openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );
		}
		return '';
	}

	/**
	 * Check if Demo Mode is enabled.
	 */
	public static function is_demo_mode() {
		$demo = get_option( 'gravity_analytics_demo_mode', 'yes' );
		if ( $demo === 'yes' ) {
			return true;
		}

		$credentials_encrypted = get_option( 'gravity_analytics_credentials' );
		$property_id = get_option( 'gravity_analytics_property_id' );
		if ( empty( $credentials_encrypted ) || empty( $property_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get GA4 Access Token using Service Account JSON.
	 */
	private static function get_access_token() {
		$token = get_transient( self::$token_transient_key );
		if ( $token ) {
			return $token;
		}

		$credentials_encrypted = get_option( 'gravity_analytics_credentials' );
		if ( empty( $credentials_encrypted ) ) {
			return new WP_Error( 'no_credentials', __( 'Google Service Account credentials missing.', 'gravity-analytics' ) );
		}

		$credentials_json = self::decrypt_data( $credentials_encrypted );
		$creds = json_decode( $credentials_json, true );
		if ( ! is_array( $creds ) || empty( $creds['private_key'] ) || empty( $creds['client_email'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid Google Service Account JSON structure.', 'gravity-analytics' ) );
		}

		// Generate JWT
		$header = self::base64url_encode( json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );

		$now = time();
		$payload = self::base64url_encode( json_encode( array(
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
			return new WP_Error( 'invalid_private_key', __( 'Could not parse private key.', 'gravity-analytics' ) );
		}

		if ( ! openssl_sign( $header_payload, $signature, $pkey, 'SHA256' ) ) {
			return new WP_Error( 'signature_failed', __( 'Failed to sign JWT.', 'gravity-analytics' ) );
		}

		$jwt = $header_payload . '.' . self::base64url_encode( $signature );

		// Fetch access token
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'body' => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'token_error', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown token error.', 'gravity-analytics' ) );
		}

		$access_token = $body['access_token'];
		set_transient( self::$token_transient_key, $access_token, 55 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Run a Batch of Reports to optimize quota usage and execution time.
	 */
	public static function batch_run_reports( $requests ) {
		// Shortened transient prefix: 'g_b_' (4 chars) + md5 (32 chars) = 36 chars. Safely under 45-character old limit.
		$cache_key = 'g_b_' . md5( json_encode( $requests ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( self::is_demo_mode() ) {
			$mock_reports = array();
			foreach ( $requests as $req ) {
				$mock_reports[] = self::generate_mock_report( $req );
			}
			$result = array( 'reports' => $mock_reports );
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}

		$property_id = get_option( 'gravity_analytics_property_id' );
		if ( empty( $property_id ) ) {
			return new WP_Error( 'no_property', __( 'Google Analytics GA4 Property ID missing.', 'gravity-analytics' ) );
		}

		$access_token = self::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $property_id . ':batchRunReports';

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( array( 'requests' => $requests ) ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			$error = json_decode( $body, true );
			$msg = isset( $error['error']['message'] ) ? $error['error']['message'] : __( 'GA4 Batch API Error', 'gravity-analytics' );
			return new WP_Error( 'api_error', $msg );
		}

		$result = json_decode( $body, true );
		set_transient( $cache_key, $result, 1 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Query Google Analytics 4 Data API (kept for single queries if needed).
	 */
	public static function get_report( $request_body ) {
		if ( self::is_demo_mode() ) {
			return self::generate_mock_report( $request_body );
		}

		$property_id = get_option( 'gravity_analytics_property_id' );
		if ( empty( $property_id ) ) {
			return new WP_Error( 'no_property', __( 'Google Analytics GA4 Property ID missing.', 'gravity-analytics' ) );
		}

		$access_token = self::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $property_id . ':runReport';

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $request_body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			$error = json_decode( $body, true );
			$msg = isset( $error['error']['message'] ) ? $error['error']['message'] : __( 'GA4 API Error', 'gravity-analytics' );
			return new WP_Error( 'api_error', $msg );
		}

		return json_decode( $body, true );
	}

	/**
	 * Generates realistic mock reporting data for UI validation.
	 */
	private static function generate_mock_report( $request_body ) {
		$dimensions = isset( $request_body['dimensions'] ) ? wp_list_pluck( $request_body['dimensions'], 'name' ) : array();
		$metrics = isset( $request_body['metrics'] ) ? wp_list_pluck( $request_body['metrics'], 'name' ) : array();
		$date_ranges = isset( $request_body['dateRanges'] ) ? $request_body['dateRanges'] : array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) );

		$start_str = $date_ranges[0]['startDate'];
		$days = ($start_str === '30daysAgo') ? 30 : 7;

		$rows = array();

		// 1. Time series report (e.g. dimension = date)
		if ( in_array( 'date', $dimensions ) ) {
			for ( $i = $days; $i >= 1; $i-- ) {
				$date = date( 'Ymd', strtotime( "-$i days" ) );
				$base_users = 100 + sin( $i / 2 ) * 40 + rand( 0, 30 );
				$base_views = $base_users * ( 1.4 + rand( 0, 4 ) / 10 );
				$bounce_rate = 0.45 + cos( $i ) * 0.05 + rand( 0, 10 ) / 100;
				$avg_duration = 90 + rand( -20, 40 );

				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $date ) ),
					'metricValues'    => array(
						array( 'value' => (string) round( $base_views ) ),
						array( 'value' => (string) round( $base_users ) ),
						array( 'value' => (string) round( $bounce_rate, 4 ) ),
						array( 'value' => (string) round( $avg_duration ) ),
					),
				);
			}
		}
		// 2. Traffic Source report
		elseif ( in_array( 'sessionDefaultChannelGroup', $dimensions ) || in_array( 'sessionSourceMedium', $dimensions ) ) {
			$sources = array(
				'Organic Search' => array( 'users' => 520, 'views' => 840, 'bounce' => 0.41, 'dur' => 110 ),
				'Direct'         => array( 'users' => 280, 'views' => 390, 'bounce' => 0.48, 'dur' => 95 ),
				'Social'         => array( 'users' => 120, 'views' => 190, 'bounce' => 0.65, 'dur' => 50 ),
				'Referral'       => array( 'users' => 60,  'views' => 110, 'bounce' => 0.35, 'dur' => 140 ),
				'Email'          => array( 'users' => 40,  'views' => 75,  'bounce' => 0.30, 'dur' => 180 ),
			);
			foreach ( $sources as $src => $data ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $src ) ),
					'metricValues'    => array(
						array( 'value' => (string) $data['views'] ),
						array( 'value' => (string) $data['users'] ),
						array( 'value' => (string) $data['bounce'] ),
						array( 'value' => (string) $data['dur'] ),
					),
				);
			}
		}
		// 3. Device Category report
		elseif ( in_array( 'deviceCategory', $dimensions ) ) {
			$devices = array(
				'desktop' => array( 'users' => 650, 'views' => 1020, 'bounce' => 0.40, 'dur' => 120 ),
				'mobile'  => array( 'users' => 320, 'views' => 450,  'bounce' => 0.58, 'dur' => 75 ),
				'tablet'  => array( 'users' => 50,  'views' => 80,   'bounce' => 0.50, 'dur' => 90 ),
			);
			foreach ( $devices as $dev => $data ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $dev ) ),
					'metricValues'    => array(
						array( 'value' => (string) $data['views'] ),
						array( 'value' => (string) $data['users'] ),
						array( 'value' => (string) $data['bounce'] ),
						array( 'value' => (string) $data['dur'] ),
					),
				);
			}
		}
		// 4. Page Path / URL level stats
		elseif ( in_array( 'pagePath', $dimensions ) ) {
			$has_filter = false;
			$filtered_url = '';
			
			if ( isset( $request_body['dimensionFilter']['filter']['stringFilter']['value'] ) ) {
				$has_filter = true;
				$filtered_url = $request_body['dimensionFilter']['filter']['stringFilter']['value'];
			}

			if ( $has_filter ) {
				$hash = md5( $filtered_url );
				$seed_num = hexdec( substr( $hash, 0, 6 ) );
				$views = 120 + ($seed_num % 730);
				$users = round( $views / ( 1.2 + (($seed_num % 5) / 10) ) );
				$bounce = 0.38 + (($seed_num % 26) / 100);
				$duration = 45 + ($seed_num % 165);

				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $filtered_url ) ),
					'metricValues'    => array(
						array( 'value' => (string) $views ),
						array( 'value' => (string) $users ),
						array( 'value' => (string) $bounce ),
						array( 'value' => (string) $duration ),
					),
				);
			} else {
				$pages = array(
					'/'                     => array( 'views' => 1250, 'users' => 900, 'bounce' => 0.42, 'dur' => 105 ),
					'/blog/'                => array( 'views' => 840,  'users' => 600, 'bounce' => 0.50, 'dur' => 85 ),
					'/about/'               => array( 'views' => 320,  'users' => 250, 'bounce' => 0.35, 'dur' => 110 ),
					'/contact/'             => array( 'views' => 150,  'users' => 110, 'bounce' => 0.28, 'dur' => 130 ),
					'/services/web-design/' => array( 'views' => 450,  'users' => 320, 'bounce' => 0.45, 'dur' => 160 ),
				);
				foreach ( $pages as $path => $data ) {
					$rows[] = array(
						'dimensionValues' => array( array( 'value' => $path ) ),
						'metricValues'    => array(
							array( 'value' => (string) $data['views'] ),
							array( 'value' => (string) $data['users'] ),
							array( 'value' => (string) $data['bounce'] ),
							array( 'value' => (string) $data['dur'] ),
						),
					);
				}
			}
		}
		// 5. Default overall aggregate summary
		else {
			$rows[] = array(
				'metricValues' => array(
					array( 'value' => '3010' ),
					array( 'value' => '2180' ),
					array( 'value' => '0.4632' ),
					array( 'value' => '102' ),
				),
			);
		}

		$metric_headers = array();
		foreach ( $metrics as $m ) {
			$metric_headers[] = array( 'name' => $m, 'type' => 'TYPE_INTEGER' );
		}

		$dimension_headers = array();
		foreach ( $dimensions as $d ) {
			$dimension_headers[] = array( 'name' => $d );
		}

		return array(
			'dimensionHeaders' => $dimension_headers,
			'metricHeaders'    => $metric_headers,
			'rows'             => $rows,
			'rowCount'         => count( $rows ),
			'kind'             => 'analyticsData#runReport',
		);
	}

	/**
	 * Get Real-Time active visitors count.
	 */
	public static function get_realtime_users() {
		if ( self::is_demo_mode() ) {
			return rand( 8, 28 );
		}

		$property_id = get_option( 'gravity_analytics_property_id' );
		if ( empty( $property_id ) ) {
			return 0;
		}

		$access_token = self::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return 0;
		}

		$url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $property_id . ':runRealtimeReport';

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( array(
				'metrics' => array(
					array( 'name' => 'activeUsers' )
				)
			) ),
			'timeout' => 5,
		) );

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['rows'][0]['metricValues'][0]['value'] ) ) {
			return intval( $body['rows'][0]['metricValues'][0]['value'] );
		}

		return 0;
	}

	/**
	 * Base64 URL friendly encoder helper.
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
