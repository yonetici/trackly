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
	const CACHE_VERSION_KEY = 'trackly_cache_ver';

	// Sentinel returned by get_realtime_users() when the value could not be retrieved (distinct from a genuine 0).
	const REALTIME_UNAVAILABLE = -1;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Current report-cache generation number. Bumping it (see flush_cache) invalidates every
	 * previously stored report key at once, which works even when an external object cache
	 * (Redis/Memcached) is active and transients never touch the options table.
	 */
	private function cache_version(): int {
		return (int) get_option( self::CACHE_VERSION_KEY, 1 );
	}

	/**
	 * Flush all cached GA4 API data by rotating the cache generation and clearing hot keys.
	 */
	public function flush_cache(): void {
		delete_transient( self::TOKEN_KEY );
		delete_transient( self::REALTIME_KEY );

		// Rotate the generation so all versioned batch keys become unreachable regardless of backend.
		update_option( self::CACHE_VERSION_KEY, $this->cache_version() + 1 );

		// Best-effort cleanup of any DB-stored batch transients (no-op under external object cache).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . self::BATCH_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . self::BATCH_PREFIX ) . '%' ) );
	}

	// AES-256-GCM binary layout constants (lengths are fixed by the cipher).
	const GCM_IV_LEN = 12;
	const GCM_TAG_LEN = 16;

	/**
	 * Derive a 32-byte (256-bit) AES key from the site salts and a persisted per-site secret salt.
	 *
	 * Uses the raw binary sha256 digest (not the 64-char hex string) so the full 256 bits of
	 * entropy actually reach AES-256. The per-site salt is generated on demand if it is missing
	 * so there is never a shared, hard-coded fallback key across installs.
	 */
	private function get_encryption_key(): string {
		$key = '';
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key .= SECURE_AUTH_KEY;
		}
		if ( defined( 'NONCE_KEY' ) ) {
			$key .= NONCE_KEY;
		}

		$secure_salt = get_option( 'trackly_secure_salt' );
		if ( empty( $secure_salt ) ) {
			$secure_salt = wp_generate_password( 64, true, true );
			update_option( 'trackly_secure_salt', $secure_salt, 'no' );
		}
		$key .= $secure_salt;

		// Raw 32-byte digest => true AES-256 key length.
		return hash( 'sha256', $key, true );
	}

	/**
	 * Encrypt credentials before saving to DB using AES-256-GCM.
	 * Stored layout (base64): [12-byte IV][16-byte tag][ciphertext].
	 */
	public function encrypt_data( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$iv = openssl_random_pseudo_bytes( self::GCM_IV_LEN );
		$tag = '';
		$encrypted = openssl_encrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
			return '';
		}
		// Concatenate fixed-length fields; never split on a delimiter that could occur in binary output.
		return base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Decrypt credentials from DB using AES-256-GCM.
	 */
	public function decrypt_data( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$decoded = base64_decode( $data, true );
		if ( false === $decoded || strlen( $decoded ) <= ( self::GCM_IV_LEN + self::GCM_TAG_LEN ) ) {
			return '';
		}

		$iv = substr( $decoded, 0, self::GCM_IV_LEN );
		$tag = substr( $decoded, self::GCM_IV_LEN, self::GCM_TAG_LEN );
		$encrypted = substr( $decoded, self::GCM_IV_LEN + self::GCM_TAG_LEN );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Get Google Service Account JSON from multiple secure configuration sources.
	 * Checks constant, environment variables, filesystem secrets, and option table.
	 */
	private function get_credentials_json(): string {
		if ( defined( 'TRACKLY_GA_JSON' ) && ! empty( TRACKLY_GA_JSON ) ) {
			return TRACKLY_GA_JSON;
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
	 * Whether Demo (mock) Mode is explicitly enabled.
	 *
	 * This intentionally reflects ONLY the user's setting. It must not silently flip to true
	 * when credentials are missing/broken — doing so would present random mock numbers as if
	 * they were real analytics. Callers use get_connection_state()/is_configured() to tell a
	 * misconfiguration apart from an intentional demo.
	 */
	public function is_demo_mode(): bool {
		return get_option( 'trackly_demo_mode', 'yes' ) === 'yes';
	}

	/**
	 * Whether real GA4 credentials are present and structurally usable.
	 */
	public function is_configured(): bool {
		$property_id = get_option( 'trackly_property_id' );
		if ( empty( $property_id ) ) {
			return false;
		}
		$credentials_json = $this->get_credentials_json();
		if ( empty( $credentials_json ) ) {
			return false;
		}
		$creds = json_decode( $credentials_json, true );
		return is_array( $creds ) && ! empty( $creds['private_key'] ) && ! empty( $creds['client_email'] );
	}

	/**
	 * Resolve the connection state for UI/badges: 'demo', 'connected', or 'misconfigured'.
	 */
	public function get_connection_state(): string {
		if ( $this->is_demo_mode() ) {
			return 'demo';
		}
		return $this->is_configured() ? 'connected' : 'misconfigured';
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
			return new WP_Error( 'no_credentials', __( 'Google Service Account credentials missing.', 'metricpulse' ) );
		}

		$creds = json_decode( $credentials_json, true );
		if ( ! is_array( $creds ) || empty( $creds['private_key'] ) || empty( $creds['client_email'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid Google Service Account JSON structure.', 'metricpulse' ) );
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
			// Backdate iat slightly to tolerate minor server clock skew against Google's servers.
			'iat'   => $now - 30,
		) ) );

		$header_payload = $header . '.' . $payload;

		$signature = '';
		$pkey = openssl_pkey_get_private( $creds['private_key'] );
		if ( ! $pkey ) {
			return new WP_Error( 'invalid_private_key', __( 'Could not parse private key.', 'metricpulse' ) );
		}

		if ( ! openssl_sign( $header_payload, $signature, $pkey, 'SHA256' ) ) {
			return new WP_Error( 'signature_failed', __( 'Failed to sign JWT.', 'metricpulse' ) );
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
			return new WP_Error( 'token_error', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown token error.', 'metricpulse' ) );
		}

		$access_token = $body['access_token'];
		set_transient( self::TOKEN_KEY, $access_token, 55 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Run a Batch of Reports to optimize quota usage and execution time.
	 */
	public function batch_run_reports( array $requests ): array|WP_Error {
		// Demo Mode: return deterministic mock data (explicit user choice).
		if ( $this->is_demo_mode() ) {
			$mock_reports = array();
			foreach ( $requests as $req ) {
				$mock_reports[] = $this->generate_mock_report( $req );
			}
			return $mock_reports;
		}

		// Not demo but not usable => surface an error instead of silently faking data.
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Google Analytics is not connected. Add a valid Property ID and Service Account JSON, or enable Demo Mode.', 'metricpulse' ) );
		}

		// Order-independent cache key (sort recursively) tied to the current cache generation.
		$normalized = $this->normalize_for_cache_key( $requests );
		$cache_key = self::BATCH_PREFIX . $this->cache_version() . '_' . md5( (string) wp_json_encode( $normalized ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$property_id = get_option( 'trackly_property_id', '' );
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:batchRunReports";

		$response = $this->post_ga_request( $url, array( 'requests' => $requests ), (string) $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return new WP_Error( 'ga_api_error', $this->format_api_error( $status, $body ) );
		}

		$reports = isset( $body['reports'] ) ? $body['reports'] : array();
		set_transient( $cache_key, $reports, 10 * MINUTE_IN_SECONDS ); // Cache reports for 10 mins

		return $reports;
	}

	/**
	 * POST a JSON payload to the GA4 Data API with a single retry on transient (429/503) errors.
	 */
	private function post_ga_request( string $url, array $payload, string $token ) {
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 429 === $status || 503 === $status ) {
			// Brief backoff, then one retry for rate-limit / temporary unavailability.
			sleep( 1 );
			$retry = wp_remote_post( $url, $args );
			if ( ! is_wp_error( $retry ) ) {
				return $retry;
			}
		}

		return $response;
	}

	/**
	 * Build a human-readable message that distinguishes quota, auth, and generic API failures.
	 */
	private function format_api_error( int $status, $body ): string {
		$detail = ( is_array( $body ) && isset( $body['error']['message'] ) ) ? $body['error']['message'] : '';

		if ( 429 === $status ) {
			return __( 'Google Analytics API quota exceeded. Please try again later.', 'metricpulse' ) . ( $detail ? ' (' . $detail . ')' : '' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'Access denied by Google Analytics. Confirm the service account is added as a Viewer on the property.', 'metricpulse' ) . ( $detail ? ' (' . $detail . ')' : '' );
		}
		if ( $detail ) {
			return $detail;
		}
		return __( 'Failed to query GA4 Data API.', 'metricpulse' );
	}

	/**
	 * Recursively sort array keys so logically identical requests map to the same cache key.
	 */
	private function normalize_for_cache_key( array $data ): array {
		ksort( $data );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->normalize_for_cache_key( $value );
			}
		}
		return $data;
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

		// Not connected or auth failed: report "unavailable" and DO NOT cache it as 0,
		// so a real zero is never confused with an outage and the next poll retries.
		if ( ! $this->is_configured() ) {
			return self::REALTIME_UNAVAILABLE;
		}

		$property_id = get_option( 'trackly_property_id', '' );
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return self::REALTIME_UNAVAILABLE;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runRealtimeReport";

		$response = wp_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'metrics' => array( array( 'name' => 'activeUsers' ) ) ) ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::REALTIME_UNAVAILABLE;
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
	 * Rich mock GA4 report generator for Demo Mode / sandbox environments.
	 *
	 * Produces realistic, self-consistent data for every dimension the dashboard requests
	 * (date trend, page paths, channel groups, device categories) plus a totals row, and
	 * scales the daily trend / totals to the requested reporting window (7 vs 30 days).
	 */
	private function generate_mock_report( array $request ): array {
		$dimensions = isset( $request['dimensions'] ) ? $request['dimensions'] : array();
		$metrics    = isset( $request['metrics'] ) ? $request['metrics'] : array();

		$dim_names = array_map(
			function ( $d ) { return isset( $d['name'] ) ? $d['name'] : ''; },
			$dimensions
		);
		$has_dim = function ( $name ) use ( $dim_names ) {
			return in_array( $name, $dim_names, true );
		};

		// Derive the window length from a "NdaysAgo" start date so 7-day and 30-day views differ.
		$days = 7;
		if ( isset( $request['dateRanges'][0]['startDate'] )
			&& preg_match( '/(\d+)daysAgo/', (string) $request['dateRanges'][0]['startDate'], $m ) ) {
			$days = max( 1, (int) $m[1] );
		}

		$rows = array();

		if ( $has_dim( 'date' ) ) {
			// Daily trend: mild upward drift across the window with weekend dips.
			for ( $i = $days; $i >= 1; $i-- ) {
				$ts             = strtotime( "-{$i} days" );
				$is_weekend     = in_array( (int) gmdate( 'N', $ts ), array( 6, 7 ), true );
				$progress       = ( $days - $i ) / max( 1, $days );
				$weekend_factor = $is_weekend ? 0.65 : 1.0;
				$views          = (int) round( ( 1800 + $progress * 700 ) * $weekend_factor ) + wp_rand( -120, 120 );
				$views          = max( 50, $views );
				$users          = max( 20, (int) round( $views * 0.63 ) + wp_rand( -30, 30 ) );
				$rows[]         = array(
					'dimensionValues' => array( array( 'value' => gmdate( 'Ymd', $ts ) ) ),
					'metricValues'    => array(
						array( 'value' => (string) $views ),
						array( 'value' => (string) $users ),
						array( 'value' => (string) ( wp_rand( 34, 52 ) / 100 ) ),
						array( 'value' => (string) wp_rand( 95, 230 ) ),
					),
				);
			}
		} elseif ( $has_dim( 'pagePath' ) ) {
			// Descending by views to match the real orderBys the dashboard requests.
			$pages = array(
				'/'                     => 5200,
				'/blog'                 => 3100,
				'/products'             => 2400,
				'/about'                => 1500,
				'/contact'              => 1100,
				'/pricing'              => 980,
				'/blog/getting-started' => 760,
				'/services'             => 640,
			);
			foreach ( $pages as $path => $views ) {
				$views  = max( 20, $views + wp_rand( -80, 120 ) );
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $path ) ),
					'metricValues'    => array(
						array( 'value' => (string) $views ),
						array( 'value' => (string) (int) round( $views * ( wp_rand( 55, 72 ) / 100 ) ) ),
						array( 'value' => (string) ( wp_rand( 28, 66 ) / 100 ) ),
						array( 'value' => (string) wp_rand( 45, 260 ) ),
					),
				);
			}
		} elseif ( $has_dim( 'sessionDefaultChannelGroup' ) || $has_dim( 'sessionSource' ) ) {
			$channels = array(
				'Organic Search' => 3400,
				'Direct'         => 2200,
				'Organic Social' => 1500,
				'Referral'       => 950,
				'Paid Search'    => 780,
				'Email'          => 640,
			);
			foreach ( $channels as $label => $users ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $label ) ),
					'metricValues'    => array( array( 'value' => (string) max( 20, $users + wp_rand( -100, 150 ) ) ) ),
				);
			}
		} elseif ( $has_dim( 'deviceCategory' ) ) {
			$devices = array(
				'desktop' => 4200,
				'mobile'  => 3600,
				'tablet'  => 620,
			);
			foreach ( $devices as $dev => $users ) {
				$rows[] = array(
					'dimensionValues' => array( array( 'value' => $dev ) ),
					'metricValues'    => array( array( 'value' => (string) max( 20, $users + wp_rand( -150, 150 ) ) ) ),
				);
			}
		} else {
			// Totals row, scaled to the window length.
			$views  = max( 500, (int) round( $days * 2100 ) + wp_rand( -500, 500 ) );
			$users  = (int) round( $views * 0.55 );
			$rows[] = array(
				'dimensionValues' => array(),
				'metricValues'    => array(
					array( 'value' => (string) $views ),                       // screenPageViews
					array( 'value' => (string) $users ),                       // activeUsers
					array( 'value' => (string) ( wp_rand( 36, 46 ) / 100 ) ),  // bounceRate
					array( 'value' => (string) wp_rand( 150, 210 ) ),          // averageSessionDuration
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
