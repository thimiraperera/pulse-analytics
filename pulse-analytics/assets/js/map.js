/*!
 * Pulse Analytics world map (TopoJSON data drawn with the Natural Earth projection).
 * Geometry: Natural Earth 1:110m via the ISC-licensed "world-atlas" package.
 */
(function (global) {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var WIDTH = 1000;
	var LAT_TOP = 84;
	var LAT_BOTTOM = -57;

	// ISO 3166-1 numeric => alpha-2.
	var NUMERIC = {};
	('004AF008AL010AQ012DZ016AS020AD024AO028AG031AZ032AR036AU040AT044BS048BH050BD051AM052BB056BE060BM064BT068BO070BA072BW074BV076BR084BZ086IO090SB092VG096BN100BG104MM108BI112BY116KH120CM124CA132CV136KY140CF144LK148TD152CL156CN158TW162CX166CC170CO174KM175YT178CG180CD184CK188CR191HR192CU196CY203CZ204BJ208DK212DM214DO218EC222SV226GQ231ET232ER233EE234FO238FK239GS242FJ246FI248AX250FR254GF258PF260TF262DJ266GA268GE270GM275PS276DE288GH292GI296KI300GR304GL308GD312GP316GU320GT324GN328GY332HT334HM336VA340HN344HK348HU352IS356IN360ID364IR368IQ372IE376IL380IT384CI388JM392JP398KZ400JO404KE408KP410KR414KW417KG418LA422LB426LS428LV430LR434LY438LI440LT442LU446MO450MG454MW458MY462MV466ML470MT474MQ478MR480MU484MX492MC496MN498MD499ME500MS504MA508MZ512OM516NA520NR524NP528NL531CW533AW534SX535BQ540NC548VU554NZ558NI562NE566NG570NU574NF578NO580MP581UM583FM584MH585PW586PK591PA598PG600PY604PE608PH612PN616PL620PT624GW626TL630PR634QA638RE642RO643RU646RW652BL654SH659KN660AI662LC663MF666PM670VC674SM678ST682SA686SN688RS690SC694SL702SG703SK704VN705SI706SO710ZA716ZW724ES728SS729SD732EH740SR744SJ748SZ752SE756CH760SY762TJ764TH768TG772TK776TO780TT784AE788TN792TR795TM796TC798TV800UG804UA807MK818EG826GB831GG832JE833IM834TZ840US850VI854BF858UY860UZ862VE876WF882WS887YE894ZM')
		.replace(/(\d{3})([A-Z]{2})/g, function (m, num, code) { NUMERIC[num] = code; return ''; });
	var BY_NAME = { 'Kosovo': 'XK', 'N. Cyprus': 'CY', 'Somaliland': 'SO' };

	var topologyPromise = null;

	function svg(tag, attrs, parent) {
		var node = document.createElementNS(SVG_NS, tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		if (parent) { parent.appendChild(node); }
		return node;
	}

	function project(lon, lat) {
		var l = lon * Math.PI / 180;
		var p = lat * Math.PI / 180;
		var p2 = p * p;
		var p4 = p2 * p2;
		return [
			l * (0.8707 - 0.131979 * p2 + p4 * (-0.013791 + p4 * (0.003971 * p2 - 0.001529 * p4))),
			-p * (1.007226 + p2 * (0.015085 + p4 * (-0.044475 + 0.028874 * p2 - 0.005916 * p4)))
		];
	}

	var X_SCALE = WIDTH / (2 * project(180, 0)[0]);
	var Y_TOP = project(0, LAT_TOP)[1];
	var HEIGHT = Math.round((project(0, LAT_BOTTOM)[1] - Y_TOP) * X_SCALE);

	function toScreen(lon, lat) {
		var p = project(lon, lat);
		return [WIDTH / 2 + p[0] * X_SCALE, (p[1] - Y_TOP) * X_SCALE];
	}

	function fmt(v) {
		return Math.round(v * 10) / 10;
	}

	/* ------------------------------------------------------------ topology */

	function decode(topo) {
		var tf = topo.transform;
		var arcs = topo.arcs.map(function (arc) {
			if (!tf) {
				return arc.map(function (p) { return [p[0], p[1]]; });
			}
			var x = 0, y = 0;
			return arc.map(function (p) {
				x += p[0];
				y += p[1];
				return [x * tf.scale[0] + tf.translate[0], y * tf.scale[1] + tf.translate[1]];
			});
		});
		function arcPoints(i) {
			return i >= 0 ? arcs[i] : arcs[~i].slice().reverse();
		}
		function ring(ids) {
			var pts = [];
			ids.forEach(function (id, k) {
				var a = arcPoints(id);
				pts = pts.concat(k ? a.slice(1) : a);
			});
			return pts;
		}
		var object = topo.objects.countries || topo.objects[Object.keys(topo.objects)[0]];
		return object.geometries.map(function (g) {
			var polygons = g.type === 'Polygon' ? [g.arcs] : (g.type === 'MultiPolygon' ? g.arcs : []);
			var id = g.id !== undefined && g.id !== null ? String(g.id) : '';
			while (id && id.length < 3) { id = '0' + id; }
			var name = g.properties && g.properties.name ? g.properties.name : '';
			return {
				code: NUMERIC[id] || BY_NAME[name] || '',
				numeric: id,
				name: name,
				polygons: polygons.map(function (p) { return p.map(ring); })
			};
		});
	}

	// Path for one ring; rings crossing the antimeridian are unwrapped and drawn twice (then clipped).
	function ringPath(points) {
		var unwrapped = [];
		var offset = 0;
		var prev = null;
		var minLon = Infinity, maxLon = -Infinity;
		points.forEach(function (pt) {
			var lon = pt[0] + offset;
			if (prev !== null) {
				if (lon - prev > 180) { offset -= 360; lon -= 360; }
				else if (lon - prev < -180) { offset += 360; lon += 360; }
			}
			prev = lon;
			unwrapped.push([lon, Math.max(LAT_BOTTOM - 3, Math.min(LAT_TOP + 1, pt[1]))]);
			if (lon < minLon) { minLon = lon; }
			if (lon > maxLon) { maxLon = lon; }
		});
		var shifts = [0];
		if (maxLon > 180) { shifts.push(-360); }
		if (minLon < -180) { shifts.push(360); }
		return shifts.map(function (shift) {
			return unwrapped.map(function (pt, i) {
				var s = toScreen(pt[0] + shift, pt[1]);
				return (i ? 'L' : 'M') + fmt(s[0]) + ',' + fmt(s[1]);
			}).join('') + 'Z';
		}).join('');
	}

	function outlinePath() {
		var pts = [], lat, lon;
		for (lat = LAT_TOP; lat >= LAT_BOTTOM; lat -= 2) { pts.push(toScreen(180, lat)); }
		for (lon = 180; lon >= -180; lon -= 5) { pts.push(toScreen(lon, LAT_BOTTOM)); }
		for (lat = LAT_BOTTOM; lat <= LAT_TOP; lat += 2) { pts.push(toScreen(-180, lat)); }
		for (lon = -180; lon <= 180; lon += 5) { pts.push(toScreen(lon, LAT_TOP)); }
		return pts.map(function (p, i) { return (i ? 'L' : 'M') + fmt(p[0]) + ',' + fmt(p[1]); }).join('') + 'Z';
	}

	function loadTopology(sources) {
		if (topologyPromise) {
			return topologyPromise;
		}
		var attempt = function (i) {
			if (i >= sources.length) {
				return Promise.reject(new Error('Map data unavailable'));
			}
			var src = sources[i];
			return fetch(src.url, { headers: src.headers || {}, credentials: src.credentials || 'omit' })
				.then(function (res) {
					if (!res.ok) { throw new Error(String(res.status)); }
					return res.json();
				})
				.then(function (json) {
					if (!json || !json.objects || !json.arcs) { throw new Error('Invalid map data'); }
					return decode(json);
				})
				.catch(function () { return attempt(i + 1); });
		};
		topologyPromise = attempt(0).catch(function (err) {
			topologyPromise = null;
			throw err;
		});
		return topologyPromise;
	}

	/* ---------------------------------------------------------------- map */

	function WorldMap(container, opts) {
		this.container = container;
		this.opts = opts || {};
		this.data = {};
		this.selected = '';
		this.markers = [];
		this.view = { k: 1, x: 0, y: 0 };
		this.features = [];
		this.paths = {};
		container.classList.add('pa-map');
		this.build();
	}

	WorldMap.prototype.build = function () {
		var self = this;
		var c = this.container;
		c.textContent = '';

		this.svg = svg('svg', { viewBox: '0 0 ' + WIDTH + ' ' + HEIGHT, 'class': 'pa-map-svg', role: 'img', 'aria-label': this.opts.ariaLabel || 'World map' }, c);
		var defs = svg('defs', {}, this.svg);
		var clipId = 'pa-map-clip-' + Math.random().toString(36).slice(2, 8);
		var clip = svg('clipPath', { id: clipId }, defs);
		svg('path', { d: outlinePath() }, clip);
		this.viewport = svg('g', { 'class': 'pa-map-viewport' }, this.svg);
		this.land = svg('g', { 'clip-path': 'url(#' + clipId + ')', 'class': 'pa-map-land' }, this.viewport);
		this.markerLayer = svg('g', { 'class': 'pa-map-markers' }, this.viewport);

		this.tip = new global.PulseCharts.Tooltip(c);

		var controls = document.createElement('div');
		controls.className = 'pa-map-controls';
		[['<path d="M12 5v14M5 12h14"/>', 'zoom-in', this.opts.labels && this.opts.labels.zoomIn], ['<path d="M5 12h14"/>', 'zoom-out', this.opts.labels && this.opts.labels.zoomOut], ['<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>', 'reset', this.opts.labels && this.opts.labels.reset]].forEach(function (b) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'pa-map-btn';
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + b[0] + '</svg>';
			btn.setAttribute('aria-label', b[2] || b[1]);
			btn.title = b[2] || b[1];
			btn.addEventListener('click', function () {
				if (b[1] === 'reset') { self.reset(); } else { self.zoomBy(b[1] === 'zoom-in' ? 1.6 : 1 / 1.6, WIDTH / 2, HEIGHT / 2); }
			});
			controls.appendChild(btn);
		});
		c.appendChild(controls);

		this.legend = document.createElement('div');
		this.legend.className = 'pa-map-legend';
		c.appendChild(this.legend);

		this.status = document.createElement('div');
		this.status.className = 'pa-map-status';
		c.appendChild(this.status);

		this.bindInteractions();
	};

	WorldMap.prototype.setStatus = function (text) {
		this.status.textContent = text || '';
		this.status.style.display = text ? 'flex' : 'none';
	};

	WorldMap.prototype.setFeatures = function (features) {
		var self = this;
		this.features = features.filter(function (f) { return f.numeric !== '010' && f.code !== 'AQ'; });
		this.land.textContent = '';
		this.paths = {};
		this.features.forEach(function (f) {
			var d = f.polygons.map(function (poly) { return poly.map(ringPath).join(''); }).join('');
			var path = svg('path', { d: d, 'class': 'pa-country', 'fill-rule': 'evenodd', 'vector-effect': 'non-scaling-stroke', 'data-code': f.code }, self.land);
			if (f.code) {
				if (self.paths[f.code]) {
					self.paths[f.code].push(path);
				} else {
					self.paths[f.code] = [path];
				}
			}
		});
		this.paint();
		this.renderMarkers();
	};

	WorldMap.prototype.setData = function (data, opts) {
		this.data = data || {};
		this.dataOpts = opts || {};
		this.paint();
	};

	WorldMap.prototype.setSelected = function (code) {
		this.selected = code || '';
		this.paint();
	};

	WorldMap.prototype.setMarkers = function (markers) {
		this.markers = markers || [];
		this.renderMarkers();
	};

	WorldMap.prototype.colorScale = function () {
		var ramp = this.opts.ramp || ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b'];
		var values = Object.keys(this.data).map(function (k) { return this.data[k]; }, this).filter(function (v) { return v > 0; }).sort(function (a, b) { return a - b; });
		var steps = Math.min(ramp.length, Math.max(1, values.length));
		var palette = steps < ramp.length ? ramp.slice(ramp.length - steps) : ramp;
		var thresholds = [];
		for (var i = 1; i < steps; i++) {
			thresholds.push(values[Math.floor((i / steps) * values.length)]);
		}
		return {
			color: function (v) {
				if (!v) { return null; }
				var idx = 0;
				while (idx < thresholds.length && v >= thresholds[idx]) { idx++; }
				return palette[Math.min(idx, palette.length - 1)];
			},
			palette: palette,
			min: values.length ? values[0] : 0,
			max: values.length ? values[values.length - 1] : 0
		};
	};

	WorldMap.prototype.paint = function () {
		var self = this;
		var scale = this.colorScale();
		Object.keys(this.paths).forEach(function (code) {
			var v = self.data[code] || 0;
			var color = scale.color(v);
			self.paths[code].forEach(function (path) {
				path.style.fill = color || '';
				path.classList.toggle('has-data', !!color);
				path.classList.toggle('is-selected', code === self.selected);
			});
		});
		// Bring the selected country to the front so its outline is not covered.
		if (this.selected && this.paths[this.selected]) {
			this.paths[this.selected].forEach(function (path) { self.land.appendChild(path); });
		}
		this.renderLegend(scale);
	};

	WorldMap.prototype.renderLegend = function (scale) {
		var o = this.dataOpts || {};
		var format = o.format || String;
		this.legend.textContent = '';
		if (!scale.max) {
			return;
		}
		var label = document.createElement('span');
		label.className = 'pa-map-legend-label';
		label.textContent = o.label || '';
		this.legend.appendChild(label);
		var min = document.createElement('span');
		min.textContent = format(scale.min);
		this.legend.appendChild(min);
		var bar = document.createElement('span');
		bar.className = 'pa-map-legend-bar';
		scale.palette.forEach(function (c) {
			var sw = document.createElement('i');
			sw.style.background = c;
			bar.appendChild(sw);
		});
		this.legend.appendChild(bar);
		var max = document.createElement('span');
		max.textContent = format(scale.max);
		this.legend.appendChild(max);
	};

	WorldMap.prototype.renderMarkers = function () {
		var self = this;
		this.markerLayer.textContent = '';
		if (!this.markers.length) {
			return;
		}
		var max = Math.max.apply(null, this.markers.map(function (m) { return m.value; }).concat([1]));
		this.markers.slice().sort(function (a, b) { return b.value - a.value; }).forEach(function (m) {
			if (m.lat === null || m.lon === null || m.lat === undefined) { return; }
			var p = toScreen(m.lon, m.lat);
			var radius = 3 + Math.sqrt(m.value / max) * 11;
			var circle = svg('circle', { cx: fmt(p[0]), cy: fmt(p[1]), r: fmt(radius / self.view.k), 'class': 'pa-marker', 'data-r': radius }, self.markerLayer);
			circle.addEventListener('pointermove', function (e) {
				var rect = self.container.getBoundingClientRect();
				self.tip.show(m.label, [{ color: '#eb6834', value: (self.dataOpts.format || String)(m.value), label: self.dataOpts.label || '' }], e.clientX - rect.left, e.clientY - rect.top);
				e.stopPropagation();
			});
			circle.addEventListener('pointerleave', function () { self.tip.hide(); });
			if (m.onClick) {
				circle.addEventListener('click', function (e) { e.stopPropagation(); m.onClick(m); });
			}
		});
	};

	WorldMap.prototype.applyView = function () {
		var v = this.view;
		this.viewport.setAttribute('transform', 'translate(' + fmt(v.x) + ',' + fmt(v.y) + ') scale(' + v.k + ')');
		var k = v.k;
		this.markerLayer.querySelectorAll('circle').forEach(function (c) {
			c.setAttribute('r', fmt(parseFloat(c.getAttribute('data-r')) / k));
		});
		this.container.classList.toggle('is-zoomed', k > 1.01);
	};

	WorldMap.prototype.clampView = function () {
		var v = this.view;
		v.k = Math.max(1, Math.min(14, v.k));
		v.x = Math.min(0, Math.max(WIDTH - WIDTH * v.k, v.x));
		v.y = Math.min(0, Math.max(HEIGHT - HEIGHT * v.k, v.y));
	};

	WorldMap.prototype.zoomBy = function (factor, cx, cy) {
		var v = this.view;
		var k = Math.max(1, Math.min(14, v.k * factor));
		v.x = cx - (cx - v.x) * (k / v.k);
		v.y = cy - (cy - v.y) * (k / v.k);
		v.k = k;
		this.clampView();
		this.applyView();
	};

	WorldMap.prototype.reset = function () {
		this.view = { k: 1, x: 0, y: 0 };
		this.applyView();
	};

	WorldMap.prototype.focus = function (code) {
		var paths = this.paths[code];
		if (!paths || !paths.length) {
			return;
		}
		// Use the largest polygon of the country to avoid zooming out for far-away islands.
		var box = null;
		paths.forEach(function (p) {
			var b = p.getBBox();
			if (!box || b.width * b.height > box.width * box.height) { box = b; }
		});
		if (!box || !box.width) {
			return;
		}
		var k = Math.max(1, Math.min(8, 0.6 * Math.min(WIDTH / box.width, HEIGHT / box.height)));
		this.view = { k: k, x: WIDTH / 2 - (box.x + box.width / 2) * k, y: HEIGHT / 2 - (box.y + box.height / 2) * k };
		this.clampView();
		this.applyView();
	};

	WorldMap.prototype.toLocal = function (e) {
		var rect = this.svg.getBoundingClientRect();
		return [(e.clientX - rect.left) * (WIDTH / rect.width), (e.clientY - rect.top) * (HEIGHT / rect.height)];
	};

	WorldMap.prototype.bindInteractions = function () {
		var self = this;
		var drag = null;
		var moved = false;

		this.svg.addEventListener('wheel', function (e) {
			if (!e.ctrlKey && !e.metaKey && self.view.k === 1 && e.deltaY > 0) {
				return; // Let the page scroll when the map is not zoomed.
			}
			e.preventDefault();
			var p = self.toLocal(e);
			self.zoomBy(Math.pow(1.0015, -e.deltaY), p[0], p[1]);
		}, { passive: false });

		this.svg.addEventListener('pointerdown', function (e) {
			if (e.button !== 0) { return; }
			drag = { x: e.clientX, y: e.clientY, vx: self.view.x, vy: self.view.y };
			moved = false;
		});
		global.addEventListener('pointermove', function (e) {
			if (!drag) { return; }
			var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
			if (Math.abs(dx) + Math.abs(dy) > 4) {
				moved = true;
				if (self.view.k > 1) {
					var rect = self.svg.getBoundingClientRect();
					self.view.x = drag.vx + dx * (WIDTH / rect.width);
					self.view.y = drag.vy + dy * (HEIGHT / rect.height);
					self.clampView();
					self.applyView();
					self.container.classList.add('is-dragging');
				}
			}
		});
		global.addEventListener('pointerup', function () {
			drag = null;
			self.container.classList.remove('is-dragging');
		});

		this.land.addEventListener('pointermove', function (e) {
			var target = e.target.closest ? e.target.closest('.pa-country') : null;
			if (!target || drag && moved) {
				self.tip.hide();
				return;
			}
			var code = target.getAttribute('data-code');
			self.land.querySelectorAll('.pa-country.is-hover').forEach(function (p) { if (p.getAttribute('data-code') !== code) { p.classList.remove('is-hover'); } });
			(self.paths[code] || [target]).forEach(function (p) { p.classList.add('is-hover'); });
			var feature = self.features.filter(function (f) { return f.code === code; })[0];
			var info = self.opts.tooltip ? self.opts.tooltip(code, feature ? feature.name : '', self.data[code] || 0) : null;
			var rect = self.container.getBoundingClientRect();
			if (info) {
				self.tip.show(info.title, info.rows, e.clientX - rect.left, e.clientY - rect.top);
			}
		});
		this.land.addEventListener('pointerleave', function () {
			self.tip.hide();
			self.land.querySelectorAll('.pa-country.is-hover').forEach(function (p) { p.classList.remove('is-hover'); });
		});
		this.land.addEventListener('click', function (e) {
			if (moved) { return; }
			var target = e.target.closest ? e.target.closest('.pa-country') : null;
			if (target && self.opts.onSelect) {
				var code = target.getAttribute('data-code');
				if (code) { self.opts.onSelect(code); }
			}
		});
	};

	global.PulseMap = {
		create: function (container, opts) { return new WorldMap(container, opts); },
		loadTopology: loadTopology,
		project: toScreen,
		size: { width: WIDTH, height: HEIGHT }
	};
})(window);
