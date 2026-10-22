<?php
/** Field polygons inside the map view (used at close zoom). Returns GeoJSON. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

[$where, $params] = build_filter($_GET);
$b = array_map('floatval', explode(',', (string)($_GET['bbox'] ?? '')));
if (count($b) !== 4) {
    json_out(['error' => 'bbox required'], 400);
}
// Pad by ~1 km so fields straddling the edge are included
$pad = 0.01;
$where .= ' AND f.lon BETWEEN ? AND ? AND f.lat BETWEEN ? AND ?';
array_push($params, $b[0] - $pad, $b[2] + $pad, $b[1] - $pad, $b[3] + $pad);
$limit = 8000;

$st = q("SELECT f.id, f.field_code, f.district, f.area_ha, f.est_yield_t, f.ndvi_mean, f.geom FROM fields f WHERE $where ORDER BY f.area_ha DESC LIMIT " . ($limit + 1), $params);

if (!ob_start('ob_gzhandler')) {
    ob_start();
}
header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: private, max-age=300');
echo '{"type":"FeatureCollection","features":[';
$i = 0;
$truncated = false;
while ($r = $st->fetch()) {
    if ($i >= $limit) {
        $truncated = true;
        break;
    }
    echo ($i++ ? ',' : ''), '{"type":"Feature","id":', (int)$r['id'], ',"properties":',
        json_encode(['id' => (int)$r['id'], 'c' => $r['field_code'], 'd' => $r['district'], 'a' => round((float)$r['area_ha'], 3),
            'y' => round((float)$r['est_yield_t'], 2), 'n' => $r['ndvi_mean'] !== null ? round((float)$r['ndvi_mean'], 3) : null], JSON_UNESCAPED_UNICODE),
        ',"geometry":', $r['geom'], '}';
}
echo '],"truncated":', $truncated ? 'true' : 'false', '}';
ob_end_flush();
