/**
 * Trackly Analytics Dashboard Script
 */
(function($) {
	'use strict';

	let mainChart = null;
	let sourceChart = null;
	let deviceChart = null;
	let realtimeInterval = null;

	// Translatable strings with English fallbacks.
	const I18N = (typeof tracklyData !== 'undefined' && tracklyData.i18n) ? tracklyData.i18n : {};
	function t(key, fallback) {
		return (I18N && I18N[key]) ? I18N[key] : fallback;
	}

	// Run a render step in isolation; log (in debug) but never let it abort sibling renders.
	function safe(fn) {
		try {
			fn();
		} catch (e) {
			if (typeof tracklyData !== 'undefined' && tracklyData.debug) {
				console.error('MetricPulse render error: ', e);
			}
		}
	}

	// Extract the first dimension value from a GA4 row, tolerating malformed/empty rows.
	function dimValue(row) {
		return (row && row.dimensionValues && row.dimensionValues[0]) ? row.dimensionValues[0].value : null;
	}

	// Format a duration given in seconds as m:ss (consistent with the frontend panel).
	function formatDuration(totalSeconds) {
		const s = parseInt(totalSeconds, 10) || 0;
		const mins = Math.floor(s / 60);
		const secs = s % 60;
		return mins + ':' + (secs < 10 ? '0' : '') + secs;
	}

	// Only allow same-document relative paths as hrefs (blocks javascript:, data:, absolute URLs).
	function safeHref(path) {
		return /^\/[^/]/.test(path) || path === '/' ? path : '#';
	}

	function escapeHtml(string) {
		return String(string).replace(/[&<>"']/g, function(s) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			}[s];
		});
	}

	$(document).ready(function() {
		initTabs();
		initCharts();
		loadDashboardData(7);
		startRealtimePolling();

		// Chart range filters
		$('.trackly-chart-filter-btn').on('click', function() {
			$('.trackly-chart-filter-btn').removeClass('active');
			$(this).addClass('active');
			const days = $(this).data('days');
			loadDashboardData(days);
		});
	});

	/**
	 * Tab switching mechanism
	 */
	function initTabs() {
		$('.trackly-tab-btn').on('click', function() {
			const target = $(this).data('target');
			
			$('.trackly-tab-btn').removeClass('active');
			$('.trackly-tab-content').removeClass('active');

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
				fontFamily: 'inherit',
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
				text: 'Loading...',
				align: 'center',
				verticalAlign: 'middle',
				style: { color: '#64748b', fontSize: '14px' }
			}
		};

		const sourceOptions = {
			chart: {
				type: 'donut',
				height: 280,
				fontFamily: 'inherit'
			},
			colors: ['#8b5cf6', '#06b6d4', '#10b981', '#f43f5e', '#e2e8f0'],
			series: [],
			labels: [],
			legend: { position: 'bottom' },
			noData: { text: 'Loading...' }
		};

		const deviceOptions = {
			chart: {
				type: 'donut',
				height: 280,
				fontFamily: 'inherit'
			},
			colors: ['#4f46e5', '#06b6d4', '#cbd5e1'],
			series: [],
			labels: [],
			legend: { position: 'bottom' },
			noData: { text: 'Loading...' }
		};

		mainChart = new ApexCharts(document.querySelector("#trackly-main-chart"), mainOptions);
		sourceChart = new ApexCharts(document.querySelector("#trackly-source-chart"), sourceOptions);
		deviceChart = new ApexCharts(document.querySelector("#trackly-device-chart"), deviceOptions);

		mainChart.render();
		sourceChart.render();
		deviceChart.render();
	}

	/**
	 * Pull dashboard statistics via WordPress REST API
	 */
	function loadDashboardData(days) {
		$.ajax({
			url: tracklyData.rest_url + '/stats',
			method: 'GET',
			data: { days: days },
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', tracklyData.rest_nonce);
			},
			success: function(res) {
				if (res.success) {
					// Isolate each section so a single malformed report never blanks the whole dashboard.
					safe(function() { updateSummary(res.summary); });
					safe(function() { updateMainChart(res.chart); });
					safe(function() { updateSourceChart(res.sources); });
					safe(function() { updateDeviceChart(res.devices); });
					safe(function() { updatePagesTable(res.pages); });
					safe(function() { updateRealtimeValue(res.realtime_users); });
				} else {
					if (typeof tracklyData !== 'undefined' && tracklyData.debug) {
						console.error('GA Data retrieval failed: ', res.error);
					}
				}
			},
			error: function(err) {
				if (typeof tracklyData !== 'undefined' && tracklyData.debug) {
					console.error('AJAX Error: ', err);
				}
			}
		});
	}

	/**
	 * Periodically fetch active visitor count
	 */
	function startRealtimePolling() {
		// Poll every 20 seconds, but skip while the tab is hidden to avoid needless background requests.
		realtimeInterval = setInterval(function() {
			if (document.visibilityState === 'hidden') {
				return;
			}
			$.ajax({
				url: tracklyData.rest_url + '/realtime',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', tracklyData.rest_nonce);
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
		const $counter = $('#trackly-active-users');
		// -1 is the "unavailable" sentinel (API error / not connected) — never render it as a real 0.
		const display = (parseInt(count, 10) < 0) ? t('unavailable', 'N/A') : count;
		$counter.fadeOut(150, function() {
			$(this).text(display).fadeIn(150);
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

		$('#trackly-stat-views').text(views);
		$('#trackly-stat-users').text(users);
		$('#trackly-stat-bounce').text(bounce);
		$('#trackly-stat-duration').text(formatDuration(metrics[3].value));
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
				const rawDate = dimValue(row);
				if (!rawDate) return;
				const year = rawDate.substring(0, 4);
				const month = rawDate.substring(4, 6);
				const day = rawDate.substring(6, 8);
				const dateObj = new Date(year, month - 1, day);
				const formattedDate = dateObj.toLocaleDateString('en-US', { day: 'numeric', month: 'short' });

				categories.push(formattedDate);
				viewsSeries.push(parseInt(row.metricValues[0].value));
				usersSeries.push(parseInt(row.metricValues[1].value));
			});
		}

		mainChart.updateOptions({
			xaxis: { categories: categories }
		});

		mainChart.updateSeries([
			{ name: t('pageviews', 'Pageviews'), data: viewsSeries },
			{ name: t('users', 'Users'), data: usersSeries }
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
				const label = dimValue(row);
				if (label === null) return;
				labels.push(label);
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
				const dev = dimValue(row);
				if (dev === null) return;
				// Translate device name
				const translatedDev = dev === 'desktop' ? t('desktop', 'Desktop') : (dev === 'mobile' ? t('mobile', 'Mobile') : t('tablet', 'Tablet'));
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
		const $tbody = $('#trackly-pages-table tbody');
		$tbody.empty();

		if (!pagesData.rows || pagesData.rows.length === 0) {
			$tbody.append('<tr><td colspan="5" class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</td></tr>');
			return;
		}

		pagesData.rows.forEach(function(row) {
			const path = dimValue(row);
			if (path === null) return;
			const escPath = escapeHtml(path);
			const escHref = escapeHtml(safeHref(path));
			const views = parseInt(row.metricValues[0].value).toLocaleString();
			const users = parseInt(row.metricValues[1].value).toLocaleString();
			const bounce = (parseFloat(row.metricValues[2].value) * 100).toFixed(1) + '%';
			const duration = formatDuration(row.metricValues[3].value);

			const html = `
				<tr>
					<td><a href="${escHref}" target="_blank" rel="noopener nofollow" class="trackly-page-link"><code>${escPath}</code></a></td>
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
