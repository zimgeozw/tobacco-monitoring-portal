<?php
/**
 * Download the filtered fields as Excel (.xlsx), CSV or GeoJSON.
 * Excel workbook: Fields (with AutoFilter), District summary, Field sizes, About.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require APP_ROOT . '/app/xlsx.php';
$user = require_login();
if (!can_download()) {
    json_out(['error' => 'Your account does not have download permission.'], 403);
}
@set_time_limit(300);
@ini_set('memory_limit', '512M');

[$where, $params, $norm, $season] = build_filter($_GET);
$format = in_array($_GET['format'] ?? 'xlsx', ['xlsx', 'csv', 'geojson'], true) ? $_GET['format'] : 'xlsx';
$base = 'TIMB_Tobacco_Fields_' . preg_replace('/[^A-Za-z0-9]+/', '_', $season['code'])
    . (!empty($norm['district']) && count($norm['district']) <= 3 ? '_' . preg_replace('/[^A-Za-z0-9]+/', '_', implode('_', $norm['district'])) : '')
    . '_' . date('Ymd');
$count = (int)qv("SELECT COUNT(*) FROM fields f WHERE $where", $params);
audit('export', strtoupper($format) . ' · ' . $season['code'] . ' · ' . $count . ' fields' . (!empty($norm['district']) ? ' · ' . implode(', ', $norm['district']) : ''));

// Unbuffered query so large exports stream without loading everything into memory
db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$cols = $format === 'geojson' ? 'f.*' : 'f.id, f.field_code, f.province, f.district, f.district_gee, f.area_ha, f.est_yield_t, f.ndvi_mean, f.lat, f.lon';
$st = q("SELECT $cols FROM fields f WHERE $where ORDER BY f.district, f.area_ha DESC", $params);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Field ID', 'Season', 'Province', 'District', 'Area (ha)', 'Estimated yield (t)', 'Mean NDVI', 'Latitude', 'Longitude'], ',', '"', '');
    while ($r = $st->fetch()) {
        fputcsv($out, [$r['field_code'], $season['code'], $r['province'], $r['district'], round((float)$r['area_ha'], 4),
            round((float)$r['est_yield_t'], 3), $r['ndvi_mean'] !== null ? round((float)$r['ndvi_mean'], 4) : '', $r['lat'], $r['lon']], ',', '"', '');
    }
    exit;
}

if ($format === 'geojson') {
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.geojson"');
    echo '{"type":"FeatureCollection","name":"' . $base . '","features":[';
    $i = 0;
    while ($r = $st->fetch()) {
        echo ($i++ ? ",\n" : "\n"), '{"type":"Feature","properties":', json_encode([
            'field_id' => $r['field_code'], 'season' => $season['code'], 'province' => $r['province'], 'district' => $r['district'],
            'area_ha' => round((float)$r['area_ha'], 4), 'est_yield_t' => round((float)$r['est_yield_t'], 3),
            'ndvi_mean' => $r['ndvi_mean'] !== null ? round((float)$r['ndvi_mean'], 4) : null,
        ], JSON_UNESCAPED_UNICODE), ',"geometry":', $r['geom'], '}';
    }
    echo "\n]}";
    exit;
}

// ---- Excel ----
$x = new XlsxWriter();
$x->creator = 'Zimgeo';
$fs = $x->addSheet('Fields', [
    ['Field ID', 16, 's'], ['Season', 10, 's'], ['Province', 18, 's'], ['District', 18, 's'],
    ['Area (ha)', 11, 'n3'], ['Estimated yield (t)', 18, 'n3'], ['Mean NDVI', 11, 'n3'],
    ['Latitude', 12, 'n'], ['Longitude', 12, 'n'], ['Map', 11, 'link'],
]);
while ($r = $st->fetch()) {
    $x->addRow($fs, [$r['field_code'], $season['code'], $r['province'], $r['district'], round((float)$r['area_ha'], 4),
        round((float)$r['est_yield_t'], 3), $r['ndvi_mean'] !== null ? round((float)$r['ndvi_mean'], 4) : null,
        round((float)$r['lat'], 6), round((float)$r['lon'], 6),
        'https://www.google.com/maps?q=' . round((float)$r['lat'], 6) . ',' . round((float)$r['lon'], 6)]);
}
$st->closeCursor();
db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

$ds = $x->addSheet('District summary', [['District', 22, 's'], ['Fields', 10, 'n0'], ['Area (ha)', 14, 'n2'], ['Estimated yield (t)', 18, 'n2'],
    ['Average field (ha)', 16, 'n3'], ['Mean NDVI', 11, 'n3'], ['Share of area', 13, 'n3']]);
$sum = district_summary($where, $params);
$totalHa = array_sum(array_column($sum, 'area_ha')) ?: 1;
foreach ($sum as $d) {
    $x->addRow($ds, [$d['district'], (int)$d['fields'], (float)$d['area_ha'], (float)$d['yield_t'], (float)$d['avg_ha'],
        $d['ndvi'] !== null ? (float)$d['ndvi'] : null, round($d['area_ha'] / $totalHa, 4)]);
}
$x->addRow($ds, ['TOTAL', array_sum(array_column($sum, 'fields')), $totalHa, array_sum(array_column($sum, 'yield_t')), null, null, 1]);

$bs = $x->addSheet('Field sizes', [['Size class', 14, 's'], ['Fields', 10, 'n0'], ['Area (ha)', 14, 'n2']], false);
foreach (SIZE_BINS as [$lo, $hi, $label]) {
    $r = q1("SELECT COUNT(*) n, COALESCE(SUM(area_ha),0) ha FROM fields f WHERE $where AND f.area_ha >= ? AND f.area_ha < ?", array_merge($params, [$lo, $hi]));
    $x->addRow($bs, [$label, (int)$r['n'], (float)$r['ha']]);
}

$as = $x->addSheet('About', [['Item', 28, 's'], ['Value', 70, 's']], false);
$about = array_merge(filter_description($norm, $season), [
    ['Imagery period', fmt_date($season['start_date']) . ' – ' . fmt_date($season['end_date'])],
    ['Sensor', 'Copernicus Sentinel-2 (surface reflectance, 10 m), cloud-masked median composite'],
    ['Classifier', 'Random Forest (100 trees)' . ($season['classifier_accuracy'] !== null ? ' · accuracy ' . round($season['classifier_accuracy'] * 100, 1) . '%' : '')],
    ['Yield method', 'Area × ' . (float)$season['yield_benchmark_t_ha'] . ' t/ha benchmark'],
    ['Fields exported', fmt_num($count)],
    ['Exported by', $user['name'] . ' (' . $user['email'] . ')'],
    ['Exported on', date('j M Y H:i')],
    ['Note', 'Estimates are derived from satellite classification and should be verified on the ground.'],
    ['Portal', 'Designed by Zimgeo — www.zimgeo.co.zw'],
]);
foreach ($about as $row) {
    $x->addRow($as, $row);
}

$tmp = STORAGE . '/cache/' . $base . '_' . bin2hex(random_bytes(4)) . '.xlsx';
$x->save($tmp);
$x->cleanup();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
