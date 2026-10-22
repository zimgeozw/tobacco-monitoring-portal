<?php
/**
 * Dashboard — TIMB Tobacco Crop Monitoring Portal.
 * Designed by Zimgeo — www.zimgeo.co.zw
 */
require __DIR__ . '/app/bootstrap.php';
$u = require_login();
$seasons = visible_seasons();

page_header('Dashboard', 'dashboard', [
    'head' => '<link rel="stylesheet" href="' . asset('vendor/maplibre/maplibre-gl.css') . '">',
    'main_class' => 'dash-main-wrap',
]);

if (!$seasons): ?>
<div class="container narrow">
  <section class="card empty-state">
    <h1>No data published yet</h1>
    <?php if (is_admin()): ?>
      <p>Create a season heading and upload the Earth Engine export to get started.</p>
      <a class="btn primary" href="<?= url('admin/seasons.php?new=1') ?>">Create first season</a>
    <?php else: ?>
      <p>The administrator has not published any season yet. You will receive an email when data is available.</p>
    <?php endif; ?>
  </section>
</div>
<?php page_footer(); exit; endif; ?>

<section class="season-banner">
  <div class="season-text">
    <span class="eyebrow" id="seasonEyebrow">Satellite crop monitoring</span>
    <h1 id="seasonTitle">Loading…</h1>
    <p id="seasonMeta" class="season-meta"></p>
    <p id="seasonNote" class="season-note" hidden></p>
  </div>
  <label class="season-pick">
    <span>Season</span>
    <select id="seasonSel" aria-label="Season"></select>
  </label>
</section>

<div class="dash">
  <aside class="filters" id="filters" aria-label="Filters">
    <div class="filters-head">
      <h2>Filters</h2>
      <button type="button" class="linkbtn" id="resetBtn">Reset</button>
    </div>

    <div class="fgroup">
      <div class="fgroup-head"><h3>Districts</h3><span class="muted small" id="districtCount"></span></div>
      <input type="search" id="districtSearch" placeholder="Find a district" aria-label="Find a district">
      <div class="district-list" id="districtList" role="group" aria-label="Districts"></div>
      <div class="fquick"><button type="button" class="linkbtn" id="allDistricts">All</button><button type="button" class="linkbtn" id="noDistricts">None</button></div>
    </div>

    <div class="fgroup">
      <h3>Field size (ha)</h3>
      <div class="range2">
        <input type="number" step="0.05" min="0" id="minArea" placeholder="min" aria-label="Minimum field size">
        <span>to</span>
        <input type="number" step="0.05" min="0" id="maxArea" placeholder="max" aria-label="Maximum field size">
      </div>
      <div class="presets" id="sizePresets">
        <button type="button" data-min="" data-max="1">Small &lt; 1</button>
        <button type="button" data-min="1" data-max="5">1 – 5</button>
        <button type="button" data-min="5" data-max="">Large &gt; 5</button>
      </div>
    </div>

    <div class="fgroup">
      <h3>Crop vigour (mean NDVI)</h3>
      <div class="range2">
        <input type="number" step="0.05" min="-1" max="1" id="minNdvi" placeholder="min" aria-label="Minimum NDVI">
        <span>to</span>
        <input type="number" step="0.05" min="-1" max="1" id="maxNdvi" placeholder="max" aria-label="Maximum NDVI">
      </div>
    </div>

    <div class="fgroup">
      <h3>Field ID</h3>
      <input type="search" id="fieldSearch" placeholder="e.g. F2526-0001234" aria-label="Search field ID">
    </div>

    <div class="fgroup downloads" id="downloads" hidden>
      <h3>Download filtered data</h3>
      <p class="muted small" id="dlCount"></p>
      <button type="button" class="btn primary block" data-format="xlsx">⬇ Excel workbook (.xlsx)</button>
      <div class="dl-row">
        <button type="button" class="btn sm" data-format="csv">CSV</button>
        <button type="button" class="btn sm" data-format="geojson">GeoJSON (GIS)</button>
      </div>
      <p class="muted xsmall">Excel includes Fields (with filter dropdowns), District summary, Field sizes and an About sheet.</p>
    </div>
  </aside>

  <div class="dash-body">
    <div class="kpis" id="kpis" aria-live="polite">
      <?php foreach (['Detected tobacco area', 'Detected fields', 'Estimated yield', 'Average field size', 'Classifier accuracy'] as $k): ?>
        <div class="kpi skeleton"><span class="kpi-label"><?= $k ?></span><span class="kpi-value">—</span><span class="kpi-sub">&nbsp;</span></div>
      <?php endforeach; ?>
    </div>

    <section class="card flush map-card">
      <div id="map" aria-label="Map of detected tobacco fields"></div>
      <div class="map-panel" id="layerPanel">
        <div class="seg" role="radiogroup" aria-label="Basemap">
          <button type="button" class="on" data-base="satellite">Satellite</button>
          <button type="button" data-base="streets">Streets</button>
        </div>
        <label class="check sm"><input type="checkbox" data-layer="fields" checked> Tobacco fields</label>
        <label class="check sm"><input type="checkbox" data-layer="heat" checked> Density heatmap</label>
        <label class="check sm"><input type="checkbox" data-layer="districts" checked> District boundaries</label>
        <label class="check sm" id="trainingToggle" hidden><input type="checkbox" data-layer="training"> Training points</label>
      </div>
      <div class="map-legend" id="legend">
        <div><span class="sw sw-field"></span>Detected tobacco</div>
        <div><span class="sw sw-none"></span>Not detected (imagery)</div>
        <div class="leg-training" hidden><span class="dot dot-t"></span>Tobacco point <span class="dot dot-n"></span>Non-tobacco point</div>
        <div><span class="sw sw-prov"></span>Province</div>
      </div>
      <div class="map-hint" id="mapHint">Zoom in to see individual field outlines</div>
      <div class="map-loading" id="mapLoading">Loading fields…</div>
    </section>

    <div class="grid-2">
      <section class="card">
        <div class="card-head"><h2>Tobacco area by district</h2><span class="muted small">hectares · click a bar to filter</span></div>
        <div class="chart-box"><canvas id="districtChart" aria-label="Tobacco area by district"></canvas></div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Field size distribution</h2><span class="muted small">number of fields</span></div>
        <div class="chart-box"><canvas id="sizeChart" aria-label="Field size distribution"></canvas></div>
      </section>
    </div>

    <section class="card flush">
      <div class="card-head pad"><h2>District summary</h2></div>
      <div class="table-wrap">
        <table class="table" id="districtTable">
          <thead><tr><th>District</th><th class="num">Fields</th><th class="num">Area (ha)</th><th class="num">Est. yield (t)</th><th class="num">Avg field (ha)</th><th class="num">Mean NDVI</th><th class="bar-col">Share of area</th></tr></thead>
          <tbody></tbody><tfoot></tfoot>
        </table>
      </div>
    </section>

    <section class="card flush">
      <div class="card-head pad">
        <h2>Fields</h2>
        <div class="table-tools">
          <span class="muted small" id="tableInfo"></span>
          <select id="perPage" aria-label="Rows per page"><option>25</option><option>50</option><option>100</option></select>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table sortable" id="fieldTable">
          <thead><tr>
            <th data-sort="field_code">Field ID</th><th data-sort="district">District</th>
            <th data-sort="area_ha" class="num sorted desc">Area (ha)</th><th data-sort="est_yield_t" class="num">Est. yield (t)</th>
            <th data-sort="ndvi_mean" class="num">Mean NDVI</th><th data-sort="lat" class="num">Lat</th><th data-sort="lon" class="num">Lon</th><th></th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="pager pad" id="pager"></div>
    </section>
  </div>
</div>

<script>
window.PORTAL = <?= json_encode([
    'base' => url(''),
    'season' => (int)($_GET['season'] ?? 0),
    'isAdmin' => is_admin(),
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php page_footer([asset('vendor/maplibre/maplibre-gl.js'), asset('vendor/chartjs/chart.umd.js'), asset('js/dashboard.js')]);
