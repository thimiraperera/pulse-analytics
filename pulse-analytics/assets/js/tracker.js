/*!
 * Pulse Analytics tracker: pageviews, time on page, scroll depth and events.
 */
(function (w, d) {
	'use strict';

	var cfg = w.pulseAnalyticsConfig;
	var api = w.pulseAnalytics = w.pulseAnalytics || {};
	if (!cfg || api.loaded) {
		return;
	}
	api.loaded = true;

	var n = w.navigator;
	var loc = w.location;
	var IGNORE_KEY = 'pulse_analytics_ignore';
	var VID_KEY = 'pulse_analytics_vid';
	var IDLE_MS = 5 * 60 * 1000;
	var extensions = String(cfg.ext || '').toLowerCase().split(',');
	var hosts = (cfg.hosts || []).map(function (h) { return String(h).toLowerCase(); });

	var started = false;
	var useAjax = false;
	var pv = null;
	var sig = null;
	var pvPending = false;
	var pendingEvents = [];
	var currentUrl = null;
	var lastUrl = null;
	var engaged = 0;
	var lastTick = Date.now();
	var lastActivity = Date.now();
	var visible = d.visibilityState !== 'hidden';
	var maxScroll = 0;
	var sent = { d: -1, sc: -1 };

	/* ------------------------------------------------------------- storage */

	function store(key, value) {
		try {
			if (value === undefined) {
				return w.localStorage.getItem(key);
			}
			if (value === null) {
				w.localStorage.removeItem(key);
			} else {
				w.localStorage.setItem(key, value);
			}
		} catch (e) {}
		return null;
	}

	function randomHex(bytes) {
		var c = w.crypto || w.msCrypto;
		var out = '';
		var arr = new Uint8Array(bytes);
		if (c && c.getRandomValues) {
			c.getRandomValues(arr);
		} else {
			for (var i = 0; i < bytes; i++) { arr[i] = Math.floor(Math.random() * 256); }
		}
		for (var j = 0; j < arr.length; j++) { out += ('0' + arr[j].toString(16)).slice(-2); }
		return out;
	}

	function visitorId() {
		var id = store(VID_KEY);
		if (!id || !/^[a-f0-9]{32}$/.test(id)) {
			id = randomHex(16);
			store(VID_KEY, id);
			if (store(VID_KEY) !== id) {
				return ''; // Storage unavailable: fall back to cookieless counting.
			}
		}
		return id;
	}

	/* --------------------------------------------------------------- guards */

	api.optOut = function () { store(IGNORE_KEY, '1'); };
	api.optIn = function () { store(IGNORE_KEY, null); };
	api.isOptedOut = function () { return store(IGNORE_KEY) === '1'; };

	function blocked() {
		if (api.isOptedOut()) {
			return true;
		}
		if (cfg.dnt && (n.doNotTrack === '1' || w.doNotTrack === '1' || n.msDoNotTrack === '1' || n.globalPrivacyControl === true)) {
			return true;
		}
		return !!(n.webdriver || /bot|crawl|spider|slurp|headless|lighthouse|pagespeed|prerender|phantom|puppeteer|playwright|selenium/i.test(n.userAgent || ''));
	}

	function hasConsent() {
		if (!cfg.consent || api.consentGiven) {
			return true;
		}
		if (typeof w.wp_has_consent === 'function') {
			try { return !!w.wp_has_consent('statistics'); } catch (e) {}
		}
		return false;
	}

	/* ------------------------------------------------------------ transport */

	function endpoint() {
		return useAjax ? cfg.ajax : cfg.api;
	}

	function request(url, body) {
		var headers = { 'Content-Type': 'text/plain;charset=UTF-8' };
		if (cfg.nonce && url === cfg.api) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return w.fetch(url, { method: 'POST', body: body, headers: headers, credentials: 'same-origin', keepalive: true })
			.then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.json();
			});
	}

	function send(data, wantResponse) {
		var body = JSON.stringify(data);
		if (!wantResponse && n.sendBeacon) {
			try {
				if (n.sendBeacon(endpoint(), new Blob([body], { type: 'text/plain;charset=UTF-8' }))) {
					return Promise.resolve(null);
				}
			} catch (e) {}
		}
		if (!w.fetch) {
			return Promise.resolve(null);
		}
		return request(endpoint(), body).catch(function () {
			if (!useAjax && cfg.ajax) {
				useAjax = true; // The REST API is blocked (security plugin, etc.): use admin-ajax from now on.
				return request(cfg.ajax, body);
			}
			return null;
		}).catch(function () { return null; });
	}

	/* ----------------------------------------------------------- engagement */

	function settle() {
		var now = Date.now();
		if (visible && now - lastActivity < IDLE_MS) {
			engaged += Math.min(now - lastTick, 15000);
		}
		lastTick = now;
	}

	function measureScroll() {
		if (!cfg.scroll) {
			return;
		}
		var de = d.documentElement;
		var body = d.body;
		var height = Math.max(de.scrollHeight, body ? body.scrollHeight : 0, de.offsetHeight);
		var bottom = (w.pageYOffset || de.scrollTop || 0) + w.innerHeight;
		var pct = height > 0 ? Math.min(100, Math.round(bottom / height * 100)) : 100;
		if (pct > maxScroll) {
			maxScroll = pct;
		}
	}

	function sendEngagement() {
		if (!pv) {
			return;
		}
		settle();
		var seconds = Math.round(engaged / 1000);
		if (seconds === sent.d && maxScroll === sent.sc) {
			return;
		}
		sent.d = seconds;
		sent.sc = maxScroll;
		send({ a: 'en', pv: pv, sig: sig, d: seconds, sc: maxScroll }, false);
	}

	/* ------------------------------------------------------------ pageviews */

	function cleanUrl() {
		return loc.href.split('#')[0];
	}

	function clientHints() {
		var uad = n.userAgentData;
		if (!uad) {
			return Promise.resolve(null);
		}
		var hints = { m: uad.mobile ? 1 : 0, b: (uad.brands || []).map(function (b) { return b.brand; }).join('|') };
		if (!uad.getHighEntropyValues) {
			return Promise.resolve(hints);
		}
		var timeout = new Promise(function (resolve) { setTimeout(function () { resolve(hints); }, 250); });
		var high = uad.getHighEntropyValues(['platformVersion']).then(function (v) {
			hints.pv = v.platformVersion || '';
			return hints;
		}).catch(function () { return hints; });
		return Promise.race([high, timeout]);
	}

	function pageview(isSpa) {
		var url = cleanUrl();
		if (url === currentUrl) {
			return;
		}
		if (currentUrl) {
			sendEngagement();
		}
		lastUrl = currentUrl;
		currentUrl = url;
		pv = null;
		sig = null;
		engaged = 0;
		lastTick = Date.now();
		maxScroll = 0;
		sent = { d: -1, sc: -1 };
		pvPending = true;

		var data = {
			a: 'pv',
			u: url,
			r: isSpa ? (lastUrl || '') : d.referrer,
			t: d.title,
			sw: w.screen ? w.screen.width : 0,
			sh: w.screen ? w.screen.height : 0,
			l: n.language || '',
			tp: n.maxTouchPoints || 0,
			pid: isSpa ? 0 : cfg.pid,
			pt: isSpa ? '' : cfg.pt
		};
		if (!isSpa && cfg.q !== undefined) {
			data.q = cfg.q;
			data.qn = cfg.qn;
		}
		if (cfg.mode === 'cookie') {
			data.vid = visitorId();
		}

		clientHints().then(function (hints) {
			if (hints) {
				data.ch = hints;
			}
			return send(data, true);
		}).then(function (res) {
			pvPending = false;
			if (res && res.pv && url === currentUrl) {
				pv = res.pv;
				sig = res.sig;
			}
			flushEvents();
		});

		setTimeout(measureScroll, 300);
	}

	/* --------------------------------------------------------------- events */

	function track(category, name, label) {
		if (!started || !name) {
			return;
		}
		var data = {
			a: 'ev',
			c: category,
			n: String(name).slice(0, 191),
			lb: String(label || '').slice(0, 255),
			u: cleanUrl()
		};
		if (cfg.mode === 'cookie') {
			data.vid = visitorId();
		}
		if (pvPending) {
			pendingEvents.push(data);
			setTimeout(flushEvents, 2000);
			return;
		}
		if (pv) {
			data.pv = pv;
			data.sig = sig;
		}
		send(data, false);
	}

	function flushEvents() {
		var queue = pendingEvents;
		pendingEvents = [];
		queue.forEach(function (data) {
			if (pv) {
				data.pv = pv;
				data.sig = sig;
			}
			send(data, false);
		});
	}

	function isInternal(host) {
		host = String(host || '').toLowerCase().replace(/^www\./, '');
		return host === loc.hostname.toLowerCase().replace(/^www\./, '') || hosts.indexOf(host) !== -1;
	}

	function pagePath() {
		return loc.pathname;
	}

	function onClick(e) {
		if (e.type === 'auxclick' && e.button !== 1) {
			return;
		}
		var target = e.target;
		if (!target || !target.closest) {
			return;
		}
		var custom = target.closest('[data-pa-event]');
		if (custom) {
			track('custom', custom.getAttribute('data-pa-event'), custom.getAttribute('data-pa-label') || '');
		}
		var link = target.closest('a');
		if (!link) {
			return;
		}
		var href = link.href && typeof link.href === 'object' ? link.href.baseVal : link.href;
		if (!href) {
			return;
		}
		var protocol = String(link.protocol || '').toLowerCase();
		if (protocol === 'mailto:' || protocol === 'tel:') {
			if (cfg.links) {
				var value = href.replace(/^(mailto|tel):/i, '').split('?')[0];
				try { value = decodeURIComponent(value); } catch (err) {}
				track(protocol === 'mailto:' ? 'email' : 'phone', value, pagePath());
			}
			return;
		}
		if (protocol !== 'http:' && protocol !== 'https:') {
			return;
		}
		var path = link.pathname || '';
		var ext = path.indexOf('.') > -1 ? path.split('.').pop().toLowerCase() : '';
		if (cfg.downloads && (link.hasAttribute('download') || (ext && extensions.indexOf(ext) > -1))) {
			var file = path.split('/').pop() || href;
			try { file = decodeURIComponent(file); } catch (err2) {}
			track('download', file, href.split('?')[0]);
			return;
		}
		if (cfg.outbound && link.hostname && !isInternal(link.hostname)) {
			track('outbound', link.hostname.replace(/^www\./, ''), href.split('?')[0].split('#')[0]);
		}
	}

	var PLUGIN_FORMS = '.wpcf7-form, .wpforms-form, [id^="gform_"], .elementor-form, .frm-fluent-form, .nf-form-layout form, .forminator-custom-form';

	function formName(form) {
		var action = form.getAttribute('action') || '';
		return form.getAttribute('data-pa-form') || form.id || form.getAttribute('name') || form.getAttribute('aria-label') ||
			(action && action.charAt(0) !== '#' ? action.split('?')[0] : '') || 'form';
	}

	function onSubmit(e) {
		var form = e.target;
		if (!cfg.forms || !form || form.nodeName !== 'FORM' || form.hasAttribute('data-pa-ignore')) {
			return;
		}
		if (form.getAttribute('role') === 'search' || form.querySelector('input[name="s"]')) {
			return; // Site searches are reported separately.
		}
		if (form.matches && form.matches(PLUGIN_FORMS)) {
			return; // Tracked on successful submission below.
		}
		track('form', formName(form), pagePath());
	}

	function bindFormPlugins() {
		if (!cfg.forms) {
			return;
		}
		d.addEventListener('wpcf7mailsent', function (e) {
			var id = e.detail && e.detail.contactFormId ? e.detail.contactFormId : '';
			track('form', 'Contact Form 7' + (id ? ' #' + id : ''), pagePath());
		});
		var $ = w.jQuery;
		if ($ && $.fn) {
			$(d).on('wpformsAjaxSubmitSuccess', function (ev) {
				var f = ev.target && ev.target.id ? ev.target.id : 'wpforms';
				track('form', 'WPForms ' + f, pagePath());
			});
			$(d).on('gform_confirmation_loaded', function (ev, id) { track('form', 'Gravity Forms #' + id, pagePath()); });
			$(d).on('submit_success', function (ev) {
				var f = ev.target && ev.target.getAttribute ? (ev.target.getAttribute('name') || ev.target.id) : '';
				track('form', 'Elementor Form' + (f ? ' ' + f : ''), pagePath());
			});
			$(d).on('fluentform_submission_success', function (ev, data) {
				var f = data && data.config && data.config.id ? data.config.id : '';
				track('form', 'Fluent Forms' + (f ? ' #' + f : ''), pagePath());
			});
			$(d).on('forminator:form:submit:success', function (ev) {
				var f = ev.target && ev.target.id ? ev.target.id : 'forminator';
				track('form', 'Forminator ' + f, pagePath());
			});
		}
	}

	/* ---------------------------------------------------------------- start */

	function begin() {
		pageview(false);

		['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel'].forEach(function (type) {
			d.addEventListener(type, function () { lastActivity = Date.now(); }, { passive: true });
		});
		var scrollTimer = null;
		w.addEventListener('scroll', function () {
			lastActivity = Date.now();
			if (!scrollTimer) {
				scrollTimer = setTimeout(function () { scrollTimer = null; measureScroll(); }, 250);
			}
		}, { passive: true });

		setInterval(settle, 5000);
		d.addEventListener('visibilitychange', function () {
			settle();
			visible = d.visibilityState !== 'hidden';
			if (!visible) {
				sendEngagement();
			}
		});
		w.addEventListener('pagehide', sendEngagement);

		d.addEventListener('click', onClick, true);
		d.addEventListener('auxclick', onClick, true);
		d.addEventListener('submit', onSubmit, true);
		bindFormPlugins();

		if (cfg.spa && w.history && w.history.pushState) {
			var push = w.history.pushState;
			w.history.pushState = function () {
				var result = push.apply(this, arguments);
				setTimeout(function () { pageview(true); }, 0);
				return result;
			};
			w.addEventListener('popstate', function () { pageview(true); });
		}

		// Process calls queued before the script loaded: pulseAnalytics.q = [['Event name', 'label'], ...].
		(api.q || []).forEach(function (args) { track('custom', args[0], args[1]); });
		api.q = [];
	}

	function start() {
		if (started || blocked() || !hasConsent()) {
			return;
		}
		started = true;
		if (d.prerendering) {
			d.addEventListener('prerenderingchange', begin, { once: true });
		} else {
			begin();
		}
	}

	/* ----------------------------------------------------------- public API */

	api.track = function (name, label) {
		track('custom', name, label);
	};
	api.consent = function (granted) {
		api.consentGiven = granted !== false;
		if (api.consentGiven) {
			start();
		}
	};
	d.addEventListener('wp_listen_for_consent_change', function (e) {
		var changed = e.detail || {};
		if (changed.statistics === 'allow') {
			start();
		}
	});

	start();
})(window, document);
