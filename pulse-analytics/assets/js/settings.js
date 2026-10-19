/* Pulse Analytics: settings screen helpers. */
(function () {
	'use strict';

	var __ = window.wp && wp.i18n ? wp.i18n.__ : function (s) { return s; };
	var KEY = 'pulse_analytics_ignore';

	function get() {
		try { return window.localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
	}

	function set(on) {
		try {
			if (on) {
				window.localStorage.setItem(KEY, '1');
			} else {
				window.localStorage.removeItem(KEY);
			}
		} catch (e) {}
	}

	// "Exclude this browser" toggle.
	var button = document.getElementById('pa-ignore-toggle');
	var status = document.getElementById('pa-ignore-status');
	function paint() {
		if (!button) { return; }
		var on = get();
		button.textContent = on ? __('Include this browser again', 'pulse-analytics') : __('Exclude this browser from statistics', 'pulse-analytics');
		status.textContent = on ? __('Excluded', 'pulse-analytics') : __('Counted', 'pulse-analytics');
		status.className = 'pa-pill ' + (on ? 'pa-pill-warn' : 'pa-pill-ok');
	}
	if (button) {
		button.addEventListener('click', function () { set(!get()); paint(); });
		paint();
	}

	// Show fields that belong to the selected geolocation source.
	var sources = document.querySelectorAll('[data-pa-source]');
	function toggleSourceFields() {
		var current = '';
		sources.forEach(function (input) { if (input.checked) { current = input.value; } });
		document.querySelectorAll('[data-pa-show]').forEach(function (row) {
			row.style.display = row.getAttribute('data-pa-show').split(' ').indexOf(current) > -1 ? '' : 'none';
		});
	}
	sources.forEach(function (input) { input.addEventListener('change', toggleSourceFields); });
	if (sources.length) { toggleSourceFields(); }

	// Confirm destructive actions.
	document.querySelectorAll('form[data-pa-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(form.getAttribute('data-pa-confirm'))) { e.preventDefault(); }
		});
	});

	// Raw export date range.
	var start = document.getElementById('pa-export-start');
	var end = document.getElementById('pa-export-end');
	function exportUrl(link) {
		var url = new URL(link.href, window.location.href);
		if (start && start.value) { url.searchParams.set('start', start.value); }
		if (end && end.value) { url.searchParams.set('end', end.value); }
		return url;
	}
	document.querySelectorAll('[data-pa-export]').forEach(function (link) {
		link.addEventListener('click', function () {
			link.href = exportUrl(link).toString();
		});
	});

	// PDF report: the download link follows the dates and comparison chosen in the card.
	var pdfLink = document.getElementById('pa-report-download');
	if (pdfLink) {
		pdfLink.addEventListener('click', function () {
			var url = new URL(pdfLink.href, window.location.href);
			['start', 'end', 'compare'].forEach(function (key) {
				var field = document.getElementById('pa-report-' + key);
				if (field) { url.searchParams.set(key, field.value); }
			});
			pdfLink.href = url.toString();
		});
	}

	// Logo for reports, picked from the media library.
	var logoField = document.querySelector('[data-pa-logo]');
	if (logoField && window.wp && wp.media) {
		var logoInput = logoField.querySelector('[data-pa-logo-input]');
		var logoPreview = logoField.querySelector('.pa-logo-preview');
		var removeBtn = logoField.querySelector('[data-pa-logo-remove]');
		var frame = null;
		var showLogo = function (url) {
			while (logoPreview.firstChild) { logoPreview.removeChild(logoPreview.firstChild); }
			if (url) {
				var img = document.createElement('img');
				img.src = url;
				img.alt = '';
				logoPreview.appendChild(img);
			} else {
				var empty = document.createElement('span');
				empty.className = 'pa-logo-empty';
				empty.textContent = __('No logo chosen', 'pulse-analytics');
				logoPreview.appendChild(empty);
			}
			removeBtn.hidden = !url;
		};
		logoField.querySelector('[data-pa-logo-choose]').addEventListener('click', function () {
			if (!frame) {
				frame = wp.media({
					title: __('Choose a logo for reports', 'pulse-analytics'),
					library: { type: 'image' },
					button: { text: __('Use this logo', 'pulse-analytics') },
					multiple: false
				});
				frame.on('select', function () {
					var image = frame.state().get('selection').first().toJSON();
					logoInput.value = image.id;
					showLogo(image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url);
				});
			}
			frame.open();
		});
		removeBtn.addEventListener('click', function () {
			logoInput.value = '0';
			showLogo('');
		});
	}

	// Prompt for AI tools, for the export picked in the list.
	var dialog = document.getElementById('pa-prompt-dialog');
	var openBtn = document.getElementById('pa-prompt-open');
	if (dialog && openBtn) {
		var text = document.getElementById('pa-prompt-text');
		var copyBtn = document.getElementById('pa-prompt-copy');
		var copyLabel = copyBtn.textContent;
		var requested = '';
		var close = function () {
			if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
		};
		var selectAndCopy = function () {
			text.focus();
			text.select();
			try { return document.execCommand('copy'); } catch (e) { return false; }
		};
		var copied = function () {
			copyBtn.textContent = __('Copied', 'pulse-analytics');
			setTimeout(function () { copyBtn.textContent = copyLabel; }, 1800);
		};

		openBtn.addEventListener('click', function () {
			var link = document.querySelector('[data-pa-export="' + document.getElementById('pa-prompt-type').value + '"]');
			if (!link) { return; }
			var url = exportUrl(link);
			url.searchParams.set('format', 'prompt');
			requested = url.toString();
			text.value = __('Writing the prompt...', 'pulse-analytics');
			copyBtn.disabled = true;
			if (typeof dialog.showModal === 'function') {
				if (!dialog.open) { dialog.showModal(); }
			} else {
				dialog.setAttribute('open', '');
			}
			var mine = requested;
			fetch(mine, { credentials: 'same-origin' }).then(function (res) {
				if (!res.ok) { throw new Error(String(res.status)); }
				return res.text();
			}).then(function (body) {
				if (mine !== requested) { return; }
				text.value = body;
				text.scrollTop = 0;
				copyBtn.disabled = false;
			}).catch(function () {
				if (mine !== requested) { return; }
				text.value = __('The prompt could not be loaded. Reload the page and try again.', 'pulse-analytics');
			});
		});

		copyBtn.addEventListener('click', function () {
			if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
				navigator.clipboard.writeText(text.value).then(copied, function () {
					if (selectAndCopy()) { copied(); }
				});
			} else if (selectAndCopy()) {
				copied();
			}
		});
		dialog.querySelectorAll('[data-pa-prompt-close]').forEach(function (btn) {
			btn.addEventListener('click', close);
		});
		dialog.addEventListener('click', function (e) {
			if (e.target === dialog) { close(); }
		});
	}
})();
