/*!
 * Pulse Analytics dashboard views.
 */
(function (window, document) {
	'use strict';

	var App = window.PulseApp;
	if (!App) {
		return;
	}
	var C = App.C, h = App.h, clear = App.clear, icon = App.icon, fmt = App.fmt, METRICS = App.METRICS, state = App.state;
	var __ = App.__, _n = App._n, sprintf = App.sprintf;
	var SERIES = App.palette.series;

	var root = document.getElementById('pulse-analytics-app');
	if (!root) {
		return;
	}

	var headerEl, filterBarEl, tabsEl, viewEl, popoverEl = null;
	// Explicit order (object keys such as "404" would otherwise sort first).
	var EVENT_ORDER = ['form', 'custom', 'outbound', 'download', 'phone', 'email', 'search', '404'];
	var timers = [];
	var lastHash = null;

	/* =================================================================== *
	 * Popovers
	 * =================================================================== */

	function closePopover() {
		if (popoverEl) {
			popoverEl.remove();
			popoverEl = null;
		}
	}

	function openPopover(anchor, content, opts) {
		closePopover();
		opts = opts || {};
		popoverEl = h('div', { 'class': 'pa-popover' + (opts.className ? ' ' + opts.className : ''), role: 'dialog' }, content);
		root.appendChild(popoverEl);
		var a = anchor.getBoundingClientRect();
		var r = root.getBoundingClientRect();
		var left = a.left - r.left;
		var width = popoverEl.offsetWidth;
		if (opts.alignRight || left + width > r.width - 8) {
			left = Math.max(8, a.right - r.left - width);
		}
		popoverEl.style.left = left + 'px';
		popoverEl.style.top = (a.bottom - r.top + 6) + 'px';
		var focusable = popoverEl.querySelector('input, select, button');
		if (focusable && opts.focus !== false) {
			focusable.focus();
		}
		return popoverEl;
	}

	document.addEventListener('mousedown', function (e) {
		if (popoverEl && !popoverEl.contains(e.target) && !e.target.closest('[data-popover-anchor]')) {
			closePopover();
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closePopover();
		}
	});

	/* =================================================================== *
	 * Header: title, live badge, date range, comparison, export
	 * =================================================================== */

	function rangeLabel() {
		var preset = App.PRESETS.filter(function (p) { return p.key === state.preset; })[0];
		var range = state.start === state.end ? App.dates.format(state.start, { month: 'short', day: 'numeric', year: 'numeric' })
			: App.dates.format(state.start, { month: 'short', day: 'numeric' }) + ' - ' + App.dates.format(state.end, { month: 'short', day: 'numeric', year: 'numeric' });
		return { title: preset ? preset.label : __('Custom range', 'pulse-analytics'), range: range };
	}

	function setRange(preset, start, end) {
		state.preset = preset;
		if (preset === 'custom') {
			state.start = start;
			state.end = end;
		} else {
			var range = App.presetRange(preset);
			state.start = range[0];
			state.end = range[1];
		}
		App.savePrefs();
		closePopover();
		App.syncHash(true);
		App.refresh();
	}

	function datePicker() {
		var label = rangeLabel();
		var button = h('button', { type: 'button', 'class': 'pa-btn pa-range-btn', 'data-popover-anchor': '', 'aria-haspopup': 'dialog' }, [
			icon('calendar'),
			h('span', { 'class': 'pa-range-title', text: label.title }),
			h('span', { 'class': 'pa-range-dates', text: label.range }),
			icon('chevronDown')
		]);
		button.addEventListener('click', function () {
			if (popoverEl) { closePopover(); return; }
			var list = h('div', { 'class': 'pa-preset-list' });
			App.PRESETS.forEach(function (p) {
				list.appendChild(h('button', { type: 'button', 'class': 'pa-preset' + (state.preset === p.key ? ' is-active' : ''), onclick: function () { setRange(p.key); } }, [
					h('span', { 'class': 'pa-preset-check' }, state.preset === p.key ? icon('check') : null),
					h('span', { text: p.label }),
					h('span', { 'class': 'pa-preset-range', text: (function () { var r = App.presetRange(p.key); return r[0] === r[1] ? App.dates.short(r[0]) : App.dates.short(r[0]) + ' - ' + App.dates.short(r[1]); })() })
				]));
			});
			var from = h('input', { type: 'date', 'class': 'pa-input', value: state.start, max: C.today, 'aria-label': __('Start date', 'pulse-analytics') });
			var to = h('input', { type: 'date', 'class': 'pa-input', value: state.end, max: C.today, 'aria-label': __('End date', 'pulse-analytics') });
			var custom = h('form', { 'class': 'pa-custom-range', onsubmit: function (e) {
				e.preventDefault();
				var s = from.value, en = to.value;
				if (!s || !en) { return; }
				if (s > en) { var tmp = s; s = en; en = tmp; }
				setRange('custom', s, en);
			} }, [
				h('div', { 'class': 'pa-custom-title', text: __('Custom range', 'pulse-analytics') }),
				h('div', { 'class': 'pa-custom-fields' }, [from, h('span', { text: __('to', 'pulse-analytics') }), to]),
				h('button', { type: 'submit', 'class': 'pa-btn pa-btn-primary pa-btn-sm', text: __('Apply', 'pulse-analytics') })
			]);
			openPopover(button, [list, custom], { className: 'pa-range-pop', focus: false });
		});
		return button;
	}

	function compareSelect() {
		var select = h('select', { 'class': 'pa-select', 'aria-label': __('Comparison', 'pulse-analytics') }, [
			h('option', { value: 'previous', text: __('Compare: previous period', 'pulse-analytics') }),
			h('option', { value: 'year', text: __('Compare: previous year', 'pulse-analytics') }),
			h('option', { value: '', text: __('No comparison', 'pulse-analytics') })
		]);
		select.value = state.compare;
		select.addEventListener('change', function () {
			state.compare = select.value;
			App.savePrefs();
			App.syncHash(false);
			App.refresh();
		});
		return select;
	}

	function exportMenu() {
		var button = h('button', { type: 'button', 'class': 'pa-btn', 'data-popover-anchor': '', 'aria-haspopup': 'menu' }, [icon('download'), h('span', { 'class': 'pa-hide-sm', text: __('Export', 'pulse-analytics') }), icon('chevronDown')]);
		button.addEventListener('click', function () {
			if (popoverEl) { closePopover(); return; }
			var items = [
				['all', null, __('All reports in one ZIP file', 'pulse-analytics')],
				['pdf', null, __('Summary report (PDF)', 'pulse-analytics')],
				'sep',
				['summary', null, __('Summary & trend', 'pulse-analytics')],
				['breakdown', 'page', __('All pages', 'pulse-analytics')],
				['breakdown', 'source', __('Traffic sources', 'pulse-analytics')],
				['breakdown', 'channel', __('Channels', 'pulse-analytics')],
				['breakdown', 'utm_campaign', __('Campaigns', 'pulse-analytics')],
				['breakdown', 'country', __('Countries', 'pulse-analytics')],
				['breakdown', 'city', __('Cities', 'pulse-analytics')],
				['breakdown', 'device', __('Devices', 'pulse-analytics')],
				['breakdown', 'browser', __('Browsers', 'pulse-analytics')],
				['breakdown', 'event_name', __('Events', 'pulse-analytics')],
				['goals', null, __('Goal conversions', 'pulse-analytics')],
				'sep',
				['sessions', null, __('Raw data: visits', 'pulse-analytics')],
				['pageviews', null, __('Raw data: pageviews', 'pulse-analytics')],
				['events', null, __('Raw data: events', 'pulse-analytics')]
			];
			var menu = h('div', { 'class': 'pa-menu', role: 'menu' }, [h('div', { 'class': 'pa-menu-title', text: __('Download (current filters)', 'pulse-analytics') })]);
			items.forEach(function (item) {
				if (item === 'sep') {
					menu.appendChild(h('hr'));
					return;
				}
				if (item[0] === 'pdf') {
					menu.appendChild(h('div', { 'class': 'pa-menu-row' }, [
						h('a', { 'class': 'pa-menu-item is-strong', role: 'menuitem', href: App.pdfUrl(), onclick: closePopover }, [icon('file'), h('span', { text: item[2] })]),
						C.canManage ? h('button', {
							type: 'button',
							'class': 'pa-menu-prompt',
							role: 'menuitem',
							title: __('Send the PDF report by email', 'pulse-analytics'),
							onclick: function () { closePopover(); App.emailReport(); }
						}, [icon('mail'), h('span', { text: __('Email', 'pulse-analytics') })]) : null
					]));
					return;
				}
				var itemIcon = item[0] === 'all' ? 'download' : (item[0] === 'breakdown' || item[0] === 'summary' || item[0] === 'goals' ? 'table' : 'file');
				var href = App.exportUrl(item[0], { dimension: item[1], interval: state.interval });
				menu.appendChild(h('div', { 'class': 'pa-menu-row' }, [
					h('a', { 'class': 'pa-menu-item' + (item[0] === 'all' ? ' is-strong' : ''), role: 'menuitem', href: href, onclick: closePopover }, [icon(itemIcon), h('span', { text: item[2] })]),
					h('button', {
						type: 'button',
						'class': 'pa-menu-prompt',
						role: 'menuitem',
						title: __('Copy a prompt that explains this file to an AI tool', 'pulse-analytics'),
						'aria-label': sprintf(
							/* translators: %s: name of an export, such as "All pages" */
							__('AI prompt for %s', 'pulse-analytics'),
							item[2]
						),
						onclick: function () { closePopover(); App.showPrompt(href); }
					}, [icon('sparkles'), h('span', { text: __('AI prompt', 'pulse-analytics') })])
				]));
			});
			openPopover(button, menu, { alignRight: true, focus: false });
		});
		return button;
	}

	var liveEl = null;
	function refreshLive() {
		App.widget({ type: 'live' }, { start: C.today, end: C.today, compare: '', filters: [] }).then(function (data) {
			if (!liveEl) { return; }
			liveEl.querySelector('.pa-live-count').textContent = fmt.num(data.active);
			liveEl.classList.toggle('is-active', data.active > 0);
			liveEl.title = sprintf(_n('%s visitor active in the last 5 minutes', '%s visitors active in the last 5 minutes', data.active, 'pulse-analytics'), fmt.num(data.active));
		}).catch(function () {});
	}

	function renderHeader() {
		clear(headerEl);
		liveEl = h('a', { 'class': 'pa-live', href: App.hashFor('realtime', {}) }, [
			h('span', { 'class': 'pa-live-dot' }),
			h('span', { 'class': 'pa-live-count', text: '-' }),
			h('span', { 'class': 'pa-live-label', text: __('online now', 'pulse-analytics') })
		]);
		headerEl.appendChild(h('div', { 'class': 'pa-brand' }, [
			h('span', { 'class': 'pa-logo', html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 13h4l3-7 4 13 3-9 2 3h4"/></svg>' }),
			h('div', { 'class': 'pa-brand-text' }, [
				h('h2', { 'class': 'pa-title', text: __('Statistics', 'pulse-analytics') }),
				h('span', { 'class': 'pa-site', text: C.siteName })
			]),
			liveEl
		]));
		headerEl.appendChild(h('div', { 'class': 'pa-controls' }, [
			datePicker(),
			compareSelect(),
			h('button', { type: 'button', 'class': 'pa-btn pa-icon-only', title: __('Refresh', 'pulse-analytics'), 'aria-label': __('Refresh', 'pulse-analytics'), onclick: function () { App.freshNextLoad(); App.refresh(); refreshLive(); } }, [icon('refresh')]),
			exportMenu(),
			C.canManage ? h('a', { 'class': 'pa-btn pa-icon-only', href: C.settingsUrl, title: __('Settings', 'pulse-analytics'), 'aria-label': __('Settings', 'pulse-analytics') }, [icon('settings')]) : null
		]));
		refreshLive();
	}

	/* =================================================================== *
	 * Filter bar
	 * =================================================================== */

	var ENUM_DIMS = ['channel', 'device', 'continent', 'visitor_type', 'user_type', 'event_category', 'goal', 'country'];

	function filterEditor(anchor, index, presetDim) {
		var existing = index !== null && index !== undefined ? state.filters[index] : null;
		var dim = existing ? existing.dim : presetDim || null;
		var content = h('div', { 'class': 'pa-filter-editor' });

		function chooseDim() {
			clear(content);
			content.appendChild(h('div', { 'class': 'pa-menu-title', text: __('Filter by', 'pulse-analytics') }));
			var grid = h('div', { 'class': 'pa-dim-groups' });
			App.FILTER_GROUPS.forEach(function (group) {
				var col = h('div', { 'class': 'pa-dim-group' }, [h('div', { 'class': 'pa-dim-group-title', text: group.label })]);
				group.dims.forEach(function (d) {
					if (d === 'goal' && !(C.goals || []).length) { return; }
					col.appendChild(h('button', { type: 'button', 'class': 'pa-dim', text: App.DIMENSIONS[d], onclick: function () { dim = d; editValue(); } }));
				});
				grid.appendChild(col);
			});
			content.appendChild(grid);
		}

		function editValue() {
			clear(content);
			var isEnum = ENUM_DIMS.indexOf(dim) > -1;
			var op = h('select', { 'class': 'pa-select' });
			Object.keys(App.OPS).forEach(function (key) {
				if (isEnum && key.indexOf('contains') > -1) { return; }
				op.appendChild(h('option', { value: key, text: App.OPS[key] }));
			});
			op.value = existing ? existing.op : 'is';
			var selectedKey = existing && !Array.isArray(existing.value) ? existing.value : null;
			var input = h('input', { type: 'text', 'class': 'pa-input', placeholder: __('Type to search...', 'pulse-analytics'), autocomplete: 'off', value: existing ? App.valueLabel(dim, existing.value) : '' });
			var list = h('ul', { 'class': 'pa-suggest' });
			var apply = h('button', { type: 'submit', 'class': 'pa-btn pa-btn-primary pa-btn-sm', text: existing ? __('Update filter', 'pulse-analytics') : __('Apply filter', 'pulse-analytics') });
			var values = [];
			var searchTimer = null;

			function renderSuggestions() {
				clear(list);
				var q = input.value.trim().toLowerCase();
				var shown = values.filter(function (v) { return !q || String(v.label).toLowerCase().indexOf(q) > -1 || String(v.key).toLowerCase().indexOf(q) > -1; }).slice(0, 12);
				if (!shown.length) {
					list.appendChild(h('li', { 'class': 'pa-suggest-empty', text: values.length ? __('No matching values', 'pulse-analytics') : __('Loading...', 'pulse-analytics') }));
				}
				shown.forEach(function (v) {
					list.appendChild(h('li', {}, h('button', {
						type: 'button',
						'class': 'pa-suggest-item' + (selectedKey === v.key ? ' is-active' : ''),
						onclick: function () {
							// One click applies the filter (for "contains" operators the label text is used).
							selectedKey = v.key;
							input.value = v.label;
							if (op.value === 'contains' || op.value === 'not_contains') {
								op.value = op.value === 'contains' ? 'is' : 'is_not';
							}
							form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
						}
					}, [dim === 'country' ? App.flag(v.key) : null, h('span', { text: v.label }), dim.indexOf('page') > -1 ? h('small', { text: App.decodePath(v.key) }) : null])));
				});
			}

			function loadSuggestions(search) {
				if (dim === 'continent') {
					values = Object.keys(C.continents).map(function (k) { return { key: k, label: C.continents[k] }; });
					renderSuggestions();
					return;
				}
				App.widget({ type: 'suggest', dimension: dim, search: search || '' }).then(function (data) {
					values = data.values || [];
					renderSuggestions();
				}).catch(function () { values = []; renderSuggestions(); });
			}

			input.addEventListener('input', function () {
				selectedKey = null;
				renderSuggestions();
				if (!isEnum) {
					clearTimeout(searchTimer);
					searchTimer = setTimeout(function () { loadSuggestions(input.value.trim()); }, 300);
				}
			});

			var form = h('form', { 'class': 'pa-filter-form', onsubmit: function (e) {
				e.preventDefault();
				var operator = op.value;
				var value = selectedKey !== null && (operator === 'is' || operator === 'is_not') ? selectedKey : input.value.trim();
				if (value === '' && operator.indexOf('contains') > -1) { return; }
				if (selectedKey === null && (operator === 'is' || operator === 'is_not')) {
					var match = values.filter(function (v) { return String(v.label).toLowerCase() === input.value.trim().toLowerCase(); })[0];
					if (match) { value = match.key; }
				}
				var filter = { dim: dim, op: operator, value: value };
				if (existing) {
					state.filters[index] = filter;
				} else {
					state.filters.push(filter);
				}
				closePopover();
				App.syncHash(true);
				App.refresh();
			} }, [
				h('div', { 'class': 'pa-filter-head' }, [
					existing ? null : h('button', { type: 'button', 'class': 'pa-icon-btn', 'aria-label': __('Back', 'pulse-analytics'), onclick: chooseDim }, [icon('chevronLeft')]),
					h('strong', { text: App.DIMENSIONS[dim] })
				]),
				h('div', { 'class': 'pa-filter-row' }, [op, input]),
				list,
				h('div', { 'class': 'pa-filter-actions' }, [
					existing ? h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', onclick: function () { closePopover(); App.removeFilter(index); }, text: __('Remove', 'pulse-analytics') }) : null,
					apply
				])
			]);
			content.appendChild(form);
			renderSuggestions();
			loadSuggestions('');
			setTimeout(function () { input.focus(); }, 0);
		}

		if (dim) {
			editValue();
		} else {
			chooseDim();
		}
		openPopover(anchor, content, { className: 'pa-filter-pop', focus: false });
	}

	function renderFilterBar() {
		clear(filterBarEl);
		var add = h('button', { type: 'button', 'class': 'pa-btn pa-btn-ghost', 'data-popover-anchor': '' }, [icon('filter'), h('span', { text: __('Add filter', 'pulse-analytics') })]);
		add.addEventListener('click', function () {
			if (popoverEl) { closePopover(); return; }
			filterEditor(add, null, null);
		});
		state.filters.forEach(function (f, i) {
			var chip = h('span', { 'class': 'pa-chip' }, [
				h('button', { type: 'button', 'class': 'pa-chip-main', 'data-popover-anchor': '', title: __('Edit filter', 'pulse-analytics'), onclick: function (e) { if (popoverEl) { closePopover(); return; } filterEditor(e.currentTarget, i); } }, [
					h('span', { 'class': 'pa-chip-dim', text: App.DIMENSIONS[f.dim] }),
					h('span', { 'class': 'pa-chip-op', text: App.OPS[f.op] }),
					f.dim === 'country' && f.op === 'is' && !Array.isArray(f.value) ? App.flag(f.value) : null,
					h('strong', { text: f.dim.indexOf('page') > -1 ? App.decodePath(App.valueLabel(f.dim, f.value)) : App.valueLabel(f.dim, f.value) })
				]),
				h('button', { type: 'button', 'class': 'pa-chip-x', 'aria-label': __('Remove filter', 'pulse-analytics'), onclick: function () { App.removeFilter(i); } }, [icon('close')])
			]);
			filterBarEl.appendChild(chip);
		});
		filterBarEl.appendChild(add);
		if (state.filters.length) {
			filterBarEl.appendChild(h('button', {
				type: 'button',
				'class': 'pa-btn pa-btn-clear',
				onclick: function () {
					closePopover();
					App.clearFilters();
				}
			}, [icon('close'), h('span', { text: __('Clear all filters', 'pulse-analytics') })]));
		}
	}

	/* =================================================================== *
	 * Tabs
	 * =================================================================== */

	var VIEWS = {};
	var TAB_ORDER = [
		['overview', __('Overview', 'pulse-analytics'), 'chart'],
		['realtime', __('Real-time', 'pulse-analytics'), 'zap'],
		['pages', __('Pages', 'pulse-analytics'), 'file'],
		['sources', __('Sources', 'pulse-analytics'), 'megaphone'],
		['campaigns', __('Campaigns', 'pulse-analytics'), 'target'],
		['locations', __('Locations', 'pulse-analytics'), 'globe'],
		['technology', __('Technology', 'pulse-analytics'), 'desktop'],
		['events', __('Events', 'pulse-analytics'), 'pointer'],
		['goals', __('Goals', 'pulse-analytics'), 'check'],
		['visitors', __('Visitor log', 'pulse-analytics'), 'users'],
		['content', __('Content', 'pulse-analytics'), 'layers']
	];

	function renderTabs() {
		clear(tabsEl);
		var activeView = state.view === 'page' ? 'pages' : state.view;
		TAB_ORDER.forEach(function (tab) {
			tabsEl.appendChild(h('a', {
				'class': 'pa-tab' + (tab[0] === activeView ? ' is-active' : ''),
				href: App.hashFor(tab[0], {}),
				'aria-current': tab[0] === activeView ? 'page' : null
			}, [icon(tab[2]), h('span', { text: tab[1] })]));
		});
	}

	/* =================================================================== *
	 * Trend: KPI tiles + main chart
	 * =================================================================== */

	function trend(parent, opts) {
		var metrics = opts.metrics;
		var chartable = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'avg_duration'];
		var kpis = h('div', { 'class': 'pa-kpis' });
		parent.appendChild(kpis);
		for (var i = 0; i < metrics.length; i++) {
			kpis.appendChild(h('div', { 'class': 'pa-kpi is-skeleton' }, [h('span', { 'class': 'pa-skel-row' }), h('span', { 'class': 'pa-skel-row is-big' })]));
		}

		var intervalWrap = h('div', { 'class': 'pa-segmented', role: 'group', 'aria-label': __('Interval', 'pulse-analytics') });
		var tableBtn = h('button', { type: 'button', 'class': 'pa-icon-btn', title: __('Show as table', 'pulse-analytics'), 'aria-label': __('Show as table', 'pulse-analytics'), 'aria-pressed': 'false' }, [icon('table')]);
		var card = App.card(parent, {
			title: '',
			className: 'pa-trend-card',
			actions: [intervalWrap, tableBtn, App.csvButton(function () { return App.exportUrl('summary', { interval: state.interval }); })]
		});
		var legend = h('div', { 'class': 'pa-legend' });
		var chartEl = h('div', { 'class': 'pa-trend-chart' });
		var tableEl = h('div', { 'class': 'pa-trend-table', hidden: true });
		card.body.appendChild(legend);
		card.body.appendChild(chartEl);
		card.body.appendChild(tableEl);
		chartEl.appendChild(App.skeleton(1));

		var summary = null, series = null, chart = null, showTable = false;

		tableBtn.addEventListener('click', function () {
			showTable = !showTable;
			tableBtn.setAttribute('aria-pressed', showTable ? 'true' : 'false');
			tableBtn.classList.toggle('is-active', showTable);
			chartEl.hidden = showTable;
			tableEl.hidden = !showTable;
			renderTable();
		});

		function metricLabel(m) {
			if (m === 'avg_duration' && summary && summary.mode === 'page') {
				return METRICS.avg_time.label;
			}
			return METRICS[m] ? METRICS[m].label : m;
		}

		function renderIntervals() {
			clear(intervalWrap);
			var days = Math.round((App.dates.parse(state.end) - App.dates.parse(state.start)) / 86400000) + 1;
			var current = series ? series.interval : state.interval;
			[['hour', __('Hourly', 'pulse-analytics'), days <= 31], ['day', __('Daily', 'pulse-analytics'), days > 1], ['week', __('Weekly', 'pulse-analytics'), days >= 14], ['month', __('Monthly', 'pulse-analytics'), days >= 60]].forEach(function (iv) {
				if (!iv[2]) { return; }
				intervalWrap.appendChild(h('button', { type: 'button', 'class': 'pa-seg' + (current === iv[0] ? ' is-active' : ''), text: iv[1], onclick: function () {
					state.interval = iv[0];
					App.syncHash(false);
					loadSeries();
				} }));
			});
		}

		function renderKpis() {
			clear(kpis);
			var cur = summary.current, prev = summary.previous;
			metrics.forEach(function (m) {
				var can = chartable.indexOf(m) > -1;
				var active = can && state.metric === m;
				var tile = h(can ? 'button' : 'div', {
					type: can ? 'button' : null,
					'class': 'pa-kpi' + (active ? ' is-active' : '') + (can ? ' is-clickable' : ''),
					'aria-pressed': can ? (active ? 'true' : 'false') : null
				}, [
					h('span', { 'class': 'pa-kpi-label', text: metricLabel(m) }),
					h('span', { 'class': 'pa-kpi-value', text: cur[m] === undefined ? '-' : App.metricFormat(m === 'avg_duration' && summary.mode === 'page' ? 'avg_time' : m, cur[m]) }),
					prev && prev[m] !== undefined ? App.deltaBadge(m, cur[m], prev[m]) : null
				]);
				if (can) {
					tile.addEventListener('click', function () {
						state.metric = m;
						App.syncHash(false);
						renderKpis();
						renderChart();
					});
				}
				kpis.appendChild(tile);
			});
		}

		function renderChart() {
			if (!series) { return; }
			var metric = chartable.indexOf(state.metric) > -1 && metrics.indexOf(state.metric) > -1 ? state.metric : metrics[0];
			var def = METRICS[metric];
			var labels = series.labels;
			var interval = series.interval;
			card.header.querySelector('.pa-card-title span').textContent = metricLabel(metric);
			clear(legend);
			legend.appendChild(h('span', { 'class': 'pa-legend-item' }, [h('i', { 'class': 'pa-line-key', style: { background: SERIES[0] } }), labels.length ? App.dates.short(state.start) + (state.start !== state.end ? ' - ' + App.dates.short(state.end) : '') : '']));
			if (series.previous && summary && summary.prev_range) {
				legend.appendChild(h('span', { 'class': 'pa-legend-item' }, [h('i', { 'class': 'pa-line-key is-muted' }), App.dates.short(summary.prev_range[0]) + (summary.prev_range[0] !== summary.prev_range[1] ? ' - ' + App.dates.short(summary.prev_range[1]) : '')]));
			}
			var partial = state.end >= C.today && labels.length > 1;
			var data = {
				labels: labels,
				height: 280,
				ariaLabel: metricLabel(metric),
				series: [{ name: __('Current period', 'pulse-analytics'), values: series.current[metric], color: SERIES[0], area: true, partialLast: partial }],
				yFormat: def.axis || def.format,
				valueFormat: def.format,
				minMax: metric === 'bounce_rate' ? 10 : 0,
				xLabel: function (i) { return App.dates.bucket(labels[i], interval); },
				tooltipTitle: function (i) {
					return App.dates.bucket(labels[i], interval, true) + (partial && i === labels.length - 1 ? ' (' + __('in progress', 'pulse-analytics') + ')' : '');
				},
				seriesLabel: function (s, i) {
					if (s.muted) { return series.prev_labels && series.prev_labels[i] ? App.dates.bucket(series.prev_labels[i], interval, true) : ''; }
					return '';
				}
			};
			if (series.previous) {
				data.series.push({ name: __('Previous period', 'pulse-analytics'), values: series.previous[metric], color: '#a9a8a2', muted: true });
			}
			if (interval === 'day' && labels.length > 1) {
				data.onClick = function (i) {
					setRange('custom', labels[i], labels[i]);
				};
			}
			if (chart) {
				chart.update(data);
			} else {
				clear(chartEl);
				chart = window.PulseCharts.line(chartEl, data);
			}
			renderTable();
		}

		function renderTable() {
			if (!showTable || !series) { return; }
			clear(tableEl);
			var metric = chartable.indexOf(state.metric) > -1 ? state.metric : metrics[0];
			var rows = series.labels.map(function (label, i) {
				return h('tr', {}, [
					h('td', { text: App.dates.bucket(label, series.interval, true) }),
					h('td', { 'class': 'pa-td-num', text: App.metricFormat(metric, series.current[metric][i]) }),
					series.previous ? h('td', { 'class': 'pa-td-num', text: App.metricFormat(metric, series.previous[metric][i]) }) : null
				]);
			});
			tableEl.appendChild(h('div', { 'class': 'pa-table-wrap pa-table-scroll' }, h('table', { 'class': 'pa-table' }, [
				h('thead', {}, h('tr', {}, [h('th', { text: __('Period', 'pulse-analytics') }), h('th', { 'class': 'pa-th-num', text: metricLabel(metric) }), series.previous ? h('th', { 'class': 'pa-th-num', text: __('Previous period', 'pulse-analytics') }) : null])),
				h('tbody', {}, rows)
			])));
		}

		function loadSeries() {
			App.loading(card.el, true);
			return App.widget({ type: 'timeseries', interval: state.interval }).then(function (data) {
				series = data;
				App.loading(card.el, false);
				renderIntervals();
				renderChart();
			}).catch(function (err) {
				App.loading(card.el, false);
				clear(chartEl).appendChild(App.errorBox(err, loadSeries));
				chart = null;
			});
		}

		var component = {
			load: function () {
				App.loading(kpis, true);
				var a = App.widget({ type: 'summary' }).then(function (data) {
					summary = data;
					App.loading(kpis, false);
					renderKpis();
					if (opts.onSummary) { opts.onSummary(data); }
					renderChart();
				}).catch(function (err) {
					App.loading(kpis, false);
					clear(kpis).appendChild(App.errorBox(err, component.load));
				});
				return Promise.all([a, loadSeries()]);
			}
		};
		renderIntervals();
		return App.mount(component);
	}

	/* =================================================================== *
	 * Map card
	 * =================================================================== */

	function mapCard(parent, opts) {
		opts = opts || {};
		var metric = 'visitors';
		var listTab = 'country';
		var metricWrap = h('div', { 'class': 'pa-segmented' });
		var card = App.card(parent, {
			title: __('Visitors by location', 'pulse-analytics'),
			icon: 'globe',
			className: 'pa-map-card' + (opts.large ? ' is-large' : ''),
			actions: [metricWrap, App.csvButton(function () { return App.exportUrl('breakdown', { dimension: listTab }); })]
		});
		var mapEl = h('div', { 'class': 'pa-map-area' });
		var side = h('div', { 'class': 'pa-map-side' });
		var notice = h('div', { 'class': 'pa-map-notice', hidden: true });
		card.body.appendChild(h('div', { 'class': 'pa-map-layout' }, [h('div', { 'class': 'pa-map-main' }, [mapEl, notice]), side]));

		var sideTabs = [{ key: 'country', label: __('Countries', 'pulse-analytics'), dimension: 'country' }, { key: 'region', label: __('Regions', 'pulse-analytics'), dimension: 'region' }, { key: 'city', label: __('Cities', 'pulse-analytics'), dimension: 'city' }];
		var tabBar = h('div', { 'class': 'pa-card-tabs is-inline' });
		var sideBody = h('div', { 'class': 'pa-map-list' });
		side.appendChild(tabBar);
		side.appendChild(sideBody);

		var map = window.PulseMap.create(mapEl, {
			ariaLabel: __('World map of visitors', 'pulse-analytics'),
			ramp: App.palette.ramp.slice(1),
			labels: { zoomIn: __('Zoom in', 'pulse-analytics'), zoomOut: __('Zoom out', 'pulse-analytics'), reset: __('Reset zoom', 'pulse-analytics') },
			onSelect: function (code) {
				var current = App.filterValue('country');
				if (current === code) {
					var idx = state.filters.findIndex(function (f) { return f.dim === 'country' && f.op === 'is'; });
					if (idx > -1) { App.removeFilter(idx); }
				} else {
					App.addFilter('country', code);
				}
			},
			tooltip: function (code, name, value) {
				var total = countryTotals[metric] || 0;
				return {
					title: App.countryName(code) !== code ? App.countryName(code) : name,
					rows: [{ color: value ? '#3987e5' : '#d6d5cf', shape: 'box', value: App.metricFormat(metric, value) + (total && value ? ' (' + fmt.pct(value / total * 100) + ')' : ''), label: METRICS[metric].label }]
				};
			}
		});
		map.setStatus(__('Loading map...', 'pulse-analytics'));
		var sources = [{ url: C.restUrl + 'map', headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' }];
		if (C.externalAssets) {
			sources.push({ url: C.mapCdn });
		}
		var countryTotals = {};
		var lastCountries = null;
		var mapReady = window.PulseMap.loadTopology(sources).then(function (features) {
			map.setFeatures(features);
			map.setStatus('');
			return true;
		}).catch(function () {
			map.setStatus(__('The map could not be loaded. Country statistics are still listed on the right.', 'pulse-analytics'));
			return false;
		});

		function renderMetricToggle() {
			clear(metricWrap);
			[['visitors', METRICS.visitors.label], ['pageviews', METRICS.pageviews.label]].forEach(function (m) {
				metricWrap.appendChild(h('button', { type: 'button', 'class': 'pa-seg' + (metric === m[0] ? ' is-active' : ''), text: m[1], onclick: function () {
					metric = m[0];
					renderMetricToggle();
					paint();
					loadList();
				} }));
			});
		}

		function renderSideTabs() {
			clear(tabBar);
			sideTabs.forEach(function (tab) {
				tabBar.appendChild(h('button', { type: 'button', 'class': 'pa-card-tab' + (tab.key === listTab ? ' is-active' : ''), text: tab.label, onclick: function () { listTab = tab.key; renderSideTabs(); loadList(); } }));
			});
		}

		function paint() {
			if (!lastCountries) { return; }
			var data = {};
			lastCountries.rows.forEach(function (row) { if (row.key) { data[row.key] = row[metric] || 0; } });
			countryTotals = lastCountries.totals || {};
			mapReady.then(function (ok) {
				if (!ok) { return; }
				map.setData(data, { label: METRICS[metric].label, format: METRICS[metric].format });
				var selected = App.filterValue('country');
				map.setSelected(selected && !Array.isArray(selected) ? selected : '');
				if (selected && !Array.isArray(selected)) {
					map.focus(selected);
				} else {
					map.reset();
				}
			});
			var unknown = lastCountries.rows.filter(function (r) { return !r.key; })[0];
			var known = lastCountries.rows.filter(function (r) { return r.key; }).length;
			notice.hidden = !(unknown && !known);
			clear(notice);
			if (unknown && !known) {
				notice.appendChild(h('span', {}, [icon('info'), __('Visitor countries are unknown. Download the free geolocation database to see countries on the map.', 'pulse-analytics')]));
				if (C.canManage) {
					notice.appendChild(h('a', { 'class': 'pa-btn pa-btn-sm', href: C.settingsUrl + '&tab=geo', text: __('Geolocation settings', 'pulse-analytics') }));
				}
			}
		}

		function loadList() {
			var tab = sideTabs.filter(function (t) { return t.key === listTab; })[0];
			App.loading(sideBody, true);
			App.widget({ type: 'breakdown', dimension: tab.dimension, limit: opts.large ? 12 : 9, sort: metric }).then(function (data) {
				App.loading(sideBody, false);
				clear(sideBody);
				App.renderRows(sideBody, { dimension: tab.dimension, metric: metric, empty: tab.key === 'country' ? null : __('City-level data needs the DB-IP City database or Cloudflare location headers.', 'pulse-analytics') }, data);
			}).catch(function (err) {
				App.loading(sideBody, false);
				clear(sideBody).appendChild(App.errorBox(err, loadList));
			});
		}

		renderMetricToggle();
		renderSideTabs();
		sideBody.appendChild(App.skeleton(8));

		return App.mount({
			load: function () {
				App.loading(card.el, true);
				App.widget({ type: 'breakdown', dimension: 'country', limit: 300 }).then(function (data) {
					App.loading(card.el, false);
					lastCountries = data;
					paint();
				}).catch(function () { App.loading(card.el, false); });
				if (C.geo.city || opts.large) {
					App.widget({ type: 'breakdown', dimension: 'city', limit: 150 }).then(function (data) {
						var markers = data.rows.filter(function (r) { return r.lat !== undefined && r.lat !== null; }).map(function (r) {
							return { lat: r.lat, lon: r.lon, value: r.visitors, label: r.label + (r.country ? ', ' + App.countryName(r.country) : ''), onClick: function () { App.addFilter('city', r.key); } };
						});
						mapReady.then(function (ok) { if (ok) { map.setMarkers(markers); } });
					}).catch(function () {});
				}
				loadList();
			}
		});
	}

	/* =================================================================== *
	 * Smaller cards
	 * =================================================================== */

	function weekdayName(d, style) {
		// 2023-01-01 was a Sunday.
		return App.dates.format('2023-01-0' + (1 + d), { weekday: style || 'short' });
	}

	function heatmapCard(parent) {
		var card = App.card(parent, { title: __('Busiest times', 'pulse-analytics'), icon: 'clock', subtitle: __('Pageviews by weekday and hour', 'pulse-analytics') });
		card.body.appendChild(App.skeleton(5));
		var component = {
			load: function () {
				App.loading(card.el, true);
				return App.widget({ type: 'heatmap' }).then(function (data) {
					App.loading(card.el, false);
					clear(card.body);
					if (!data.max) {
						card.body.appendChild(App.empty());
						return;
					}
					var order = [];
					for (var i = 0; i < 7; i++) { order.push((C.startOfWeek + i) % 7); }
					var hourFmt = function (hr) { return App.dates.format('2023-01-01 ' + (hr < 10 ? '0' : '') + hr + ':00', { hour: 'numeric' }); };
					var el = h('div');
					card.body.appendChild(el);
					window.PulseCharts.heatmap(el, {
						cells: order.map(function (d) { return data.cells[d]; }),
						rowLabels: order.map(function (d) { return weekdayName(d); }),
						colLabels: Array.apply(null, Array(24)).map(function (x, hr) { return hr % 3 === 0 ? hourFmt(hr) : ''; }),
						ramp: App.palette.ramp,
						format: fmt.num,
						valueLabel: METRICS.pageviews.label,
						lessLabel: __('Less', 'pulse-analytics'),
						moreLabel: __('More', 'pulse-analytics'),
						title: function (ri, ci) { return weekdayName(order[ri], 'long') + ', ' + hourFmt(ci) + ' - ' + hourFmt((ci + 1) % 24); }
					});
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	}

	function goalsCard(parent) {
		var card = App.card(parent, { title: __('Goals', 'pulse-analytics'), icon: 'check', className: 'pa-goals-card' });
		card.body.appendChild(App.skeleton(3));
		var component = {
			load: function () {
				if (!(C.goals || []).length) {
					clear(card.body).appendChild(h('div', { 'class': 'pa-empty pa-empty-cta' }, [
						icon('target'),
						h('p', { text: __('Track conversions such as contact form submissions, sign-ups or visits to a thank-you page.', 'pulse-analytics') }),
						h('a', { 'class': 'pa-btn pa-btn-primary pa-btn-sm', href: App.hashFor('goals', {}), text: __('Create a goal', 'pulse-analytics') })
					]));
					return Promise.resolve();
				}
				App.loading(card.el, true);
				return App.widget({ type: 'goals' }).then(function (data) {
					App.loading(card.el, false);
					clear(card.body);
					var list = h('ul', { 'class': 'pa-rows' });
					var max = Math.max.apply(null, data.goals.map(function (g) { return g.conversions; }).concat([1]));
					data.goals.forEach(function (g) {
						var li = h('li', { 'class': 'pa-row is-clickable', tabindex: 0, title: __('Filter by this goal', 'pulse-analytics') }, [
							h('span', { 'class': 'pa-row-bar is-goal', style: { width: Math.max(1, g.conversions / max * 100) + '%' } }),
							h('span', { 'class': 'pa-row-label' }, [icon(g.type === 'event' ? 'pointer' : 'file'), h('span', { 'class': 'pa-row-text', text: g.name })]),
							h('span', { 'class': 'pa-row-num is-secondary', text: fmt.pct(g.cr) }),
							h('span', { 'class': 'pa-row-num' }, [fmt.num(g.conversions), g.prev_conversions !== undefined ? App.deltaBadge('conversions', g.conversions, g.prev_conversions) : null])
						]);
						li.addEventListener('click', function () { App.addFilter('goal', g.id); });
						list.appendChild(li);
					});
					card.body.appendChild(h('div', { 'class': 'pa-rows-head' }, [h('span', { text: __('Goal', 'pulse-analytics') }), h('span', { 'class': 'pa-rows-head-num', text: METRICS.cr.label }), h('span', { 'class': 'pa-rows-head-num', text: METRICS.conversions.label })]));
					card.body.appendChild(list);
					clear(card.footer).appendChild(h('a', { 'class': 'pa-more', href: App.hashFor('goals', {}), text: __('Manage goals', 'pulse-analytics') }, [icon('chevronRight')]));
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	}

	function scrollCard(parent) {
		var card = App.card(parent, { title: __('Scroll depth', 'pulse-analytics'), icon: 'arrowDown', subtitle: __('Share of pageviews that reached each point', 'pulse-analytics') });
		card.body.appendChild(App.skeleton(4));
		var component = {
			load: function () {
				App.loading(card.el, true);
				return App.widget({ type: 'scroll' }).then(function (data) {
					App.loading(card.el, false);
					clear(card.body);
					if (!data.measured) {
						card.body.appendChild(App.empty(__('No scroll data yet.', 'pulse-analytics')));
						return;
					}
					var list = h('div', { 'class': 'pa-meters' });
					data.reached.forEach(function (r) {
						list.appendChild(h('div', { 'class': 'pa-meter' }, [
							h('span', { 'class': 'pa-meter-label', text: sprintf(__('Reached %s%%', 'pulse-analytics'), r.threshold) }),
							h('span', { 'class': 'pa-meter-track' }, h('span', { 'class': 'pa-meter-fill', style: { width: r.percent + '%' } })),
							h('span', { 'class': 'pa-meter-value', text: fmt.pct(r.percent, 0) })
						]));
					});
					card.body.appendChild(list);
					card.body.appendChild(h('p', { 'class': 'pa-note', text: sprintf(__('Average scroll depth: %1$s of the page (%2$s pageviews measured).', 'pulse-analytics'), fmt.pct(data.average, 0), fmt.num(data.measured)) }));
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	}

	function insightsCard(parent) {
		var card = App.card(parent, { title: __('Insights', 'pulse-analytics'), icon: 'sparkles', className: 'pa-insights-card' });
		card.body.appendChild(App.skeleton(4));
		var component = {
			load: function () {
				App.loading(card.el, true);
				return Promise.all([
					App.widget({ type: 'summary' }),
					App.widget({ type: 'breakdown', dimension: 'source', limit: 50, with_previous: true }),
					App.widget({ type: 'breakdown', dimension: 'page', limit: 50, with_previous: true }),
					App.widget({ type: 'breakdown', dimension: 'device', limit: 5 }),
					App.widget({ type: 'breakdown', dimension: 'channel', limit: 12 }),
					App.widget({ type: 'timeseries', interval: 'day' })
				]).then(function (res) {
					App.loading(card.el, false);
					clear(card.body);
					var items = buildInsights(res[0], res[1], res[2], res[3], res[4], res[5]);
					if (!items.length) {
						card.body.appendChild(App.empty(__('Insights appear once there is enough data.', 'pulse-analytics')));
						return;
					}
					var list = h('ul', { 'class': 'pa-insights' });
					items.slice(0, 6).forEach(function (it) {
						list.appendChild(h('li', { 'class': 'pa-insight is-' + (it.tone || 'neutral') }, [icon(it.icon), h('span', { text: it.text })]));
					});
					card.body.appendChild(list);
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(card.body).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		return App.mount(component);
	}

	function buildInsights(summary, sources, pages, devices, channels, series) {
		var out = [];
		var cur = summary.current, prev = summary.previous;
		if (!cur.visitors) {
			return out;
		}
		if (prev && prev.visitors) {
			var change = App.change(cur.visitors, prev.visitors);
			if (Math.abs(change) >= 1) {
				out.push({
					icon: change >= 0 ? 'arrowUp' : 'arrowDown',
					tone: change >= 0 ? 'good' : 'bad',
					text: change >= 0 ? sprintf(__('Visitors are up %1$s compared with the previous period (%2$s vs. %3$s).', 'pulse-analytics'), fmt.pct(change, 0), fmt.num(cur.visitors), fmt.num(prev.visitors))
						: sprintf(__('Visitors are down %1$s compared with the previous period (%2$s vs. %3$s).', 'pulse-analytics'), fmt.pct(Math.abs(change), 0), fmt.num(cur.visitors), fmt.num(prev.visitors))
				});
			}
		}
		var grower = null;
		(sources.rows || []).forEach(function (row) {
			if (!row.key) { return; }
			var before = row.prev ? row.prev.visitors : 0;
			var gain = row.visitors - before;
			if (row.visitors >= 5 && gain > 0 && (!grower || gain > grower.gain)) {
				grower = { row: row, gain: gain, before: before };
			}
		});
		if (grower && summary.previous) {
			out.push({
				icon: 'megaphone',
				tone: 'good',
				text: grower.before ? sprintf(__('Fastest-growing source: %1$s sent %2$s visitors (+%3$s).', 'pulse-analytics'), grower.row.label, fmt.num(grower.row.visitors), fmt.pct(App.change(grower.row.visitors, grower.before), 0))
					: sprintf(__('New traffic source: %1$s sent %2$s visitors.', 'pulse-analytics'), grower.row.label, fmt.num(grower.row.visitors))
			});
		}
		var ai = (channels.rows || []).filter(function (r) { return r.key === 'ai'; })[0];
		if (ai && ai.visitors) {
			out.push({ icon: 'sparkles', tone: 'neutral', text: sprintf(__('AI assistants such as ChatGPT, Perplexity and Gemini sent %1$s visitors (%2$s of all traffic).', 'pulse-analytics'), fmt.num(ai.visitors), fmt.pct(ai.visitors / cur.visitors * 100)) });
		}
		if (pages.rows && pages.rows.length) {
			var top = pages.rows[0];
			out.push({ icon: 'file', tone: 'neutral', text: sprintf(__('Most viewed page: "%1$s" with %2$s pageviews.', 'pulse-analytics'), top.label, fmt.num(top.pageviews)) });
			var riser = null;
			pages.rows.forEach(function (row) {
				if (row.prev && row.prev.pageviews >= 5 && row.pageviews >= 10) {
					var c = App.change(row.pageviews, row.prev.pageviews);
					if (c >= 25 && (!riser || c > riser.c)) { riser = { row: row, c: c }; }
				}
			});
			if (riser && riser.row !== top) {
				out.push({ icon: 'arrowUp', tone: 'good', text: sprintf(__('Trending: pageviews of "%1$s" grew by %2$s.', 'pulse-analytics'), riser.row.label, fmt.pct(riser.c, 0)) });
			}
		}
		var mobile = (devices.rows || []).filter(function (r) { return r.key === 'mobile'; })[0];
		if (mobile) {
			out.push({ icon: 'mobile', tone: 'neutral', text: sprintf(__('%s of visitors use a mobile phone, so make sure your main pages work well on small screens.', 'pulse-analytics'), fmt.pct(mobile.visitors / cur.visitors * 100, 0)) });
		}
		if (series && series.labels && series.labels.length > 2) {
			var best = 0;
			series.current.visitors.forEach(function (v, i) { if (v > series.current.visitors[best]) { best = i; } });
			if (series.current.visitors[best] > 0) {
				out.push({ icon: 'calendar', tone: 'neutral', text: sprintf(__('Busiest day: %1$s with %2$s visitors.', 'pulse-analytics'), App.dates.long(series.labels[best]), fmt.num(series.current.visitors[best])) });
			}
		}
		if (prev && prev.bounce_rate && cur.bounce_rate !== null) {
			var diff = cur.bounce_rate - prev.bounce_rate;
			if (Math.abs(diff) >= 3) {
				out.push({ icon: 'leave', tone: diff < 0 ? 'good' : 'bad', text: diff < 0 ? sprintf(__('Bounce rate improved by %s points.', 'pulse-analytics'), fmt.dec(Math.abs(diff))) : sprintf(__('Bounce rate worsened by %s points.', 'pulse-analytics'), fmt.dec(diff)) });
			}
		}
		return out;
	}

	function stackedCard(parent, dimension, title) {
		var card = App.card(parent, { title: title, icon: 'chart', subtitle: __('Visits per period', 'pulse-analytics') });
		var legend = h('div', { 'class': 'pa-legend' });
		var chartEl = h('div', { 'class': 'pa-trend-chart' });
		card.body.appendChild(legend);
		card.body.appendChild(chartEl);
		chartEl.appendChild(App.skeleton(1));
		var chart = null;
		var component = {
			load: function () {
				App.loading(card.el, true);
				return App.widget({ type: 'stacked', dimension: dimension, interval: state.interval, top: 6 }).then(function (data) {
					App.loading(card.el, false);
					clear(legend);
					if (!data.series.length) {
						clear(chartEl).appendChild(App.empty());
						chart = null;
						return;
					}
					var series = data.series.map(function (s, i) {
						var color = dimension === 'channel' ? App.channelColor(s.key) : (dimension === 'device' ? App.palette.devices[s.key] || SERIES[i] : SERIES[i]);
						legend.appendChild(h('button', { type: 'button', 'class': 'pa-legend-item is-clickable', title: __('Filter by this value', 'pulse-analytics'), onclick: function () { App.addFilter(dimension, s.key); } }, [h('i', { 'class': 'pa-line-key', style: { background: color } }), s.label]));
						return { name: s.label, values: s.values, color: color };
					});
					var opts = {
						labels: data.labels,
						series: series,
						height: 260,
						yFormat: fmt.compact,
						valueFormat: fmt.num,
						xLabel: function (i) { return App.dates.bucket(data.labels[i], data.interval); },
						tooltipTitle: function (i) { return App.dates.bucket(data.labels[i], data.interval, true); }
					};
					if (chart) { chart.update(opts); } else { clear(chartEl); chart = window.PulseCharts.line(chartEl, opts); }
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(chartEl).appendChild(App.errorBox(err, component.load));
					chart = null;
				});
			}
		};
		return App.mount(component);
	}

	function grid(parent, cols, className) {
		var el = h('div', { 'class': 'pa-grid pa-grid-' + cols + (className ? ' ' + className : '') });
		parent.appendChild(el);
		return el;
	}

	function tabbedTable(parent, cfg) {
		var active = state.params.tab && cfg.tabs.some(function (t) { return t.key === state.params.tab; }) ? state.params.tab : cfg.tabs[0].key;
		var tabFor = function (key) { return cfg.tabs.filter(function (t) { return t.key === key; })[0]; };
		var first = tabFor(active);
		var table = App.dataTable(parent, Object.assign({}, cfg, first, {
			tabs: cfg.tabs,
			activeTab: active,
			onTab: function (key) {
				var tab = tabFor(key);
				state.params.tab = key;
				App.syncHash(false);
				table.setConfig({ dimension: tab.dimension, columns: tab.columns, filters: tab.filters, labelHeader: tab.labelHeader, empty: tab.empty, sortable: tab.sortable, filterDim: tab.filterDim, rowAction: tab.rowAction });
			}
		}));
		return table;
	}

	/* =================================================================== *
	 * Notices: welcome & sample data
	 * =================================================================== */

	function welcome(parent) {
		var excludedAdmin = (C.excludedRoles || []).indexOf('administrator') > -1;
		var geoOk = C.geo.exists || C.geo.cfHeader || C.geo.source === 'none';
		parent.appendChild(h('section', { 'class': 'pa-welcome' }, [
			h('div', { 'class': 'pa-welcome-icon' }, icon('activity')),
			h('div', { 'class': 'pa-welcome-body' }, [
				h('h3', { text: __('Pulse Analytics is installed. Statistics will show up here as soon as people visit your website.', 'pulse-analytics') }),
				h('ul', { 'class': 'pa-checklist' }, [
					h('li', { 'class': C.trackingOn ? 'is-ok' : 'is-warn' }, [icon(C.trackingOn ? 'check' : 'info'), C.trackingOn ? __('Tracking is active on every page of your website (cookieless by default).', 'pulse-analytics') : __('Tracking is paused in the settings.', 'pulse-analytics')]),
					excludedAdmin ? h('li', { 'class': 'is-info' }, [icon('info'), __('Your own visits as an administrator are not counted. To test, open your website in a private/incognito window.', 'pulse-analytics')]) : null,
					h('li', { 'class': geoOk ? 'is-ok' : 'is-warn' }, [icon(geoOk ? 'check' : 'info'), geoOk ? __('Visitor countries are detected for the world map.', 'pulse-analytics') : __('The free geolocation database is being downloaded in the background (needed for the world map).', 'pulse-analytics')])
				]),
				h('div', { 'class': 'pa-welcome-actions' }, [
					C.canManage ? h('a', { 'class': 'pa-btn pa-btn-primary', href: C.demoUrl }, [icon('sparkles'), __('Explore with sample data', 'pulse-analytics')]) : null,
					h('a', { 'class': 'pa-btn', href: C.homeUrl, target: '_blank', rel: 'noopener' }, [icon('external'), __('Visit your website', 'pulse-analytics')]),
					C.canManage ? h('a', { 'class': 'pa-btn pa-btn-ghost', href: C.settingsUrl }, [icon('settings'), __('Settings', 'pulse-analytics')]) : null
				])
			])
		]));
	}

	function demoBanner(parent) {
		if (!C.hasDemo) {
			return;
		}
		parent.appendChild(h('div', { 'class': 'pa-banner' }, [
			icon('sparkles'),
			h('span', { text: __('Sample data is included in these reports.', 'pulse-analytics') }),
			C.canManage ? h('a', { href: C.settingsUrl + '&tab=tools', text: __('Remove sample data', 'pulse-analytics') }) : null
		]));
	}

	/* =================================================================== *
	 * Views
	 * =================================================================== */

	VIEWS.overview = function (view) {
		if (!C.firstDate) {
			welcome(view);
			return;
		}
		demoBanner(view);
		trend(view, { metrics: ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'avg_duration'] });

		var g1 = grid(view, 2);
		App.listCard(g1, {
			id: 'ov-pages', title: __('Pages', 'pulse-analytics'), icon: 'file', view: 'pages',
			tabs: [
				{ key: 'page', label: __('Top pages', 'pulse-analytics'), dimension: 'page', metric: 'pageviews', secondary: 'visitors' },
				{ key: 'entry_page', label: __('Entry pages', 'pulse-analytics'), dimension: 'entry_page', metric: 'visits', columnLabel: App.DIMENSIONS.entry_page },
				{ key: 'exit_page', label: __('Exit pages', 'pulse-analytics'), dimension: 'exit_page', metric: 'exits', columnLabel: App.DIMENSIONS.exit_page }
			]
		});
		App.listCard(g1, {
			id: 'ov-sources', title: __('Traffic sources', 'pulse-analytics'), icon: 'megaphone', view: 'sources',
			tabs: [
				{ key: 'channel', label: __('Channels', 'pulse-analytics'), dimension: 'channel', metric: 'visitors' },
				{ key: 'source', label: __('Sources', 'pulse-analytics'), dimension: 'source', metric: 'visitors' },
				{ key: 'referrer', label: __('Referrers', 'pulse-analytics'), dimension: 'referrer', metric: 'visitors', empty: __('No referring websites yet.', 'pulse-analytics') },
				{ key: 'utm_campaign', label: __('Campaigns', 'pulse-analytics'), dimension: 'utm_campaign', metric: 'visitors', empty: __('No UTM-tagged campaign visits yet. Use the campaign URL builder on the Campaigns tab.', 'pulse-analytics') }
			]
		});

		mapCard(view);

		var g2 = grid(view, 2);
		App.donutCard(g2, { title: __('Devices', 'pulse-analytics'), icon: 'mobile', dimension: 'device', color: function (key, i) { return App.palette.devices[key] || SERIES[i]; } });
		App.listCard(g2, {
			id: 'ov-tech', title: __('Technology', 'pulse-analytics'), icon: 'desktop', view: 'technology',
			tabs: [
				{ key: 'browser', label: __('Browsers', 'pulse-analytics'), dimension: 'browser', metric: 'visitors' },
				{ key: 'os', label: __('Operating systems', 'pulse-analytics'), dimension: 'os', metric: 'visitors' },
				{ key: 'screen', label: __('Screen sizes', 'pulse-analytics'), dimension: 'screen', metric: 'visitors' },
				{ key: 'language', label: __('Languages', 'pulse-analytics'), dimension: 'language', metric: 'visitors' }
			]
		});

		var g3 = grid(view, 2);
		App.donutCard(g3, { title: __('New vs. returning visitors', 'pulse-analytics'), icon: 'users', dimension: 'visitor_type', metric: 'visits', color: function (key) { return key === 'new' ? SERIES[0] : SERIES[1]; } });
		App.listCard(g3, {
			id: 'ov-events', title: __('Events', 'pulse-analytics'), icon: 'pointer', view: 'events',
			tabs: [
				{ key: 'event_category', label: __('Event types', 'pulse-analytics'), dimension: 'event_category', metric: 'events', empty: __('No clicks, downloads or form submissions recorded yet.', 'pulse-analytics') },
				{ key: 'event_name', label: __('Top events', 'pulse-analytics'), dimension: 'event_name', metric: 'events' }
			]
		});

		var g4 = grid(view, 2);
		goalsCard(g4);
		insightsCard(g4);
		heatmapCard(view);
	};

	VIEWS.realtime = function (view) {
		var top = h('div', { 'class': 'pa-rt-top' });
		view.appendChild(top);
		var hero = h('div', { 'class': 'pa-card pa-rt-hero' });
		var chartCard = App.card(top, { title: __('Pageviews per minute', 'pulse-analytics'), icon: 'activity', subtitle: __('Last 30 minutes', 'pulse-analytics'), className: 'pa-rt-chart' });
		top.insertBefore(hero, chartCard.el);
		var chartEl = h('div');
		chartCard.body.appendChild(chartEl);
		var paused = false;
		var pauseBtn = h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm' });
		var bar = null;

		var g = grid(view, 3);
		var pagesCard = App.card(g, { title: __('Active pages', 'pulse-analytics'), icon: 'file' });
		var sourcesCard = App.card(g, { title: __('Sources', 'pulse-analytics'), icon: 'megaphone' });
		var countriesCard = App.card(g, { title: __('Countries', 'pulse-analytics'), icon: 'globe' });
		var feedCard = App.card(view, { title: __('Latest pageviews', 'pulse-analytics'), icon: 'eye', className: 'pa-feed-card' });

		function paintPause() {
			clear(pauseBtn);
			pauseBtn.appendChild(icon(paused ? 'play' : 'pause'));
			pauseBtn.appendChild(document.createTextNode(paused ? __('Resume', 'pulse-analytics') : __('Pause', 'pulse-analytics')));
		}
		pauseBtn.addEventListener('click', function () { paused = !paused; paintPause(); if (!paused) { component.load(); } });
		paintPause();

		function simpleRows(card, dim, rows) {
			clear(card.body);
			App.renderRows(card.body, { dimension: dim, metric: 'visitors', empty: __('Nobody right now.', 'pulse-analytics') }, { rows: rows, metrics: ['visitors'], totals: {} });
		}

		var component = {
			load: function () {
				return App.widget({ type: 'realtime' }).then(function (data) {
					clear(hero);
					hero.appendChild(h('div', { 'class': 'pa-rt-number' }, [h('span', { 'class': 'pa-live-dot' + (data.active ? ' is-on' : '') }), fmt.num(data.active)]));
					hero.appendChild(h('div', { 'class': 'pa-rt-label', text: _n('visitor on your site right now', 'visitors on your site right now', data.active, 'pulse-analytics') }));
					hero.appendChild(h('div', { 'class': 'pa-rt-stats' }, [
						h('div', {}, [h('strong', { text: fmt.num(data.active30) }), h('span', { text: __('visitors in the last 30 minutes', 'pulse-analytics') })]),
						h('div', {}, [h('strong', { text: fmt.num(data.pageviews30) }), h('span', { text: __('pageviews in the last 30 minutes', 'pulse-analytics') })])
					]));
					hero.appendChild(h('div', { 'class': 'pa-rt-foot' }, [h('span', { 'class': 'pa-note', text: sprintf(__('Updated %s', 'pulse-analytics'), App.dates.time(data.now)) }), pauseBtn]));

					var opts = {
						values: data.minutes.map(function (m) { return m.pageviews; }),
						height: 190,
						color: SERIES[0],
						highlightLast: true,
						yFormat: fmt.num,
						valueLabel: METRICS.pageviews.label,
						xLabel: function (i) { return i % 5 === 0 ? data.minutes[i].time : ''; },
						tooltipTitle: function (i) { return data.minutes[i].ago === 0 ? __('This minute', 'pulse-analytics') : sprintf(_n('%s minute ago', '%s minutes ago', data.minutes[i].ago, 'pulse-analytics'), data.minutes[i].ago) + ' (' + data.minutes[i].time + ')'; }
					};
					if (bar) { bar.update(opts); } else { bar = window.PulseCharts.bar(chartEl, opts); }

					simpleRows(pagesCard, 'page', data.pages);
					simpleRows(sourcesCard, 'source', data.sources);
					simpleRows(countriesCard, 'country', data.countries);

					clear(feedCard.body);
					if (!data.recent.length) {
						feedCard.body.appendChild(App.empty(__('No pageviews in the last 30 minutes.', 'pulse-analytics')));
					} else {
						var list = h('ul', { 'class': 'pa-feed' });
						data.recent.forEach(function (r) {
							list.appendChild(h('li', {}, [
								h('span', { 'class': 'pa-feed-time', text: App.dates.ago(r.ago) }),
								App.flag(r.country),
								h('span', { 'class': 'pa-feed-page' }, [h('strong', { text: r.title || App.decodePath(r.path) }), h('small', { text: App.decodePath(r.path) })]),
								h('span', { 'class': 'pa-feed-meta' }, [icon(['desktop', 'mobile', 'tablet', 'tv'].indexOf(r.device) > -1 ? r.device : 'desktop'), [r.city, App.countryName(r.country)].filter(Boolean).join(', '), ', ', r.browser, ', ', r.source || __('Direct', 'pulse-analytics')]),
								r.is_new ? h('span', { 'class': 'pa-badge', text: __('new', 'pulse-analytics') }) : null
							]));
						});
						feedCard.body.appendChild(list);
					}
				}).catch(function (err) {
					clear(hero).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		timers.push(setInterval(function () {
			if (!paused && document.visibilityState === 'visible') { component.load(); refreshLive(); }
		}, 10000));
		return App.mount(component);
	};

	var PAGE_TABS = [
		{ key: 'page', label: __('All pages', 'pulse-analytics'), dimension: 'page', columns: ['pageviews', 'visitors', 'avg_time', 'avg_scroll', 'entrances', 'bounce_rate', 'exits', 'exit_rate'], sortable: ['pageviews', 'visitors', 'avg_time', 'avg_scroll'] },
		{ key: 'entry_page', label: __('Entry pages', 'pulse-analytics'), dimension: 'entry_page', columns: ['visits', 'visitors', 'bounce_rate', 'avg_duration', 'views_per_visit'], sortable: ['visits', 'visitors', 'bounce_rate', 'avg_duration'] },
		{ key: 'exit_page', label: __('Exit pages', 'pulse-analytics'), dimension: 'exit_page', columns: ['exits', 'visitors', 'pageviews', 'exit_rate'], sortable: ['exits', 'visitors'] }
	];

	VIEWS.pages = function (view) {
		demoBanner(view);
		tabbedTable(view, { title: __('Pages', 'pulse-analytics'), icon: 'file', tabs: PAGE_TABS, pageSize: 25 });
		var g = grid(view, 2);
		App.listCard(g, {
			id: 'pg-content', title: __('Content types', 'pulse-analytics'), icon: 'layers', view: 'content',
			tabs: [{ key: 'post_type', label: __('Content types', 'pulse-analytics'), dimension: 'post_type', metric: 'pageviews' }]
		});
		scrollCard(g);
	};

	VIEWS.page = function (view) {
		var path = state.params.path || '/';
		var head = h('section', { 'class': 'pa-page-head' });
		view.appendChild(head);
		head.appendChild(h('a', { 'class': 'pa-back', href: App.hashFor('pages', {}) }, [icon('chevronLeft'), __('All pages', 'pulse-analytics')]));
		var titleEl = h('h3', { 'class': 'pa-page-title', text: App.decodePath(path) });
		var metaEl = h('div', { 'class': 'pa-page-meta' });
		head.appendChild(titleEl);
		head.appendChild(metaEl);
		App.mount({
			load: function () {
				return App.widget({ type: 'page_info', path: path }).then(function (info) {
					titleEl.textContent = info.title || App.decodePath(path);
					clear(metaEl);
					metaEl.appendChild(h('a', { href: App.pageUrl(path), target: '_blank', rel: 'noopener', 'class': 'pa-page-url' }, [App.decodePath(path), icon('external')]));
					if (info.published) { metaEl.appendChild(h('span', { text: sprintf(__('Published %s', 'pulse-analytics'), App.dates.long(info.published)) })); }
					if (info.author) { metaEl.appendChild(h('span', { text: sprintf(__('by %s', 'pulse-analytics'), info.author) })); }
					if (info.edit_url) { metaEl.appendChild(h('a', { href: info.edit_url, 'class': 'pa-btn pa-btn-sm' }, [icon('edit'), __('Edit', 'pulse-analytics')])); }
					metaEl.appendChild(h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', onclick: function () { App.addFilter('page', path); App.navigate('overview', {}); } }, [icon('filter'), __('Filter whole dashboard by this page', 'pulse-analytics')]));
				}).catch(function () {});
			}
		});

		trend(view, { metrics: ['pageviews', 'visitors', 'avg_duration', 'bounce_rate', 'entrances', 'exit_rate', 'avg_scroll'] });

		var g1 = grid(view, 2);
		App.listCard(g1, { id: 'pd-prev', title: __('Where visitors came from', 'pulse-analytics'), icon: 'enter', tabs: [{ key: 'prev_page', label: __('Previous page', 'pulse-analytics'), dimension: 'prev_page', metric: 'pageviews', columnLabel: __('Previous page', 'pulse-analytics') }] });
		App.listCard(g1, { id: 'pd-next', title: __('Where they went next', 'pulse-analytics'), icon: 'leave', tabs: [{ key: 'next_page', label: __('Next page', 'pulse-analytics'), dimension: 'next_page', metric: 'pageviews', columnLabel: __('Next page', 'pulse-analytics') }] });

		var g2 = grid(view, 2);
		App.listCard(g2, {
			id: 'pd-sources', title: __('Traffic sources', 'pulse-analytics'), icon: 'megaphone',
			tabs: [
				{ key: 'source', label: __('Sources', 'pulse-analytics'), dimension: 'source', metric: 'visitors' },
				{ key: 'channel', label: __('Channels', 'pulse-analytics'), dimension: 'channel', metric: 'visitors' },
				{ key: 'utm_campaign', label: __('Campaigns', 'pulse-analytics'), dimension: 'utm_campaign', metric: 'visitors' }
			]
		});
		App.listCard(g2, {
			id: 'pd-geo', title: __('Locations', 'pulse-analytics'), icon: 'globe',
			tabs: [
				{ key: 'country', label: __('Countries', 'pulse-analytics'), dimension: 'country', metric: 'visitors' },
				{ key: 'city', label: __('Cities', 'pulse-analytics'), dimension: 'city', metric: 'visitors' }
			]
		});

		var g3 = grid(view, 2);
		App.listCard(g3, {
			id: 'pd-tech', title: __('Devices', 'pulse-analytics'), icon: 'mobile',
			tabs: [
				{ key: 'device', label: __('Devices', 'pulse-analytics'), dimension: 'device', metric: 'visitors' },
				{ key: 'browser', label: __('Browsers', 'pulse-analytics'), dimension: 'browser', metric: 'visitors' },
				{ key: 'os', label: __('Operating systems', 'pulse-analytics'), dimension: 'os', metric: 'visitors' }
			]
		});
		scrollCard(g3);
		App.listCard(view, { id: 'pd-events', title: __('Events on this page', 'pulse-analytics'), icon: 'pointer', tabs: [{ key: 'event_name', label: __('Events', 'pulse-analytics'), dimension: 'event_name', metric: 'events', empty: __('No clicks, downloads or form submissions on this page yet.', 'pulse-analytics') }] });
	};

	VIEWS.sources = function (view) {
		demoBanner(view);
		stackedCard(view, 'channel', __('Channels over time', 'pulse-analytics'));
		var common = ['visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'views_per_visit'];
		tabbedTable(view, {
			title: __('Traffic sources', 'pulse-analytics'),
			icon: 'megaphone',
			tabs: [
				{ key: 'channel', label: __('Channels', 'pulse-analytics'), dimension: 'channel', columns: common },
				{ key: 'source', label: __('Sources', 'pulse-analytics'), dimension: 'source', columns: common },
				{ key: 'referrer', label: __('Referring sites', 'pulse-analytics'), dimension: 'referrer', columns: common, empty: __('No referring websites yet.', 'pulse-analytics') },
				{ key: 'referrer_url', label: __('Referrer URLs', 'pulse-analytics'), dimension: 'referrer_url', columns: common, empty: __('No referring websites yet.', 'pulse-analytics') }
			]
		});
		if ((C.goals || []).length && !App.hasFilter('goal')) {
			view.appendChild(h('p', { 'class': 'pa-tip-line' }, [icon('info'), __('Tip: add a goal filter (Add filter > Goal) to see conversions and conversion rates for each source.', 'pulse-analytics')]));
		}
	};

	VIEWS.campaigns = function (view) {
		demoBanner(view);
		var common = ['visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'views_per_visit'];
		var empty = __('No campaign visits yet. Add UTM parameters to the links you share. The builder below can create them for you.', 'pulse-analytics');
		tabbedTable(view, {
			title: __('UTM campaigns', 'pulse-analytics'),
			icon: 'target',
			tabs: [
				{ key: 'utm_campaign', label: __('Campaigns', 'pulse-analytics'), dimension: 'utm_campaign', columns: common, empty: empty },
				{ key: 'utm_source', label: __('Sources', 'pulse-analytics'), dimension: 'utm_source', columns: common, empty: empty },
				{ key: 'utm_medium', label: __('Mediums', 'pulse-analytics'), dimension: 'utm_medium', columns: common, empty: empty },
				{ key: 'utm_term', label: __('Terms', 'pulse-analytics'), dimension: 'utm_term', columns: common, empty: empty },
				{ key: 'utm_content', label: __('Content', 'pulse-analytics'), dimension: 'utm_content', columns: common, empty: empty }
			]
		});
		campaignBuilder(view);
	};

	function campaignBuilder(parent) {
		var card = App.card(parent, { title: __('Campaign URL builder', 'pulse-analytics'), icon: 'link', subtitle: __('Create trackable links for ads, emails and social posts', 'pulse-analytics') });
		var fields = {};
		var output = h('input', { type: 'text', 'class': 'pa-input pa-builder-output', readonly: true, 'aria-label': __('Generated URL', 'pulse-analytics') });
		var copyBtn = h('button', { type: 'button', 'class': 'pa-btn pa-btn-primary' }, [icon('copy'), __('Copy', 'pulse-analytics')]);
		var defs = [
			['url', __('Website URL', 'pulse-analytics'), C.homeUrl, true],
			['utm_source', __('Campaign source', 'pulse-analytics'), 'facebook, google, newsletter', true],
			['utm_medium', __('Campaign medium', 'pulse-analytics'), 'cpc, email, social', true],
			['utm_campaign', __('Campaign name', 'pulse-analytics'), 'spring_sale', true],
			['utm_term', __('Campaign term', 'pulse-analytics'), __('paid keywords (optional)', 'pulse-analytics'), false],
			['utm_content', __('Campaign content', 'pulse-analytics'), __('ad variant (optional)', 'pulse-analytics'), false]
		];
		var formGrid = h('div', { 'class': 'pa-builder' });
		defs.forEach(function (d) {
			var input = h('input', { type: 'text', 'class': 'pa-input', placeholder: d[2], value: d[0] === 'url' ? C.homeUrl : '' });
			input.addEventListener('input', update);
			fields[d[0]] = input;
			formGrid.appendChild(h('label', { 'class': 'pa-field' + (d[0] === 'url' ? ' is-wide' : '') }, [h('span', {}, [d[1], d[3] ? h('em', { text: ' *' }) : null]), input]));
		});
		var presets = [
			[__('Facebook ad', 'pulse-analytics'), { utm_source: 'facebook', utm_medium: 'paid_social' }],
			[__('Instagram bio', 'pulse-analytics'), { utm_source: 'instagram', utm_medium: 'social', utm_campaign: 'bio_link' }],
			[__('Email newsletter', 'pulse-analytics'), { utm_source: 'newsletter', utm_medium: 'email' }],
			[__('Google Ads', 'pulse-analytics'), { utm_source: 'google', utm_medium: 'cpc' }],
			[__('WhatsApp share', 'pulse-analytics'), { utm_source: 'whatsapp', utm_medium: 'social' }],
			[__('QR code / print', 'pulse-analytics'), { utm_source: 'qr_code', utm_medium: 'offline' }]
		];
		var presetRow = h('div', { 'class': 'pa-presets' }, [h('span', { 'class': 'pa-note', text: __('Quick fill:', 'pulse-analytics') })]);
		presets.forEach(function (p) {
			presetRow.appendChild(h('button', { type: 'button', 'class': 'pa-chip-btn', text: p[0], onclick: function () {
				Object.keys(p[1]).forEach(function (k) { fields[k].value = p[1][k]; });
				update();
			} }));
		});
		function update() {
			var base = fields.url.value.trim() || C.homeUrl;
			var url;
			try { url = new URL(base); } catch (e) { output.value = __('Enter a valid website URL', 'pulse-analytics'); return; }
			['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) {
				var v = fields[k].value.trim().toLowerCase().replace(/\s+/g, '_');
				if (v) { url.searchParams.set(k, v); } else { url.searchParams.delete(k); }
			});
			output.value = url.toString();
		}
		copyBtn.addEventListener('click', function () {
			output.select();
			var done = function () {
				clear(copyBtn);
				copyBtn.appendChild(icon('check'));
				copyBtn.appendChild(document.createTextNode(__('Copied', 'pulse-analytics')));
				setTimeout(function () { clear(copyBtn); copyBtn.appendChild(icon('copy')); copyBtn.appendChild(document.createTextNode(__('Copy', 'pulse-analytics'))); }, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(output.value).then(done).catch(function () { document.execCommand('copy'); done(); });
			} else {
				document.execCommand('copy');
				done();
			}
		});
		card.body.appendChild(presetRow);
		card.body.appendChild(formGrid);
		card.body.appendChild(h('div', { 'class': 'pa-builder-result' }, [output, copyBtn]));
		update();
	}

	VIEWS.locations = function (view) {
		demoBanner(view);
		mapCard(view, { large: true });
		var common = ['visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'views_per_visit'];
		tabbedTable(view, {
			title: __('Locations', 'pulse-analytics'),
			icon: 'pin',
			tabs: [
				{ key: 'country', label: __('Countries', 'pulse-analytics'), dimension: 'country', columns: common },
				{ key: 'region', label: __('Regions', 'pulse-analytics'), dimension: 'region', columns: common, empty: __('Region data needs the DB-IP City database or Cloudflare location headers.', 'pulse-analytics') },
				{ key: 'city', label: __('Cities', 'pulse-analytics'), dimension: 'city', columns: common, empty: __('City data needs the DB-IP City database or Cloudflare location headers.', 'pulse-analytics') },
				{ key: 'continent', label: __('Continents', 'pulse-analytics'), dimension: 'continent', columns: common },
				{ key: 'language', label: __('Languages', 'pulse-analytics'), dimension: 'language', columns: common }
			]
		});
	};

	VIEWS.technology = function (view) {
		demoBanner(view);
		var g = grid(view, 3);
		App.donutCard(g, { title: __('Devices', 'pulse-analytics'), icon: 'mobile', dimension: 'device', color: function (key, i) { return App.palette.devices[key] || SERIES[i]; } });
		App.donutCard(g, { title: __('Browsers', 'pulse-analytics'), icon: 'globe', dimension: 'browser' });
		App.donutCard(g, { title: __('Operating systems', 'pulse-analytics'), icon: 'desktop', dimension: 'os' });
		var common = ['visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'views_per_visit'];
		tabbedTable(view, {
			title: __('Technology', 'pulse-analytics'),
			icon: 'desktop',
			tabs: [
				{ key: 'browser', label: __('Browsers', 'pulse-analytics'), dimension: 'browser', columns: common },
				{ key: 'browser_version', label: __('Browser versions', 'pulse-analytics'), dimension: 'browser_version', columns: common },
				{ key: 'os', label: __('Operating systems', 'pulse-analytics'), dimension: 'os', columns: common },
				{ key: 'os_version', label: __('OS versions', 'pulse-analytics'), dimension: 'os_version', columns: common },
				{ key: 'screen', label: __('Screen sizes', 'pulse-analytics'), dimension: 'screen', columns: common },
				{ key: 'device', label: __('Device types', 'pulse-analytics'), dimension: 'device', columns: common }
			]
		});
	};

	VIEWS.events = function (view) {
		demoBanner(view);
		var tiles = h('div', { 'class': 'pa-event-tiles' });
		view.appendChild(tiles);
		App.mount({
			load: function () {
				return App.widget({ type: 'breakdown', dimension: 'event_category', limit: 10 }).then(function (data) {
					clear(tiles);
					var counts = {};
					data.rows.forEach(function (r) { counts[r.key] = r; });
					var icons = { outbound: 'external', download: 'download', form: 'edit', email: 'mail', phone: 'phone', search: 'search', '404': 'info', custom: 'pointer' };
					EVENT_ORDER.forEach(function (key) {
						var row = counts[key];
						var active = App.filterValue('event_category') === key;
						tiles.appendChild(h('button', { type: 'button', 'class': 'pa-event-tile' + (active ? ' is-active' : ''), onclick: function () { App.addFilter('event_category', key); } }, [
							icon(icons[key] || 'pointer'),
							h('span', { 'class': 'pa-event-tile-label', text: C.eventCats[key] }),
							h('strong', { text: fmt.num(row ? row.events : 0) }),
							h('small', { text: row ? sprintf(_n('%s visitor', '%s visitors', row.visitors, 'pulse-analytics'), fmt.num(row.visitors)) : '' })
						]));
					});
				}).catch(function (err) { clear(tiles).appendChild(App.errorBox(err)); });
			}
		});
		var cat = function (c) { return [{ dim: 'event_category', op: 'is', value: c }]; };
		var cols = ['events', 'visitors', 'visits'];
		tabbedTable(view, {
			title: __('Events', 'pulse-analytics'),
			icon: 'pointer',
			tabs: [
				{ key: 'all', label: __('All events', 'pulse-analytics'), dimension: 'event_name', columns: cols, labelHeader: __('Event', 'pulse-analytics'), empty: __('No events recorded yet.', 'pulse-analytics') },
				{ key: 'custom', label: __('Custom', 'pulse-analytics'), dimension: 'event_name', filters: cat('custom'), columns: cols, labelHeader: __('Event', 'pulse-analytics'), empty: __('Add data-pa-event="Name" to any button or link to track clicks.', 'pulse-analytics') },
				{ key: 'outbound', label: __('Outbound links', 'pulse-analytics'), dimension: 'event_name', filters: cat('outbound'), columns: cols, labelHeader: __('Destination', 'pulse-analytics') },
				{ key: 'download', label: __('Downloads', 'pulse-analytics'), dimension: 'event_name', filters: cat('download'), columns: cols, labelHeader: __('File', 'pulse-analytics') },
				{ key: 'form', label: __('Forms', 'pulse-analytics'), dimension: 'event_name', filters: cat('form'), columns: cols, labelHeader: __('Form', 'pulse-analytics') },
				{ key: 'contact', label: __('Email & phone', 'pulse-analytics'), dimension: 'event_name', filters: [{ dim: 'event_category', op: 'is', value: ['email', 'phone'] }], columns: cols, labelHeader: __('Contact link', 'pulse-analytics') },
				{ key: 'search', label: __('Site search', 'pulse-analytics'), dimension: 'event_name', filters: cat('search'), columns: cols, labelHeader: __('Search term', 'pulse-analytics'), empty: __('No internal searches yet.', 'pulse-analytics') },
				{ key: '404', label: __('404 errors', 'pulse-analytics'), dimension: 'event_name', filters: cat('404'), columns: cols, labelHeader: __('Missing URL', 'pulse-analytics'), empty: __('No broken links found.', 'pulse-analytics') },
				{ key: 'details', label: __('Details', 'pulse-analytics'), dimension: 'event_label', columns: cols, labelHeader: __('Detail (URL, page, label)', 'pulse-analytics') }
			]
		});
		scrollCard(view);
	};

	VIEWS.goals = function (view) {
		var card = App.card(view, { title: __('Goals & conversions', 'pulse-analytics'), icon: 'target', className: 'pa-goals-view', actions: [App.csvButton(function () { return App.exportUrl('goals'); })] });
		var formHost = h('div');
		var editing = null;

		function goalRule(g) {
			var matches = { equals: __('is', 'pulse-analytics'), contains: __('contains', 'pulse-analytics'), starts: __('starts with', 'pulse-analytics'), ends: __('ends with', 'pulse-analytics'), regex: __('matches regex', 'pulse-analytics') };
			if (g.type === 'event') {
				return sprintf(__('Event %1$s "%2$s"', 'pulse-analytics'), matches[g.match] || g.match, g.value) + (g.category ? ' (' + (C.eventCats[g.category] || g.category) + ')' : '');
			}
			return sprintf(__('Page %1$s "%2$s"', 'pulse-analytics'), matches[g.match] || g.match, g.value);
		}

		function renderForm(goal) {
			clear(formHost);
			if (!C.canManage) { return; }
			editing = goal || null;
			var g = goal || { name: '', type: 'event', match: 'contains', value: '', category: 'form' };
			var name = h('input', { type: 'text', 'class': 'pa-input', value: g.name, placeholder: __('e.g. Contact form sent', 'pulse-analytics'), required: true });
			var type = h('select', { 'class': 'pa-select' }, [h('option', { value: 'event', text: __('Event (form, click, download, etc.)', 'pulse-analytics') }), h('option', { value: 'page', text: __('Page visit (e.g. thank-you page)', 'pulse-analytics') })]);
			type.value = g.type;
			var category = h('select', { 'class': 'pa-select' }, [h('option', { value: '', text: __('Any event type', 'pulse-analytics') })].concat(EVENT_ORDER.map(function (k) { return h('option', { value: k, text: C.eventCats[k] || k }); })));
			category.value = g.category || '';
			var match = h('select', { 'class': 'pa-select' }, [['equals', __('is exactly', 'pulse-analytics')], ['contains', __('contains', 'pulse-analytics')], ['starts', __('starts with', 'pulse-analytics')], ['ends', __('ends with', 'pulse-analytics')], ['regex', __('matches regex', 'pulse-analytics')]].map(function (m) { return h('option', { value: m[0], text: m[1] }); }));
			match.value = g.match;
			var value = h('input', { type: 'text', 'class': 'pa-input', value: g.value, required: true });
			var categoryField = h('label', { 'class': 'pa-field' }, [h('span', { text: __('Event type', 'pulse-analytics') }), category]);
			var help = h('p', { 'class': 'pa-note' });
			var errorEl = h('p', { 'class': 'pa-form-error', hidden: true });
			function sync() {
				var isEvent = type.value === 'event';
				categoryField.hidden = !isEvent;
				value.placeholder = isEvent ? __('e.g. contact (form name) or CTA Click', 'pulse-analytics') : __('e.g. /thank-you/', 'pulse-analytics');
				help.textContent = isEvent ? __('Matches the event name: form id/name, clicked link host, file name, phone number or your custom event name.', 'pulse-analytics') : __('Matches the page path (the part after your domain). You can paste a full URL.', 'pulse-analytics');
			}
			type.addEventListener('change', sync);
			sync();
			var form = h('form', { 'class': 'pa-goal-form', onsubmit: function (e) {
				e.preventDefault();
				errorEl.hidden = true;
				App.api('POST', 'goals', { id: editing ? editing.id : '', name: name.value, type: type.value, category: category.value, match: match.value, value: value.value }).then(function (res) {
					C.goals = res.goals;
					renderForm(null);
					component.load();
				}).catch(function (err) {
					errorEl.textContent = err.message;
					errorEl.hidden = false;
				});
			} }, [
				h('h4', { text: editing ? __('Edit goal', 'pulse-analytics') : __('Add a goal', 'pulse-analytics') }),
				h('div', { 'class': 'pa-goal-grid' }, [
					h('label', { 'class': 'pa-field' }, [h('span', { text: __('Name', 'pulse-analytics') }), name]),
					h('label', { 'class': 'pa-field' }, [h('span', { text: __('Goal type', 'pulse-analytics') }), type]),
					categoryField,
					h('label', { 'class': 'pa-field' }, [h('span', { text: __('Match', 'pulse-analytics') }), match]),
					h('label', { 'class': 'pa-field is-wide' }, [h('span', { text: __('Value', 'pulse-analytics') }), value])
				]),
				help,
				errorEl,
				h('div', { 'class': 'pa-form-actions' }, [
					h('button', { type: 'submit', 'class': 'pa-btn pa-btn-primary', text: editing ? __('Save goal', 'pulse-analytics') : __('Add goal', 'pulse-analytics') }),
					editing ? h('button', { type: 'button', 'class': 'pa-btn', text: __('Cancel', 'pulse-analytics'), onclick: function () { renderForm(null); } }) : null
				])
			]);
			formHost.appendChild(form);
		}

		var listHost = h('div');
		card.body.appendChild(listHost);
		card.body.appendChild(formHost);

		var component = {
			load: function () {
				if (!(C.goals || []).length) {
					clear(listHost).appendChild(h('div', { 'class': 'pa-empty pa-empty-cta' }, [icon('target'), h('p', { text: __('No goals yet. A goal counts a conversion, for example a contact form submission, a click on your phone number or a visit to a thank-you page. Once you add one, you can see which channels and campaigns bring in customers.', 'pulse-analytics') })]));
					renderForm(editing);
					return Promise.resolve();
				}
				App.loading(card.el, true);
				return App.widget({ type: 'goals' }).then(function (data) {
					App.loading(card.el, false);
					clear(listHost);
					var tbody = h('tbody');
					data.goals.forEach(function (g) {
						tbody.appendChild(h('tr', {}, [
							h('td', { 'class': 'pa-td-label' }, [h('strong', { text: g.name }), h('div', { 'class': 'pa-row-sub', text: goalRule(g) })]),
							h('td', { 'class': 'pa-td-num' }, [fmt.num(g.conversions), g.prev_conversions !== undefined ? App.deltaBadge('conversions', g.conversions, g.prev_conversions) : null]),
							h('td', { 'class': 'pa-td-num', text: fmt.num(g.completions) }),
							h('td', { 'class': 'pa-td-num' }, [fmt.pct(g.cr, 2), g.prev_cr !== undefined ? App.deltaBadge('cr', g.cr, g.prev_cr) : null]),
							h('td', { 'class': 'pa-td-actions' }, [
								h('button', { type: 'button', 'class': 'pa-btn pa-btn-sm', title: __('See which sources convert', 'pulse-analytics'), onclick: function () { state.filters = state.filters.filter(function (f) { return f.dim !== 'goal'; }); state.filters.push({ dim: 'goal', op: 'is', value: g.id }); App.navigate('sources', {}); } }, [icon('filter'), __('Analyze', 'pulse-analytics')]),
								C.canManage ? h('button', { type: 'button', 'class': 'pa-icon-btn', title: __('Edit', 'pulse-analytics'), 'aria-label': __('Edit', 'pulse-analytics'), onclick: function () { renderForm(g); formHost.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }, [icon('edit')]) : null,
								C.canManage ? h('button', { type: 'button', 'class': 'pa-icon-btn is-danger', title: __('Delete', 'pulse-analytics'), 'aria-label': __('Delete', 'pulse-analytics'), onclick: function () {
									if (!window.confirm(sprintf(__('Delete the goal "%s"? Your statistics are not affected.', 'pulse-analytics'), g.name))) { return; }
									App.api('DELETE', 'goals/' + encodeURIComponent(g.id)).then(function (res) { C.goals = res.goals; component.load(); });
								} }, [icon('trash')]) : null
							])
						]));
					});
					listHost.appendChild(h('div', { 'class': 'pa-table-wrap' }, h('table', { 'class': 'pa-table' }, [
						h('thead', {}, h('tr', {}, [
							h('th', { text: __('Goal', 'pulse-analytics') }),
							h('th', { 'class': 'pa-th-num', text: __('Conversions', 'pulse-analytics'), title: __('Unique visitors who completed the goal', 'pulse-analytics') }),
							h('th', { 'class': 'pa-th-num', text: __('Completions', 'pulse-analytics'), title: __('Total number of times the goal was completed', 'pulse-analytics') }),
							h('th', { 'class': 'pa-th-num', text: __('Conversion rate', 'pulse-analytics') }),
							h('th', { 'class': 'pa-th-num', text: '' })
						])),
						tbody
					])));
					listHost.appendChild(h('p', { 'class': 'pa-note', text: sprintf(__('Conversion rate is the share of all %s visitors in this period who completed the goal.', 'pulse-analytics'), fmt.num(data.total_visitors)) }));
					renderForm(editing);
				}).catch(function (err) {
					App.loading(card.el, false);
					clear(listHost).appendChild(App.errorBox(err, component.load));
				});
			}
		};
		App.mount(component);
	};

	VIEWS.visitors = function (view) {
		demoBanner(view);
		var card = App.card(view, { title: __('Visitor log', 'pulse-analytics'), icon: 'users', subtitle: __('Every visit with its journey through your site', 'pulse-analytics'), className: 'pa-log-card', actions: [App.csvButton(function () { return App.exportUrl('sessions'); })] });
		var list = h('div', { 'class': 'pa-log' });
		var more = h('button', { type: 'button', 'class': 'pa-btn', text: __('Load more', 'pulse-analytics'), hidden: true });
		card.body.appendChild(list);
		card.footer.appendChild(more);
		var offset = 0;

		function deviceIcon(d) {
			return icon(['desktop', 'mobile', 'tablet', 'tv'].indexOf(d) > -1 ? d : 'desktop');
		}

		function sessionRow(s) {
			var detail = h('div', { 'class': 'pa-log-detail', hidden: true });
			var place = [s.city, s.region, s.country_name].filter(Boolean).join(', ') || __('Unknown location', 'pulse-analytics');
			var campaign = s.utm_campaign ? h('small', { text: s.utm_campaign + (s.utm_medium ? ' / ' + s.utm_medium : '') }) : (s.referrer_url ? h('small', { text: s.referrer_url }) : null);
			var head = h('button', { type: 'button', 'class': 'pa-log-head', 'aria-expanded': 'false' }, [
				h('span', { 'class': 'pa-log-time' }, [h('strong', { text: App.dates.time(s.created_at) }), h('small', { text: App.dates.short(s.created_at) })]),
				h('span', { 'class': 'pa-log-who' }, [
					App.flag(s.country),
					h('span', {}, [h('strong', { text: place }), h('small', {}, [deviceIcon(s.device), ' ', [s.browser, s.os].filter(Boolean).join(', ')])]),
					s.user ? h('span', { 'class': 'pa-badge is-user', text: s.user }) : (s.is_new ? h('span', { 'class': 'pa-badge', text: __('new', 'pulse-analytics') }) : h('span', { 'class': 'pa-badge is-muted', text: __('returning', 'pulse-analytics') }))
				]),
				h('span', { 'class': 'pa-log-source' }, [s.source ? App.favicon(s.source) : icon('cursor', 'pa-fav-icon'), h('span', {}, [h('strong', { text: s.source || __('Direct / None', 'pulse-analytics') }), campaign || h('small', { text: s.channel_label })])]),
				h('span', { 'class': 'pa-log-page' }, [h('strong', { text: s.entry_title || App.decodePath(s.entry_path) }), h('small', { text: App.decodePath(s.entry_path) })]),
				h('span', { 'class': 'pa-log-stats' }, [
					h('span', { title: METRICS.pageviews.label }, [icon('eye'), fmt.num(s.pageviews)]),
					h('span', { title: METRICS.avg_duration.label }, [icon('clock'), fmt.dur(s.duration)]),
					s.events ? h('span', { title: METRICS.events.label }, [icon('pointer'), fmt.num(s.events)]) : null
				]),
				icon('chevronDown', 'pa-log-chevron')
			]);
			head.addEventListener('click', function () {
				var open = detail.hidden;
				detail.hidden = !open;
				head.setAttribute('aria-expanded', open ? 'true' : 'false');
				head.parentNode.classList.toggle('is-open', open);
				if (open && !detail.dataset.loaded) {
					detail.appendChild(App.skeleton(3));
					App.widget({ type: 'session', id: s.id }).then(function (data) {
						detail.dataset.loaded = '1';
						clear(detail);
						var meta = h('div', { 'class': 'pa-log-meta' }, [
							s.screen ? h('span', {}, [icon('desktop'), s.screen]) : null,
							s.language ? h('span', {}, [icon('globe'), App.languageName(s.language)]) : null,
							s.referrer_url ? h('span', {}, [icon('link'), s.referrer_url]) : null,
							s.utm_campaign ? h('span', {}, [icon('target'), [s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content].filter(Boolean).join(' / ')]) : null,
							s.ip ? h('span', {}, [icon('pin'), s.ip]) : null
						]);
						var timeline = h('ol', { 'class': 'pa-timeline' });
						data.timeline.forEach(function (item) {
							if (item.type === 'pageview') {
								timeline.appendChild(h('li', { 'class': 'is-pageview' }, [
									h('span', { 'class': 'pa-tl-time', text: App.dates.time(item.time) }),
									h('span', { 'class': 'pa-tl-icon' }, icon('file')),
									h('span', { 'class': 'pa-tl-body' }, [
										h('a', { href: App.hashFor('page', { path: item.path }), text: item.title || App.decodePath(item.path) }),
										h('small', { text: App.decodePath(item.path) + ', ' + sprintf(__('%1$s on page, scrolled %2$s', 'pulse-analytics'), fmt.dur(item.duration), fmt.pct(item.scroll, 0)) })
									])
								]));
							} else {
								timeline.appendChild(h('li', { 'class': 'is-event' }, [
									h('span', { 'class': 'pa-tl-time', text: App.dates.time(item.time) }),
									h('span', { 'class': 'pa-tl-icon' }, icon('pointer')),
									h('span', { 'class': 'pa-tl-body' }, [h('strong', { text: item.category_label + ': ' + item.name }), item.label ? h('small', { text: item.label }) : null])
								]));
							}
						});
						detail.appendChild(meta);
						detail.appendChild(timeline);
					}).catch(function (err) { clear(detail).appendChild(App.errorBox(err)); });
				}
			});
			return h('div', { 'class': 'pa-log-item' }, [head, detail]);
		}

		function loadPage(reset) {
			if (reset) {
				offset = 0;
			}
			App.loading(card.el, true);
			return App.widget({ type: 'sessions', limit: 30, offset: offset }).then(function (data) {
				App.loading(card.el, false);
				if (reset) {
					clear(list);
				}
				if (!data.rows.length && offset === 0) {
					list.appendChild(App.empty(__('No visits in this period.', 'pulse-analytics')));
				}
				data.rows.forEach(function (s) { list.appendChild(sessionRow(s)); });
				offset += data.rows.length;
				more.hidden = !data.has_more;
			}).catch(function (err) {
				App.loading(card.el, false);
				clear(list).appendChild(App.errorBox(err, function () { loadPage(true); }));
			});
		}
		more.addEventListener('click', function () { loadPage(false); });
		list.appendChild(App.skeleton(8));
		App.mount({ load: function () { return loadPage(true); } });
	};

	VIEWS.content = function (view) {
		demoBanner(view);
		var cols = ['pageviews', 'visitors', 'avg_time'];
		tabbedTable(view, {
			title: __('Content performance', 'pulse-analytics'),
			icon: 'layers',
			tabs: [
				{ key: 'post_type', label: __('Content types', 'pulse-analytics'), dimension: 'post_type', columns: cols },
				{ key: 'author', label: __('Authors', 'pulse-analytics'), dimension: 'author', columns: cols, filterDim: null },
				{ key: 'category', label: __('Categories', 'pulse-analytics'), dimension: 'category', columns: cols, filterDim: null }
			]
		});
		tabbedTable(view, { title: __('Top content', 'pulse-analytics'), icon: 'file', tabs: [PAGE_TABS[0]], pageSize: 15 });
	};

	/* =================================================================== *
	 * Rendering & routing
	 * =================================================================== */

	function render() {
		closePopover();
		timers.forEach(function (t) { clearInterval(t); });
		timers = [];
		renderHeader();
		renderFilterBar();
		renderTabs();
		App.mounted = [];
		clear(viewEl);
		viewEl.className = 'pa-view pa-view-' + state.view;
		var renderView = VIEWS[state.view] || VIEWS.overview;
		if (!VIEWS[state.view]) {
			state.view = 'overview';
		}
		renderView(viewEl);
		App.mounted.forEach(function (c) { c.load(); });
		lastHash = window.location.hash;
	}

	App.refresh = function () {
		closePopover();
		renderHeader();
		renderFilterBar();
		lastHash = window.location.hash;
		App.mounted.forEach(function (c) { c.load(); });
	};

	App.onRoute = function () {
		var previousView = state.view;
		var previousPath = state.params.path;
		App.readHash();
		if (lastHash !== null && previousView === state.view && previousPath === state.params.path && App.mounted.length) {
			App.refresh();
			return;
		}
		render();
		window.scrollTo(0, 0);
	};

	function boot() {
		clear(root);
		headerEl = h('header', { 'class': 'pa-header' });
		filterBarEl = h('div', { 'class': 'pa-filterbar' });
		tabsEl = h('nav', { 'class': 'pa-tabs', 'aria-label': __('Reports', 'pulse-analytics') });
		viewEl = h('main', { 'class': 'pa-view' });
		var footer = h('footer', { 'class': 'pa-footer' }, [
			h('span', { text: 'Pulse Analytics ' + C.version }),
			h('span', {}, [__('IP geolocation by', 'pulse-analytics'), ' ', h('a', { href: 'https://db-ip.com', target: '_blank', rel: 'noopener', text: 'DB-IP' }), ' (CC BY 4.0)']),
			h('span', { text: __('Map data: Natural Earth', 'pulse-analytics') }),
			C.externalAssets ? h('span', { text: __('Flags: Twemoji (CC BY 4.0)', 'pulse-analytics') }) : null
		]);
		root.appendChild(headerEl);
		root.appendChild(filterBarEl);
		root.appendChild(tabsEl);
		root.appendChild(viewEl);
		root.appendChild(footer);

		window.addEventListener('hashchange', function () {
			if (window.location.hash !== lastHash) {
				App.onRoute();
			}
		});
		window.addEventListener('popstate', function () {
			if (window.location.hash !== lastHash) {
				App.onRoute();
			}
		});

		App.readHash();
		if (!window.location.hash) {
			window.history.replaceState(null, '', App.hashFor(state.view, state.params));
		}
		render();
		setInterval(function () { if (document.visibilityState === 'visible' && state.view !== 'realtime') { refreshLive(); } }, 60000);
	}

	boot();
})(window, document);
