<?php
/**
 * Filtering shared by the dashboard APIs and exports.
 * Filters: season, district[], min_area, max_area, min_ndvi, max_ndvi, q (field ID).
 * User district restrictions are always enforced here.
 */
declare(strict_types=1);

const SIZE_BINS = [[0, 0.25, '< 0.25 ha'], [0.25, 0.5, '0.25–0.5'], [0.5, 1, '0.5–1'], [1, 2, '1–2'], [2, 5, '2–5'], [5, 10, '5–10'], [10, 1e9, '> 10 ha']];

/** @return array{0:string,1:array,2:array,3:array} [where, params, normalized filters, season] */
function build_filter(array $src): array
{
    $sid = (int)($src['season'] ?? 0) ?: default_season_id();
    $season = $sid ? season_for_user($sid) : null;
    if (!$season) {
        json_out(['error' => 'Season not found or not published.'], 404);
    }
    $where = ['f.season_id = ?'];
    $params = [(int)$season['id']];
    $norm = ['season' => (int)$season['id']];

    $districts = $src['district'] ?? [];
    if (is_string($districts)) {
        $districts = $districts === '' ? [] : explode('|', $districts);
    }
    $districts = array_values(array_filter(array_map('trim', (array)$districts), 'strlen'));
    $allowed = allowed_districts();
    if ($allowed !== null) {
        $districts = $districts ? array_values(array_intersect($districts, $allowed)) : $allowed;
        if (!$districts) {
            $districts = ['__none__'];
        }
    }
    if ($districts) {
        $where[] = 'f.district IN (' . implode(',', array_fill(0, count($districts), '?')) . ')';
        array_push($params, ...$districts);
        $norm['district'] = $districts;
    }
    foreach ([['min_area', 'f.area_ha >= ?'], ['max_area', 'f.area_ha <= ?'], ['min_ndvi', 'f.ndvi_mean >= ?'], ['max_ndvi', 'f.ndvi_mean <= ?']] as [$k, $cond]) {
        if (isset($src[$k]) && $src[$k] !== '' && is_numeric($src[$k])) {
            $where[] = $cond;
            $params[] = (float)$src[$k];
            $norm[$k] = (float)$src[$k];
        }
    }
    $qq = trim((string)($src['q'] ?? ''));
    if ($qq !== '') {
        $where[] = 'f.field_code LIKE ?';
        $params[] = '%' . $qq . '%';
        $norm['q'] = $qq;
    }
    return [implode(' AND ', $where), $params, $norm, $season];
}

function filter_description(array $norm, array $season): array
{
    $out = [['Season', $season['title']]];
    $out[] = ['Districts', !empty($norm['district']) ? implode(', ', $norm['district']) : 'All'];
    if (isset($norm['min_area']) || isset($norm['max_area'])) {
        $out[] = ['Field size (ha)', ($norm['min_area'] ?? 0) . ' – ' . ($norm['max_area'] ?? 'max')];
    }
    if (isset($norm['min_ndvi']) || isset($norm['max_ndvi'])) {
        $out[] = ['Mean NDVI', ($norm['min_ndvi'] ?? 'min') . ' – ' . ($norm['max_ndvi'] ?? 'max')];
    }
    if (!empty($norm['q'])) {
        $out[] = ['Field ID contains', $norm['q']];
    }
    return $out;
}

function district_summary(string $where, array $params): array
{
    return q("SELECT f.district, COUNT(*) fields, SUM(f.area_ha) area_ha, SUM(f.est_yield_t) yield_t,
                     AVG(f.area_ha) avg_ha, AVG(f.ndvi_mean) ndvi
              FROM fields f WHERE $where GROUP BY f.district ORDER BY area_ha DESC", $params)->fetchAll();
}

const SORTABLE = ['field_code' => 'f.field_code', 'district' => 'f.district', 'area_ha' => 'f.area_ha',
    'est_yield_t' => 'f.est_yield_t', 'ndvi_mean' => 'f.ndvi_mean', 'lat' => 'f.lat', 'lon' => 'f.lon', 'id' => 'f.id'];
