<?php
/**
 * Main loader class for Gravity Analytics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gravity_Analytics {

	protected $plugin_name;
	protected $version;

	public function __construct() {
		$this->plugin_name = 'gravity-analytics';
		$this->version = GRAVITY_ANALYTICS_VERSION;
	}

	public function run() {
		// Initialize Database and Cron hooks (Loaded via Autoloader)
		Gravity_Analytics_DB::init();

		// Initialize Admin Hooks (Loaded via Autoloader)
		$admin = new Gravity_Analytics_Admin( $this->plugin_name, $this->version );
		$admin->init_hooks();

		// Initialize Public (Frontend) Hooks (Loaded via Autoloader)
		$public = new Gravity_Analytics_Public( $this->plugin_name, $this->version );
		$public->init_hooks();
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_version() {
		return $this->version;
	}
}
