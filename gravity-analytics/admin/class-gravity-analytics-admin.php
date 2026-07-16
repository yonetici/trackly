<?php
/**
 * Admin Panel controls and REST API handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gravity_Analytics_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function init_hooks() {
		// Admin Menu
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		// Register Admin Assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );
		// Register REST API Endpoints
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// Register Settings API
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings under WordPress Settings API.
	 */
	public function register_settings() {
		register_setting( 'gravity_analytics_settings_group', 'gravity_analytics_demo_mode', array(
			'type'              => 'string',
			'default'           => 'yes',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'gravity_analytics_settings_group', 'gravity_analytics_property_id', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'gravity_analytics_settings_group', 'gravity_analytics_credentials', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => array( $this, 'sanitize_and_encrypt_credentials' ),
		) );
		// Sampling rate option to prevent database table bloat
		register_setting( 'gravity_analytics_settings_group', 'gravity_analytics_sampling_rate', array(
			'type'              => 'string',
			'default'           => '100',
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}

	/**
	 * Settings callback to validate JSON and encrypt private key.
	 */
	public function sanitize_and_encrypt_credentials( $value ) {
		$value = wp_unslash( trim( $value ) );
		if ( empty( $value ) ) {
			return '';
		}

		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// If it's already encrypted, keep it
			$decrypted = Gravity_Analytics_API::decrypt_data( $value );
			if ( ! empty( $decrypted ) && null !== json_decode( $decrypted ) ) {
				return $value;
			}
			add_settings_error( 'gravity_analytics_credentials', 'invalid_json', __( 'Geçersiz JSON formatı.', 'gravity-analytics' ) );
			return get_option( 'gravity_analytics_credentials', '' );
		}

		// Save encrypted JSON
		return Gravity_Analytics_API::encrypt_data( $value );
	}

	/**
	 * Register Admin Menu Page.
	 */
	public function add_plugin_admin_menu() {
		add_menu_page(
			__( 'Gravity Analytics', 'gravity-analytics' ),
			__( 'Gravity Analytics', 'gravity-analytics' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_admin_page' ),
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Enqueue Styles and Scripts for Admin page.
	 */
	public function enqueue_styles_and_scripts( $hook ) {
		if ( 'toplevel_page_gravity-analytics' !== $hook ) {
			return;
		}

		// Enqueue Localized ApexCharts (No longer loading from CDN)
		wp_enqueue_script( 'apexcharts', GRAVITY_ANALYTICS_URL . 'admin/js/vendor/apexcharts.min.js', array(), '3.41.0', true );

		// Local Admin CSS & JS
		wp_enqueue_style( $this->plugin_name . '-admin-css', GRAVITY_ANALYTICS_URL . 'admin/css/gravity-analytics-admin.css', array(), $this->version );
		wp_enqueue_script( $this->plugin_name . '-admin-js', GRAVITY_ANALYTICS_URL . 'admin/js/gravity-analytics-admin.js', array( 'jquery', 'apexcharts' ), $this->version, true );

		// Localize Script for REST API URL & Nonce
		wp_localize_script( $this->plugin_name . '-admin-js', 'gravityAnalyticsData', array(
			'rest_url'   => esc_url_raw( rest_url( 'gravity-analytics/v1' ) ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Render the main Admin Dashboard page.
	 */
	public function display_plugin_admin_page() {
		// Clear GA transient cache if settings just updated & verify authority
		if ( isset( $_GET['settings-updated'] ) && current_user_can( 'manage_options' ) ) {
			delete_transient( 'g_token' );
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_g_b_%'" );
		}

		$demo_mode = get_option( 'gravity_analytics_demo_mode', 'yes' );
		$property_id = get_option( 'gravity_analytics_property_id', '' );
		$credentials_encrypted = get_option( 'gravity_analytics_credentials', '' );
		$sampling_rate = get_option( 'gravity_analytics_sampling_rate', '100' );
		
		// Decrypt credentials to show in textarea
		$credentials = Gravity_Analytics_API::decrypt_data( $credentials_encrypted );
		$is_connected = ! empty( $property_id ) && ! empty( $credentials_encrypted );

		?>
		<div class="gravity-admin-wrapper">
			<!-- Settings errors output added here (Fixes hidden validation errors QA bug) -->
			<?php settings_errors( 'gravity_analytics_settings_group' ); ?>

			<!-- Header -->
			<header class="gravity-header">
				<div class="gravity-logo-area">
					<span class="dashicons dashicons-chart-area gravity-logo-icon"></span>
					<h1><?php _e( 'Gravity Analytics', 'gravity-analytics' ); ?> <span class="gravity-badge">v<?php echo esc_html( $this->version ); ?></span></h1>
				</div>
				<div class="gravity-status-indicator">
					<?php if ( $demo_mode === 'yes' ) : ?>
						<span class="gravity-status demo"><span class="dot"></span> <?php _e( 'Demo Modu Aktif', 'gravity-analytics' ); ?></span>
					<?php elseif ( $is_connected ) : ?>
						<span class="gravity-status connected"><span class="dot"></span> <?php _e( 'GA4 Bağlı', 'gravity-analytics' ); ?></span>
					<?php else : ?>
						<span class="gravity-status disconnected"><span class="dot"></span> <?php _e( 'GA4 Bağlantısı Yok', 'gravity-analytics' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<!-- Tabs/Navigation -->
			<div class="gravity-tabs">
				<button class="gravity-tab-btn active" data-target="dashboard-tab">
					<span class="dashicons dashicons-dashboard"></span> <?php _e( 'Gösterge Paneli', 'gravity-analytics' ); ?>
				</button>
				<button class="gravity-tab-btn" data-target="settings-tab">
					<span class="dashicons dashicons-admin-generic"></span> <?php _e( 'Ayarlar', 'gravity-analytics' ); ?>
				</button>
			</div>

			<!-- Dashboard Tab Content -->
			<div id="dashboard-tab" class="gravity-tab-content active">
				<!-- Real-time Banner -->
				<div class="gravity-card gravity-realtime-card">
					<div class="gravity-realtime-content">
						<h3><?php _e( 'Şu Anda Sitede', 'gravity-analytics' ); ?></h3>
						<div class="gravity-realtime-value">
							<span id="gravity-active-users">--</span>
							<span class="pulse-ring"></span>
						</div>
						<p><?php _e( 'Aktif Ziyaretçi', 'gravity-analytics' ); ?></p>
					</div>
					<div class="gravity-realtime-spark"></div>
				</div>

				<!-- Stats Grid -->
				<div class="gravity-grid">
					<div class="gravity-card stat-card">
						<div class="stat-info">
							<h4><?php _e( 'Sayfa Görüntüleme', 'gravity-analytics' ); ?></h4>
							<h2 id="gravity-stat-views">--</h2>
						</div>
						<span class="dashicons dashicons-visibility stat-icon views"></span>
					</div>
					<div class="gravity-card stat-card">
						<div class="stat-info">
							<h4><?php _e( 'Tekil Ziyaretçiler', 'gravity-analytics' ); ?></h4>
							<h2 id="gravity-stat-users">--</h2>
						</div>
						<span class="dashicons dashicons-groups stat-icon users"></span>
					</div>
					<div class="gravity-card stat-card">
						<div class="stat-info">
							<h4><?php _e( 'Hemen Çıkma Oranı (Unengaged)', 'gravity-analytics' ); ?></h4>
							<h2 id="gravity-stat-bounce">--</h2>
						</div>
						<span class="dashicons dashicons-exit stat-icon bounce"></span>
					</div>
					<div class="gravity-card stat-card">
						<div class="stat-info">
							<h4><?php _e( 'Ortalama Kalma Süresi', 'gravity-analytics' ); ?></h4>
							<h2 id="gravity-stat-duration">--</h2>
						</div>
						<span class="dashicons dashicons-clock stat-icon duration"></span>
					</div>
				</div>

				<!-- GA4 Processing Latency Alert Box -->
				<div class="gravity-card gravity-latency-notice">
					<span class="dashicons dashicons-info gravity-notice-icon"></span>
					<div class="gravity-notice-text">
						<strong><?php _e( 'GA4 Veri Gecikmesi Bilgilendirmesi', 'gravity-analytics' ); ?></strong>
						<p><?php _e( 'Google Analytics 4 (GA4) verileri işlemek için 24-48 saat arası gecikmeye ihtiyaç duyar. Bu nedenle dün ve bugüne ait trafik metrikleriniz grafikte düşük veya eksik görünebilir. Bu durum geçicidir.', 'gravity-analytics' ); ?></p>
					</div>
				</div>

				<!-- Main Graph -->
				<div class="gravity-card main-chart-card">
					<div class="chart-header">
						<h3><?php _e( 'Ziyaretçi Trafik Trendi', 'gravity-analytics' ); ?></h3>
						<div class="chart-actions">
							<button class="gravity-chart-filter-btn active" data-days="7"><?php _e( 'Son 7 Gün', 'gravity-analytics' ); ?></button>
							<button class="gravity-chart-filter-btn" data-days="30"><?php _e( 'Son 30 Gün', 'gravity-analytics' ); ?></button>
						</div>
					</div>
					<div class="chart-container">
						<div id="gravity-main-chart"></div>
					</div>
				</div>

				<!-- Bottom Grid (Sources & Devices) -->
				<div class="gravity-grid double">
					<div class="gravity-card chart-half">
						<h3><?php _e( 'Trafik Kaynakları', 'gravity-analytics' ); ?></h3>
						<div id="gravity-source-chart"></div>
					</div>
					<div class="gravity-card chart-half">
						<h3><?php _e( 'Cihaz Dağılımı', 'gravity-analytics' ); ?></h3>
						<div id="gravity-device-chart"></div>
					</div>
				</div>

				<!-- Top Pages Table -->
				<div class="gravity-card table-card">
					<h3><?php _e( 'En Çok Görüntülenen Sayfalar', 'gravity-analytics' ); ?></h3>
					<div class="gravity-table-wrapper">
						<table class="gravity-table" id="gravity-pages-table">
							<thead>
								<tr>
									<th><?php _e( 'Sayfa URL', 'gravity-analytics' ); ?></th>
									<th><?php _e( 'Görüntüleme', 'gravity-analytics' ); ?></th>
									<th><?php _e( 'Kullanıcılar', 'gravity-analytics' ); ?></th>
									<th><?php _e( 'Hemen Çıkma Oranı', 'gravity-analytics' ); ?></th>
									<th><?php _e( 'Ort. Süre', 'gravity-analytics' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td colspan="5" class="loading-td"><?php _e( 'Yükleniyor...', 'gravity-analytics' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Settings Tab Content (WordPress Settings API Integrated) -->
			<div id="settings-tab" class="gravity-tab-content">
				<div class="gravity-card settings-card">
					<h3><?php _e( 'Google Analytics Entegrasyon Ayarları', 'gravity-analytics' ); ?></h3>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'gravity_analytics_settings_group' );
						do_settings_sections( 'gravity_analytics_settings_group' );
						?>

						<div class="gravity-form-group">
							<label class="gravity-switch-label">
								<input type="checkbox" name="gravity_analytics_demo_mode" value="yes" <?php checked( $demo_mode, 'yes' ); ?>>
								<span class="gravity-switch-slider"></span>
								<div class="label-text">
									<strong><?php _e( 'Demo (Mock) Modu Etkin', 'gravity-analytics' ); ?></strong>
									<p class="description"><?php _e( 'Google Analytics bağlantısı yapmadan eklentiyi sahte (gerçekçi) verilerle test etmek için bunu aktif tutun.', 'gravity-analytics' ); ?></p>
								</div>
							</label>
						</div>

						<div class="gravity-form-group">
							<label for="gravity_analytics_property_id"><?php _e( 'GA4 Property ID (Mülk Kimliği)', 'gravity-analytics' ); ?></label>
							<input type="text" id="gravity_analytics_property_id" name="gravity_analytics_property_id" value="<?php echo esc_attr( $property_id ); ?>" placeholder="Örn: 382901248" class="regular-text">
							<p class="description"><?php _e( 'Google Analytics mülkünüzün sayısal Property ID\'si (Örn: Yönetici > Mülk Ayarları bölümünden bulabilirsiniz).', 'gravity-analytics' ); ?></p>
						</div>

						<div class="gravity-form-group">
							<label for="gravity_analytics_credentials"><?php _e( 'Service Account JSON Anahtarı', 'gravity-analytics' ); ?></label>
							<textarea id="gravity_analytics_credentials" name="gravity_analytics_credentials" rows="8" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea( $credentials ); ?></textarea>
							<p class="description"><?php _e( 'Google Cloud Console\'dan oluşturduğunuz Service Account (Hizmet Hesabı) JSON anahtar dosyasının içeriğini buraya yapıştırın. Bu hesabın e-posta adresini GA4 mülkünüze Viewer (Okuyucu) olarak eklemeyi unutmayın.', 'gravity-analytics' ); ?></p>
						</div>

						<!-- Sampling Rate Option Selector to prevent DB bloat -->
						<div class="gravity-form-group">
							<label for="gravity_analytics_sampling_rate"><?php _e( 'Tıklama Örnekleme Oranı (Sampling Rate)', 'gravity-analytics' ); ?></label>
							<select id="gravity_analytics_sampling_rate" name="gravity_analytics_sampling_rate" style="width: 100%; max-width: 25rem; height: 2.5rem; border-radius: 8px; border: 1px solid #cbd5e1; padding: 0 10px;">
								<option value="100" <?php selected( $sampling_rate, '100' ); ?>><?php _e( '%100 (Tüm tıklamaları kaydet)', 'gravity-analytics' ); ?></option>
								<option value="50" <?php selected( $sampling_rate, '50' ); ?>><?php _e( '%50 (Tıklamaların yarısını kaydet)', 'gravity-analytics' ); ?></option>
								<option value="25" <?php selected( $sampling_rate, '25' ); ?>><?php _e( '%25 (Tıklamaların çeyreğini kaydet)', 'gravity-analytics' ); ?></option>
								<option value="10" <?php selected( $sampling_rate, '10' ); ?>><?php _e( '%10 (Sadece %10\'unu kaydet - Büyük siteler için önerilir)', 'gravity-analytics' ); ?></option>
							</select>
							<p class="description"><?php _e( 'Yüksek trafikli sitelerde veritabanının şişmesini önlemek için tıklama takip sıklığını düşürebilirsiniz. Örnekleme session bazlı çalışır.', 'gravity-analytics' ); ?></p>
						</div>

						<button type="submit" class="gravity-btn gravity-btn-primary"><?php _e( 'Ayarları Kaydet', 'gravity-analytics' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Register WordPress REST API Routes.
	 */
	public function register_rest_routes() {
		register_rest_route( 'gravity-analytics/v1', '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'gravity-analytics/v1', '/realtime', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_realtime_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'gravity-analytics/v1', '/page-stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'gravity-analytics/v1', '/record-click', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'record_click_callback' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'gravity-analytics/v1', '/clicks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_clicks_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'gravity-analytics/v1', '/save-event', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_event_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );
	}

	/**
	 * Verify if current user is admin.
	 */
	public function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Robust client IP retriever.
	 */
	private function get_ip_address() {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
						return $ip;
					}
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * REST Callback for overall site statistics.
	 */
	public function get_stats_callback( $request ) {
		$days = intval( $request->get_param( 'days' ) );
		if ( ! in_array( $days, array( 7, 30 ) ) ) {
			$days = 7;
		}

		$start_date = $days === 30 ? '30daysAgo' : '7daysAgo';

		$summary_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$chart_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$sources_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
		);

		$devices_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'deviceCategory' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
		);

		$pages_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$batch_report = Gravity_Analytics_API::batch_run_reports( array(
			$summary_req,
			$chart_req,
			$sources_req,
			$devices_req,
			$pages_req,
		) );

		if ( is_wp_error( $batch_report ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $batch_report->get_error_message() ), 500 );
		}

		$realtime_users = Gravity_Analytics_API::get_realtime_users();

		return new WP_REST_Response( array(
			'success'        => true,
			'summary'        => isset( $batch_report['reports'][0] ) ? $batch_report['reports'][0] : array(),
			'chart'          => isset( $batch_report['reports'][1] ) ? $batch_report['reports'][1] : array(),
			'sources'        => isset( $batch_report['reports'][2] ) ? $batch_report['reports'][2] : array(),
			'devices'        => isset( $batch_report['reports'][3] ) ? $batch_report['reports'][3] : array(),
			'pages'          => isset( $batch_report['reports'][4] ) ? $batch_report['reports'][4] : array(),
			'realtime_users' => $realtime_users,
		), 200 );
	}

	/**
	 * REST Callback for lightweight realtime polling.
	 */
	public function get_realtime_callback( $request ) {
		$realtime_users = Gravity_Analytics_API::get_realtime_users();
		return new WP_REST_Response( array(
			'success'        => true,
			'realtime_users' => $realtime_users,
		), 200 );
	}

	/**
	 * REST Callback for single page stats.
	 */
	public function get_page_stats_callback( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'gravity-analytics' ) ), 400 );
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}

		$report = Gravity_Analytics_API::get_report( array(
			'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions'      => array( array( 'name' => 'pagePath' ) ),
			'metrics'         => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
			'dimensionFilter' => array(
				'filter' => array(
					'fieldName' => 'pagePath',
					'stringFilter' => array(
						'matchType' => 'EXACT',
						'value'     => $path,
					),
				),
			),
		) );

		if ( is_wp_error( $report ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $report->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'path'    => $path,
			'report'  => $report,
		), 200 );
	}

	/**
	 * REST Callback to record a visitor click.
	 */
	public function record_click_callback( $request ) {
		$ip = $this->get_ip_address();
		$transient_key = 'gravity_rate_' . md5( $ip );
		$clicks = get_transient( $transient_key );

		if ( false === $clicks ) {
			set_transient( $transient_key, 1, 60 );
		} elseif ( $clicks >= 10 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Rate limit exceeded.', 'gravity-analytics' ) ), 429 );
		} else {
			set_transient( $transient_key, $clicks + 1, 60 );
		}

		$params = $request->get_json_params();
		if ( empty( $params['page_url'] ) || ! isset( $params['click_x_pct'] ) || ! isset( $params['click_y_pct'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid click data.', 'gravity-analytics' ) ), 400 );
		}

		$log_result = Gravity_Analytics_DB::log_click( array(
			'page_url'         => $params['page_url'],
			'element_tag'      => isset( $params['element_tag'] ) ? sanitize_text_field( $params['element_tag'] ) : 'unknown',
			'element_selector' => isset( $params['element_selector'] ) ? sanitize_text_field( $params['element_selector'] ) : '',
			'click_x_pct'      => floatval( $params['click_x_pct'] ),
			'click_y_pct'      => floatval( $params['click_y_pct'] ),
		) );

		return new WP_REST_Response( array( 'success' => (bool) $log_result ), 200 );
	}

	/**
	 * REST Callback to retrieve clicks.
	 */
	public function get_clicks_callback( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'gravity-analytics' ) ), 400 );
		}

		$clicks = Gravity_Analytics_DB::get_clicks_for_page( $url );

		return new WP_REST_Response( array(
			'success' => true,
			'clicks'  => $clicks,
		), 200 );
	}

	/**
	 * REST Callback to save GA4 event mapping.
	 * Relaxed regex to only block HTML tag enjections '<' and '>', resolving quotes regression.
	 */
	public function save_event_callback( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params['selector'] ) || empty( $params['event_name'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Selector and Event Name required.', 'gravity-analytics' ) ), 400 );
		}

		$selector = sanitize_text_field( $params['selector'] );
		// Allows single and double quotes for attribute selectors, blocks HTML brackets
		if ( preg_match( '/[<>]/i', $selector ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid CSS Selector payload.', 'gravity-analytics' ) ), 400 );
		}

		$saved_events = get_option( 'gravity_analytics_custom_events', array() );
		$saved_events[] = array(
			'selector'   => $selector,
			'event_name' => sanitize_key( $params['event_name'] ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( 'gravity_analytics_custom_events', $saved_events );

		return new WP_REST_Response( array( 'success' => true, 'events' => $saved_events ), 200 );
	}
}
