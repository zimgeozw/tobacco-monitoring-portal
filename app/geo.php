<?php
/**
 * Geometry helpers: compact GEE geometries, bounding boxes, point-in-polygon
 * against Zimbabwe province/district boundaries (geoBoundaries, CC BY 4.0).
 */
declare(strict_types=1);

/** Round coordinates recursively to $dp decimals (6 dp ≈ 0.1 m). */
function round_coords($c, int $dp = 6)
{
    if (is_array($c) && isset($c[0]) && !is_array($c[0])) {
        return [round((float)$c[0], $dp), round((float)$c[1], $dp)];
    }
    return array_map(fn($x) => round_coords($x, $dp), (array)$c);
}

/** Walk all [lon,lat] pairs and return [minlon, minlat, maxlon, maxlat]. */
function coords_bbox($c, array &$b = null): array
{
    if ($b === null) {
        $b = [INF, INF, -INF, -INF];
    }
    if (isset($c[0]) && !is_array($c[0])) {
        $b[0] = min($b[0], $c[0]); $b[1] = min($b[1], $c[1]);
        $b[2] = max($b[2], $c[0]); $b[3] = max($b[3], $c[1]);
        return $b;
    }
    foreach ($c as $x) {
        coords_bbox($x, $b);
    }
    return $b;
}

/**
 * Parse a GEE ".geo" cell into compact GeoJSON. Returns [json, bbox] or null.
 */
function compact_geom(string $geo): ?array
{
    $g = json_decode($geo, true);
    if (!is_array($g) || empty($g['type']) || empty($g['coordinates'])) {
        return null;
    }
    if (!in_array($g['type'], ['Polygon', 'MultiPolygon'], true)) {
        return null;
    }
    $coords = round_coords($g['coordinates']);
    $bbox = coords_bbox($coords);
    return [json_encode(['type' => $g['type'], 'coordinates' => $coords], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $bbox];
}

function ring_contains(array $ring, float $x, float $y): bool
{
    $in = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][0]; $yi = $ring[$i][1];
        $xj = $ring[$j][0]; $yj = $ring[$j][1];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
            $in = !$in;
        }
    }
    return $in;
}

function polygon_contains(array $poly, float $x, float $y): bool
{
    if (!ring_contains($poly[0], $x, $y)) {
        return false;
    }
    for ($h = 1, $n = count($poly); $h < $n; $h++) {
        if (ring_contains($poly[$h], $x, $y)) {
            return false;
        }
    }
    return true;
}

/** Load a boundary file as [[name, bbox, polygons[]], ...]. */
function load_boundaries(string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $out = [];
    $fc = json_decode((string)@file_get_contents(APP_ROOT . '/assets/data/' . $file), true);
    foreach ($fc['features'] ?? [] as $f) {
        $g = $f['geometry'];
        $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
        $out[] = [$f['properties']['name'], coords_bbox($g['coordinates']), $polys];
    }
    return $cache[$file] = $out;
}

/** Name of the boundary containing the point, or null. */
function locate_in(string $file, float $lon, float $lat): ?string
{
    static $last = [];
    $all = load_boundaries($file);
    // Rows tend to be spatially grouped, so try the previous hit first
    $order = isset($last[$file]) ? array_merge([$last[$file]], array_keys($all)) : array_keys($all);
    foreach ($order as $k) {
        [$name, $b, $polys] = $all[$k];
        if ($lon < $b[0] || $lon > $b[2] || $lat < $b[1] || $lat > $b[3]) {
            continue;
        }
        foreach ($polys as $p) {
            if (polygon_contains($p, $lon, $lat)) {
                $last[$file] = $k;
                return $name;
            }
        }
    }
    return null;
}
