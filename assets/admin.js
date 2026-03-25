/* global shopwalkAdmin, ajaxurl */
(function ($) {
	'use strict';

	var s = shopwalkAdmin || {};

	function showResult($el, type, msg) {
		$el.removeClass('sw-result--success sw-result--error')
			.addClass('sw-result--' + type)
			.text(msg)
			.show();
	}

	// ── License activation (free state) ──────────────────────────────────────

	$('#sw-activate-btn').on('click', function () {
		var key = $('#sw-license-key').val().trim();
		if (!key) return;

		var $btn = $(this).prop('disabled', true).text(s.strings.activating);
		$('#sw-activate-result').hide();

		$.post(s.ajaxUrl, {
			action: 'shopwalk_activate_license',
			nonce: s.nonce,
			license_key: key
		}, function (resp) {
			$btn.prop('disabled', false).text('Activate License');
			if (resp.success) {
				showResult($('#sw-activate-result'), 'success', resp.data.message);
				// Reload after short delay so licensed dashboard renders
				setTimeout(function () { location.reload(); }, 1500);
			} else {
				showResult($('#sw-activate-result'), 'error', resp.data.message);
			}
		});
	});

	// ── Partners Portal (licensed state) ─────────────────────────────────────

	$('#sw-portal-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true).text('Opening…');
		$('#sw-portal-result').hide();

		$.post(s.ajaxUrl, {
			action: 'shopwalk_open_portal',
			nonce: s.nonce
		}, function (resp) {
			$btn.prop('disabled', false).html('🚀 Open Partners Portal');
			if (resp.success && resp.data.url) {
				window.open(resp.data.url, '_blank');
			} else {
				showResult($('#sw-portal-result'), 'error', 'Could not open portal. Please visit shopwalk.com/partners directly.');
			}
		});
	});

	// ── Manual sync ───────────────────────────────────────────────────────────

	$('#sw-sync-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true).text(s.strings.syncing);
		$('#sw-sync-result').hide();

		$.post(s.ajaxUrl, {
			action: 'shopwalk_manual_sync',
			nonce: s.nonce
		}, function (resp) {
			$btn.prop('disabled', false).html('🔄 Sync Now');
			if (resp.success) {
				showResult($('#sw-sync-result'), 'success', s.strings.syncDone);
				// Update status line
				var d = resp.data;
				$('#sw-sync-status').text(
					d.synced_count + ' products synced · Just now · ' + d.queue_count + ' pending'
				);
			} else {
				showResult($('#sw-sync-result'), 'error', resp.data.message || 'Sync failed.');
			}
		});
	});

	// ── Deactivate license ────────────────────────────────────────────────────

	$('#sw-deactivate-btn').on('click', function () {
		if (!window.confirm(s.strings.confirm)) return;

		var $btn = $(this).prop('disabled', true).text(s.strings.deactivating);
		$('#sw-deactivate-result').hide();

		$.post(s.ajaxUrl, {
			action: 'shopwalk_deactivate_license',
			nonce: s.nonce
		}, function (resp) {
			$btn.prop('disabled', false);
			if (resp.success) {
				showResult($('#sw-deactivate-result'), 'success', resp.data.message);
				setTimeout(function () { location.reload(); }, 1200);
			} else {
				showResult($('#sw-deactivate-result'), 'error', resp.data.message || 'Deactivation failed.');
			}
		});
	});

	// ── Diagnostics ───────────────────────────────────────────────────────────

	$('#sw-diag-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true).text('Running…');
		var $results = $('#sw-diag-results').show().html('<p>Running checks…</p>');

		$.post(s.ajaxUrl, {
			action: 'shopwalk_run_diagnostics',
			nonce: s.nonce
		}, function (resp) {
			$btn.prop('disabled', false).html('🔍 Run Diagnostics');
			if (!resp.success || !resp.data || !resp.data.checks) {
				$results.html('<p>Failed to run diagnostics.</p>');
				return;
			}
			var html = '<ul class="sw-diag-list">';
			$.each(resp.data.checks, function (i, c) {
				html += '<li>';
				html += '<span class="sw-diag-icon">' + (c.ok ? '✅' : '❌') + '</span>';
				html += '<div>';
				html += '<div class="sw-diag-name">' + $('<div>').text(c.name).html() + '</div>';
				html += '<div class="sw-diag-val">' + $('<div>').text(c.value).html() + '</div>';
				if (!c.ok && c.fix) {
					html += '<div class="sw-diag-fix">' + $('<div>').text(c.fix).html() + '</div>';
				}
				html += '</div></li>';
			});
			html += '</ul>';
			$results.html(html);
		});
	});

}(jQuery));
