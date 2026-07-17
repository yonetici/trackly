<?php
use PHPUnit\Framework\TestCase;
use Trackly\Includes\Service\GoogleAnalyticsService;

/**
 * GoogleAnalyticsServiceTest runs unit and integration checks for GA4 connection.
 */
class GoogleAnalyticsServiceTest extends TestCase {

	private $service;

	protected function setUp(): void {
		// Reset in-memory option/transient state so tests do not leak into each other.
		global $mock_options, $mock_transients;
		$mock_options = array();
		$mock_transients = array();

		// Mock encryption salt
		update_option( 'trackly_secure_salt', str_repeat( 's', 64 ) );
		$this->service = new GoogleAnalyticsService();
	}

	public function test_is_demo_mode_default() {
		update_option( 'trackly_demo_mode', 'yes' );
		$this->assertTrue( $this->service->is_demo_mode() );
	}

	public function test_is_demo_mode_false_when_configured() {
		update_option( 'trackly_demo_mode', 'no' );
		update_option( 'trackly_property_id', '123456789' );

		// Encrypt valid dummy JSON credentials so decryption passes
		$encrypted = $this->service->encrypt_data( '{"private_key": "mysecretkey", "client_email": "myemail"}' );
		update_option( 'trackly_credentials', $encrypted );

		$this->assertFalse( $this->service->is_demo_mode() );
	}

	public function test_connection_state_demo() {
		update_option( 'trackly_demo_mode', 'yes' );
		$this->assertSame( 'demo', $this->service->get_connection_state() );
	}

	public function test_connection_state_misconfigured_when_demo_off_and_no_credentials() {
		update_option( 'trackly_demo_mode', 'no' );
		delete_option( 'trackly_property_id' );
		delete_option( 'trackly_credentials' );
		// is_demo_mode must NOT silently flip to true — it must report the real (unconfigured) state.
		$this->assertFalse( $this->service->is_demo_mode() );
		$this->assertSame( 'misconfigured', $this->service->get_connection_state() );
	}

	public function test_connection_state_connected_when_credentials_present() {
		update_option( 'trackly_demo_mode', 'no' );
		update_option( 'trackly_property_id', '123456789' );
		$encrypted = $this->service->encrypt_data( '{"private_key": "k", "client_email": "e"}' );
		update_option( 'trackly_credentials', $encrypted );
		$this->assertSame( 'connected', $this->service->get_connection_state() );
	}

	public function test_misconfigured_batch_returns_error_not_mock() {
		update_option( 'trackly_demo_mode', 'no' );
		delete_option( 'trackly_property_id' );
		delete_option( 'trackly_credentials' );
		$result = $this->service->batch_run_reports( array( array( 'metrics' => array() ) ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_encryption_roundtrip_with_binary_heavy_payload() {
		// Guards against the old '::' delimiter bug: random binary ciphertext must still decrypt.
		for ( $i = 0; $i < 25; $i++ ) {
			$secret = base64_encode( random_bytes( 64 ) ) . ':: :: ::';
			$encrypted = $this->service->encrypt_data( $secret );
			$this->assertNotEmpty( $encrypted );
			$this->assertSame( $secret, $this->service->decrypt_data( $encrypted ) );
		}
	}

	public function test_demo_channel_and_device_rows_have_dimension_values() {
		update_option( 'trackly_demo_mode', 'yes' );
		$reports = $this->service->batch_run_reports( array(
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ), 'metrics' => array( array( 'name' => 'activeUsers' ) ) ),
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'deviceCategory' ) ), 'metrics' => array( array( 'name' => 'activeUsers' ) ) ),
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'pagePath' ) ), 'metrics' => array( array( 'name' => 'screenPageViews' ) ) ),
		) );

		foreach ( $reports as $report ) {
			$this->assertNotEmpty( $report['rows'] );
			foreach ( $report['rows'] as $row ) {
				// Regression guard: every dimensioned demo row must carry a dimension value,
				// otherwise the dashboard JS throws on row.dimensionValues[0].value.
				$this->assertNotEmpty( $row['dimensionValues'], 'Dimensioned demo row is missing dimensionValues.' );
				$this->assertArrayHasKey( 'value', $row['dimensionValues'][0] );
			}
		}
	}

	public function test_demo_chart_scales_with_date_range() {
		update_option( 'trackly_demo_mode', 'yes' );
		$make = function ( $start ) {
			return array( 'dateRanges' => array( array( 'startDate' => $start, 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'date' ) ), 'metrics' => array( array( 'name' => 'screenPageViews' ), array( 'name' => 'activeUsers' ) ) );
		};
		$seven  = $this->service->get_report( $make( '7daysAgo' ) );
		$thirty = $this->service->get_report( $make( '30daysAgo' ) );
		$this->assertCount( 7, $seven['rows'] );
		$this->assertCount( 30, $thirty['rows'] );
	}

	public function test_encryption_key_is_256_bit() {
		$method = new ReflectionMethod( $this->service, 'get_encryption_key' );
		$method->setAccessible( true );
		$key = $method->invoke( $this->service );
		// Raw AES-256 key must be exactly 32 bytes.
		$this->assertSame( 32, strlen( $key ) );
	}

	public function test_remote_ga_api_integration() {
		// Skip unless secure JSON config environment variable is explicitly provided (Step 7: Integration testing)
		$json_config = getenv( 'TRACKLY_GA_JSON' );
		if ( ! $json_config && defined( 'TRACKLY_GA_JSON' ) ) {
			$json_config = TRACKLY_GA_JSON;
		}

		if ( ! $json_config ) {
			$this->markTestSkipped( 'TRACKLY_GA_JSON not defined. Skipping live Google Analytics API integration test.' );
		}

		// Configure live service credentials from constant/env
		if ( ! defined( 'TRACKLY_GA_JSON' ) ) {
			define( 'TRACKLY_GA_JSON', $json_config );
		}
		update_option( 'trackly_property_id', getenv( 'TRACKLY_GA_PROPERTY_ID' ) ?: '123456789' );

		$report = $this->service->get_report( array(
			'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
			'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
		) );

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertArrayHasKey( 'rowCount', $report );
	}
}
