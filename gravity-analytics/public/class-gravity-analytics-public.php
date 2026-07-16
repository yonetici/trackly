<?php
/**
 * Public Front-End hooks and rendering handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gravity_Analytics_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function init_hooks() {
		// Enqueue scripts & styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );

		// Render Floating Analytics Bar in footer
		add_action( 'wp_footer', array( $this, 'render_floating_stats_bar' ) );

		// Enqueue GA4 Custom event tags if any exist
		add_action( 'wp_head', array( $this, 'inject_custom_ga4_events' ) );
	}

	/**
	 * Enqueue Frontend Assets.
	 */
	public function enqueue_styles_and_scripts() {
		global $wp;
		$current_url = home_url( add_query_arg( array(), $wp->request ) );
		$current_url = trailingslashit( $current_url );

		$sampling_rate = get_option( 'gravity_analytics_sampling_rate', '100' );

		// 1. Enqueue lightweight click tracker script for EVERYONE (no jQuery dependency)
		wp_enqueue_script( $this->plugin_name . '-tracker-js', GRAVITY_ANALYTICS_URL . 'public/js/gravity-analytics-tracker.js', array(), $this->version, true );
		
		wp_localize_script( $this->plugin_name . '-tracker-js', 'gravityAnalyticsTrackerData', array(
			'rest_url'      => esc_url_raw( rest_url( 'gravity-analytics/v1' ) ),
			'page_url'      => $current_url,
			'sampling_rate' => intval( $sampling_rate ), // Passes rate (e.g. 10, 25, 50, 100)
		) );

		// 2. Load heavy admin panel JS/CSS ONLY for logged-in administrators (Core Web Vitals Optimisation)
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( $this->plugin_name . '-public-css', GRAVITY_ANALYTICS_URL . 'public/css/gravity-analytics-public.css', array(), $this->version );
			wp_enqueue_script( $this->plugin_name . '-public-js', GRAVITY_ANALYTICS_URL . 'public/js/gravity-analytics-public.js', array( 'jquery' ), $this->version, true );

			wp_localize_script( $this->plugin_name . '-public-js', 'gravityAnalyticsPublicData', array(
				'rest_url'   => esc_url_raw( rest_url( 'gravity-analytics/v1' ) ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				'page_url'   => $current_url,
				'is_admin'   => 1,
				'admin_url'  => esc_url( admin_url( 'admin.php?page=' . $this->plugin_name ) ),
			) );
		}
	}

	/**
	 * Render the gorgeous glassmorphism overlay bar in the footer for administrators.
	 */
	public function render_floating_stats_bar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp;
		$current_path = wp_parse_url( home_url( add_query_arg( array(), $wp->request ) ), PHP_URL_PATH );
		if ( empty( $current_path ) ) {
			$current_path = '/';
		}

		?>
		<div id="gravity-stats-bar-wrapper">
			<!-- Floating Toggle Button -->
			<button id="gravity-stats-toggle-btn" title="<?php esc_attr_e( 'Gravity Analytics', 'gravity-analytics' ); ?>">
				<span class="dashicons dashicons-chart-area"></span>
			</button>

			<!-- Main Panel -->
			<div id="gravity-stats-panel">
				<!-- Panel Header -->
				<div class="gravity-panel-header">
					<div class="gravity-panel-logo">
						<span class="dashicons dashicons-chart-area"></span>
						<h4><?php _e( 'Gravity Analytics', 'gravity-analytics' ); ?></h4>
					</div>
					<div class="gravity-panel-controls">
						<button id="gravity-panel-minimize-btn" title="<?php esc_attr_e( 'Gizle', 'gravity-analytics' ); ?>">
							<span class="dashicons dashicons-minus"></span>
						</button>
					</div>
				</div>

				<!-- Tabs -->
				<div class="gravity-panel-tabs">
					<button class="gravity-panel-tab active" data-tab="stats"><?php _e( 'İstatistikler', 'gravity-analytics' ); ?></button>
					<button class="gravity-panel-tab" data-tab="heatmap"><?php _e( 'Tıklama Haritası', 'gravity-analytics' ); ?></button>
					<button class="gravity-panel-tab" data-tab="builder"><?php _e( 'Event Sihirbazı', 'gravity-analytics' ); ?></button>
					<button class="gravity-panel-tab" data-tab="ai"><?php _e( 'AI Analizi', 'gravity-analytics' ); ?></button>
				</div>

				<!-- Stats Tab Content -->
				<div class="gravity-panel-tab-content active" id="gravity-tab-stats">
					<p class="gravity-url-indicator"><?php _e( 'Bu Sayfa:', 'gravity-analytics' ); ?> <code><?php echo esc_html( $current_path ); ?></code></p>
					
					<div class="gravity-panel-metrics-grid">
						<div class="gravity-panel-metric-card">
							<span class="label"><?php _e( 'Görüntüleme', 'gravity-analytics' ); ?></span>
							<h3 id="gravity-p-views">--</h3>
						</div>
						<div class="gravity-panel-metric-card">
							<span class="label"><?php _e( 'Kullanıcılar', 'gravity-analytics' ); ?></span>
							<h3 id="gravity-p-users">--</h3>
						</div>
						<div class="gravity-panel-metric-card">
							<span class="label"><?php _e( 'Hemen Çıkma', 'gravity-analytics' ); ?></span>
							<h3 id="gravity-p-bounce">--</h3>
						</div>
						<div class="gravity-panel-metric-card">
							<span class="label"><?php _e( 'Ort. Süre', 'gravity-analytics' ); ?></span>
							<h3 id="gravity-p-duration">--</h3>
						</div>
					</div>

					<div class="gravity-panel-info-box">
						<span class="dashicons dashicons-info"></span>
						<p><?php _e( 'Veriler son 7 günün ortalamasını yansıtır. Güncelleme periyodu: 1 saat.', 'gravity-analytics' ); ?></p>
					</div>
				</div>

				<!-- Heatmap Tab Content -->
				<div class="gravity-panel-tab-content" id="gravity-tab-heatmap">
					<h5><?php _e( 'Yerel Tıklama Haritası', 'gravity-analytics' ); ?></h5>
					<p><?php _e( 'Kullanıcıların bu sayfada tıkladığı elementlerin yoğunluğunu görsel olarak izleyin.', 'gravity-analytics' ); ?></p>
					
					<div class="gravity-action-buttons">
						<button id="gravity-toggle-heatmap-btn" class="gravity-p-btn">
							<span class="dashicons dashicons-visibility"></span> <?php _e( 'Haritayı Göster', 'gravity-analytics' ); ?>
						</button>
						<button id="gravity-clear-heatmap-btn" class="gravity-p-btn secondary"><?php _e( 'Temizle', 'gravity-analytics' ); ?></button>
					</div>
					<div class="heatmap-info-stats" style="margin-top: 10px; font-size: 12px; display:none;">
						<?php _e( 'Kayıtlı Tıklama Sayısı:', 'gravity-analytics' ); ?> <strong id="gravity-heatmap-click-count">0</strong>
					</div>
				</div>

				<!-- Event Builder Tab Content -->
				<div class="gravity-panel-tab-content" id="gravity-tab-builder">
					<h5><?php _e( 'GA4 Etkinlik Sihirbazı', 'gravity-analytics' ); ?></h5>
					<p><?php _e( 'Buton veya bağlantıları tıklayarak GA4 özel izleme kodu oluşturun.', 'gravity-analytics' ); ?></p>
					
					<div id="gravity-builder-setup">
						<button id="gravity-start-selector-btn" class="gravity-p-btn">
							<span class="dashicons dashicons-mouse"></span> <?php _e( 'Element Seçimini Başlat', 'gravity-analytics' ); ?>
						</button>
						<p class="selector-notice description"><?php _e( 'Butona bastıktan sonra sitede izlemek istediğiniz herhangi bir buton/linkin üzerine gelin.', 'gravity-analytics' ); ?></p>
					</div>

					<div id="gravity-builder-form" style="display: none;">
						<div class="gravity-p-form-group">
							<label><?php _e( 'Seçilen Element:', 'gravity-analytics' ); ?></label>
							<code id="gravity-selected-selector-display">div > a.btn</code>
						</div>
						<div class="gravity-p-form-group">
							<label for="gravity-p-event-name"><?php _e( 'GA4 Etkinlik Adı (Event Name):', 'gravity-analytics' ); ?></label>
							<input type="text" id="gravity-p-event-name" placeholder="Örn: cta_button_click">
						</div>
						<div class="gravity-action-buttons">
							<button id="gravity-save-event-btn" class="gravity-p-btn"><?php _e( 'Kaydet', 'gravity-analytics' ); ?></button>
							<button id="gravity-cancel-event-btn" class="gravity-p-btn secondary"><?php _e( 'Vazgeç', 'gravity-analytics' ); ?></button>
						</div>
					</div>
				</div>

				<!-- AI Insights Tab Content -->
				<div class="gravity-panel-tab-content" id="gravity-tab-ai">
					<h5><?php _e( 'Yapay Zeka Destekli Sayfa Analizi', 'gravity-analytics' ); ?></h5>
					<div class="gravity-ai-container">
						<div id="gravity-ai-insights-content">
							<div class="ai-insight-item">
								<span class="dashicons dashicons-awards ai-icon purple"></span>
								<div class="ai-text">
									<strong><?php _e( 'İçerik Performansı', 'gravity-analytics' ); ?></strong>
									<p><?php _e( 'Sayfa istatistikleri yüklenirken AI analizi hesaplanıyor...', 'gravity-analytics' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Footer links -->
				<div class="gravity-panel-footer">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->plugin_name ) ); ?>" target="_blank">
						<span class="dashicons dashicons-external"></span> <?php _e( 'Kontrol Paneline Git', 'gravity-analytics' ); ?>
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
		$saved_events = get_option( 'gravity_analytics_custom_events', array() );
		if ( empty( $saved_events ) ) {
			return;
		}

		$json_events = wp_json_encode( $saved_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $json_events ) {
			return;
		}

		?>
		<!-- Gravity Analytics Custom GA4 Tracking Events -->
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				const customEvents = <?php echo $json_events; ?>;
				if (!customEvents || !Array.isArray(customEvents)) return;

				customEvents.forEach(function(item) {
					document.querySelectorAll(item.selector).forEach(function(el) {
						el.addEventListener('click', function() {
							if (typeof gtag === 'function') {
								gtag('event', item.event_name, {
									'event_category': 'gravity_custom',
									'event_label': item.selector
								});
								console.log('Gravity GA4 Event Tracked: ' + item.event_name);
							}
						});
					});
				});
			});
		</script>
		<?php
	}
}
