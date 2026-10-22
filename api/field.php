<?php
/** One field with geometry (for the map popup / zoom). */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

$f = q1('SELECT f.*, s.title season_title, s.status, s.yield_benchmark_t_ha FROM fields f JOIN seasons s ON s.id = f.season_id WHERE f.id = ?', [(int)($_GET['id'] ?? 0)]);
$allowed = allowed_districts();
if (!$f || ($f['status'] !== 'published' && !is_admin()) || ($allowed !== null && !in_array($f['district'], $allowed, true))) {
    json_out(['error' => 'Field not found.'], 404);
}
json_out([
    'id' => (int)$f['id'], 'code' => $f['field_code'], 'season' => $f['season_title'],
    'province' => $f['province'], 'district' => $f['district'], 'district_gee' => $f['district_gee'],
    'area_ha' => (float)$f['area_ha'], 'yield_t' => (float)$f['est_yield_t'], 'benchmark' => (float)$f['yield_benchmark_t_ha'],
    'ndvi' => $f['ndvi_mean'] !== null ? (float)$f['ndvi_mean'] : null,
    'lon' => (float)$f['lon'], 'lat' => (float)$f['lat'],
    'bbox' => [(float)$f['minlon'], (float)$f['minlat'], (float)$f['maxlon'], (float)$f['maxlat']],
    'geometry' => json_decode($f['geom'], true),
]);
