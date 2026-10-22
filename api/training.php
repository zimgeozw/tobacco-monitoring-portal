<?php
/** Training / validation points (tobacco = 1, non-tobacco = 0) as GeoJSON. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

[, , , $season] = build_filter(['season' => $_GET['season'] ?? 0]);
$rows = q('SELECT class, lon, lat FROM training_points WHERE season_id = ?', [$season['id']])->fetchAll(PDO::FETCH_NUM);
$features = array_map(fn($r) => ['type' => 'Feature', 'properties' => ['c' => (int)$r[0]],
    'geometry' => ['type' => 'Point', 'coordinates' => [(float)$r[1], (float)$r[2]]]], $rows);
json_out(['type' => 'FeatureCollection', 'features' => $features]);
