/*!
 * Pulse Analytics dashboard: shared helpers, data loading and UI components.
 */
(function (window, document) {
	'use strict';

	var C = window.PulseAnalytics;
	if (!C) {
		return;
	}

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function (s) { return s; };
	var _n = i18n ? i18n._n : function (s, p, n) { return n === 1 ? s : p; };
	var sprintf = i18n ? i18n.sprintf : function (format) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(format).replace(/%(\d+\$)?[sd]/g, function () { return args[i++]; });
	};

	var App = window.PulseApp = { C: C, __: __, _n: _n, sprintf: sprintf };

	/* =================================================================== *
	 * Colors (a channel or device keeps the same color everywhere)
	 * =================================================================== */

	var SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
	var OTHER = '#a9a8a2';
	var RAMP = ['#e9e9e6', '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b'];
	var CHANNEL_COLORS = {
		organic_search: SERIES[0],
		direct: SERIES[1],
		social: SERIES[2],
		referral: SERIES[3],
		email: SERIES[4],
		paid_search: SERIES[5],
		ai: SERIES[6],
		paid_social: SERIES[7]
	};
	var DEVICE_COLORS = { desktop: SERIES[0], mobile: SERIES[1], tablet: SERIES[2], tv: SERIES[3] };
	App.palette = { series: SERIES, other: OTHER, ramp: RAMP, channels: CHANNEL_COLORS, devices: DEVICE_COLORS };

	App.channelColor = function (key) {
		return CHANNEL_COLORS[key] || OTHER;
	};

	/* =================================================================== *
	 * DOM helpers
	 * =================================================================== */

	function h(tag, props, children) {
		var el = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (key) {
				var v = props[key];
				if (v === null || v === undefined || v === false) {
					return;
				}
				if (key === 'class') {
					el.className = v;
				} else if (key === 'text') {
					el.textContent = v;
				} else if (key === 'html') {
					el.innerHTML = v; // Only ever used with static, trusted markup (icons).
				} else if (key.slice(0, 2) === 'on' && typeof v === 'function') {
					el.addEventListener(key.slice(2).toLowerCase(), v);
				} else if (key === 'style' && typeof v === 'object') {
					Object.keys(v).forEach(function (s) { el.style[s] = v[s]; });
				} else if (key === 'dataset') {
					Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; });
				} else {
					el.setAttribute(key, v === true ? '' : v);
				}
			});
		}
		append(el, children);
		return el;
	}

	function append(el, children) {
		(Array.isArray(children) ? children : [children]).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			if (Array.isArray(child)) {
				append(el, child);
			} else {
				el.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
			}
		});
		return el;
	}

	function clear(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
		return el;
	}

	App.h = h;
	App.append = append;
	App.clear = clear;

	/* =================================================================== *
	 * Icons (static inline SVG)
	 * =================================================================== */

	var ICONS = {
		calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		chevronDown: '<path d="M6 9l6 6 6-6"/>',
		chevronLeft: '<path d="M15 6l-6 6 6 6"/>',
		chevronRight: '<path d="M9 6l6 6-6 6"/>',
		refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
		download: '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
		settings: '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
		filter: '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
		close: '<path d="M6 6l12 12M18 6L6 18"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
		external: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		desktop: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
		mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
		tablet: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M11 18h2"/>',
		tv: '<rect x="2" y="5" width="20" height="13" rx="2"/><path d="M8 21h8"/>',
		zap: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
		target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.8 5.5 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.5-3.8-9S9.5 5.5 12 3z"/>',
		users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.8.8 3 2.6 3.5 5.2"/>',
		eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		file: '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/>',
		link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		phone: '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
		pointer: '<path d="M5 3l14 7-6 2-2 6z"/>',
		activity: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
		edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		check: '<path d="M5 12l5 5 9-10"/>',
		copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
		table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 10v10"/>',
		chart: '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
		pin: '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
		layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
		enter: '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M14 7l5 5-5 5M19 12H8"/>',
		leave: '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>',
		sparkles: '<path d="M12 3l1.8 4.7 4.7 1.8-4.7 1.8L12 16l-1.8-4.7-4.7-1.8 4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
		arrowUp: '<path d="M12 19V5M6 11l6-6 6 6"/>',
		arrowDown: '<path d="M12 5v14M6 13l6 6 6-6"/>',
		pause: '<path d="M8 5v14M16 5v14"/>',
		play: '<path d="M7 4l13 8-13 8z"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.2 4.2-6.5 8-6.5s7 2.3 8 6.5"/>',
		megaphone: '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1z"/><path d="M17 8a5 5 0 0 1 0 8"/>',
		cursor: '<path d="M4 4l7 17 2.5-7.5L21 11z"/>'
	};

	function icon(name, extraClass) {
		return h('span', { 'class': 'pa-i' + (extraClass ? ' ' + extraClass : ''), 'aria-hidden': 'true', html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || ICONS.info) + '</svg>' });
	}
	App.icon = icon;

	/* =================================================================== *
	 * Formatting
	 * =================================================================== */

	var locale = C.locale || 'en-US';
	var numberFormat, compactFormat, decimalFormat;
	try {
		numberFormat = new Intl.NumberFormat(locale);
		decimalFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 1, minimumFractionDigits: 0 });
	} catch (e) {
		numberFormat = new Intl.NumberFormat('en-US');
		decimalFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
	}
	try {
		compactFormat = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
	} catch (e2) {
		compactFormat = null;
	}

	var fmt = App.fmt = {
		num: function (v) {
			return v === null || v === undefined ? '-' : numberFormat.format(Math.round(v));
		},
		compact: function (v) {
			if (v === null || v === undefined) { return '-'; }
			if (Math.abs(v) < 10000 || !compactFormat) { return numberFormat.format(Math.round(v * 100) / 100); }
			return compactFormat.format(v);
		},
		dec: function (v, digits) {
			if (v === null || v === undefined) { return '-'; }
			return new Intl.NumberFormat(locale, { maximumFractionDigits: digits === undefined ? 1 : digits }).format(v);
		},
		pct: function (v, digits) {
			if (v === null || v === undefined || !isFinite(v)) { return '-'; }
			return (digits === 0 ? numberFormat.format(Math.round(v)) : decimalFormat.format(v)) + '%';
		},
		dur: function (s) {
			if (s === null || s === undefined || !isFinite(s)) { return '-'; }
			s = Math.round(s);
			if (s < 60) { return s + 's'; }
			if (s < 3600) { return Math.floor(s / 60) + 'm ' + ('0' + (s % 60)).slice(-2) + 's'; }
			return Math.floor(s / 3600) + 'h ' + ('0' + Math.floor((s % 3600) / 60)).slice(-2) + 'm';
		}
	};

	var METRICS = App.METRICS = {
		visitors: { label: __('Visitors', 'pulse-analytics'), format: fmt.num, axis: fmt.compact },
		visits: { label: __('Visits', 'pulse-analytics'), format: fmt.num, axis: fmt.compact },
		pageviews: { label: __('Pageviews', 'pulse-analytics'), format: fmt.num, axis: fmt.compact },
		views_per_visit: { label: __('Views per visit', 'pulse-analytics'), format: function (v) { return fmt.dec(v, 2); }, axis: function (v) { return fmt.dec(v, 1); } },
		bounce_rate: { label: __('Bounce rate', 'pulse-analytics'), format: fmt.pct, axis: function (v) { return fmt.pct(v, 0); }, lowerIsBetter: true },
		avg_duration: { label: __('Visit duration', 'pulse-analytics'), format: fmt.dur, axis: fmt.dur },
		avg_time: { label: __('Time on page', 'pulse-analytics'), format: fmt.dur, axis: fmt.dur },
		avg_scroll: { label: __('Scroll depth', 'pulse-analytics'), format: function (v) { return fmt.pct(v, 0); }, axis: fmt.pct },
		entrances: { label: __('Entrances', 'pulse-analytics'), format: fmt.num },
		exits: { label: __('Exits', 'pulse-analytics'), format: fmt.num },
		exit_rate: { label: __('Exit rate', 'pulse-analytics'), format: fmt.pct, lowerIsBetter: true },
		events: { label: __('Events', 'pulse-analytics'), format: fmt.num },
		conversions: { label: __('Conversions', 'pulse-analytics'), format: fmt.num },
		cr: { label: __('Conv. rate', 'pulse-analytics'), format: fmt.pct },
		completions: { label: __('Completions', 'pulse-analytics'), format: fmt.num }
	};

	App.metricFormat = function (key, v) {
		return METRICS[key] ? METRICS[key].format(v) : fmt.num(v);
	};

	App.change = function (current, previous) {
		if (previous === null || previous === undefined || current === null || current === undefined || !previous) {
			return null;
		}
		return (current - previous) / previous * 100;
	};

	// Change badge. Green means the change is good, red means bad (for bounce and exit rate, lower is better).
	App.deltaBadge = function (metric, current, previous) {
		var pct = App.change(current, previous);
		if (pct === null) {
			return h('span', { 'class': 'pa-delta is-flat', title: __('No data for the comparison period', 'pulse-analytics') }, '-');
		}
		var up = pct >= 0;
		var lowerBetter = METRICS[metric] && METRICS[metric].lowerIsBetter;
		var good = Math.abs(pct) < 0.05 ? null : (lowerBetter ? !up : up);
		var cls = good === null ? 'is-flat' : (good ? 'is-good' : 'is-bad');
		return h('span', { 'class': 'pa-delta ' + cls, title: sprintf(__('Previous period: %s', 'pulse-analytics'), App.metricFormat(metric, previous)) }, [
			icon(up ? 'arrowUp' : 'arrowDown'),
			fmt.pct(Math.abs(pct), Math.abs(pct) >= 100 ? 0 : 1)
		]);
	};

	/* =================================================================== *
	 * Dates
	 * =================================================================== */

	function parseDate(str) {
		var p = String(str).split(/[- :]/).map(Number);
		return new Date(Date.UTC(p[0], (p[1] || 1) - 1, p[2] || 1, p[3] || 0, p[4] || 0));
	}

	function isoDate(date) {
		return date.toISOString().slice(0, 10);
	}

	function addDays(str, days) {
		var d = parseDate(str);
		d.setUTCDate(d.getUTCDate() + days);
		return isoDate(d);
	}

	var dateFmtCache = {};
	function dateFormat(str, options) {
		var key = JSON.stringify(options);
		if (!dateFmtCache[key]) {
			try {
				dateFmtCache[key] = new Intl.DateTimeFormat(locale, Object.assign({ timeZone: 'UTC' }, options));
			} catch (e) {
				dateFmtCache[key] = new Intl.DateTimeFormat('en-US', Object.assign({ timeZone: 'UTC' }, options));
			}
		}
		return dateFmtCache[key].format(parseDate(str));
	}

	App.dates = {
		parse: parseDate,
		iso: isoDate,
		add: addDays,
		format: dateFormat,
		short: function (str) { return dateFormat(str, { month: 'short', day: 'numeric' }); },
		long: function (str) { return dateFormat(str, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }); },
		time: function (str) { return dateFormat(str, { hour: 'numeric', minute: '2-digit' }); },
		dateTime: function (str) { return dateFormat(str, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); },
		bucket: function (label, interval, long) {
			if (!label) { return ''; }
			if (interval === 'hour') {
				return long ? dateFormat(label, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric' }) : dateFormat(label, { hour: 'numeric' });
			}
			if (interval === 'month') {
				return dateFormat(label, { month: long ? 'long' : 'short', year: 'numeric' });
			}
			if (interval === 'week') {
				return long ? sprintf(__('Week of %s', 'pulse-analytics'), dateFormat(label, { month: 'short', day: 'numeric', year: 'numeric' })) : dateFormat(label, { month: 'short', day: 'numeric' });
			}
			return long ? dateFormat(label, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : dateFormat(label, { month: 'short', day: 'numeric' });
		},
		ago: function (seconds) {
			if (seconds < 60) { return sprintf(__('%ss ago', 'pulse-analytics'), Math.max(0, Math.round(seconds))); }
			if (seconds < 3600) { return sprintf(__('%sm ago', 'pulse-analytics'), Math.floor(seconds / 60)); }
			return sprintf(__('%sh ago', 'pulse-analytics'), Math.floor(seconds / 3600));
		}
	};

	/* =================================================================== *
	 * Labels for dimensions and values
	 * =================================================================== */

	App.DIMENSIONS = {
		page: __('Page', 'pulse-analytics'),
		entry_page: __('Entry page', 'pulse-analytics'),
		exit_page: __('Exit page', 'pulse-analytics'),
		post_type: __('Content type', 'pulse-analytics'),
		channel: __('Channel', 'pulse-analytics'),
		source: __('Source', 'pulse-analytics'),
		referrer: __('Referrer', 'pulse-analytics'),
		referrer_url: __('Referrer URL', 'pulse-analytics'),
		utm_campaign: __('UTM campaign', 'pulse-analytics'),
		utm_source: __('UTM source', 'pulse-analytics'),
		utm_medium: __('UTM medium', 'pulse-analytics'),
		utm_term: __('UTM term', 'pulse-analytics'),
		utm_content: __('UTM content', 'pulse-analytics'),
		country: __('Country', 'pulse-analytics'),
		region: __('Region', 'pulse-analytics'),
		city: __('City', 'pulse-analytics'),
		continent: __('Continent', 'pulse-analytics'),
		language: __('Language', 'pulse-analytics'),
		device: __('Device', 'pulse-analytics'),
		browser: __('Browser', 'pulse-analytics'),
		browser_version: __('Browser version', 'pulse-analytics'),
		os: __('Operating system', 'pulse-analytics'),
		os_version: __('OS version', 'pulse-analytics'),
		screen: __('Screen size', 'pulse-analytics'),
		visitor_type: __('Visitor type', 'pulse-analytics'),
		user_type: __('User type', 'pulse-analytics'),
		event_category: __('Event type', 'pulse-analytics'),
		event_name: __('Event', 'pulse-analytics'),
		event_label: __('Event detail', 'pulse-analytics'),
		goal: __('Goal', 'pulse-analytics')
	};

	App.FILTER_GROUPS = [
		{ label: __('Content', 'pulse-analytics'), dims: ['page', 'entry_page', 'exit_page', 'post_type'] },
		{ label: __('Acquisition', 'pulse-analytics'), dims: ['channel', 'source', 'referrer', 'utm_campaign', 'utm_source', 'utm_medium', 'utm_term', 'utm_content'] },
		{ label: __('Location', 'pulse-analytics'), dims: ['country', 'region', 'city', 'continent', 'language'] },
		{ label: __('Technology', 'pulse-analytics'), dims: ['device', 'browser', 'os', 'screen'] },
		{ label: __('Behaviour', 'pulse-analytics'), dims: ['visitor_type', 'user_type', 'event_category', 'event_name', 'goal'] }
	];

	App.OPS = {
		is: __('is', 'pulse-analytics'),
		is_not: __('is not', 'pulse-analytics'),
		contains: __('contains', 'pulse-analytics'),
		not_contains: __('does not contain', 'pulse-analytics')
	};

	var DEVICE_LABELS = { desktop: __('Desktop', 'pulse-analytics'), mobile: __('Mobile', 'pulse-analytics'), tablet: __('Tablet', 'pulse-analytics'), tv: __('TV', 'pulse-analytics') };
	App.deviceLabel = function (key) {
		return DEVICE_LABELS[key] || __('Unknown', 'pulse-analytics');
	};

	App.countryName = function (code) {
		if (!code) { return __('Unknown', 'pulse-analytics'); }
		return C.countries[code] ? C.countries[code][0] : code;
	};

	var languageNames = null;
	try { languageNames = new Intl.DisplayNames([locale], { type: 'language' }); } catch (e3) { languageNames = null; }
	App.languageName = function (code) {
		if (!code) { return __('Unknown', 'pulse-analytics'); }
		if (languageNames) {
			try { return languageNames.of(code.replace(/_/g, '-')); } catch (e) { return code; }
		}
		return code;
	};

	App.goalName = function (id) {
		var goal = (C.goals || []).filter(function (g) { return g.id === id; })[0];
		return goal ? goal.name : id;
	};

	// Display label of a filter value.
	App.valueLabel = function (dim, value) {
		if (Array.isArray(value)) {
			return value.map(function (v) { return App.valueLabel(dim, v); }).join(', ');
		}
		switch (dim) {
			case 'country': return App.countryName(value);
			case 'continent': return C.continents[value] || value;
			case 'channel': return C.channels[value] || value;
			case 'device': return App.deviceLabel(value);
			case 'visitor_type': return value === 'new' ? __('New visitors', 'pulse-analytics') : __('Returning visitors', 'pulse-analytics');
			case 'user_type': return value === 'logged_in' ? __('Logged-in users', 'pulse-analytics') : __('Guests', 'pulse-analytics');
			case 'event_category': return C.eventCats[value] || value;
			case 'goal': return App.goalName(value);
			case 'language': return App.languageName(value);
			case 'source': return value === '' ? __('Direct / None', 'pulse-analytics') : value;
			default: return value === '' ? __('(not set)', 'pulse-analytics') : value;
		}
	};

	/* =================================================================== *
	 * Flags & favicons
	 * =================================================================== */

	if (C.externalAssets && C.flagFont && !document.getElementById('pa-flag-font')) {
		var style = document.createElement('style');
		style.id = 'pa-flag-font';
		style.textContent = '@font-face{font-family:"Twemoji Country Flags";unicode-range:U+1F1E6-1F1FF,U+1F3F4,U+E0062-E0063,U+E0065,U+E0067,U+E006C,U+E006E,U+E0073-E0074,U+E0077,U+E007F;src:url("' + C.flagFont + '") format("woff2");font-display:swap}';
		document.head.appendChild(style);
	}

	App.flag = function (code) {
		if (!code || !/^[A-Z]{2}$/.test(code)) {
			return icon('globe', 'pa-flag-icon');
		}
		var emoji = String.fromCodePoint(127397 + code.charCodeAt(0), 127397 + code.charCodeAt(1));
		return h('span', { 'class': 'pa-flag', 'aria-hidden': 'true', text: emoji });
	};

	var SOURCE_DOMAINS = {
		'Google': 'google.com', 'Bing': 'bing.com', 'Yahoo': 'yahoo.com', 'DuckDuckGo': 'duckduckgo.com', 'Baidu': 'baidu.com', 'Yandex': 'yandex.com', 'Ecosia': 'ecosia.org',
		'Facebook': 'facebook.com', 'Instagram': 'instagram.com', 'X (Twitter)': 'x.com', 'LinkedIn': 'linkedin.com', 'Pinterest': 'pinterest.com', 'Reddit': 'reddit.com',
		'YouTube': 'youtube.com', 'TikTok': 'tiktok.com', 'WhatsApp': 'whatsapp.com', 'Telegram': 'telegram.org', 'Quora': 'quora.com', 'Threads': 'threads.net',
		'ChatGPT': 'chatgpt.com', 'Perplexity': 'perplexity.ai', 'Claude': 'claude.ai', 'Gemini': 'gemini.google.com', 'Microsoft Copilot': 'copilot.microsoft.com',
		'DeepSeek': 'deepseek.com', 'Gmail': 'mail.google.com', 'Outlook': 'outlook.com', 'GitHub': 'github.com', 'Medium': 'medium.com', 'Product Hunt': 'producthunt.com'
	};

	App.favicon = function (hostOrSource) {
		var host = SOURCE_DOMAINS[hostOrSource] || hostOrSource;
		if (!C.externalAssets || !host || !/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(host)) {
			return icon('link', 'pa-fav-icon');
		}
		var img = h('img', { 'class': 'pa-fav', src: 'https://icons.duckduckgo.com/ip3/' + encodeURIComponent(host) + '.ico', alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', width: 16, height: 16 });
		img.addEventListener('error', function () {
			img.replaceWith(icon('link', 'pa-fav-icon'));
		});
		return img;
	};

	App.pageUrl = function (path) {
		return (C.origin || '').replace(/\/$/, '') + (path || '/');
	};

	App.decodePath = function (path) {
		try { return decodeURIComponent(path); } catch (e) { return path; }
	};

	/* =================================================================== *
	 * State & routing
	 * =================================================================== */

	var PRESETS = App.PRESETS = [
		{ key: 'today', label: __('Today', 'pulse-analytics') },
		{ key: 'yesterday', label: __('Yesterday', 'pulse-analytics') },
		{ key: '7d', label: __('Last 7 days', 'pulse-analytics') },
		{ key: '30d', label: __('Last 30 days', 'pulse-analytics') },
		{ key: '90d', label: __('Last 90 days', 'pulse-analytics') },
		{ key: 'mtd', label: __('This month', 'pulse-analytics') },
		{ key: 'lastmonth', label: __('Last month', 'pulse-analytics') },
		{ key: 'ytd', label: __('This year', 'pulse-analytics') },
		{ key: '12mo', label: __('Last 12 months', 'pulse-analytics') },
		{ key: 'all', label: __('All time', 'pulse-analytics') }
	];

	App.presetRange = function (key) {
		var today = C.today;
		var d = parseDate(today);
		switch (key) {
			case 'today': return [today, today];
			case 'yesterday': return [addDays(today, -1), addDays(today, -1)];
			case '7d': return [addDays(today, -6), today];
			case '90d': return [addDays(today, -89), today];
			case 'mtd': return [today.slice(0, 8) + '01', today];
			case 'lastmonth':
				var first = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() - 1, 1));
				var last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 0));
				return [isoDate(first), isoDate(last)];
			case 'ytd': return [today.slice(0, 4) + '-01-01', today];
			case '12mo': return [addDays(today, -364), today];
			case 'all': return [C.firstDate && C.firstDate < today ? C.firstDate : addDays(today, -29), today];
			default: return [addDays(today, -29), today];
		}
	};

	var stored = {};
	try { stored = JSON.parse(window.localStorage.getItem('pulse_analytics_prefs') || '{}') || {}; } catch (e4) { stored = {}; }

	var state = App.state = {
		view: 'overview',
		params: {},
		preset: stored.preset || '30d',
		start: '',
		end: '',
		compare: stored.compare !== undefined ? stored.compare : 'previous',
		filters: [],
		metric: 'visitors',
		interval: ''
	};

	App.savePrefs = function () {
		try { window.localStorage.setItem('pulse_analytics_prefs', JSON.stringify({ preset: state.preset === 'custom' ? '30d' : state.preset, compare: state.compare })); } catch (e) {}
	};

	App.readHash = function () {
		var hash = window.location.hash.replace(/^#\/?/, '');
		var parts = hash.split('?');
		var params = new URLSearchParams(parts[1] || '');
		state.view = parts[0] || 'overview';
		state.params = {};
		params.forEach(function (v, k) {
			if (['preset', 'start', 'end', 'cmp', 'f', 'metric', 'interval'].indexOf(k) === -1) {
				state.params[k] = v;
			}
		});
		if (params.get('preset')) {
			state.preset = params.get('preset');
		}
		if (state.preset === 'custom' && params.get('start') && params.get('end')) {
			state.start = params.get('start');
			state.end = params.get('end');
		} else {
			if (state.preset === 'custom') { state.preset = '30d'; }
			var range = App.presetRange(state.preset);
			state.start = range[0];
			state.end = range[1];
		}
		if (params.has('cmp')) {
			state.compare = params.get('cmp');
		}
		state.filters = [];
		if (params.get('f')) {
			try {
				var parsed = JSON.parse(params.get('f'));
				if (Array.isArray(parsed)) {
					state.filters = parsed.filter(function (f) { return f && App.DIMENSIONS[f.dim] && App.OPS[f.op]; });
				}
			} catch (e) { state.filters = []; }
		}
		if (params.get('metric') && METRICS[params.get('metric')]) {
			state.metric = params.get('metric');
		}
		state.interval = params.get('interval') || '';
	};

	App.hashFor = function (view, params, overrides) {
		var s = Object.assign({}, state, overrides || {});
		var q = new URLSearchParams();
		Object.keys(params || {}).forEach(function (k) { if (params[k] !== undefined && params[k] !== null && params[k] !== '') { q.set(k, params[k]); } });
		q.set('preset', s.preset);
		if (s.preset === 'custom') {
			q.set('start', s.start);
			q.set('end', s.end);
		}
		if (s.compare !== 'previous') {
			q.set('cmp', s.compare);
		}
		if (s.filters.length) {
			q.set('f', JSON.stringify(s.filters));
		}
		if (s.metric !== 'visitors') {
			q.set('metric', s.metric);
		}
		if (s.interval) {
			q.set('interval', s.interval);
		}
		return '#/' + view + '?' + q.toString();
	};

	App.navigate = function (view, params, replace) {
		var hash = App.hashFor(view, params || {});
		if (replace) {
			window.history.replaceState(null, '', hash);
		} else {
			window.history.pushState(null, '', hash);
		}
		App.onRoute();
	};

	// Updates the URL for the current view after a state change (no new history entry for minor changes).
	App.syncHash = function (push) {
		var hash = App.hashFor(state.view, state.params);
		if (push) {
			window.history.pushState(null, '', hash);
		} else {
			window.history.replaceState(null, '', hash);
		}
	};

	// Filters implied by the current view (e.g. the page report).
	App.viewFilters = function () {
		if (state.view === 'page' && state.params.path) {
			return [{ dim: 'page', op: 'is', value: state.params.path }];
		}
		return [];
	};

	App.allFilters = function () {
		return state.filters.concat(App.viewFilters());
	};

	App.hasFilter = function (dim) {
		return App.allFilters().some(function (f) { return f.dim === dim; });
	};

	App.filterValue = function (dim) {
		var f = App.allFilters().filter(function (x) { return x.dim === dim && x.op === 'is'; })[0];
		return f ? f.value : null;
	};

	App.addFilter = function (dim, value, op) {
		op = op || 'is';
		state.filters = state.filters.filter(function (f) { return !(f.dim === dim && (f.op === 'is' || op === 'is')); });
		state.filters.push({ dim: dim, op: op, value: value });
		App.syncHash(true);
		App.refresh();
	};

	App.removeFilter = function (index) {
		state.filters.splice(index, 1);
		App.syncHash(true);
		App.refresh();
	};

	App.clearFilters = function () {
		state.filters = [];
		App.syncHash(true);
		App.refresh();
	};

	/* =================================================================== *
	 * API & batching
	 * =================================================================== */

	function handle(res) {
		return res.json().catch(function () { return {}; }).then(function (json) {
			if (!res.ok) {
				var err = new Error(json && json.message ? json.message : res.status + ' ' + res.statusText);
				err.status = res.status;
				throw err;
			}
			return json;
		});
	}

	App.api = function (method, path, body) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(C.restUrl + path, opts).then(handle);
	};

	var queue = [];
	var timer = null;
	var forceFresh = false;

	App.baseQuery = function () {
		return { start: state.start, end: state.end, compare: state.compare, filters: App.allFilters() };
	};

	function flush() {
		timer = null;
		var items = queue;
		queue = [];
		var nocache = forceFresh;
		forceFresh = false;
		var groups = {};
		items.forEach(function (item) {
			var key = JSON.stringify(item.base);
			(groups[key] = groups[key] || []).push(item);
		});
		Object.keys(groups).forEach(function (key) {
			var group = groups[key];
			for (var i = 0; i < group.length; i += 6) {
				(function (chunk) {
					var widgets = {};
					chunk.forEach(function (item, idx) { widgets['w' + idx] = item.def; });
					var body = Object.assign({}, chunk[0].base, { widgets: widgets, nocache: nocache });
					App.api('POST', 'report', body).then(function (res) {
						chunk.forEach(function (item, idx) {
							var data = res['w' + idx];
							if (!data || data.error) {
								item.reject(new Error(data && data.error ? data.error : __('No data', 'pulse-analytics')));
							} else {
								item.resolve(data);
							}
						});
					}).catch(function (err) {
						chunk.forEach(function (item) { item.reject(err); });
					});
				})(group.slice(i, i + 6));
			}
		});
	}

	// Requests one report widget; calls made in the same tick share HTTP requests.
	App.widget = function (def, base) {
		return new Promise(function (resolve, reject) {
			queue.push({ def: def, base: base || App.baseQuery(), resolve: resolve, reject: reject });
			if (!timer) {
				timer = setTimeout(flush, 0);
			}
		});
	};

	App.freshNextLoad = function () {
		forceFresh = true;
	};

	App.exportUrl = function (type, extra) {
		extra = extra || {};
		var params = new URLSearchParams();
		params.set('action', 'pulse_analytics_export');
		params.set('_wpnonce', C.exportNonce);
		params.set('type', type);
		params.set('start', state.start);
		params.set('end', state.end);
		params.set('compare', state.compare);
		params.set('filters', JSON.stringify(App.allFilters().concat(extra.filters || [])));
		if (extra.dimension) { params.set('dimension', extra.dimension); }
		if (extra.interval) { params.set('interval', extra.interval); }
		return C.exportUrl + '?' + params.toString();
	};

	/* =================================================================== *
	 * Components
	 * =================================================================== */

	// Mounted components of the current view (refreshed on filter / date changes).
	App.mounted = [];
	App.mount = function (component) {
		App.mounted.push(component);
		return component;
	};

	App.loading = function (el, on) {
		el.classList.toggle('is-loading', !!on);
	};

	App.skeleton = function (rows) {
		var wrap = h('div', { 'class': 'pa-skeleton' });
		for (var i = 0; i < (rows || 5); i++) {
			wrap.appendChild(h('div', { 'class': 'pa-skel-row', style: { width: (90 - i * 9) + '%' } }));
		}
		return wrap;
	};

	App.errorBox = function (err, retry) {
		return h('div', { 'class': 'pa-error' }, [
			icon('info'),
			h('span', { text: err && err.message ? err.message : __('Something went wrong.', 'pulse-analytics') }),
			retry ? h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', onclick: retry, text: __('Retry', 'pulse-analytics') }) : null
		]);
	};

	App.empty = function (text) {
		return h('div', { 'class': 'pa-empty' }, [icon('chart'), h('span', { text: text || __('No data for this period.', 'pulse-analytics') })]);
	};

	/**
	 * Card shell: { title, icon, tabs: [{key,label}], activeTab, onTab, actions: [nodes], footer, className }.
	 */
	App.card = function (parent, opts) {
		var tabsEl = null;
		var header = h('div', { 'class': 'pa-card-head' }, [
			h('h3', { 'class': 'pa-card-title' }, [opts.icon ? icon(opts.icon) : null, h('span', { text: opts.title })]),
			opts.subtitle ? h('span', { 'class': 'pa-card-sub', text: opts.subtitle }) : null
		]);
		var actions = h('div', { 'class': 'pa-card-actions' }, opts.actions || []);
		if (opts.tabs && opts.tabs.length > 1) {
			tabsEl = h('div', { 'class': 'pa-card-tabs', role: 'tablist' });
			opts.tabs.forEach(function (tab) {
				tabsEl.appendChild(h('button', {
					type: 'button',
					role: 'tab',
					'class': 'pa-card-tab' + (tab.key === opts.activeTab ? ' is-active' : ''),
					'aria-selected': tab.key === opts.activeTab ? 'true' : 'false',
					dataset: { key: tab.key },
					text: tab.label,
					onclick: function () {
						tabsEl.querySelectorAll('.pa-card-tab').forEach(function (b) {
							b.classList.toggle('is-active', b === this);
							b.setAttribute('aria-selected', b === this ? 'true' : 'false');
						}, this);
						if (opts.onTab) { opts.onTab(tab.key); }
					}
				}));
			});
			header.appendChild(tabsEl);
		}
		header.appendChild(actions);
		var body = h('div', { 'class': 'pa-card-body' });
		var footer = h('div', { 'class': 'pa-card-foot' });
		var el = h('section', { 'class': 'pa-card' + (opts.className ? ' ' + opts.className : '') }, [header, body, footer]);
		parent.appendChild(el);
		return { el: el, header: header, body: body, footer: footer, actions: actions, tabs: tabsEl };
	};

	// CSV download plus the matching prompt for AI tools.
	App.csvButton = function (getUrl) {
		return h('span', { 'class': 'pa-export-pair' }, [
			h('a', {
				'class': 'pa-icon-btn',
				href: '#',
				title: __('Download CSV', 'pulse-analytics'),
				'aria-label': __('Download CSV', 'pulse-analytics'),
				onclick: function (e) {
					e.preventDefault();
					window.location.href = getUrl();
				}
			}, [icon('download'), h('span', { 'class': 'pa-icon-btn-label', text: 'CSV' })]),
			App.promptButton(getUrl)
		]);
	};

	App.promptButton = function (getUrl) {
		return h('button', {
			type: 'button',
			'class': 'pa-icon-btn',
			title: __('Copy a prompt that explains this CSV file to an AI tool', 'pulse-analytics'),
			'aria-label': __('AI prompt for this CSV file', 'pulse-analytics'),
			onclick: function () { App.showPrompt(getUrl()); }
		}, [icon('sparkles'), h('span', { 'class': 'pa-icon-btn-label', text: __('AI prompt', 'pulse-analytics') })]);
	};

	/* -------------------------------------------------- PDF report by email */

	var emailDialog = null;

	// Link to the PDF summary report for the current period and filters.
	App.pdfUrl = function () {
		return App.exportUrl('summary', { interval: state.interval }) + '&format=pdf';
	};

	// Dialog that emails the PDF report for the current period and filters.
	App.emailReport = function () {
		var appRoot = document.getElementById('pulse-analytics-app') || document.body;
		var longDate = function (d) { return App.dates.format(d, { day: 'numeric', month: 'short', year: 'numeric' }); };
		/* translators: 1: first day, 2: last day */
		var range = state.start === state.end ? longDate(state.start) : sprintf(__('%1$s to %2$s', 'pulse-analytics'), longDate(state.start), longDate(state.end));
		var parts = [range];
		if (state.compare) {
			parts.push(state.compare === 'year' ? __('compared with a year earlier', 'pulse-analytics') : __('compared with the period before', 'pulse-analytics'));
		}
		var filters = App.allFilters();
		if (filters.length) {
			/* translators: %s: number of filters */
			parts.push(sprintf(_n('%s filter', '%s filters', filters.length, 'pulse-analytics'), filters.length));
		}

		if (emailDialog && emailDialog.parentNode) {
			emailDialog.parentNode.removeChild(emailDialog);
		}
		var to = h('input', { type: 'text', 'class': 'pa-input', id: 'pa-mail-to', placeholder: 'name@example.com, another@example.com', autocomplete: 'email' });
		/* translators: 1: site name, 2: date range */
		var subject = h('input', { type: 'text', 'class': 'pa-input', id: 'pa-mail-subject', maxlength: '200', value: sprintf(__('Website statistics for %1$s: %2$s', 'pulse-analytics'), C.siteName, range) });
		var message = h('textarea', { 'class': 'pa-input pa-textarea', id: 'pa-mail-message', rows: '5', maxlength: '5000', placeholder: __('A short note for the people who get the report (optional).', 'pulse-analytics') });
		var status = h('p', { 'class': 'pa-dialog-status', role: 'status' });
		var sendBtn = h('button', { type: 'submit', 'class': 'pa-btn pa-btn-primary' }, [icon('mail'), h('span', { text: __('Send report', 'pulse-analytics') })]);
		var closeBtn = h('button', { type: 'button', 'class': 'pa-btn', text: __('Cancel', 'pulse-analytics') });
		var close = function () {
			if (typeof emailDialog.close === 'function') { emailDialog.close(); } else { emailDialog.removeAttribute('open'); }
		};
		closeBtn.addEventListener('click', close);

		var form = h('form', { 'class': 'pa-dialog-inner', novalidate: true }, [
			h('div', { 'class': 'pa-dialog-head' }, [
				h('h2', { id: 'pa-mail-title' }, [icon('mail'), h('span', { text: __('Email the PDF report', 'pulse-analytics') })]),
				h('button', { type: 'button', 'class': 'pa-icon-btn', 'aria-label': __('Close', 'pulse-analytics'), onclick: close }, icon('close'))
			]),
			h('p', {
				'class': 'pa-dialog-note',
				/* translators: %s: period, comparison and number of filters */
				text: sprintf(__('The PDF covers %s, the same view you have on screen.', 'pulse-analytics'), parts.join(', '))
			}),
			h('div', { 'class': 'pa-field' }, [h('label', { 'for': 'pa-mail-to', text: __('To', 'pulse-analytics') }), to]),
			h('div', { 'class': 'pa-field' }, [h('label', { 'for': 'pa-mail-subject', text: __('Subject', 'pulse-analytics') }), subject]),
			h('div', { 'class': 'pa-field' }, [h('label', { 'for': 'pa-mail-message', text: __('Message', 'pulse-analytics') }), message]),
			status,
			h('div', { 'class': 'pa-dialog-foot' }, [
				h('a', { 'class': 'pa-btn pa-btn-ghost pa-foot-left', href: App.pdfUrl() }, [icon('download'), h('span', { text: __('Download instead', 'pulse-analytics') })]),
				closeBtn,
				sendBtn
			])
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!to.value.trim()) {
				status.className = 'pa-dialog-status is-error';
				status.textContent = __('Add at least one email address.', 'pulse-analytics');
				to.focus();
				return;
			}
			sendBtn.disabled = true;
			status.className = 'pa-dialog-status';
			status.textContent = __('Sending...', 'pulse-analytics');
			var body = App.baseQuery();
			body.interval = state.interval;
			body.to = to.value;
			body.subject = subject.value;
			body.message = message.value;
			App.api('POST', 'email-report', body).then(function (res) {
				status.className = 'pa-dialog-status is-ok';
				/* translators: %s: email addresses */
				status.textContent = sprintf(__('Sent to %s.', 'pulse-analytics'), (res.to || []).join(', '));
				sendBtn.hidden = true;
				closeBtn.textContent = __('Close', 'pulse-analytics');
			}).catch(function (err) {
				status.className = 'pa-dialog-status is-error';
				status.textContent = err && err.message ? err.message : __('The report could not be sent.', 'pulse-analytics');
				sendBtn.disabled = false;
			});
		});

		emailDialog = h('dialog', { 'class': 'pa-dialog pa-dialog-narrow', 'aria-labelledby': 'pa-mail-title' }, form);
		emailDialog.addEventListener('click', function (e) {
			if (e.target === emailDialog) { close(); }
		});
		appRoot.appendChild(emailDialog);
		if (typeof emailDialog.showModal === 'function') {
			emailDialog.showModal();
		} else {
			emailDialog.setAttribute('open', '');
		}
		to.focus();
	};

	/* ------------------------------------------------------- prompt dialog */

	var promptDialog = null;

	// Copies text. Plain-http sites have no Clipboard API, so select the text and use the older command there.
	App.copyText = function (text, textarea) {
		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			textarea.focus();
			textarea.select();
			try {
				if (document.execCommand('copy')) { resolve(); } else { reject(new Error('copy')); }
			} catch (e) {
				reject(e);
			}
		});
	};

	// Opens a dialog with the prompt for an export URL.
	App.showPrompt = function (exportUrl) {
		var appRoot = document.getElementById('pulse-analytics-app') || document.body;
		if (!promptDialog) {
			var textarea = h('textarea', { 'class': 'pa-prompt-text', rows: '16', spellcheck: 'false', 'aria-label': __('Prompt', 'pulse-analytics') });
			var copyBtn = h('button', { type: 'button', 'class': 'pa-btn pa-btn-primary pa-prompt-copy' });
			var setCopyLabel = function (copied) {
				clear(copyBtn);
				copyBtn.appendChild(icon(copied ? 'check' : 'copy'));
				copyBtn.appendChild(h('span', { text: copied ? __('Copied', 'pulse-analytics') : __('Copy prompt', 'pulse-analytics') }));
			};
			var close = function () {
				if (typeof promptDialog.close === 'function') { promptDialog.close(); } else { promptDialog.removeAttribute('open'); }
			};
			setCopyLabel(false);
			copyBtn.addEventListener('click', function () {
				App.copyText(textarea.value, textarea).then(function () {
					setCopyLabel(true);
					setTimeout(function () { setCopyLabel(false); }, 1800);
				}).catch(function () {
					textarea.focus();
					textarea.select();
				});
			});
			promptDialog = h('dialog', { 'class': 'pa-dialog', 'aria-labelledby': 'pa-prompt-title' }, h('div', { 'class': 'pa-dialog-inner' }, [
				h('div', { 'class': 'pa-dialog-head' }, [
					h('h2', { id: 'pa-prompt-title' }, [icon('sparkles'), h('span', { text: __('Prompt for AI tools', 'pulse-analytics') })]),
					h('button', { type: 'button', 'class': 'pa-icon-btn', 'aria-label': __('Close', 'pulse-analytics'), onclick: close }, icon('close'))
				]),
				h('p', { 'class': 'pa-dialog-note', text: __('Paste this into ChatGPT, Claude, Gemini or another AI chat together with the file, so the AI knows what the columns mean and what to look for. You can change the text before you copy it.', 'pulse-analytics') }),
				textarea,
				h('div', { 'class': 'pa-dialog-foot' }, [
					h('button', { type: 'button', 'class': 'pa-btn', text: __('Close', 'pulse-analytics'), onclick: close }),
					copyBtn
				])
			]));
			// A click on the dimmed backdrop lands on the dialog element itself.
			promptDialog.addEventListener('click', function (e) {
				if (e.target === promptDialog) { close(); }
			});
			promptDialog.textarea = textarea;
			promptDialog.copyBtn = copyBtn;
		}
		if (promptDialog.parentNode !== appRoot) {
			appRoot.appendChild(promptDialog);
		}

		var area = promptDialog.textarea;
		area.value = __('Writing the prompt...', 'pulse-analytics');
		promptDialog.copyBtn.disabled = true;
		if (typeof promptDialog.showModal === 'function') {
			if (!promptDialog.open) { promptDialog.showModal(); }
		} else {
			promptDialog.setAttribute('open', '');
		}

		var requested = exportUrl;
		promptDialog.requested = requested;
		fetch(exportUrl + (exportUrl.indexOf('?') > -1 ? '&' : '?') + 'format=prompt', { credentials: 'same-origin' }).then(function (res) {
			if (!res.ok) { throw new Error(String(res.status)); }
			return res.text();
		}).then(function (text) {
			if (promptDialog.requested !== requested) { return; }
			area.value = text;
			area.scrollTop = 0;
			promptDialog.copyBtn.disabled = false;
		}).catch(function () {
			if (promptDialog.requested !== requested) { return; }
			area.value = __('The prompt could not be loaded. Reload the page and try again.', 'pulse-analytics');
		});
	};

	/* ------------------------------------------------------------ row labels */

	App.rowLabel = function (dim, row) {
		var key = row.key;
		switch (dim) {
			case 'country':
				return [App.flag(key), h('span', { 'class': 'pa-row-text', text: row.label || App.countryName(key) })];
			case 'region':
			case 'city':
				return [App.flag(row.country), h('span', { 'class': 'pa-row-text', text: row.label }), dim === 'city' && row.region ? h('span', { 'class': 'pa-row-sub', text: row.region }) : null];
			case 'continent':
				return [icon('globe'), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'page':
			case 'entry_page':
			case 'exit_page':
			case 'prev_page':
			case 'next_page':
				if (row.special) {
					return [icon(row.special === 'exit' ? 'leave' : 'enter'), h('span', { 'class': 'pa-row-text is-muted', text: row.label })];
				}
				return [h('span', { 'class': 'pa-page-label' }, [
					h('span', { 'class': 'pa-row-text', text: row.label || App.decodePath(key) }),
					h('span', { 'class': 'pa-row-sub', text: App.decodePath(key) })
				])];
			case 'source':
				return [key === '' ? icon('cursor', 'pa-fav-icon') : App.favicon(key), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'referrer':
			case 'referrer_url':
				return [App.favicon(String(key).split('/')[0]), h('span', { 'class': 'pa-row-text', text: key })];
			case 'channel':
				return [h('span', { 'class': 'pa-dot', style: { background: App.channelColor(key) } }), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'device':
				return [icon(['desktop', 'mobile', 'tablet', 'tv'].indexOf(key) > -1 ? key : 'desktop'), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'language':
				return [h('span', { 'class': 'pa-row-text', text: App.languageName(key) }), key ? h('span', { 'class': 'pa-row-sub', text: key }) : null];
			case 'visitor_type':
				return [icon(key === 'new' ? 'user' : 'users'), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'event_category':
				var icons = { outbound: 'external', download: 'download', form: 'edit', email: 'mail', phone: 'phone', search: 'search', '404': 'info', custom: 'pointer' };
				return [icon(icons[key] || 'pointer'), h('span', { 'class': 'pa-row-text', text: row.label })];
			case 'event_name':
				return [h('span', { 'class': 'pa-row-text', text: row.label }), row.category ? h('span', { 'class': 'pa-badge', text: C.eventCats[row.category] || row.category }) : null];
			default:
				return [h('span', { 'class': 'pa-row-text', text: row.label })];
		}
	};

	// Filter dimension used when a row of a breakdown is clicked.
	App.rowFilterDim = function (dim) {
		var map = { prev_page: 'page', next_page: 'page', author: null, category: null };
		if (map.hasOwnProperty(dim)) {
			return map[dim];
		}
		return App.DIMENSIONS[dim] ? dim : null;
	};

	/* ------------------------------------------------------------ list card */

	/**
	 * Top-N list card with tabs.
	 * cfg: { title, icon, tabs: [{ key, label, dimension, metric, filters, empty }], limit, view (details view), viewParams, pageAction }
	 */
	App.listCard = function (parent, cfg) {
		var storeKey = 'pulse_tab_' + cfg.id;
		var active = cfg.tabs[0].key;
		try {
			var saved = window.localStorage.getItem(storeKey);
			if (saved && cfg.tabs.some(function (t) { return t.key === saved; })) { active = saved; }
		} catch (e) {}

		var currentTab = function () {
			return cfg.tabs.filter(function (t) { return t.key === active; })[0];
		};

		var card = App.card(parent, {
			title: cfg.title,
			icon: cfg.icon,
			className: 'pa-list-card ' + (cfg.className || ''),
			tabs: cfg.tabs,
			activeTab: active,
			onTab: function (key) {
				active = key;
				try { window.localStorage.setItem(storeKey, key); } catch (e) {}
				component.load();
			},
			actions: [App.csvButton(function () {
				var tab = currentTab();
				return App.exportUrl('breakdown', { dimension: tab.dimension, filters: tab.filters });
			})]
		});
		card.body.appendChild(App.skeleton(cfg.limit || 8));

		var component = {
			load: function () {
				var tab = currentTab();
				App.loading(card.el, true);
				return App.widget({ type: 'breakdown', dimension: tab.dimension, limit: cfg.limit || 8, filters: tab.filters, sort: tab.sort }).then(function (data) {
					App.loading(card.el, false);
					clear(card.body);
					App.renderRows(card.body, tab, data, cfg);
					clear(card.footer);
					if (cfg.view) {
						card.footer.appendChild(h('a', {
							'class': 'pa-more',
							href: App.hashFor(cfg.view, Object.assign({ tab: tab.viewTab || tab.key }, cfg.viewParams || {})),
							text: __('View details', 'pulse-analytics')
						}, [icon('chevronRight')]));
					}
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	};

	App.renderRows = function (body, tab, data, cfg) {
		var rows = data.rows || [];
		var metric = tab.metric || data.metrics[0];
		if (!rows.length) {
			body.appendChild(App.empty(tab.empty));
			return;
		}
		var max = Math.max.apply(null, rows.map(function (r) { return r[metric] || 0; }).concat([1]));
		var total = data.totals && data.totals[metric] ? data.totals[metric] : 0;
		var secondary = tab.secondary;
		var head = h('div', { 'class': 'pa-rows-head' }, [
			h('span', { text: tab.columnLabel || App.DIMENSIONS[tab.dimension] || '' }),
			secondary ? h('span', { 'class': 'pa-rows-head-num', text: METRICS[secondary] ? METRICS[secondary].label : secondary }) : null,
			h('span', { 'class': 'pa-rows-head-num', text: METRICS[metric] ? METRICS[metric].label : metric })
		]);
		var list = h('ul', { 'class': 'pa-rows' });
		var filterDim = tab.filterDim !== undefined ? tab.filterDim : App.rowFilterDim(tab.dimension);
		var isPage = ['page', 'entry_page', 'exit_page', 'prev_page', 'next_page'].indexOf(tab.dimension) > -1;

		rows.forEach(function (row) {
			var value = row[metric] || 0;
			var share = total ? value / total * 100 : null;
			var clickable = !row.special && (filterDim || isPage);
			var actions = h('span', { 'class': 'pa-row-actions' });
			if (isPage && !row.special && row.key) {
				actions.appendChild(h('a', { 'class': 'pa-row-action', href: App.pageUrl(row.key), target: '_blank', rel: 'noopener', title: __('Open page', 'pulse-analytics'), onclick: function (e) { e.stopPropagation(); } }, [icon('external')]));
				if (filterDim) {
					var pageFilterDim = tab.dimension === 'entry_page' || tab.dimension === 'exit_page' ? tab.dimension : 'page';
					actions.appendChild(h('button', { type: 'button', 'class': 'pa-row-action', title: __('Filter by this page', 'pulse-analytics'), onclick: function (e) { e.stopPropagation(); App.addFilter(pageFilterDim, row.key); } }, [icon('filter')]));
				}
			}
			var li = h('li', {
				'class': 'pa-row' + (clickable ? ' is-clickable' : ''),
				tabindex: clickable ? 0 : null,
				title: clickable ? (isPage ? __('Open page report', 'pulse-analytics') : __('Filter by this value', 'pulse-analytics')) : null
			}, [
				h('span', { 'class': 'pa-row-bar', style: { width: Math.max(1, value / max * 100) + '%' } }),
				h('span', { 'class': 'pa-row-label' }, App.rowLabel(tab.dimension, row)),
				actions,
				secondary ? h('span', { 'class': 'pa-row-num is-secondary', text: App.metricFormat(secondary, row[secondary]) }) : null,
				h('span', { 'class': 'pa-row-num' }, [
					App.metricFormat(metric, value),
					share !== null && !METRICS[metric].lowerIsBetter && metric !== 'avg_time' && metric !== 'avg_duration' ? h('span', { 'class': 'pa-row-pct', text: fmt.pct(share, share < 10 ? 1 : 0) }) : null
				])
			]);
			if (clickable) {
				var activate = function () {
					if (isPage && !(cfg && cfg.filterPages)) {
						App.navigate('page', { path: row.key });
					} else if (filterDim) {
						App.addFilter(filterDim, row.key);
					}
				};
				li.addEventListener('click', activate);
				li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { activate(); } });
			}
			list.appendChild(li);
		});
		body.appendChild(head);
		body.appendChild(list);
	};

	/* ------------------------------------------------------------ data table */

	/**
	 * Full sortable, searchable, paginated table.
	 * cfg: { dimension, columns: [metric keys], filters, pageSize, rowAction: 'filter'|'page'|null, title, sort }
	 */
	App.dataTable = function (parent, cfg) {
		var offset = 0;
		var pageSize = cfg.pageSize || 25;
		var sort = cfg.sort || '';
		var order = 'desc';
		var search = '';
		var searchTimer = null;

		var searchInput = h('input', { type: 'search', 'class': 'pa-input pa-table-search', placeholder: __('Search...', 'pulse-analytics'), 'aria-label': __('Search', 'pulse-analytics') });
		searchInput.addEventListener('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				search = searchInput.value.trim();
				offset = 0;
				component.load();
			}, 350);
		});

		var card = App.card(parent, {
			title: cfg.title,
			icon: cfg.icon,
			className: 'pa-table-card ' + (cfg.className || ''),
			tabs: cfg.tabs,
			activeTab: cfg.activeTab,
			onTab: cfg.onTab,
			actions: [cfg.searchable === false ? null : searchInput, App.csvButton(function () {
				return App.exportUrl('breakdown', { dimension: cfg.dimension, filters: cfg.filters });
			})]
		});
		card.body.appendChild(App.skeleton(8));

		var component = {
			card: card,
			setConfig: function (next) {
				Object.assign(cfg, next);
				offset = 0;
				sort = cfg.sort || '';
				order = 'desc';
				search = '';
				searchInput.value = '';
				component.load();
			},
			load: function () {
				App.loading(card.el, true);
				return App.widget({
					type: 'breakdown',
					dimension: cfg.dimension,
					limit: pageSize,
					offset: offset,
					sort: sort,
					order: order,
					search: search,
					filters: cfg.filters,
					with_total: true,
					with_previous: App.state.compare !== ''
				}).then(function (data) {
					App.loading(card.el, false);
					render(data);
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};

		function render(data) {
			clear(card.body);
			clear(card.footer);
			var rows = data.rows || [];
			var columns = (cfg.columns || data.metrics).filter(function (c) { return data.metrics.indexOf(c) > -1; });
			if (data.metrics.indexOf('conversions') > -1 && columns.indexOf('conversions') === -1) {
				columns.splice(1, 0, 'conversions', 'cr');
			}
			var serverSortable = cfg.sortable || columns;
			if (!rows.length) {
				card.body.appendChild(App.empty(search ? __('No matches.', 'pulse-analytics') : cfg.empty));
				return;
			}
			var activeSort = sort || columns[0];
			var mainMetric = columns[0];
			var max = Math.max.apply(null, rows.map(function (r) { return r[mainMetric] || 0; }).concat([1]));
			var filterDim = cfg.filterDim !== undefined ? cfg.filterDim : App.rowFilterDim(cfg.dimension);
			var isPage = ['page', 'entry_page', 'exit_page'].indexOf(cfg.dimension) > -1;

			var thead = h('tr', {}, [h('th', { 'class': 'pa-th-label', text: cfg.labelHeader || App.DIMENSIONS[cfg.dimension] || '' })]);
			columns.forEach(function (col) {
				var sortable = serverSortable.indexOf(col) > -1;
				var th = h('th', { 'class': 'pa-th-num' + (sortable ? ' is-sortable' : '') + (activeSort === col ? ' is-sorted' : ''), scope: 'col', 'aria-sort': activeSort === col ? (order === 'asc' ? 'ascending' : 'descending') : null }, [
					sortable ? h('button', {
						type: 'button',
						'class': 'pa-sort',
						onclick: function () {
							if (sort === col || (!sort && col === columns[0])) {
								order = order === 'desc' ? 'asc' : 'desc';
							} else {
								order = 'desc';
							}
							sort = col;
							offset = 0;
							component.load();
						}
					}, [METRICS[col] ? METRICS[col].label : col, activeSort === col ? icon(order === 'asc' ? 'arrowUp' : 'arrowDown') : null]) : (METRICS[col] ? METRICS[col].label : col)
				]);
				thead.appendChild(th);
			});

			var tbody = h('tbody');
			rows.forEach(function (row) {
				var clickable = !row.special && (filterDim || isPage);
				var tr = h('tr', { 'class': clickable ? 'is-clickable' : '', tabindex: clickable ? 0 : null }, [
					h('td', { 'class': 'pa-td-label' }, [
						h('span', { 'class': 'pa-td-bar', style: { width: Math.max(1, (row[mainMetric] || 0) / max * 100) + '%' } }),
						h('span', { 'class': 'pa-row-label' }, App.rowLabel(cfg.dimension, row)),
						isPage && row.key ? h('a', { 'class': 'pa-row-action', href: App.pageUrl(row.key), target: '_blank', rel: 'noopener', title: __('Open page', 'pulse-analytics'), onclick: function (e) { e.stopPropagation(); } }, [icon('external')]) : null,
						isPage && row.key && filterDim ? h('button', { type: 'button', 'class': 'pa-row-action', title: __('Filter by this page', 'pulse-analytics'), onclick: function (e) { e.stopPropagation(); App.addFilter(cfg.dimension, row.key); } }, [icon('filter')]) : null
					])
				]);
				columns.forEach(function (col, idx) {
					var cell = h('td', { 'class': 'pa-td-num' }, [App.metricFormat(col, row[col])]);
					if (row.prev !== undefined && idx === 0) {
						cell.appendChild(row.prev ? App.deltaBadge(col, row[col], row.prev[col]) : h('span', { 'class': 'pa-delta is-new', text: __('new', 'pulse-analytics') }));
					}
					tr.appendChild(cell);
				});
				if (clickable) {
					var activate = function () {
						if (isPage && cfg.rowAction !== 'filter') {
							App.navigate('page', { path: row.key });
						} else if (filterDim) {
							App.addFilter(filterDim, row.key);
						}
					};
					tr.addEventListener('click', activate);
					tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') { activate(); } });
				}
				tbody.appendChild(tr);
			});

			card.body.appendChild(h('div', { 'class': 'pa-table-wrap' }, h('table', { 'class': 'pa-table' }, [h('thead', {}, thead), tbody])));

			var total = data.total_rows || rows.length;
			var from = offset + 1;
			var to = offset + rows.length;
			card.footer.appendChild(h('div', { 'class': 'pa-pager' }, [
				h('span', { 'class': 'pa-pager-info', text: sprintf(__('%1$s-%2$s of %3$s', 'pulse-analytics'), fmt.num(from), fmt.num(to), fmt.num(total)) }),
				h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', disabled: offset === 0 ? true : null, onclick: function () { offset = Math.max(0, offset - pageSize); component.load(); }, 'aria-label': __('Previous page', 'pulse-analytics') }, [icon('chevronLeft')]),
				h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', disabled: to >= total ? true : null, onclick: function () { offset += pageSize; component.load(); }, 'aria-label': __('Next page', 'pulse-analytics') }, [icon('chevronRight')])
			]));
		}

		return App.mount(component);
	};

	/* ------------------------------------------------------------ donut card */

	App.donutCard = function (parent, cfg) {
		var card = App.card(parent, { title: cfg.title, icon: cfg.icon, className: 'pa-donut-card', actions: [App.csvButton(function () { return App.exportUrl('breakdown', { dimension: cfg.dimension }); })] });
		card.body.appendChild(App.skeleton(4));
		var component = {
			load: function () {
				App.loading(card.el, true);
				return App.widget({ type: 'breakdown', dimension: cfg.dimension, limit: 12 }).then(function (data) {
					App.loading(card.el, false);
					clear(card.body);
					var rows = data.rows || [];
					var metric = cfg.metric || 'visitors';
					if (!rows.length) {
						card.body.appendChild(App.empty());
						return;
					}
					var items = rows.slice(0, 5).map(function (row, i) {
						return { key: row.key, label: row.label, value: row[metric] || 0, color: cfg.color ? cfg.color(row.key, i) : SERIES[i] };
					});
					var rest = rows.slice(5).reduce(function (sum, row) { return sum + (row[metric] || 0); }, 0);
					if (rest > 0) {
						items.push({ key: '__other', label: __('Other', 'pulse-analytics'), value: rest, color: OTHER });
					}
					var total = items.reduce(function (s, it) { return s + it.value; }, 0);
					var chartEl = h('div', { 'class': 'pa-donut-chart' });
					var legend = h('ul', { 'class': 'pa-legend-list' });
					items.forEach(function (it) {
						var li = h('li', { 'class': it.key !== '__other' ? 'is-clickable' : '', tabindex: it.key !== '__other' ? 0 : null }, [
							h('span', { 'class': 'pa-swatch', style: { background: it.color } }),
							h('span', { 'class': 'pa-legend-label', text: it.label }),
							h('span', { 'class': 'pa-legend-value', text: App.metricFormat(metric, it.value) }),
							h('span', { 'class': 'pa-legend-pct', text: fmt.pct(total ? it.value / total * 100 : 0, 0) })
						]);
						if (it.key !== '__other') {
							li.addEventListener('click', function () { App.addFilter(cfg.dimension, it.key); });
							li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { App.addFilter(cfg.dimension, it.key); } });
						}
						legend.appendChild(li);
					});
					card.body.appendChild(h('div', { 'class': 'pa-donut-wrap' }, [chartEl, legend]));
					window.PulseCharts.donut(chartEl, {
						items: items,
						size: 150,
						thickness: 20,
						format: function (v) { return App.metricFormat(metric, v); },
						centerValue: fmt.compact(total),
						centerLabel: METRICS[metric].label,
						onClick: function (it) { if (it.key !== '__other') { App.addFilter(cfg.dimension, it.key); } }
					});
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	};
})(window, document);
