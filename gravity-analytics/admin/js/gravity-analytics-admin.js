/**
 * Gravity Analytics Dashboard Script
 */
(function($) {
	'use strict';

	let mainChart = null;
	let sourceChart = null;
	let deviceChart = null;
	let realtimeInterval = null;

	$(document).ready(function() {
		initTabs();
		initCharts();
		loadDashboardData(7);
		startRealtimePolling();

		// Chart range filters
		$('.gravity-chart-filter-btn').on('click', function() {
			$('.gravity-chart-filter-btn').removeClass('active');
			$(this).addClass('active');
			const days = $(this).data('days');
			loadDashboardData(days);
		});
	});

	/**
	 * Tab switching mechanism
	 */
	function initTabs() {
		$('.gravity-tab-btn').on('click', function() {
			const target = $(this).data('target');
			
			$('.gravity-tab-btn').removeClass('active');
			$('.gravity-tab-content').removeClass('active');

			$(this).addClass('active');
			$('#' + target).addClass('active');
		});
	}

	/**
	 * Initialize empty ApexCharts instances with loading states
	 */
	function initCharts() {
		const mainOptions = {
			chart: {
				type: 'area',
				height: 350,
				toolbar: { show: false },
				zoom: { enabled: false },
				fontFamily: 'Outfit, sans-serif',
				foreColor: '#64748b'
			},
			colors: ['#8b5cf6', '#06b6d4'],
			fill: {
				type: 'gradient',
				gradient: {
					shadeIntensity: 1,
					opacityFrom: 0.45,
					opacityTo: 0.05,
					stops: [0, 100]
				}
			},
			dataLabels: { enabled: false },
			stroke: { curve: 'smooth', width: 3 },
			series: [],
			xaxis: { categories: [] },
			noData: {
				text: 'Yükleniyor...',
				align: 'center',
				verticalAlign: 'middle',
				style: { color: '#64748b', fontSize: '14px' }
			}
		};

		const sourceOptions = {
			chart: {
				type: 'donut',
				height: 280,
				fontFamily: 'Outfit, sans-serif'
			},
			colors: ['#8b5cf6', '#06b6d4', '#10b981', '#f43f5e', '#e2e8f0'],
			series: [],
			labels: [],
			legend: { position: 'bottom' },
			noData: { text: 'Yükleniyor...' }
		};

		const deviceOptions = {
			chart: {
				type: 'donut',
				height: 280,
				fontFamily: 'Outfit, sans-serif'
			},
			colors: ['#4f46e5', '#06b6d4', '#cbd5e1'],
			series: [],
			labels: [],
			legend: { position: 'bottom' },
			noData: { text: 'Yükleniyor...' }
		};

		mainChart = new ApexCharts(document.querySelector("#gravity-main-chart"), mainOptions);
		sourceChart = new ApexCharts(document.querySelector("#gravity-source-chart"), sourceOptions);
		deviceChart = new ApexCharts(document.querySelector("#gravity-device-chart"), deviceOptions);

		mainChart.render();
		sourceChart.render();
		deviceChart.render();
	}

	/**
	 * Pull dashboard statistics via WordPress REST API
	 */
	function loadDashboardData(days) {
		$.ajax({
			url: gravityAnalyticsData.rest_url + '/stats',
			method: 'GET',
			data: { days: days },
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', gravityAnalyticsData.rest_nonce);
			},
			success: function(res) {
				if (res.success) {
					updateSummary(res.summary);
					updateMainChart(res.chart);
					updateSourceChart(res.sources);
					updateDeviceChart(res.devices);
					updatePagesTable(res.pages);
					updateRealtimeValue(res.realtime_users);
				} else {
					console.error('GA Data retrieval failed: ', res.error);
				}
			},
			error: function(err) {
				console.error('AJAX Error: ', err);
			}
		});
	}

	/**
	 * Periodically fetch active visitor count
	 */
	function startRealtimePolling() {
		// Poll every 20 seconds to lightweight /realtime endpoint
		realtimeInterval = setInterval(function() {
			$.ajax({
				url: gravityAnalyticsData.rest_url + '/realtime',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', gravityAnalyticsData.rest_nonce);
				},
				success: function(res) {
					if (res.success) {
						updateRealtimeValue(res.realtime_users);
					}
				}
			});
		}, 20000);
	}

	function updateRealtimeValue(count) {
		const $counter = $('#gravity-active-users');
		// Simple counter increment/decrement animation
		$counter.fadeOut(150, function() {
			$(this).text(count).fadeIn(150);
		});
	}

	/**
	 * Update aggregate metric cards
	 */
	function updateSummary(summaryData) {
		if (!summaryData.rows || summaryData.rows.length === 0) return;
		
		const metrics = summaryData.rows[0].metricValues;
		const views = parseInt(metrics[0].value).toLocaleString();
		const users = parseInt(metrics[1].value).toLocaleString();
		
		// Bounce rate is a ratio e.g. 0.4632 -> 46.3%
		const bounce = (parseFloat(metrics[2].value) * 100).toFixed(1) + '%';
		
		// Avg duration in seconds -> mm:ss
		const durSec = parseInt(metrics[3].value);
		const mins = Math.floor(durSec / 60);
		const secs = durSec % 60;
		const duration = mins + 'd ' + (secs < 10 ? '0' : '') + secs + 's';

		$('#gravity-stat-views').text(views);
		$('#gravity-stat-users').text(users);
		$('#gravity-stat-bounce').text(bounce);
		$('#gravity-stat-duration').text(duration);
	}

	/**
	 * Map time series rows to ApexCharts series
	 */
	function updateMainChart(chartData) {
		const categories = [];
		const viewsSeries = [];
		const usersSeries = [];

		if (chartData.rows && chartData.rows.length > 0) {
			chartData.rows.forEach(function(row) {
				// Parse date e.g. '20260715' -> '15 Tem'
				const rawDate = row.dimensionValues[0].value;
				const year = rawDate.substring(0, 4);
				const month = rawDate.substring(4, 6);
				const day = rawDate.substring(6, 8);
				const dateObj = new Date(year, month - 1, day);
				const formattedDate = dateObj.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' });

				categories.push(formattedDate);
				viewsSeries.push(parseInt(row.metricValues[0].value));
				usersSeries.push(parseInt(row.metricValues[1].value));
			});
		}

		mainChart.updateOptions({
			xaxis: { categories: categories }
		});

		mainChart.updateSeries([
			{ name: 'Sayfa Görüntüleme', data: viewsSeries },
			{ name: 'Kullanıcılar', data: usersSeries }
		]);
	}

	/**
	 * Map traffic sources to Donut Chart
	 */
	function updateSourceChart(sourcesData) {
		const series = [];
		const labels = [];

		if (sourcesData.rows && sourcesData.rows.length > 0) {
			sourcesData.rows.forEach(function(row) {
				labels.push(row.dimensionValues[0].value);
				series.push(parseInt(row.metricValues[0].value));
			});
		}

		sourceChart.updateOptions({
			labels: labels
		});
		sourceChart.updateSeries(series);
	}

	/**
	 * Map device categories to Donut Chart
	 */
	function updateDeviceChart(devicesData) {
		const series = [];
		const labels = [];

		if (devicesData.rows && devicesData.rows.length > 0) {
			devicesData.rows.forEach(function(row) {
				const dev = row.dimensionValues[0].value;
				// Translate device name
				const translatedDev = dev === 'desktop' ? 'Masaüstü' : (dev === 'mobile' ? 'Mobil' : 'Tablet');
				labels.push(translatedDev);
				series.push(parseInt(row.metricValues[0].value));
			});
		}

		deviceChart.updateOptions({
			labels: labels
		});
		deviceChart.updateSeries(series);
	}

	/**
	 * Render Top Pages table
	 */
	function updatePagesTable(pagesData) {
		const $tbody = $('#gravity-pages-table tbody');
		$tbody.empty();

		if (!pagesData.rows || pagesData.rows.length === 0) {
			$tbody.append('<tr><td colspan="5" class="loading-td">Veri bulunamadı.</td></tr>');
			return;
		}

		pagesData.rows.forEach(function(row) {
			const path = row.dimensionValues[0].value;
			const views = parseInt(row.metricValues[0].value).toLocaleString();
			const users = parseInt(row.metricValues[1].value).toLocaleString();
			const bounce = (parseFloat(row.metricValues[2].value) * 100).toFixed(1) + '%';
			
			const durSec = parseInt(row.metricValues[3].value);
			const mins = Math.floor(durSec / 60);
			const secs = durSec % 60;
			const duration = mins + ':' + (secs < 10 ? '0' : '') + secs;

			const html = `
				<tr>
					<td><a href="${path}" target="_blank" class="gravity-page-link"><code>${path}</code></a></td>
					<td><strong>${views}</strong></td>
					<td>${users}</td>
					<td>${bounce}</td>
					<td>${duration}</td>
				</tr>
			`;
			$tbody.append(html);
		});
	}

})(jQuery);
