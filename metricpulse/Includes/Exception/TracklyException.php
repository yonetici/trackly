<?php
declare(strict_types=1);

namespace Trackly\Includes\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Exception class for MetricPulse domain-specific exceptions.
 */
class TracklyException extends \Exception {}
