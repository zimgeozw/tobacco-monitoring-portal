<?php
/** Season list + filter metadata (districts, value ranges) for the dashboard. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

$seasons = array_map(fn($s) => [
    'id' => (int)$s['id'], 'code' => $s['code'], 'title' => $s['title'], 'status' => $s['status'],
], visible_seasons());

if (!$seasons) {
    json_out(['seasons' => [], 'season' => null]);
}
[$where, $params, , $season] = build_filter(['season' => $_GET['season'] ?? 0]);
$r = q1("SELECT MIN(area_ha) amin, MAX(area_ha) amax, MIN(ndvi_mean) nmin, MAX(ndvi_mean) nmax,
               MIN(minlon) x0, MIN(minlat) y0, MAX(maxlon) x1, MAX(maxlat) y1 FROM fields f WHERE $where", $params);
$districts = q("SELECT district, COUNT(*) n, SUM(area_ha) ha FROM fields f WHERE $where GROUP BY district ORDER BY district", $params)->fetchAll();

json_out([
    'seasons' => $seasons,
    'season' => [
        'id' => (int)$season['id'], 'code' => $season['code'], 'title' => $season['title'],
        'description' => $season['description'], 'province' => $season['province'],
        'start_date' => $season['start_date'], 'end_date' => $season['end_date'],
        'status' => $season['status'],
        'accuracy' => $season['classifier_accuracy'] !== null ? (float)$season['classifier_accuracy'] : null,
        'images_used' => $season['images_used'] !== null ? (int)$season['images_used'] : null,
        'training_points' => $season['training_points'] !== null ? (int)$season['training_points'] : null,
        'yield_benchmark' => (float)$season['yield_benchmark_t_ha'],
        'min_field_ha' => $season['min_field_ha'] !== null ? (float)$season['min_field_ha'] : null,
        'version' => (int)$season['data_version'],
        'has_training' => (bool)qv('SELECT 1 FROM training_points WHERE season_id = ? LIMIT 1', [$season['id']]),
        'basemap_url' => $season['basemap_url'] ?: null,
        'basemap_maxzoom' => $season['basemap_maxzoom'] !== null ? (int)$season['basemap_maxzoom'] : null,
        'default_view' => $season['default_view'] ? json_decode($season['default_view'], true) : null,
    ],
    'districts' => array_map(fn($d) => ['name' => $d['district'], 'fields' => (int)$d['n'], 'area_ha' => (float)$d['ha']], $districts),
    'ranges' => ['area' => [(float)$r['amin'], (float)$r['amax']], 'ndvi' => [(float)$r['nmin'], (float)$r['nmax']]],
    'bounds' => $r['x0'] !== null ? [(float)$r['x0'], (float)$r['y0'], (float)$r['x1'], (float)$r['y1']] : null,
    'restricted' => allowed_districts(),
    'can_download' => can_download(),
]);
