/* TIMB Tobacco Crop Monitoring — dashboard. Designed by Zimgeo (www.zimgeo.co.zw). */
(function () {
  'use strict';

  var P = window.PORTAL;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var nf0 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
  var nf1 = new Intl.NumberFormat('en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  var nf2 = new Intl.NumberFormat('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var nf3 = new Intl.NumberFormat('en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmtDate(d) { if (!d) return '—'; var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function compact(n) { return n >= 1e6 ? nf1.format(n / 1e6) + ' M' : n >= 1e4 ? nf1.format(n / 1e3) + ' k' : nf0.format(n); }

  var COLORS = { field: '#34d058', fieldLine: '#0c5c25', heatHi: '#e9f99d', gold: '#e5b83b', green: '#1f7a3f', greenSoft: '#5aa86f' };

  var state = {
    meta: null,
    season: P.season || 0,
    districts: null,          // Set of selected district names; null = all
    min_area: '', max_area: '', min_ndvi: '', max_ndvi: '', q: '',
    sort: 'area_ha', dir: 'desc', page: 1, per: 25,
    points: null, pointsSeason: null,
    stats: null
  };

  function api(path, params) {
    var qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return fetch(P.base + 'api/' + path + qs, { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.href = P.base + 'login.php'; throw new Error('signed out'); }
      return r.json().then(function (j) { if (!r.ok || j.error) throw new Error(j.error || 'Request failed'); return j; });
    });
  }

  function allDistrictNames() { return (state.meta ? state.meta.districts : []).map(function (d) { return d.name; }); }
  function selectedDistricts() {
    if (!state.districts) return null;
    if (state.districts.size === allDistrictNames().length) return null;
    return Array.from(state.districts);
  }

  function filterParams(extra) {
    var p = { season: state.season };
    var d = selectedDistricts();
    if (d) p.district = d.length ? d.join('|') : '__none__';
    ['min_area', 'max_area', 'min_ndvi', 'max_ndvi', 'q'].forEach(function (k) { if (state[k] !== '' && state[k] != null) p[k] = state[k]; });
    return Object.assign(p, extra || {});
  }

  /* ---------------- Season & filter UI ---------------- */

  function loadMeta(seasonId) {
    return api('meta.php', seasonId ? { season: seasonId } : null).then(function (m) {
      state.meta = m;
      state.season = m.season.id;
      state.districts = null;
      var url = new URL(location.href); url.searchParams.set('season', m.season.id); history.replaceState(null, '', url);

      var sel = $('#seasonSel');
      sel.innerHTML = m.seasons.map(function (s) {
        return '<option value="' + s.id + '"' + (s.id === m.season.id ? ' selected' : '') + '>' + esc(s.title) + (s.status !== 'published' ? ' (draft)' : '') + '</option>';
      }).join('');

      var s = m.season;
      $('#seasonTitle').textContent = s.title;
      $('#seasonEyebrow').textContent = (s.province || 'Mashonaland West') + ' Province · Sentinel-2 satellite detection' + (s.status !== 'published' ? ' · DRAFT (admins only)' : '');
      var bits = ['Imagery ' + fmtDate(s.start_date) + ' – ' + fmtDate(s.end_date)];
      if (s.images_used) bits.push(nf0.format(s.images_used) + ' scenes');
      if (s.training_points) bits.push(nf0.format(s.training_points) + ' training points');
      bits.push('Yield benchmark ' + s.yield_benchmark + ' t/ha');
      $('#seasonMeta').textContent = bits.join(' · ');
      var note = $('#seasonNote');
      note.hidden = !s.description; note.textContent = s.description || '';
      $('#downloads').hidden = !m.can_download;
      $('#trainingToggle').hidden = !s.has_training;
      renderDistrictList();
      return m;
    });
  }

  function renderDistrictList() {
    var list = $('#districtList');
    var term = $('#districtSearch').value.trim().toLowerCase();
    var ds = state.meta.districts.slice().sort(function (a, b) { return b.area_ha - a.area_ha; });
    list.innerHTML = ds.filter(function (d) { return !term || d.name.toLowerCase().indexOf(term) >= 0; }).map(function (d) {
      var on = !state.districts || state.districts.has(d.name);
      return '<label class="ditem"><input type="checkbox" value="' + esc(d.name) + '"' + (on ? ' checked' : '') + '>' +
        '<span class="dname">' + esc(d.name) + '</span><span class="dval">' + compact(d.area_ha) + ' ha</span></label>';
    }).join('') || '<p class="muted small">No districts match.</p>';
    var n = state.districts ? state.districts.size : state.meta.districts.length;
    $('#districtCount').textContent = n + ' of ' + state.meta.districts.length;
    if (state.meta.restricted) $('#districtCount').textContent += ' (your access)';
  }

  function setDistricts(names) {
    state.districts = names === null ? null : new Set(names);
    renderDistrictList();
    refresh(true);
  }

  function bindFilters() {
    $('#seasonSel').addEventListener('change', function () {
      resetFilterInputs();
      loadMeta(this.value).then(function () { fitSeason(); refresh(false); return loadPoints(); }).catch(showError);
    });
    $('#districtSearch').addEventListener('input', renderDistrictList);
    $('#districtList').addEventListener('change', function (e) {
      if (!e.target.matches('input')) return;
      var cur = state.districts ? new Set(state.districts) : new Set(allDistrictNames());
      e.target.checked ? cur.add(e.target.value) : cur.delete(e.target.value);
      state.districts = cur;
      renderDistrictList();
      refresh(true);
    });
    $('#allDistricts').addEventListener('click', function () { setDistricts(null); });
    $('#noDistricts').addEventListener('click', function () { setDistricts([]); });
    var debounced = debounce(function () { refresh(false); }, 450);
    [['#minArea', 'min_area'], ['#maxArea', 'max_area'], ['#minNdvi', 'min_ndvi'], ['#maxNdvi', 'max_ndvi'], ['#fieldSearch', 'q']].forEach(function (pair) {
      $(pair[0]).addEventListener('input', function () { state[pair[1]] = this.value.trim(); state.page = 1; debounced(); });
    });
    $$('#sizePresets button').forEach(function (b) {
      b.addEventListener('click', function () {
        $('#minArea').value = state.min_area = b.dataset.min;
        $('#maxArea').value = state.max_area = b.dataset.max;
        $$('#sizePresets button').forEach(function (x) { x.classList.toggle('on', x === b); });
        refresh(false);
      });
    });
    $('#resetBtn').addEventListener('click', function () { resetFilterInputs(); state.districts = null; renderDistrictList(); refresh(true); });
    $$('#downloads [data-format]').forEach(function (b) {
      b.addEventListener('click', function () {
        var label = b.innerHTML;
        b.disabled = true; b.textContent = 'Preparing…';
        location.href = P.base + 'api/export.php?' + new URLSearchParams(filterParams({ format: b.dataset.format }));
        setTimeout(function () { b.disabled = false; b.innerHTML = label; }, 4000);
      });
    });
    $('#perPage').addEventListener('change', function () { state.per = +this.value; state.page = 1; loadTable(); });
    $$('#fieldTable th[data-sort]').forEach(function (th) {
      th.addEventListener('click', function () {
        var k = th.dataset.sort;
        state.dir = state.sort === k && state.dir === 'desc' ? 'asc' : 'desc';
        state.sort = k; state.page = 1;
        $$('#fieldTable th').forEach(function (x) { x.classList.remove('sorted', 'asc', 'desc'); });
        th.classList.add('sorted', state.dir);
        loadTable();
      });
    });
  }

  function resetFilterInputs() {
    ['min_area', 'max_area', 'min_ndvi', 'max_ndvi', 'q'].forEach(function (k) { state[k] = ''; });
    ['#minArea', '#maxArea', '#minNdvi', '#maxNdvi', '#fieldSearch', '#districtSearch'].forEach(function (s) { $(s).value = ''; });
    $$('#sizePresets button').forEach(function (x) { x.classList.remove('on'); });
    state.page = 1;
  }

  function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

  /* ---------------- Refresh cycle ---------------- */

  var refreshSeq = 0;
  function refresh(fit) {
    state.page = 1;
    var seq = ++refreshSeq;
    applyMapFilter();
    if (fit) fitSelection();
    loadPolygons();
    api('stats.php', filterParams()).then(function (s) {
      if (seq !== refreshSeq) return;
      state.stats = s;
      renderKpis(s);
      renderCharts(s);
      renderDistrictTable(s);
    }).catch(showError);
    loadTable();
  }

  function renderKpis(s) {
    var k = s.kpi, m = state.meta.season;
    var share = k.season_area_ha ? k.area_ha / k.season_area_ha * 100 : 0;
    var cards = [
      ['Detected tobacco area', nf1.format(k.area_ha) + ' <small>ha</small>', share < 99.95 ? nf1.format(share) + '% of season total' : 'Whole season'],
      ['Detected fields', nf0.format(k.fields), 'Largest ' + nf1.format(k.max_ha || 0) + ' ha'],
      ['Estimated yield', nf0.format(k.yield_t) + ' <small>t</small>', '@ ' + m.yield_benchmark + ' t/ha benchmark'],
      ['Average field size', nf2.format(k.avg_ha || 0) + ' <small>ha</small>', k.ndvi != null ? 'Mean NDVI ' + nf2.format(k.ndvi) : '&nbsp;'],
      ['Classifier accuracy', m.accuracy != null ? nf1.format(m.accuracy * 100) + '<small>%</small>' : '—', 'Random Forest · hold-out test']
    ];
    $('#kpis').innerHTML = cards.map(function (c, i) {
      return '<div class="kpi' + (i === 0 ? ' hero' : '') + '"><span class="kpi-label">' + c[0] + '</span><span class="kpi-value">' + c[1] + '</span><span class="kpi-sub">' + c[2] + '</span></div>';
    }).join('');
    $('#dlCount').textContent = nf0.format(k.fields) + ' fields match the current filters.';
  }

  /* ---------------- Charts ---------------- */

  var charts = {};
  function baseChartOpts(horizontal) {
    return {
      indexAxis: horizontal ? 'y' : 'x',
      responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f2418', padding: 10, cornerRadius: 6, displayColors: false } },
      scales: {
        x: { grid: { display: !horizontal ? false : true, color: '#eceae2' }, border: { display: false }, ticks: { color: '#6b7064', font: { size: 11 } } },
        y: { grid: { display: horizontal ? false : true, color: '#eceae2' }, border: { display: false }, ticks: { color: '#3c4237', font: { size: 11 } } }
      }
    };
  }

  function renderCharts(s) {
    if (!window.Chart) return;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    var ds = s.districts.slice(0, 14);
    var dOpts = baseChartOpts(true);
    dOpts.plugins.tooltip.callbacks = { label: function (c) { var d = ds[c.dataIndex]; return [nf1.format(d.area_ha) + ' ha', nf0.format(d.fields) + ' fields', nf0.format(d.yield_t) + ' t est. yield']; } };
    dOpts.scales.x.ticks.callback = function (v) { return compact(v); };
    dOpts.onClick = function (e, els) { if (els.length) setDistricts([ds[els[0].index].name]); };
    dOpts.onHover = function (e, els) { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; };
    var dData = { labels: ds.map(function (d) { return d.name; }), datasets: [{ data: ds.map(function (d) { return d.area_ha; }), backgroundColor: COLORS.green, hoverBackgroundColor: COLORS.gold, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }] };
    $('#districtChart').parentNode.style.height = Math.max(180, ds.length * 30 + 40) + 'px';
    if (charts.d) { charts.d.data = dData; charts.d.options = dOpts; charts.d.update(); } else { charts.d = new Chart($('#districtChart'), { type: 'bar', data: dData, options: dOpts }); }

    var bins = s.size_bins;
    var sOpts = baseChartOpts(false);
    sOpts.plugins.tooltip.callbacks = { title: function (c) { return bins[c[0].dataIndex].label; }, label: function (c) { var b = bins[c.dataIndex]; return [nf0.format(b.fields) + ' fields', nf1.format(b.area_ha) + ' ha in total']; } };
    sOpts.scales.y.ticks.callback = function (v) { return compact(v); };
    var sData = { labels: bins.map(function (b) { return b.label; }), datasets: [{ data: bins.map(function (b) { return b.fields; }), backgroundColor: COLORS.greenSoft, hoverBackgroundColor: COLORS.gold, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 44 }] };
    if (charts.s) { charts.s.data = sData; charts.s.options = sOpts; charts.s.update(); } else { charts.s = new Chart($('#sizeChart'), { type: 'bar', data: sData, options: sOpts }); }
  }

  function renderDistrictTable(s) {
    var total = s.kpi.area_ha || 1;
    $('#districtTable tbody').innerHTML = s.districts.map(function (d) {
      var pct = d.area_ha / total * 100;
      return '<tr data-d="' + esc(d.name) + '"><td><button type="button" class="linkbtn strong">' + esc(d.name) + '</button></td><td class="num">' + nf0.format(d.fields) + '</td><td class="num">' + nf1.format(d.area_ha) +
        '</td><td class="num">' + nf0.format(d.yield_t) + '</td><td class="num">' + nf2.format(d.avg_ha) + '</td><td class="num">' + (d.ndvi != null ? nf2.format(d.ndvi) : '—') +
        '</td><td class="bar-col"><span class="sharebar"><span style="width:' + pct.toFixed(1) + '%"></span></span><span class="small">' + nf1.format(pct) + '%</span></td></tr>';
    }).join('') || '<tr><td colspan="7" class="empty">No fields match these filters.</td></tr>';
    $('#districtTable tfoot').innerHTML = s.districts.length > 1 ? '<tr><th>Total</th><th class="num">' + nf0.format(s.kpi.fields) + '</th><th class="num">' + nf1.format(s.kpi.area_ha) + '</th><th class="num">' + nf0.format(s.kpi.yield_t) + '</th><th class="num">' + nf2.format(s.kpi.avg_ha || 0) + '</th><th class="num">' + (s.kpi.ndvi != null ? nf2.format(s.kpi.ndvi) : '—') + '</th><th></th></tr>' : '';
    $$('#districtTable tbody tr[data-d] button').forEach(function (b) {
      b.addEventListener('click', function () { setDistricts([b.closest('tr').dataset.d]); $('#map').scrollIntoView({ behavior: 'smooth', block: 'center' }); });
    });
  }

  /* ---------------- Field table ---------------- */

  var tableSeq = 0;
  function loadTable() {
    var seq = ++tableSeq;
    api('fields.php', filterParams({ page: state.page, per: state.per, sort: state.sort, dir: state.dir })).then(function (t) {
      if (seq !== tableSeq) return;
      $('#fieldTable tbody').innerHTML = t.rows.map(function (r) {
        return '<tr><td class="mono">' + esc(r.code) + '</td><td>' + esc(r.district) + '</td><td class="num">' + nf3.format(r.area_ha) + '</td><td class="num">' + nf2.format(r.yield_t) +
          '</td><td class="num">' + (r.ndvi != null ? nf3.format(r.ndvi) : '—') + '</td><td class="num">' + r.lat.toFixed(5) + '</td><td class="num">' + r.lon.toFixed(5) +
          '</td><td><button type="button" class="btn sm ghost" data-id="' + r.id + '">Show on map</button></td></tr>';
      }).join('') || '<tr><td colspan="8" class="empty">No fields match these filters.</td></tr>';
      var from = t.total ? (t.page - 1) * t.per + 1 : 0;
      $('#tableInfo').textContent = nf0.format(from) + '–' + nf0.format(Math.min(t.total, t.page * t.per)) + ' of ' + nf0.format(t.total);
      renderPager(t);
      $$('#fieldTable button[data-id]').forEach(function (b) { b.addEventListener('click', function () { zoomToField(+b.dataset.id); }); });
    }).catch(showError);
  }

  function renderPager(t) {
    var p = t.page, n = t.pages, html = '';
    function btn(i, label, dis) { return '<button type="button" class="btn sm' + (i === p ? ' primary' : ' ghost') + '" data-p="' + i + '"' + (dis ? ' disabled' : '') + '>' + label + '</button>'; }
    if (n > 1) {
      html += btn(p - 1, '‹ Prev', p <= 1);
      var pages = [1, p - 1, p, p + 1, n].filter(function (x, i, a) { return x >= 1 && x <= n && a.indexOf(x) === i; }).sort(function (a, b) { return a - b; });
      pages.forEach(function (x, i) { if (i && x - pages[i - 1] > 1) html += '<span class="gap">…</span>'; html += btn(x, nf0.format(x)); });
      html += btn(p + 1, 'Next ›', p >= n);
    }
    $('#pager').innerHTML = html;
    $$('#pager button').forEach(function (b) { b.addEventListener('click', function () { state.page = +b.dataset.p; loadTable(); }); });
  }

  /* ---------------- Map ---------------- */

  var map, popup, hoveredDistrict = null, polyAbort = null;
  var POLY_ZOOM = 11.5;

  function initMap() {
    map = new maplibregl.Map({
      container: 'map',
      attributionControl: false,
      center: [29.9, -17.3], zoom: 7, maxZoom: 18, minZoom: 5,
      style: {
        version: 8,
        sources: {
          satellite: { type: 'raster', tileSize: 256, maxzoom: 19, tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'], attribution: 'Imagery © Esri, Maxar, Earthstar Geographics' },
          labels: { type: 'raster', tileSize: 256, maxzoom: 19, tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'] },
          streets: { type: 'raster', tileSize: 256, maxzoom: 19, tiles: ['a', 'b', 'c', 'd'].map(function (s) { return 'https://' + s + '.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png'; }), attribution: '© OpenStreetMap contributors © CARTO' }
        },
        layers: [
          { id: 'bg', type: 'background', paint: { 'background-color': '#1d2a1f' } },
          { id: 'satellite', type: 'raster', source: 'satellite' },
          { id: 'streets', type: 'raster', source: 'streets', layout: { visibility: 'none' } }
        ]
      }
    });
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: false }), 'top-left');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');
    map.addControl(new maplibregl.FullscreenControl(), 'top-left');
    map.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: 'Boundaries: geoBoundaries (CC BY 4.0) · Fields: Sentinel-2 / Google Earth Engine' }), 'bottom-right');
    popup = new maplibregl.Popup({ closeButton: true, maxWidth: '300px', className: 'field-popup' });

    return new Promise(function (resolve) {
      map.on('load', function () {
        map.addSource('districts', { type: 'geojson', data: P.base + 'assets/data/zwe_districts.geojson', generateId: true });
        map.addSource('provinces', { type: 'geojson', data: P.base + 'assets/data/zwe_provinces.geojson' });
        map.addSource('points', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addSource('polys', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addSource('training', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addSource('selected', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

        map.addLayer({ id: 'district-fill', type: 'fill', source: 'districts', paint: {
          'fill-color': '#ffffff', 'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 0.08, 0] } });
        map.addLayer({ id: 'heat', type: 'heatmap', source: 'points', maxzoom: 11, paint: {
          'heatmap-weight': ['interpolate', ['linear'], ['get', 'a'], 0, 0.03, 2, 0.25, 10, 1],
          'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 5, 0.25, 8, 0.6, 10, 1.4],
          'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 5, 2, 8, 6, 10, 12],
          'heatmap-opacity': ['interpolate', ['linear'], ['zoom'], 8, 0.8, 10.5, 0],
          'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(0,0,0,0)', 0.08, 'rgba(26,152,80,0.45)', 0.3, 'rgba(47,191,85,0.8)', 0.6, '#6fdc5a', 0.85, '#b9ef5f', 1, '#f3f9a6'] } });
        map.addLayer({ id: 'points', type: 'circle', source: 'points', minzoom: 8, maxzoom: POLY_ZOOM, paint: {
          'circle-color': COLORS.field,
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, ['max', 1.2, ['*', 0.9, ['sqrt', ['get', 'a']]]], 11.5, ['max', 2.5, ['*', 2.6, ['sqrt', ['get', 'a']]]]],
          'circle-opacity': ['interpolate', ['linear'], ['zoom'], 8, 0, 9, 0.9],
          'circle-stroke-color': '#0b3d1a', 'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 9, 0, 11, 0.6] } });
        map.addLayer({ id: 'polys-fill', type: 'fill', source: 'polys', minzoom: POLY_ZOOM - 0.01, paint: { 'fill-color': COLORS.field, 'fill-opacity': 0.8 } });
        map.addLayer({ id: 'polys-line', type: 'line', source: 'polys', minzoom: 13, paint: { 'line-color': COLORS.fieldLine, 'line-width': 0.8 } });
        map.addLayer({ id: 'labels', type: 'raster', source: 'labels', paint: { 'raster-opacity': 0.9 } });
        map.addLayer({ id: 'district-line', type: 'line', source: 'districts', paint: {
          'line-color': '#ffffff', 'line-opacity': 0.55, 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 0.6, 12, 1.6], 'line-dasharray': [3, 2] } });
        map.addLayer({ id: 'province-line', type: 'line', source: 'provinces', paint: { 'line-color': '#FFD700', 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 1.6, 12, 3] } });
        map.addLayer({ id: 'training', type: 'circle', source: 'training', layout: { visibility: 'none' }, paint: {
          'circle-color': ['case', ['==', ['get', 'c'], 1], '#22c55e', '#ef4444'], 'circle-radius': ['interpolate', ['linear'], ['zoom'], 7, 3, 14, 7],
          'circle-stroke-color': '#ffffff', 'circle-stroke-width': 1.5 } });
        map.addLayer({ id: 'selected-line', type: 'line', source: 'selected', paint: { 'line-color': '#ffe14d', 'line-width': 3 } });

        bindMapEvents();
        resolve();
      });
    });
  }

  function bindMapEvents() {
    var tip = new maplibregl.Popup({ closeButton: false, closeOnClick: false, className: 'district-tip', offset: 12 });
    map.on('mousemove', 'district-fill', function (e) {
      if (!e.features.length) return;
      var f = e.features[0];
      if (hoveredDistrict !== null && hoveredDistrict !== f.id) map.setFeatureState({ source: 'districts', id: hoveredDistrict }, { hover: false });
      hoveredDistrict = f.id;
      map.setFeatureState({ source: 'districts', id: f.id }, { hover: true });
      if (map.getZoom() < POLY_ZOOM && !map.queryRenderedFeatures(e.point, { layers: ['points'] }).length) {
        var name = f.properties.name, d = state.stats && state.stats.districts.find(function (x) { return x.name === name; });
        tip.setLngLat(e.lngLat).setHTML('<strong>' + esc(name) + '</strong>' + (d ? '<br>' + nf1.format(d.area_ha) + ' ha · ' + nf0.format(d.fields) + ' fields' : '<br><span class="muted">no detected fields in filter</span>')).addTo(map);
      } else { tip.remove(); }
    });
    map.on('mouseleave', 'district-fill', function () {
      if (hoveredDistrict !== null) map.setFeatureState({ source: 'districts', id: hoveredDistrict }, { hover: false });
      hoveredDistrict = null; tip.remove();
    });
    ['points', 'polys-fill'].forEach(function (layer) {
      map.on('mouseenter', layer, function () { map.getCanvas().style.cursor = 'pointer'; tip.remove(); });
      map.on('mouseleave', layer, function () { map.getCanvas().style.cursor = ''; });
      map.on('click', layer, function (e) { showFieldPopup(e.features[0].properties.id, e.lngLat); });
    });
    map.on('moveend', debounce(loadPolygons, 250));
    map.on('zoom', function () { $('#mapHint').classList.toggle('hide', map.getZoom() >= POLY_ZOOM); });

    $$('#layerPanel [data-base]').forEach(function (b) {
      b.addEventListener('click', function () {
        $$('#layerPanel [data-base]').forEach(function (x) { x.classList.toggle('on', x === b); });
        var sat = b.dataset.base === 'satellite';
        map.setLayoutProperty('satellite', 'visibility', sat ? 'visible' : 'none');
        map.setLayoutProperty('labels', 'visibility', sat ? 'visible' : 'none');
        map.setLayoutProperty('streets', 'visibility', sat ? 'none' : 'visible');
      });
    });
    $$('#layerPanel [data-layer]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var vis = cb.checked ? 'visible' : 'none';
        ({ fields: ['points', 'polys-fill', 'polys-line'], heat: ['heat'], districts: ['district-line'], training: ['training'] })[cb.dataset.layer].forEach(function (l) { map.setLayoutProperty(l, 'visibility', vis); });
        if (cb.dataset.layer === 'training') {
          $('.leg-training').hidden = !cb.checked;
          if (cb.checked) loadTraining();
        }
      });
    });
  }

  function loadPoints() {
    if (state.pointsSeason === state.season) return Promise.resolve();
    $('#mapLoading').classList.add('show');
    $('#mapLoading').textContent = 'Loading fields…';
    return api('points.php', { season: state.season }).then(function (j) {
      state.pointsSeason = state.season;
      state.points = j.f;
      state.pointNames = j.d;
      var feats = new Array(j.f.length);
      for (var i = 0; i < j.f.length; i++) {
        var r = j.f[i];
        feats[i] = { type: 'Feature', geometry: { type: 'Point', coordinates: [r[1], r[2]] }, properties: { id: r[0], a: r[3], n: r[4], d: j.d[r[5]] } };
      }
      map.getSource('points').setData({ type: 'FeatureCollection', features: feats });
      map.getSource('training').setData({ type: 'FeatureCollection', features: [] });
      trainingLoaded = null;
      if ($('#layerPanel [data-layer="training"]').checked) loadTraining();
      applyMapFilter();
    }).catch(showError).then(function () { $('#mapLoading').classList.remove('show'); });
  }

  var trainingLoaded = null;
  function loadTraining() {
    if (trainingLoaded === state.season) return;
    trainingLoaded = state.season;
    api('training.php', { season: state.season }).then(function (g) { map.getSource('training').setData(g); }).catch(showError);
  }

  function mapFilterExpr() {
    var f = ['all'];
    var d = selectedDistricts();
    if (d) f.push(['in', ['get', 'd'], ['literal', d]]);
    if (state.min_area !== '') f.push(['>=', ['get', 'a'], +state.min_area]);
    if (state.max_area !== '') f.push(['<=', ['get', 'a'], +state.max_area]);
    if (state.min_ndvi !== '') f.push(['>=', ['coalesce', ['get', 'n'], -9], +state.min_ndvi]);
    if (state.max_ndvi !== '') f.push(['<=', ['coalesce', ['get', 'n'], 9], +state.max_ndvi]);
    return f.length > 1 ? f : null;
  }

  function applyMapFilter() {
    if (!map || !map.getLayer('points')) return;
    var f = mapFilterExpr();
    map.setFilter('points', f);
    map.setFilter('heat', f);
    var d = selectedDistricts();
    map.setPaintProperty('district-line', 'line-opacity', d ? ['case', ['in', ['get', 'name'], ['literal', d]], 1, 0.3] : 0.55);
  }

  var polySeq = 0;
  function loadPolygons() {
    if (!map || !map.getSource('polys')) return;
    if (map.getZoom() < POLY_ZOOM) { map.getSource('polys').setData({ type: 'FeatureCollection', features: [] }); return; }
    if (polyAbort) polyAbort.abort();
    polyAbort = new AbortController();
    var seq = ++polySeq;
    var b = map.getBounds();
    var params = filterParams({ bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map(function (x) { return x.toFixed(5); }).join(',') });
    $('#mapLoading').textContent = 'Loading field outlines…';
    $('#mapLoading').classList.add('show');
    fetch(P.base + 'api/geo.php?' + new URLSearchParams(params), { credentials: 'same-origin', signal: polyAbort.signal })
      .then(function (r) { return r.json(); })
      .then(function (g) {
        if (seq !== polySeq) return;
        map.getSource('polys').setData(g);
        $('#mapHint').textContent = g.truncated ? 'Showing the 8,000 largest fields in view — zoom in to see all' : 'Zoom in to see individual field outlines';
        $('#mapHint').classList.toggle('hide', !g.truncated);
      })
      .catch(function (e) { if (e.name !== 'AbortError') showError(e); })
      .then(function () { if (seq === polySeq) $('#mapLoading').classList.remove('show'); });
  }

  function boundsOf(points) {
    var b = [180, 90, -180, -90];
    points.forEach(function (r) { if (r[1] < b[0]) b[0] = r[1]; if (r[2] < b[1]) b[1] = r[2]; if (r[1] > b[2]) b[2] = r[1]; if (r[2] > b[3]) b[3] = r[2]; });
    return b[0] <= b[2] ? b : null;
  }

  function fitSeason() {
    var b = state.meta.bounds;
    if (b) map.fitBounds([[b[0], b[1]], [b[2], b[3]]], { padding: 30, duration: 600 });
  }

  function fitSelection() {
    if (!map || !state.points) return;
    var d = selectedDistricts();
    if (!d) { fitSeason(); return; }
    if (!d.length) return;
    var set = new Set(d);
    var b = boundsOf(state.points.filter(function (r) { return set.has(state.pointNames[r[5]]); }));
    if (b) map.fitBounds([[b[0], b[1]], [b[2], b[3]]], { padding: 40, duration: 700, maxZoom: 13 });
  }

  function showFieldPopup(id, lngLat) {
    api('field.php', { id: id }).then(function (f) {
      map.getSource('selected').setData({ type: 'Feature', geometry: f.geometry, properties: {} });
      var gm = 'https://www.google.com/maps?q=' + f.lat.toFixed(6) + ',' + f.lon.toFixed(6);
      popup.setLngLat(lngLat || [f.lon, f.lat]).setHTML(
        '<div class="fp"><div class="fp-head"><span class="fp-tag">Detected tobacco</span><strong class="mono">' + esc(f.code) + '</strong></div>' +
        '<dl><dt>District</dt><dd>' + esc(f.district) + ', ' + esc(f.province) + '</dd>' +
        '<dt>Area</dt><dd>' + nf3.format(f.area_ha) + ' ha</dd>' +
        '<dt>Est. yield</dt><dd>' + nf2.format(f.yield_t) + ' t <span class="muted">(@ ' + f.benchmark + ' t/ha)</span></dd>' +
        '<dt>Mean NDVI</dt><dd>' + (f.ndvi != null ? nf3.format(f.ndvi) : '—') + '</dd>' +
        '<dt>Centre</dt><dd class="mono">' + f.lat.toFixed(5) + ', ' + f.lon.toFixed(5) + '</dd></dl>' +
        '<div class="fp-actions"><button type="button" class="btn sm" id="fpZoom">Zoom to field</button><a class="btn sm ghost" target="_blank" rel="noopener" href="' + gm + '">Google Maps ↗</a></div></div>'
      ).addTo(map);
      var z = document.getElementById('fpZoom');
      if (z) z.addEventListener('click', function () { map.fitBounds([[f.bbox[0], f.bbox[1]], [f.bbox[2], f.bbox[3]]], { padding: 80, maxZoom: 17 }); });
    }).catch(showError);
  }

  function zoomToField(id) {
    api('field.php', { id: id }).then(function (f) {
      $('#map').scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.fitBounds([[f.bbox[0], f.bbox[1]], [f.bbox[2], f.bbox[3]]], { padding: 120, maxZoom: 16, duration: 900 });
      map.once('moveend', function () { showFieldPopup(id, [f.lon, f.lat]); });
    }).catch(showError);
  }

  function showError(e) {
    if (!e || e.message === 'signed out') return;
    var t = $('#toast') || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'toast', className: 'toast' }));
    t.textContent = e.message || String(e);
    t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 5000);
    console.error(e);
  }

  /* ---------------- Boot ---------------- */

  bindFilters();
  var mapReady = initMap();
  loadMeta(state.season).then(function () {
    refresh(false);
    return mapReady.then(function () { fitSeason(); return loadPoints(); });
  }).catch(showError);
})();
