<?php
/** Paginated, sortable field table. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/data.php';
require_login();

[$where, $params] = build_filter($_GET);
$per = max(10, min(200, (int)($_GET['per'] ?? 25)));
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = SORTABLE[$_GET['sort'] ?? 'area_ha'] ?? 'f.area_ha';
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$total = (int)qv("SELECT COUNT(*) FROM fields f WHERE $where", $params);
$rows = q("SELECT f.id, f.field_code, f.province, f.district, f.area_ha, f.est_yield_t, f.ndvi_mean, f.lon, f.lat
           FROM fields f WHERE $where ORDER BY $sort $dir, f.id ASC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();

json_out([
    'total' => $total, 'page' => $page, 'per' => $per, 'pages' => (int)ceil($total / $per),
    'rows' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'code' => $r['field_code'], 'province' => $r['province'], 'district' => $r['district'],
        'area_ha' => (float)$r['area_ha'], 'yield_t' => (float)$r['est_yield_t'],
        'ndvi' => $r['ndvi_mean'] !== null ? (float)$r['ndvi_mean'] : null, 'lon' => (float)$r['lon'], 'lat' => (float)$r['lat'],
    ], $rows),
]);
