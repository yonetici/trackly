<?php
/**
 * Bootstrap file for Trackly PHPUnit tests.
 * Mocks necessary WordPress core components to run unit tests in isolation.
 */

// Define WPINC to bypass trackly.php direct access check
define( 'WPINC', true );

// Define ABSPATH safely
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Mock plugin directory helpers required by trackly.php
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

// Mock global variables
global $wpdb;
class wpdb {
	public $prefix = 'wp_';
	public $last_insert = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}
	public function query( $query ) {
		return true;
	}
	public function get_var( $query ) {
		return null;
	}
	public function get_results( $query, $output = 'OBJECT' ) {
		return array();
	}
	public function insert( $table, $data, $format = null ) {
		$this->last_insert = array( 'table' => $table, 'data' => $data );
		return true;
	}
}
$wpdb = new wpdb();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// Mock memory-based options and transients storage
global $mock_options;
$mock_options = array();

global $mock_transients;
$mock_transients = array();

// Mock WordPress Core Functions
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function do_action( $hook, ...$args ) { return; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function register_activation_hook( $file, $callback ) { return true; }
function register_deactivation_hook( $file, $callback ) { return true; }
function wp_clear_scheduled_hook( $hook ) { return true; }
function plugin_basename( $file ) { return basename( $file ); }
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) { return true; }

class WP_Role {
	public function add_cap( $cap, $grant = true ) {}
	public function remove_cap( $cap ) {}
}
function get_role( $role ) {
	return new WP_Role();
}

function get_option( $option, $default = false ) {
	global $mock_options;
	return isset( $mock_options[ $option ] ) ? $mock_options[ $option ] : $default;
}
function update_option( $option, $value, $autoload = null ) {
	global $mock_options;
	$mock_options[ $option ] = $value;
	return true;
}
function delete_option( $option ) {
	global $mock_options;
	unset( $mock_options[ $option ] );
	return true;
}

function get_transient( $transient ) {
	global $mock_transients;
	return isset( $mock_transients[ $transient ] ) ? $mock_transients[ $transient ] : false;
}
function set_transient( $transient, $value, $expiration = 0 ) {
	global $mock_transients;
	$mock_transients[ $transient ] = $value;
	return true;
}
function delete_transient( $transient ) {
	global $mock_transients;
	unset( $mock_transients[ $transient ] );
	return true;
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return str_repeat( 'a', $length );
}

function esc_url_raw( $url ) {
	return $url;
}
function sanitize_text_field( $str ) {
	return strip_tags( $str );
}
function wp_unslash( $str ) {
	return stripslashes( $str );
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function _e( $text, $domain = 'default' ) {
	echo $text;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	return true;
}
function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	return true;
}
function wp_remote_get( $url, $args = array() ) {
	return array();
}
function wp_remote_retrieve_response_code( $response ) {
	return 200;
}
function wp_remote_retrieve_body( $response ) {
	return '';
}

// Load PSR-4 Autoloader
require_once dirname( __DIR__ ) . '/trackly.php';
