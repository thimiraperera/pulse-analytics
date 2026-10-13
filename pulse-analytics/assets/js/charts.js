/*!
 * Pulse Analytics charts: small SVG line, bar, donut and heatmap charts.
 * Line/area charts with crosshair tooltips, bar charts, donuts, sparklines and heatmaps.
 */
(function (global) {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var GRID = '#e1e0d9';
	var AXIS = '#c3c2b7';
	var MUTED = '#898781';

	function svg(tag, attrs, parent) {
		var node = document.createElementNS(SVG_NS, tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		}
		if (parent) {
			parent.appendChild(node);
		}
		return node;
	}

	function html(tag, className, parent, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		if (parent) {
			parent.appendChild(node);
		}
		return node;
	}

	function r(v) {
		return Math.round(v * 10) / 10;
	}

	function niceScale(max, ticks) {
		ticks = ticks || 4;
		if (!isFinite(max) || max <= 0) {
			return { max: ticks, step: 1 };
		}
		var raw = max / ticks;
		var mag = Math.pow(10, Math.floor(Math.log10(raw)));
		var norm = raw / mag;
		var step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * mag;
		if (step < 1 && max >= ticks) {
			step = 1;
		}
		return { max: Math.ceil(max / step) * step, step: step };
	}

	// Monotone cubic interpolation (no overshoot), points sorted by x.
	function monotone(points) {
		var n = points.length;
		if (!n) {
			return '';
		}
		var d = 'M' + r(points[0][0]) + ',' + r(points[0][1]);
		if (n === 1) {
			return d;
		}
		if (n === 2) {
			return d + 'L' + r(points[1][0]) + ',' + r(points[1][1]);
		}
		var dx = [], m = [], t = [], i;
		for (i = 0; i < n - 1; i++) {
			dx[i] = points[i + 1][0] - points[i][0];
			m[i] = dx[i] ? (points[i + 1][1] - points[i][1]) / dx[i] : 0;
		}
		t[0] = m[0];
		t[n - 1] = m[n - 2];
		for (i = 1; i < n - 1; i++) {
			t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
		}
		for (i = 0; i < n - 1; i++) {
			if (m[i] === 0) {
				t[i] = 0;
				t[i + 1] = 0;
				continue;
			}
			var a = t[i] / m[i], b = t[i + 1] / m[i], s = a * a + b * b;
			if (s > 9) {
				var tau = 3 / Math.sqrt(s);
				t[i] = tau * a * m[i];
				t[i + 1] = tau * b * m[i];
			}
		}
		for (i = 0; i < n - 1; i++) {
			var h = dx[i] / 3;
			d += 'C' + r(points[i][0] + h) + ',' + r(points[i][1] + h * t[i]) + ',' +
				r(points[i + 1][0] - h) + ',' + r(points[i + 1][1] - h * t[i + 1]) + ',' +
				r(points[i + 1][0]) + ',' + r(points[i + 1][1]);
		}
		return d;
	}

	// Splits values into continuous segments (null breaks the line).
	function segments(values, xf, yf) {
		var out = [], current = [];
		values.forEach(function (v, i) {
			if (v === null || v === undefined || !isFinite(v)) {
				if (current.length) { out.push(current); }
				current = [];
			} else {
				current.push([xf(i), yf(v)]);
			}
		});
		if (current.length) {
			out.push(current);
		}
		return out;
	}

	function textWidth(text, size) {
		return String(text).length * (size || 11) * 0.6;
	}

	/* ------------------------------------------------------------ tooltip */

	function Tooltip(container) {
		this.node = html('div', 'pa-tip', container);
		this.node.setAttribute('role', 'status');
		this.node.style.display = 'none';
		this.container = container;
	}

	Tooltip.prototype.show = function (title, rows, x, y) {
		var node = this.node;
		node.textContent = '';
		if (title) {
			html('div', 'pa-tip-title', node, title);
		}
		rows.forEach(function (row) {
			var line = html('div', 'pa-tip-row', node);
			if (row.color) {
				var key = html('span', 'pa-tip-key' + (row.shape === 'box' ? ' is-box' : ''), line);
				key.style.background = row.color;
			}
			html('strong', 'pa-tip-value', line, row.value);
			if (row.label) {
				html('span', 'pa-tip-label', line, row.label);
			}
		});
		node.style.display = 'block';
		var cw = this.container.clientWidth;
		var tw = node.offsetWidth;
		var th = node.offsetHeight;
		var left = x + 14;
		if (left + tw > cw - 4) {
			left = x - tw - 14;
		}
		node.style.left = Math.max(4, left) + 'px';
		node.style.top = Math.max(0, y - th / 2) + 'px';
	};

	Tooltip.prototype.hide = function () {
		this.node.style.display = 'none';
	};

	function observe(container, callback) {
		var lastWidth = container.clientWidth;
		if (global.ResizeObserver) {
			var ro = new ResizeObserver(function () {
				var w = container.clientWidth;
				if (Math.abs(w - lastWidth) > 2) {
					lastWidth = w;
					callback();
				}
			});
			ro.observe(container);
			return function () { ro.disconnect(); };
		}
		var handler = function () { callback(); };
		global.addEventListener('resize', handler);
		return function () { global.removeEventListener('resize', handler); };
	}

	/* ---------------------------------------------------------- line chart */

	/**
	 * opts: {
	 *   labels: [...], series: [{ name, values, color, area, muted }],
	 *   height, yFormat(v), xLabel(i), tooltipTitle(i), seriesLabel(series, i), valueFormat(v), minMax
	 * }
	 */
	function LineChart(container, opts) {
		this.container = container;
		this.opts = opts;
		container.classList.add('pa-chart');
		this.tip = new Tooltip(container);
		var self = this;
		this.disconnect = observe(container, function () { self.render(); });
		this.render();
	}

	LineChart.prototype.update = function (opts) {
		this.opts = opts;
		this.render();
	};

	LineChart.prototype.destroy = function () {
		this.disconnect();
		this.container.textContent = '';
	};

	LineChart.prototype.render = function () {
		var self = this;
		var o = this.opts;
		var container = this.container;
		var width = Math.max(240, container.clientWidth);
		var height = o.height || 260;
		var yFormat = o.yFormat || String;
		var labels = o.labels || [];
		var n = labels.length;
		var series = (o.series || []).filter(function (s) { return s && s.values; });

		if (this.svg) {
			this.svg.remove();
		}
		this.tip.hide();

		var max = 0;
		series.forEach(function (s) {
			s.values.forEach(function (v) { if (v !== null && isFinite(v) && v > max) { max = v; } });
		});
		if (o.minMax && max < o.minMax) {
			max = o.minMax;
		}
		var scale = niceScale(max, 4);
		var ticks = [];
		for (var t = 0; t <= scale.max + scale.step / 2; t += scale.step) {
			ticks.push(t);
		}

		var left = Math.max.apply(null, ticks.map(function (v) { return textWidth(yFormat(v)); })) + 14;
		var margin = { top: 12, right: 14, bottom: 28, left: Math.max(28, left) };
		var plotW = width - margin.left - margin.right;
		var plotH = height - margin.top - margin.bottom;
		var xf = function (i) { return margin.left + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW); };
		var yf = function (v) { return margin.top + plotH - (v / scale.max) * plotH; };

		var root = svg('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': o.ariaLabel || '' });
		root.classList.add('pa-chart-svg');
		this.svg = root;

		// Grid and y labels.
		var grid = svg('g', { 'class': 'pa-grid' }, root);
		ticks.forEach(function (v) {
			var y = r(yf(v)) + 0.5;
			svg('line', { x1: margin.left, x2: width - margin.right, y1: y, y2: y, stroke: v === 0 ? AXIS : GRID, 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, grid);
			var label = svg('text', { x: margin.left - 8, y: y + 4, 'text-anchor': 'end', 'class': 'pa-axis-label' }, grid);
			label.textContent = yFormat(v);
		});

		// X labels.
		if (n) {
			var maxLabels = Math.max(2, Math.floor(plotW / 74));
			var step = Math.max(1, Math.ceil(n / maxLabels));
			for (var i = 0; i < n; i += step) {
				var tx = svg('text', { x: r(xf(i)), y: height - 8, 'text-anchor': n === 1 ? 'middle' : (i === 0 ? 'start' : 'middle'), 'class': 'pa-axis-label' }, grid);
				tx.textContent = o.xLabel ? o.xLabel(i) : labels[i];
			}
		}

		// Series (muted comparison first so the current period sits on top).
		var ordered = series.slice().sort(function (a, b) { return (b.muted ? 1 : 0) - (a.muted ? 1 : 0); });
		ordered.forEach(function (s) {
			var values = s.values.slice(0, n);
			var segs = segments(values, xf, yf);
			var lastValue = values[n - 1];
			segs.forEach(function (pts, si) {
				// The last bucket of a period that is still running (today, this week) is drawn dashed.
				var partial = s.partialLast && si === segs.length - 1 && pts.length > 1 && lastValue !== null && lastValue !== undefined && isFinite(lastValue);
				var solid = partial ? pts.slice(0, -1) : pts;
				var end = pts[pts.length - 1];
				if (s.area && pts.length > 1) {
					var base = r(yf(0));
					var top = solid.length > 1 ? monotone(solid) : 'M' + r(solid[0][0]) + ',' + r(solid[0][1]);
					if (partial) {
						top += 'L' + r(end[0]) + ',' + r(end[1]);
					}
					svg('path', { d: top + 'L' + r(end[0]) + ',' + base + 'L' + r(pts[0][0]) + ',' + base + 'Z', fill: s.color, 'fill-opacity': 0.1, stroke: 'none' }, root);
				}
				if (pts.length === 1) {
					svg('circle', { cx: r(pts[0][0]), cy: r(pts[0][1]), r: 3, fill: s.color }, root);
					return;
				}
				if (solid.length > 1) {
					svg('path', { d: monotone(solid), fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'class': s.muted ? 'pa-line-muted' : 'pa-line' }, root);
				}
				if (partial) {
					var from = solid[solid.length - 1];
					svg('line', { x1: r(from[0]), y1: r(from[1]), x2: r(end[0]), y2: r(end[1]), stroke: s.color, 'stroke-width': 2, 'stroke-dasharray': '4 4', 'stroke-linecap': 'round' }, root);
				}
			});
		});

		// Hover layer: crosshair + dots + tooltip.
		var hover = svg('g', { 'class': 'pa-hover', visibility: 'hidden' }, root);
		var cross = svg('line', { y1: margin.top, y2: margin.top + plotH, stroke: MUTED, 'stroke-width': 1 }, hover);
		var dots = series.map(function (s) {
			return svg('circle', { r: 4, fill: s.color, stroke: '#ffffff', 'stroke-width': 2 }, hover);
		});
		var overlay = svg('rect', { x: margin.left - 6, y: margin.top, width: plotW + 12, height: plotH, fill: 'transparent', tabindex: 0, 'class': 'pa-overlay' }, root);
		var active = -1;

		function showIndex(i) {
			if (i < 0 || i >= n) {
				return;
			}
			active = i;
			var x = xf(i);
			cross.setAttribute('x1', r(x));
			cross.setAttribute('x2', r(x));
			var rows = [];
			series.forEach(function (s, k) {
				var v = s.values[i];
				var dot = dots[k];
				if (v === null || v === undefined || !isFinite(v)) {
					dot.setAttribute('visibility', 'hidden');
				} else {
					dot.setAttribute('visibility', 'visible');
					dot.setAttribute('cx', r(x));
					dot.setAttribute('cy', r(yf(v)));
				}
				rows.push({
					color: s.color,
					value: v === null || v === undefined ? '-' : (o.valueFormat ? o.valueFormat(v) : yFormat(v)),
					label: o.seriesLabel ? o.seriesLabel(s, i) : s.name
				});
			});
			hover.setAttribute('visibility', 'visible');
			var y = series.length && series[0].values[i] !== null && isFinite(series[0].values[i]) ? yf(series[0].values[i]) : margin.top + plotH / 2;
			self.tip.show(o.tooltipTitle ? o.tooltipTitle(i) : labels[i], rows, x, y);
		}

		function hide() {
			active = -1;
			hover.setAttribute('visibility', 'hidden');
			self.tip.hide();
		}

		overlay.addEventListener('pointermove', function (e) {
			var rect = root.getBoundingClientRect();
			var px = e.clientX - rect.left;
			var i = n <= 1 ? 0 : Math.round(((px - margin.left) / plotW) * (n - 1));
			showIndex(Math.max(0, Math.min(n - 1, i)));
		});
		overlay.addEventListener('pointerleave', hide);
		overlay.addEventListener('blur', hide);
		overlay.addEventListener('focus', function () { showIndex(active < 0 ? n - 1 : active); });
		overlay.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
				e.preventDefault();
				showIndex(Math.max(0, Math.min(n - 1, (active < 0 ? n - 1 : active) + (e.key === 'ArrowLeft' ? -1 : 1))));
			}
		});
		if (o.onClick) {
			overlay.style.cursor = 'pointer';
			overlay.addEventListener('click', function () { if (active >= 0) { o.onClick(active); } });
		}

		container.insertBefore(root, this.tip.node);
	};

	/* ----------------------------------------------------------- bar chart */

	/**
	 * opts: { labels, values, color, height, yFormat, tooltipTitle(i), valueLabel, highlightLast }
	 */
	function BarChart(container, opts) {
		this.container = container;
		this.opts = opts;
		container.classList.add('pa-chart');
		this.tip = new Tooltip(container);
		var self = this;
		this.disconnect = observe(container, function () { self.render(); });
		this.render();
	}

	BarChart.prototype.update = LineChart.prototype.update;
	BarChart.prototype.destroy = LineChart.prototype.destroy;

	BarChart.prototype.render = function () {
		var self = this;
		var o = this.opts;
		var width = Math.max(200, this.container.clientWidth);
		var height = o.height || 160;
		var values = o.values || [];
		var n = values.length;
		var yFormat = o.yFormat || String;
		if (this.svg) {
			this.svg.remove();
		}
		this.tip.hide();

		var max = Math.max.apply(null, values.concat([0]));
		var scale = niceScale(max, 3);
		var margin = { top: 10, right: 8, bottom: o.xLabel ? 22 : 8, left: Math.max(24, textWidth(yFormat(scale.max)) + 12) };
		var plotW = width - margin.left - margin.right;
		var plotH = height - margin.top - margin.bottom;
		var band = n ? plotW / n : plotW;
		var barW = Math.max(2, Math.min(24, band - 2));

		var root = svg('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': o.ariaLabel || '' });
		this.svg = root;
		for (var t = 0; t <= scale.max + scale.step / 2; t += scale.step) {
			var y = r(margin.top + plotH - (t / scale.max) * plotH) + 0.5;
			svg('line', { x1: margin.left, x2: width - margin.right, y1: y, y2: y, stroke: t === 0 ? AXIS : GRID, 'shape-rendering': 'crispEdges' }, root);
			var label = svg('text', { x: margin.left - 6, y: y + 4, 'text-anchor': 'end', 'class': 'pa-axis-label' }, root);
			label.textContent = yFormat(t);
		}
		values.forEach(function (v, i) {
			var h = scale.max ? (v / scale.max) * plotH : 0;
			var x = margin.left + i * band + (band - barW) / 2;
			var yTop = margin.top + plotH - h;
			var radius = Math.min(4, barW / 2, h);
			var d = h <= 0 ? '' :
				'M' + r(x) + ',' + r(margin.top + plotH) +
				'V' + r(yTop + radius) +
				'Q' + r(x) + ',' + r(yTop) + ' ' + r(x + radius) + ',' + r(yTop) +
				'H' + r(x + barW - radius) +
				'Q' + r(x + barW) + ',' + r(yTop) + ' ' + r(x + barW) + ',' + r(yTop + radius) +
				'V' + r(margin.top + plotH) + 'Z';
			if (d) {
				var bar = svg('path', { d: d, fill: o.color || '#2a78d6', 'class': 'pa-bar' + (o.highlightLast && i === n - 1 ? ' is-current' : '') }, root);
				bar.style.opacity = o.highlightLast && i !== n - 1 ? 0.75 : 1;
			}
			if (o.xLabel && o.xLabel(i)) {
				var tx = svg('text', { x: r(x + barW / 2), y: height - 6, 'text-anchor': 'middle', 'class': 'pa-axis-label' }, root);
				tx.textContent = o.xLabel(i);
			}
			var hit = svg('rect', { x: r(margin.left + i * band), y: margin.top, width: r(band), height: plotH, fill: 'transparent' }, root);
			hit.addEventListener('pointerenter', function () {
				root.querySelectorAll('.pa-bar').forEach(function (b) { b.classList.remove('is-hover'); });
				var bars = root.querySelectorAll('.pa-bar');
				self.tip.show(o.tooltipTitle ? o.tooltipTitle(i) : String(i), [{ color: o.color || '#2a78d6', shape: 'box', value: yFormat(v), label: o.valueLabel || '' }], x + barW / 2, yTop);
				if (bars[i]) { bars[i].classList.add('is-hover'); }
			});
			hit.addEventListener('pointerleave', function () { self.tip.hide(); });
		});
		this.container.insertBefore(root, this.tip.node);
	};

	/* --------------------------------------------------------------- donut */

	/**
	 * opts: { items: [{ label, value, color }], size, thickness, centerValue, centerLabel, format(v) }
	 */
	function Donut(container, opts) {
		this.container = container;
		this.opts = opts;
		container.classList.add('pa-chart', 'pa-donut');
		this.tip = new Tooltip(container);
		this.render();
	}

	Donut.prototype.update = LineChart.prototype.update;
	Donut.prototype.destroy = function () { this.container.textContent = ''; };

	Donut.prototype.render = function () {
		var self = this;
		var o = this.opts;
		var size = o.size || 150;
		var thick = o.thickness || 20;
		var items = (o.items || []).filter(function (it) { return it.value > 0; });
		var total = items.reduce(function (sum, it) { return sum + it.value; }, 0);
		if (this.svg) {
			this.svg.remove();
		}
		var root = svg('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': o.ariaLabel || '' });
		this.svg = root;
		var cx = size / 2, cy = size / 2, R = size / 2 - 2, ri = R - thick;
		if (!total) {
			svg('circle', { cx: cx, cy: cy, r: R - thick / 2, fill: 'none', stroke: GRID, 'stroke-width': thick }, root);
		}
		var angle = -Math.PI / 2;
		var gap = items.length > 1 ? 0.025 : 0;
		items.forEach(function (it) {
			var sweep = (it.value / total) * Math.PI * 2;
			var a0 = angle + gap / 2, a1 = angle + sweep - gap / 2;
			angle += sweep;
			var path;
			if (items.length === 1) {
				path = svg('circle', { cx: cx, cy: cy, r: R - thick / 2, fill: 'none', stroke: it.color, 'stroke-width': thick }, root);
			} else {
				if (a1 <= a0) { return; }
				var large = a1 - a0 > Math.PI ? 1 : 0;
				var p = function (rad, a) { return r(cx + rad * Math.cos(a)) + ',' + r(cy + rad * Math.sin(a)); };
				var d = 'M' + p(R, a0) + 'A' + R + ',' + R + ' 0 ' + large + ' 1 ' + p(R, a1) + 'L' + p(ri, a1) + 'A' + ri + ',' + ri + ' 0 ' + large + ' 0 ' + p(ri, a0) + 'Z';
				path = svg('path', { d: d, fill: it.color, 'class': 'pa-arc' }, root);
			}
			path.addEventListener('pointermove', function (e) {
				var rect = self.container.getBoundingClientRect();
				root.classList.add('is-hovering');
				path.classList.add('is-hover');
				self.tip.show(it.label, [{ color: it.color, shape: 'box', value: (o.format ? o.format(it.value) : it.value) + ' (' + (Math.round(it.value / total * 1000) / 10) + '%)' }], e.clientX - rect.left, e.clientY - rect.top);
			});
			path.addEventListener('pointerleave', function () {
				root.classList.remove('is-hovering');
				path.classList.remove('is-hover');
				self.tip.hide();
			});
			if (o.onClick) {
				path.style.cursor = 'pointer';
				path.addEventListener('click', function () { o.onClick(it); });
			}
		});
		var center = svg('text', { x: cx, y: cy + 2, 'text-anchor': 'middle', 'class': 'pa-donut-value' }, root);
		center.textContent = o.centerValue !== undefined ? o.centerValue : (o.format ? o.format(total) : total);
		if (o.centerLabel) {
			var sub = svg('text', { x: cx, y: cy + 20, 'text-anchor': 'middle', 'class': 'pa-donut-label' }, root);
			sub.textContent = o.centerLabel;
		}
		this.container.insertBefore(root, this.tip.node);
	};

	/* ----------------------------------------------------------- sparkline */

	function sparkline(container, values, opts) {
		opts = opts || {};
		var width = opts.width || 120;
		var height = opts.height || 32;
		container.textContent = '';
		var root = svg('svg', { width: '100%', height: height, viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none', 'aria-hidden': 'true' }, container);
		var clean = values.map(function (v) { return v === null || !isFinite(v) ? 0 : v; });
		var max = Math.max.apply(null, clean.concat([1]));
		var n = clean.length;
		if (n < 2) {
			return;
		}
		var pts = clean.map(function (v, i) { return [i / (n - 1) * width, height - 3 - (v / max) * (height - 6)]; });
		var d = monotone(pts);
		svg('path', { d: d + 'L' + width + ',' + height + 'L0,' + height + 'Z', fill: opts.color || '#2a78d6', 'fill-opacity': 0.1 }, root);
		svg('path', { d: d, fill: 'none', stroke: opts.color || '#2a78d6', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke', 'stroke-linejoin': 'round' }, root);
	}

	/* ------------------------------------------------------------- heatmap */

	/**
	 * opts: { cells: [[...]], rowLabels, colLabels, ramp: [...colors], format(v), title(row, col) }
	 */
	function heatmap(container, opts) {
		container.textContent = '';
		container.classList.add('pa-heatmap');
		var cells = opts.cells || [];
		var ramp = opts.ramp;
		var max = 0;
		cells.forEach(function (row) { row.forEach(function (v) { if (v > max) { max = v; } }); });
		var tip = new Tooltip(container);
		var grid = html('div', 'pa-heat-grid', container);
		grid.style.gridTemplateColumns = 'auto repeat(' + (cells[0] ? cells[0].length : 24) + ', minmax(0, 1fr))';
		cells.forEach(function (row, ri) {
			html('div', 'pa-heat-row-label', grid, opts.rowLabels[ri]);
			row.forEach(function (v, ci) {
				var cell = html('div', 'pa-heat-cell', grid);
				var level = max ? Math.ceil((v / max) * (ramp.length - 1)) : 0;
				cell.style.background = v > 0 ? ramp[Math.max(1, level)] : ramp[0];
				cell.setAttribute('tabindex', '-1');
				cell.addEventListener('pointerenter', function () {
					var rect = container.getBoundingClientRect();
					var cr = cell.getBoundingClientRect();
					tip.show(opts.title ? opts.title(ri, ci) : '', [{ color: cell.style.background, shape: 'box', value: opts.format ? opts.format(v) : String(v), label: opts.valueLabel || '' }], cr.left - rect.left + cr.width / 2, cr.top - rect.top);
				});
				cell.addEventListener('pointerleave', function () { tip.hide(); });
			});
		});
		html('div', '', grid);
		(opts.colLabels || []).forEach(function (label) {
			html('div', 'pa-heat-col-label', grid, label);
		});
		var legend = html('div', 'pa-heat-legend', container);
		html('span', '', legend, opts.lessLabel || 'Less');
		ramp.forEach(function (color) {
			var sw = html('i', '', legend);
			sw.style.background = color;
		});
		html('span', '', legend, opts.moreLabel || 'More');
	}

	global.PulseCharts = {
		line: function (container, opts) { return new LineChart(container, opts); },
		bar: function (container, opts) { return new BarChart(container, opts); },
		donut: function (container, opts) { return new Donut(container, opts); },
		sparkline: sparkline,
		heatmap: heatmap,
		niceScale: niceScale,
		Tooltip: Tooltip
	};
})(window);
