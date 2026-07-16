/**
 * Gravity Analytics Frontend Admin Panel Script
 */
(function($) {
	'use strict';

	let isSelectorMode = false;
	let hoveredElement = null;
	let heatmapActive = false;

	$(document).ready(function() {
		if (parseInt(gravityAnalyticsPublicData.is_admin) === 1) {
			initAdminFloatingPanel();
		}
	});

	/**
	 * Premium Non-blocking Toast Notification System
	 */
	function showToast(message, type = 'success') {
		$('#gravity-stats-bar-wrapper .gravity-toast').remove();
		const $toast = $('<div class="gravity-toast"></div>').text(message);
		
		// Color scheme styles
		const bgColor = type === 'error' ? 'rgba(244, 63, 94, 0.95)' : 'rgba(16, 185, 129, 0.95)';
		
		$toast.css({
			position: 'fixed',
			bottom: '30px',
			left: '30px',
			background: bgColor,
			color: '#ffffff',
			padding: '14px 24px',
			borderRadius: '12px',
			boxShadow: '0 20px 40px -10px rgba(0, 0, 0, 0.3)',
			backdropFilter: 'blur(10px)',
			'-webkit-backdrop-filter': 'blur(10px)',
			zIndex: 1000000,
			fontFamily: "'Outfit', sans-serif",
			fontSize: '13px',
			fontWeight: '600',
			opacity: 0,
			transform: 'translateY(20px)',
			transition: 'all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)'
		});

		$('#gravity-stats-bar-wrapper').append($toast);

		setTimeout(() => {
			$toast.css({ opacity: 1, transform: 'translateY(0)' });
		}, 50);

		setTimeout(() => {
			$toast.css({ opacity: 0, transform: 'translateY(20px)' });
			setTimeout(() => $toast.remove(), 300);
		}, 3500);
	}

	/**
	 * Setup and display admin panel interaction
	 */
	function initAdminFloatingPanel() {
		const $toggleBtn = $('#gravity-stats-toggle-btn');
		const $panel = $('#gravity-stats-panel');
		const $minimizeBtn = $('#gravity-panel-minimize-btn');
		const $tabs = $('.gravity-panel-tab');

		$toggleBtn.on('click', function() {
			$panel.toggleClass('active');
			$toggleBtn.fadeOut(200);
			loadPageStats();
		});

		$minimizeBtn.on('click', function() {
			$panel.removeClass('active');
			$toggleBtn.fadeIn(200);
		});

		$tabs.on('click', function() {
			const tabId = $(this).data('tab');
			$tabs.removeClass('active');
			$(this).addClass('active');

			$('.gravity-panel-tab-content').removeClass('active');
			$('#gravity-tab-' + tabId).addClass('active');
		});

		$('#gravity-toggle-heatmap-btn').on('click', toggleHeatmap);
		$('#gravity-clear-heatmap-btn').on('click', clearHeatmapDots);

		$('#gravity-start-selector-btn').on('click', startSelectorMode);
		$('#gravity-cancel-event-btn').on('click', cancelSelectorMode);
		$('#gravity-save-event-btn').on('click', saveCustomEvent);
	}

	/**
	 * Fetch page stats via WP REST API and run recommendations engine
	 */
	function loadPageStats() {
		$.ajax({
			url: gravityAnalyticsPublicData.rest_url + '/page-stats',
			method: 'GET',
			data: { url: gravityAnalyticsPublicData.page_url },
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', gravityAnalyticsPublicData.rest_nonce);
			},
			success: function(res) {
				if (res.success && res.report.rows && res.report.rows.length > 0) {
					const metrics = res.report.rows[0].metricValues;
					const views = parseInt(metrics[0].value);
					const users = parseInt(metrics[1].value);
					const bounce = parseFloat(metrics[2].value);
					const duration = parseInt(metrics[3].value);

					$('#gravity-p-views').text(views.toLocaleString());
					$('#gravity-p-users').text(users.toLocaleString());
					$('#gravity-p-bounce').text((bounce * 100).toFixed(1) + '%');
					
					const mins = Math.floor(duration / 60);
					const secs = duration % 60;
					$('#gravity-p-duration').text(mins + ':' + (secs < 10 ? '0' : '') + secs);

					generateAIInsights(views, users, bounce, duration);
				} else {
					$('#gravity-p-views').text('0');
					$('#gravity-p-users').text('0');
					$('#gravity-p-bounce').text('0%');
					$('#gravity-p-duration').text('0:00');
					generateAIInsights(0, 0, 0, 0);
				}
			}
		});
	}

	/**
	 * Context-aware recommendations engine
	 */
	function generateAIInsights(views, users, bounce, duration) {
		const $insights = $('#gravity-ai-insights-content');
		$insights.empty();

		if (views === 0) {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-warning ai-icon red"></span>
					<div class="ai-text">
						<strong>Veri Bekleniyor</strong>
						<p>Sayfada henüz yeterli trafik verisi toplanmadı. Demo modunu test edebilir veya GA4 entegrasyonunu doğrulayabilirsiniz.</p>
					</div>
				</div>
			`);
			return;
		}

		if (bounce > 0.55) {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-flag ai-icon red"></span>
					<div class="ai-text">
						<strong>Hemen Çıkma Oranı Yüksek (%${(bounce * 100).toFixed(1)})</strong>
						<p>Ziyaretçiler sayfadan hızlıca ayrılıyor. İçeriğin başlığıyla uyumunu kontrol edin veya sayfa sonuna ilgi çekici bir CTA yerleştirin.</p>
					</div>
				</div>
			`);
		} else {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-yes-alt ai-icon cyan"></span>
					<div class="ai-text">
						<strong>Hemen Çıkma Oranı Sağlıklı (%${(bounce * 100).toFixed(1)})</strong>
						<p>Ziyaretçileriniz sayfada gezinmeye istekli. Tebrikler, içerik hedefe ulaşıyor!</p>
					</div>
				</div>
			`);
		}

		if (duration < 90) {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-clock ai-icon purple"></span>
					<div class="ai-text">
						<strong>Sayfada Kalma Süresi Düşük (${duration}sn)</strong>
						<p>Ziyaretçiler sayfayı okumuyor olabilir. Giriş paragrafını kısaltın ve görsel ögelerle sayfayı canlandırın.</p>
					</div>
				</div>
			`);
		} else {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-clock ai-icon cyan"></span>
					<div class="ai-text">
						<strong>Sayfada Kalma Süresi Harika (${Math.floor(duration/60)}dk ${duration%60}sn)</strong>
						<p>Kullanıcılar içeriğinizi derinlemesine okuyor. Bu sayfayı e-posta bülteni abonelikleri toplamak için kullanabilirsiniz.</p>
					</div>
				</div>
			`);
		}

		if (views > 100) {
			$insights.append(`
				<div class="ai-insight-item">
					<span class="dashicons dashicons-lightbulb ai-icon purple"></span>
					<div class="ai-text">
						<strong>Dönüşüm Ölçümleme Önerisi</strong>
						<p>Bu sayfa ${views} kez görüntülendi! Sayfadaki butonları izlemek için yan sekmedeki <strong>Event Sihirbazı</strong>'nı çalıştırın.</p>
					</div>
				</div>
			`);
		}
	}

	/**
	 * Toggle Click Heatmap Overlay
	 */
	function toggleHeatmap() {
		const $btn = $('#gravity-toggle-heatmap-btn');
		if (heatmapActive) {
			clearHeatmapDots();
			$btn.html('<span class="dashicons dashicons-visibility"></span> Haritayı Göster').removeClass('secondary');
			$('.heatmap-info-stats').fadeOut(200);
			heatmapActive = false;
		} else {
			$btn.text('Yükleniyor...');
			fetchHeatmapData();
		}
	}

	/**
	 * Fetch recorded clicks and render heatmap indicators
	 */
	function fetchHeatmapData() {
		$.ajax({
			url: gravityAnalyticsPublicData.rest_url + '/clicks',
			method: 'GET',
			data: { url: gravityAnalyticsPublicData.page_url },
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', gravityAnalyticsPublicData.rest_nonce);
			},
			success: function(res) {
				if (res.success && res.clicks.length > 0) {
					renderHeatmap(res.clicks);
					$('#gravity-heatmap-click-count').text(res.clicks.length);
					$('.heatmap-info-stats').fadeIn(200);
					
					$('#gravity-toggle-heatmap-btn').html('<span class="dashicons dashicons-hidden"></span> Haritayı Gizle').addClass('secondary');
					heatmapActive = true;
				} else {
					showToast('Bu sayfa için henüz tıklama kaydı bulunamadı.', 'error');
					$('#gravity-toggle-heatmap-btn').html('<span class="dashicons dashicons-visibility"></span> Haritayı Göster');
				}
			},
			error: function() {
				showToast('Tıklama verileri çekilirken bir hata oluştu.', 'error');
				$('#gravity-toggle-heatmap-btn').html('<span class="dashicons dashicons-visibility"></span> Haritayı Göster');
			}
		});
	}

	/**
	 * Render percentage-normalized click dots.
	 * Explicitly sets body position to relative to prevent coordinate shifts.
	 */
	function renderHeatmap(clicks) {
		clearHeatmapDots();
		
		// Injects temporary body styling to ensure correct absolute offsets (Fixes Heatmap alignment QA bug)
		$('body').css('position', 'relative');
		
		clicks.forEach(function(click) {
			const $dot = $('<div class="gravity-heatmap-dot"></div>');
			$dot.css({
				left: click.click_x_pct + '%',
				top: click.click_y_pct + '%'
			});
			$('body').append($dot);
		});
	}

	function clearHeatmapDots() {
		$('.gravity-heatmap-dot').remove();
		$('body').css('position', ''); // Restores body static positioning on heatmap close
		if (heatmapActive) {
			$('#gravity-toggle-heatmap-btn').html('<span class="dashicons dashicons-visibility"></span> Haritayı Göster').removeClass('secondary');
			$('.heatmap-info-stats').fadeOut(200);
			heatmapActive = false;
		}
	}

	/**
	 * GA4 Event Builder Selector Mode
	 */
	function startSelectorMode() {
		isSelectorMode = true;
		window.gravitySelectorModeActive = true; // Block click tracker global logging
		$('#gravity-stats-panel').removeClass('active');

		$('body').css('cursor', 'crosshair');

		$(document).on('mouseover.gravitySelector', handleSelectorMouseOver);
		$(document).on('mouseout.gravitySelector', handleSelectorMouseOut);
		$(document).on('click.gravitySelector', handleSelectorClick);
	}

	function handleSelectorMouseOver(e) {
		if ($(e.target).closest('#gravity-stats-bar-wrapper').length) return;
		hoveredElement = e.target;
		$(hoveredElement).addClass('gravity-selector-hovered');
	}

	function handleSelectorMouseOut(e) {
		if (hoveredElement) {
			$(hoveredElement).removeClass('gravity-selector-hovered');
			hoveredElement = null;
		}
	}

	function handleSelectorClick(e) {
		if ($(e.target).closest('#gravity-stats-bar-wrapper').length) return;

		e.preventDefault();
		e.stopPropagation();

		const selector = getUniqueSelector(e.target);
		$(e.target).removeClass('gravity-selector-hovered');
		exitSelectorMode();

		$('#gravity-selected-selector-display').text(selector);
		$('#gravity-p-event-name').val('');
		
		$('#gravity-builder-setup').hide();
		$('#gravity-builder-form').show();
		
		$('#gravity-stats-panel').addClass('active');
	}

	function cancelSelectorMode() {
		$('#gravity-builder-setup').show();
		$('#gravity-builder-form').hide();
	}

	function exitSelectorMode() {
		isSelectorMode = false;
		window.gravitySelectorModeActive = false; // Re-enable click tracker
		$('body').css('cursor', 'default');
		
		$(document).off('mouseover.gravitySelector');
		$(document).off('mouseout.gravitySelector');
		$(document).off('click.gravitySelector');
	}

	/**
	 * Unique CSS Selector builder supporting SVG elements class names
	 */
	function getUniqueSelector(el) {
		if (!(el instanceof HTMLElement)) return '';
		let path = [];
		while (el.nodeType === Node.ELEMENT_NODE) {
			let selector = el.nodeName.toLowerCase();
			if (el.id) {
				selector += '#' + el.id;
				path.unshift(selector);
				break;
			} else {
				let className = '';
				if (typeof el.className === 'string') {
					className = el.className.trim();
				} else if (el.getAttribute) {
					className = (el.getAttribute('class') || '').trim();
				}

				className = className.replace('.gravity-selector-hovered', '');
				if (className) {
					selector += '.' + className.replace(/\s+/g, '.');
				}
				
				let sib = el, nth = 1;
				while (sib = sib.previousElementSibling) {
					if (sib.nodeName.toLowerCase() === el.nodeName.toLowerCase()) nth++;
				}
				if (nth !== 1) {
					selector += `:nth-of-type(${nth})`;
				}
			}
			path.unshift(selector);
			el = el.parentNode;
		}
		return path.join(' > ');
	}

	/**
	 * Save custom event mapping to database
	 */
	function saveCustomEvent() {
		const selector = $('#gravity-selected-selector-display').text();
		const eventName = $('#gravity-p-event-name').val().trim();

		if (!eventName) {
			showToast('Lütfen geçerli bir etkinlik adı girin.', 'error');
			return;
		}

		// Relaxed XSS Check (Allows quotes for valid selectors like input[type="text"])
		if (/[<>]/.test(selector)) {
			showToast('Geçersiz CSS Seçici.', 'error');
			return;
		}

		$.ajax({
			url: gravityAnalyticsPublicData.rest_url + '/save-event',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({
				selector: selector,
				event_name: eventName
			}),
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', gravityAnalyticsPublicData.rest_nonce);
			},
			success: function(res) {
				if (res.success) {
					showToast(`Başarılı! "${eventName}" GA4 etkinliği kaydedildi.`, 'success');
					cancelSelectorMode();
				} else {
					showToast('Etkinlik kaydedilirken hata oluştu.', 'error');
				}
			},
			error: function() {
				showToast('Sunucu hatası oluştu.', 'error');
			}
		});
	}

})(jQuery);
