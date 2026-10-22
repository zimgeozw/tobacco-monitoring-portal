<?php
/** KPIs, district breakdown and field-size distribution for the current filters. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

[$where, $params, $norm, $season] = build_filter($_GET);

$k = q1("SELECT COUNT(*) n, COALESCE(SUM(area_ha),0) ha, COALESCE(SUM(est_yield_t),0) y,
                AVG(area_ha) avg_ha, AVG(ndvi_mean) ndvi, MAX(area_ha) max_ha FROM fields f WHERE $where", $params);

$cases = [];
foreach (SIZE_BINS as $i => [$lo, $hi]) {
    $cases[] = "SUM(CASE WHEN f.area_ha >= $lo AND f.area_ha < $hi THEN 1 ELSE 0 END) b$i";
    $cases[] = "SUM(CASE WHEN f.area_ha >= $lo AND f.area_ha < $hi THEN f.area_ha ELSE 0 END) h$i";
}
$h = q1('SELECT ' . implode(',', $cases) . " FROM fields f WHERE $where", $params);
$bins = [];
foreach (SIZE_BINS as $i => [, , $label]) {
    $bins[] = ['label' => $label, 'fields' => (int)$h["b$i"], 'area_ha' => (float)$h["h$i"]];
}

json_out([
    'filters' => $norm,
    'kpi' => [
        'fields' => (int)$k['n'],
        'area_ha' => (float)$k['ha'],
        'yield_t' => (float)$k['y'],
        'avg_ha' => (float)$k['avg_ha'],
        'max_ha' => (float)$k['max_ha'],
        'ndvi' => $k['ndvi'] !== null ? (float)$k['ndvi'] : null,
        'season_area_ha' => (float)$season['total_ha'],
    ],
    'districts' => array_map(fn($d) => [
        'name' => $d['district'], 'fields' => (int)$d['fields'], 'area_ha' => (float)$d['area_ha'],
        'yield_t' => (float)$d['yield_t'], 'avg_ha' => (float)$d['avg_ha'], 'ndvi' => $d['ndvi'] !== null ? (float)$d['ndvi'] : null,
    ], district_summary($where, $params)),
    'size_bins' => $bins,
]);
