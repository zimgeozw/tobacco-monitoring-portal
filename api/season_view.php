<?php
/** Save the dashboard map's current position as a season's default view (admin). */
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();
csrf_check();

$id = (int)post('season');
$lon = (float)post('lon');
$lat = (float)post('lat');
$zoom = (float)post('zoom');
if (!q1('SELECT id FROM seasons WHERE id = ?', [$id]) || $lon < 20 || $lon > 40 || $lat < -30 || $lat > -10 || $zoom < 4 || $zoom > 19) {
    json_out(['error' => 'Invalid view.'], 400);
}
$view = ['lon' => round($lon, 6), 'lat' => round($lat, 6), 'zoom' => round($zoom, 2)];
q('UPDATE seasons SET default_view = ? WHERE id = ?', [json_encode($view), $id]);
audit('default_view', "season #$id → " . json_encode($view));
json_out(['ok' => true, 'view' => $view]);
