<?php
use PHPUnit\Framework\TestCase;
use Trackly\Includes\ProxyRegistry;

/**
 * Unit tests for Cloudflare IP/CIDR validation (the highest-risk hand-rolled parsing code).
 */
class ProxyRegistryTest extends TestCase {

	private function is_valid( string $line ): bool {
		$method = new ReflectionMethod( ProxyRegistry::class, 'is_valid_ip_or_cidr' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $line );
	}

	public function test_valid_ipv4() {
		$this->assertTrue( $this->is_valid( '173.245.48.1' ) );
	}

	public function test_valid_ipv4_cidr() {
		$this->assertTrue( $this->is_valid( '173.245.48.0/20' ) );
	}

	public function test_valid_ipv6_cidr() {
		$this->assertTrue( $this->is_valid( '2400:cb00::/32' ) );
	}

	public function test_rejects_out_of_range_ipv4_cidr() {
		$this->assertFalse( $this->is_valid( '173.245.48.0/33' ) );
	}

	public function test_rejects_non_numeric_cidr() {
		$this->assertFalse( $this->is_valid( '173.245.48.0/abc' ) );
	}

	public function test_rejects_garbage() {
		$this->assertFalse( $this->is_valid( 'not-an-ip' ) );
		$this->assertFalse( $this->is_valid( '999.999.999.999' ) );
	}

	public function test_registers_weekly_interval() {
		$schedules = ProxyRegistry::add_cron_intervals( array() );
		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertSame( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
	}
}
