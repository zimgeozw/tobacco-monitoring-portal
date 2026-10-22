<?php
/**
 * Compact field centroids for the whole season (map dots & heatmap).
 * Cached per season data version and per district restriction.
 * Format: {d:[district names], f:[[id, lon, lat, area_ha, ndvi, districtIndex], ...]}
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

[$where, $params, , $season] = build_filter(['season' => $_GET['season'] ?? 0]);
$allowed = allowed_districts();
$key = $season['id'] . '_v' . $season['data_version'] . '_' . ($allowed ? substr(md5(json_encode($allowed)), 0, 10) : 'all');
$file = STORAGE . '/cache/points_' . $key . '.json.gz';

if (!is_file($file)) {
    $names = [];
    $rows = [];
    $st = q("SELECT f.id, f.lon, f.lat, f.area_ha, f.ndvi_mean, f.district FROM fields f WHERE $where", $params);
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        if (!isset($names[$r[5]])) {
            $names[$r[5]] = count($names);
        }
        $rows[] = '[' . $r[0] . ',' . round((float)$r[1], 5) . ',' . round((float)$r[2], 5) . ',' . round((float)$r[3], 3) . ','
            . ($r[4] === null ? 'null' : round((float)$r[4], 3)) . ',' . $names[$r[5]] . ']';
    }
    $json = '{"d":' . json_encode(array_keys($names), JSON_UNESCAPED_UNICODE) . ',"f":[' . implode(',', $rows) . ']}';
    file_put_contents($file . '.tmp', gzencode($json, 6));
    rename($file . '.tmp', $file);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=86400');
header('ETag: "' . $key . '"');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $key . '"') {
    http_response_code(304);
    exit;
}
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
    header('Content-Encoding: gzip');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} else {
    echo gzdecode((string)file_get_contents($file));
}
