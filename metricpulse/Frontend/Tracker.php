<?php
namespace Trackly\Frontend;

use Trackly\Includes\Api;
use Trackly\Includes\Database;

/**
 * Public Front-End hooks and rendering handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tracker {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Cache-busting asset version: the file's modification time, falling back to the plugin version.
	 */
	private function asset_version( $relative ) {
		$file = TRACKLY_PATH . $relative;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		return $mtime ? (string) $mtime : $this->version;
	}

	public function init_hooks() {
		// Enqueue scripts & styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

		// Flag pages viewed by dashboard users as non-cacheable EARLY, before output starts,
		// so page-cache plugins (which check DONOTCACHEPAGE during generation) actually honor it.
		add_action( 'template_redirect', array( $this, 'maybe_disable_cache_for_admins' ) );

		// Render Floating Analytics Bar in footer
		add_action( 'wp_footer', array( $this, 'render_floating_stats_bar' ) );

		// Inject GA4 custom event bindings in the footer (deferred; keeps <head> lean).
		add_action( 'wp_footer', array( $this, 'inject_custom_ga4_events' ) );
	}

	/**
	 * Disable full-page caching only for users who can see the live admin overlay.
	 */
	public function maybe_disable_cache_for_admins() {
		if ( ! current_user_can( 'trackly_view_dashboard' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Enqueue Frontend Assets.
	 */
	public function enqueue_public_assets() {
		// Detect exact canonical path/query structure
		global $wp;
		$current_url = home_url( add_query_arg( array(), $wp->request ) );
		$sampling_rate = get_option( 'trackly_sampling_rate', '100' );
		// Default to strict opt-in ('yes') to match the registered setting default and the GDPR branding.
		$require_consent = get_option( 'trackly_require_consent', 'yes' ) === 'yes';

		// 1. Zero-Footprint vanilla tracking engine loaded dynamically for all visitors (Minified)
		wp_enqueue_script( $this->plugin_name . '-tracker-js', TRACKLY_URL . 'Public/js/trackly-tracker.min.js', array(), $this->asset_version( 'Public/js/trackly-tracker.min.js' ), true );
		// No nonce: the /record-click endpoint authorizes anonymous writes by same-origin + rate limit,
		// which (unlike a cached nonce) survives full-page caching. See Admin::check_public_click_permissions().
		$tracker_data = array(
			'rest_url'        => esc_url_raw( rest_url( 'trackly/v1' ) ),
			'page_url'        => $current_url,
			'sampling_rate'   => intval( $sampling_rate ), // Passes rate (e.g. 10, 25, 50, 100)
			'require_consent' => $require_consent,
		);
		wp_add_inline_script( $this->plugin_name . '-tracker-js', 'const tracklyTrackerData = ' . wp_json_encode( $tracker_data ) . ';', 'before' );

		// 2. Load heavy admin panel JS/CSS ONLY for users with dashboard view capabilities (Core Web Vitals Optimisation, Minified)
		if ( current_user_can( 'trackly_view_dashboard' ) || current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( $this->plugin_name . '-public-css', TRACKLY_URL . 'Public/css/trackly-public.min.css', array(), $this->asset_version( 'Public/css/trackly-public.min.css' ) );
			wp_enqueue_script( $this->plugin_name . '-public-js', TRACKLY_URL . 'Public/js/trackly-public.min.js', array( 'jquery' ), $this->asset_version( 'Public/js/trackly-public.min.js' ), true );

			$public_data = array(
				'rest_url'   => esc_url_raw( rest_url( 'trackly/v1' ) ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				'page_url'   => $current_url,
				'is_admin'   => 1,
				'is_demo'    => \Trackly\Includes\Api::is_demo_mode() ? 1 : 0,
				'admin_url'  => esc_url( admin_url( 'admin.php?page=' . $this->plugin_name ) ),
			);
			wp_add_inline_script( $this->plugin_name . '-public-js', 'const tracklyPublicData = ' . wp_json_encode( $public_data ) . ';', 'before' );
		}
	}

	/**
	 * Render the gorgeous glassmorphism overlay bar in the footer for administrators.
	 */
	public function render_floating_stats_bar() {
		if ( ! current_user_can( 'trackly_view_dashboard' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Cache exclusion is handled earlier on template_redirect (see maybe_disable_cache_for_admins).

		$is_demo = \Trackly\Includes\Api::is_demo_mode();

		global $wp;
		$current_path = wp_parse_url( home_url( add_query_arg( array(), $wp->request ) ), PHP_URL_PATH );
		if ( empty( $current_path ) ) {
			$current_path = '/';
		}

		?>
		<div id="trackly-stats-bar-wrapper">
			<!-- Floating Toggle Button -->
			<button id="trackly-stats-toggle-btn" title="<?php esc_attr_e( 'MetricPulse', 'metricpulse' ); ?>">
				<span class="dashicons dashicons-chart-area"></span>
			</button>

			<!-- Main Panel -->
			<div id="trackly-stats-panel">
				<!-- Panel Header -->
				<div class="trackly-panel-header">
					<div class="trackly-panel-logo">
						<span class="dashicons dashicons-chart-area"></span>
						<h4><?php esc_html_e( 'MetricPulse', 'metricpulse' ); ?></h4>
						<?php if ( $is_demo ) : ?>
							<span class="trackly-demo-tag"><?php esc_html_e( 'DEMO', 'metricpulse' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="trackly-panel-controls">
						<button id="trackly-panel-minimize-btn" title="<?php esc_attr_e( 'Hide', 'metricpulse' ); ?>">
							<span class="dashicons dashicons-minus"></span>
						</button>
					</div>
				</div>

				<!-- Tabs -->
				<div class="trackly-panel-tabs">
					<button class="trackly-panel-tab active" data-tab="stats"><?php esc_html_e( 'Statistics', 'metricpulse' ); ?></button>
					<button class="trackly-panel-tab" data-tab="heatmap"><?php esc_html_e( 'Click Heatmap', 'metricpulse' ); ?></button>
					<button class="trackly-panel-tab" data-tab="builder"><?php esc_html_e( 'Event Builder', 'metricpulse' ); ?></button>
					<button class="trackly-panel-tab" data-tab="ai"><?php esc_html_e( 'Predictive Insights', 'metricpulse' ); ?></button>
				</div>

				<!-- Stats Tab Content -->
				<div class="trackly-panel-tab-content active" id="trackly-tab-stats">
					<p class="trackly-url-indicator"><?php esc_html_e( 'This Page:', 'metricpulse' ); ?> <code><?php echo esc_html( $current_path ); ?></code></p>
					
					<div class="trackly-panel-metrics-grid">
						<div class="trackly-panel-metric-card">
							<span class="label"><?php esc_html_e( 'Pageviews', 'metricpulse' ); ?></span>
							<h3 id="trackly-p-views">--</h3>
						</div>
						<div class="trackly-panel-metric-card">
							<span class="label"><?php esc_html_e( 'Users', 'metricpulse' ); ?></span>
							<h3 id="trackly-p-users">--</h3>
						</div>
						<div class="trackly-panel-metric-card">
							<span class="label"><?php esc_html_e( 'Bounce', 'metricpulse' ); ?></span>
							<h3 id="trackly-p-bounce">--</h3>
						</div>
						<div class="trackly-panel-metric-card">
							<span class="label"><?php esc_html_e( 'Avg. Duration', 'metricpulse' ); ?></span>
							<h3 id="trackly-p-duration">--</h3>
						</div>
					</div>

					<div class="trackly-panel-info-box">
						<span class="dashicons dashicons-info"></span>
						<p><?php esc_html_e( 'Data reflects the average of the last 7 days. Update interval: 1 hour.', 'metricpulse' ); ?></p>
					</div>
				</div>

				<!-- Heatmap Tab Content -->
				<div class="trackly-panel-tab-content" id="trackly-tab-heatmap">
					<h5><?php esc_html_e( 'Local Click Heatmap', 'metricpulse' ); ?></h5>
					<p><?php esc_html_e( 'Visually track the click density of elements on this page.', 'metricpulse' ); ?></p>
					
					<div class="trackly-action-buttons">
						<button id="trackly-toggle-heatmap-btn" class="trackly-p-btn">
							<span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Show Heatmap', 'metricpulse' ); ?>
						</button>
						<button id="trackly-clear-heatmap-btn" class="trackly-p-btn secondary"><?php esc_html_e( 'Clear', 'metricpulse' ); ?></button>
					</div>
					<div class="heatmap-info-stats">
						<?php esc_html_e( 'Recorded Clicks:', 'metricpulse' ); ?> <strong id="trackly-heatmap-click-count">0</strong>
					</div>
				</div>

				<!-- Event Builder Tab Content -->
				<div class="trackly-panel-tab-content" id="trackly-tab-builder">
					<h5><?php esc_html_e( 'GA4 Event Builder', 'metricpulse' ); ?></h5>
					<p><?php esc_html_e( 'Create custom GA4 tracking events by selecting buttons or links on the page.', 'metricpulse' ); ?></p>
					
					<div id="trackly-builder-setup">
						<button id="trackly-start-selector-btn" class="trackly-p-btn">
							<span class="dashicons dashicons-mouse"></span> <?php esc_html_e( 'Start Element Selection', 'metricpulse' ); ?>
						</button>
						<p class="selector-notice description"><?php esc_html_e( 'Click the button, then hover over any button/link you wish to track on the page.', 'metricpulse' ); ?></p>
					</div>

					<div id="trackly-builder-form" class="trackly-hidden">
						<div class="trackly-p-form-group">
							<label><?php esc_html_e( 'Selected Element:', 'metricpulse' ); ?></label>
							<code id="trackly-selected-selector-display">div > a.btn</code>
						</div>
						<div class="trackly-p-form-group">
							<label for="trackly-p-event-name"><?php esc_html_e( 'GA4 Event Name:', 'metricpulse' ); ?></label>
							<input type="text" id="trackly-p-event-name" placeholder="<?php esc_attr_e( 'e.g., cta_button_click', 'metricpulse' ); ?>">
						</div>
						<div class="trackly-action-buttons">
							<button id="trackly-save-event-btn" class="trackly-p-btn"><?php esc_html_e( 'Save', 'metricpulse' ); ?></button>
							<button id="trackly-cancel-event-btn" class="trackly-p-btn secondary"><?php esc_html_e( 'Cancel', 'metricpulse' ); ?></button>
						</div>
					</div>
				</div>

				<!-- Predictive Insights Tab Content -->
				<div class="trackly-panel-tab-content" id="trackly-tab-ai">
					<h5><?php esc_html_e( 'Predictive Page Analysis', 'metricpulse' ); ?></h5>
					<div class="trackly-ai-container">
						<div id="trackly-ai-insights-content">
							<div class="ai-insight-item">
								<span class="dashicons dashicons-awards ai-icon purple"></span>
								<div class="ai-text">
									<strong><?php esc_html_e( 'Content Performance', 'metricpulse' ); ?></strong>
									<p><?php esc_html_e( 'Predictive anomaly engine is calculating statistics...', 'metricpulse' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Footer links -->
				<div class="trackly-panel-footer">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->plugin_name ) ); ?>" target="_blank">
						<span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Go to Dashboard', 'metricpulse' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inject custom tracked GA4 events into page header.
	 */
	public function inject_custom_ga4_events() {
		$saved_events = get_option( 'trackly_custom_events', array() );
		if ( empty( $saved_events ) ) {
			return;
		}

		$json_events = wp_json_encode( $saved_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $json_events ) {
			return;
		}

		?>
		<!-- Trackly Custom GA4 Tracking Events -->
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				const customEvents = <?php echo $json_events; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				if (!customEvents || !Array.isArray(customEvents)) return;

				customEvents.forEach(function(item) {
					// Isolate each selector: one malformed selector must not abort the whole loop.
					let nodes;
					try {
						nodes = document.querySelectorAll(item.selector);
					} catch (e) {
						return;
					}
					nodes.forEach(function(el) {
						el.addEventListener('click', function() {
							if (typeof gtag === 'function') {
								gtag('event', item.event_name, {
									'event_category': 'trackly_custom',
									'event_label': item.selector
								});
							}
						});
					});
				});
			});
		</script>
		<?php
	}
}
