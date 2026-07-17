<?php
namespace Trackly\Includes;

/**
 * Cloudflare and reverse proxy IP range sync and validation engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProxyRegistry {

	/**
	 * Register custom weekly cron interval recurrence.
	 */
	public static function add_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS, // 604800 seconds
				'display'  => __( 'Once Weekly', 'trackly' ),
			);
		}
		return $schedules;
	}

	/**
	 * Dynamic weekly Cloudflare IP subnet ranges refresh from Cloudflare APIs.
	 * Fetches, sanitizes, validates, and stores IPv4/IPv6 ranges securely.
	 */
	public static function refresh_cf_ips() {
		// Use a 15-second timeout to prevent blocking thread execution on slow API queries
		$args = array(
			'timeout' => 15,
		);

		$v4 = wp_remote_get( 'https://www.cloudflare.com/ips-v4', $args );
		$v6 = wp_remote_get( 'https://www.cloudflare.com/ips-v6', $args );

		$ips = array();

		if ( ! is_wp_error( $v4 ) && 200 === wp_remote_retrieve_response_code( $v4 ) ) {
			$body = wp_remote_retrieve_body( $v4 );
			$lines = explode( "\n", $body );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && self::is_valid_ip_or_cidr( $line ) ) {
					$ips[] = $line;
				}
			}
		}

		if ( ! is_wp_error( $v6 ) && 200 === wp_remote_retrieve_response_code( $v6 ) ) {
			$body = wp_remote_retrieve_body( $v6 );
			$lines = explode( "\n", $body );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && self::is_valid_ip_or_cidr( $line ) ) {
					$ips[] = $line;
				}
			}
		}

		// Only overwrite option if we received valid IPs to prevent wiping database on temporary connection drop
		if ( ! empty( $ips ) ) {
			update_option( 'trackly_cf_proxies', $ips );
		}
	}

	/**
	 * Validate a line to ensure it is a valid IP or a properly-formed CIDR subnet range.
	 */
	private static function is_valid_ip_or_cidr( $line ) {
		if ( strpos( $line, '/' ) !== false ) {
			list( $ip, $cidr ) = explode( '/', $line, 2 );
			if ( ! is_numeric( $cidr ) ) {
				return false;
			}
			$cidr = intval( $cidr );
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $cidr >= 0 && $cidr <= 32 ) {
				return true;
			} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && $cidr >= 0 && $cidr <= 128 ) {
				return true;
			}
		} else {
			if ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
				return true;
			}
		}
		return false;
	}
}
