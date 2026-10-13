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
	document.querySelectorAll('[data-pa-export]').forEach(function (link) {
		link.addEventListener('click', function () {
			if (!start || !end) { return; }
			var url = new URL(link.href, window.location.href);
			if (start.value) { url.searchParams.set('start', start.value); }
			if (end.value) { url.searchParams.set('end', end.value); }
			link.href = url.toString();
		});
	});
})();
