<?php
/**
 * Resumable CSV importer. Each call to import_step() processes a slice of the file
 * within a time budget, so very large GEE exports (100k+ fields, 100+ MB) import
 * safely on shared hosting without hitting PHP time limits.
 *
 * Supported files (exported from the GEE script in /gee):
 *   fields  — Tobacco_Fields_*.csv   (area_ha, ndvi_mean, lon, lat, .geo ...)
 *   runinfo — Tobacco_RunInfo_*.csv  (classifier_accuracy, images_used ...)
 *   points  — Tobacco_TrainingPoints_*.csv (class, .geo point)
 */
declare(strict_types=1);

require_once __DIR__ . '/geo.php';

const IMPORT_TIME_BUDGET = 8.0;      // seconds per request
const IMPORT_BATCH_BYTES = 1500000;  // flush INSERT when the batch reaches ~1.5 MB

function upload_dir(): string
{
    $d = STORAGE . '/uploads';
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

function detect_kind(array $header): string
{
    $h = array_map('strtolower', $header);
    if (in_array('area_ha', $h, true) && in_array('.geo', $h, true)) {
        return 'fields';
    }
    if (in_array('classifier_accuracy', $h, true)) {
        return 'runinfo';
    }
    if (in_array('class', $h, true) && (in_array('.geo', $h, true) || in_array('lon', $h, true))) {
        return 'points';
    }
    return 'unknown';
}

/** Read the CSV header (stripping a UTF-8 BOM). */
function read_header($fh): array
{
    $h = fgetcsv($fh, 0, ',', '"', '');
    if (!$h) {
        return [];
    }
    $h[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h[0]);
    return array_map('trim', $h);
}

/** Extract the first CSV inside a .zip upload. */
function unzip_upload(array $imp): array
{
    $path = upload_dir() . '/' . $imp['stored_name'];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the ZIP file.');
    }
    $entry = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('/\.csv$/i', $n) && strpos($n, '__MACOSX') === false) {
            $entry = $n;
            break;
        }
    }
    if ($entry === null) {
        throw new RuntimeException('The ZIP file does not contain a CSV.');
    }
    $newName = preg_replace('/\.zip$/i', '', $imp['stored_name']) . '.csv';
    $in = $zip->getStream($entry);
    $out = fopen(upload_dir() . '/' . $newName, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    $zip->close();
    @unlink($path);
    $size = filesize(upload_dir() . '/' . $newName);
    q('UPDATE imports SET stored_name = ?, bytes_total = ? WHERE id = ?', [$newName, $size, $imp['id']]);
    return q1('SELECT * FROM imports WHERE id = ?', [$imp['id']]);
}

/**
 * Process the next slice of an import. Returns a progress array for the UI.
 */
function import_step(int $importId): array
{
    @set_time_limit(60);
    $t0 = microtime(true);
    $imp = q1('SELECT * FROM imports WHERE id = ?', [$importId]);
    if (!$imp) {
        throw new RuntimeException('Import not found.');
    }
    if (in_array($imp['status'], ['done', 'failed', 'cancelled'], true)) {
        return import_progress($imp);
    }
    $season = q1('SELECT * FROM seasons WHERE id = ?', [$imp['season_id']]);
    if (!$season) {
        throw new RuntimeException('Season not found.');
    }

    if (preg_match('/\.zip$/i', $imp['stored_name'])) {
        $imp = unzip_upload($imp);
        return import_progress($imp, 'Extracted ZIP — starting import…');
    }

    $path = upload_dir() . '/' . $imp['stored_name'];
    if (!is_file($path)) {
        throw new RuntimeException('Uploaded file is missing on the server. Please upload again.');
    }
    $fh = fopen($path, 'rb');
    $meta = json_decode((string)$imp['header_json'], true) ?: [];

    // First call: read header, detect type, clear old data if replacing
    if (empty($meta['header'])) {
        $header = read_header($fh);
        $kind = detect_kind($header);
        if ($kind === 'unknown') {
            fclose($fh);
            fail_import($imp, 'Unrecognised file. Expected the Tobacco_Fields, Tobacco_RunInfo or Tobacco_TrainingPoints CSV exported from Earth Engine.');
            return import_progress(q1('SELECT * FROM imports WHERE id = ?', [$importId]));
        }
        $meta = ['header' => $header, 'cleared' => false];
        q("UPDATE imports SET kind = ?, header_json = ?, bytes_done = ?, status = 'running' WHERE id = ?",
            [$kind, json_encode($meta), ftell($fh), $importId]);
        if ($kind === 'fields' && isset(array_flip($header)['yield_benchmark_t_ha'])) {
            // Take the benchmark from the first data row
            $row = fgetcsv($fh, 0, ',', '"', '');
            $bm = (float)($row[array_search('yield_benchmark_t_ha', $header, true)] ?? 0);
            if ($bm > 0 && $imp['mode'] === 'replace') {
                q('UPDATE seasons SET yield_benchmark_t_ha = ? WHERE id = ?', [$bm, $season['id']]);
            }
        }
        fclose($fh);
        return import_progress(q1('SELECT * FROM imports WHERE id = ?', [$importId]), 'File recognised: ' . $kind);
    }

    $kind = $imp['kind'];
    $header = $meta['header'];
    $col = array_flip(array_map('strtolower', $header));

    if ($kind === 'runinfo') {
        fseek($fh, (int)$imp['bytes_done']);
        $row = fgetcsv($fh, 0, ',', '"', '');
        fclose($fh);
        if (!$row) {
            fail_import($imp, 'The run-info file has no data row.');
            return import_progress(q1('SELECT * FROM imports WHERE id = ?', [$importId]));
        }
        $g = fn($k) => isset($col[$k]) && isset($row[$col[$k]]) && $row[$col[$k]] !== '' ? $row[$col[$k]] : null;
        q('UPDATE seasons SET classifier_accuracy = COALESCE(?, classifier_accuracy), images_used = COALESCE(?, images_used),
             training_points = COALESCE(?, training_points), min_field_ha = COALESCE(?, min_field_ha),
             start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date),
             yield_benchmark_t_ha = COALESCE(?, yield_benchmark_t_ha), province = COALESCE(?, province), updated_at = NOW() WHERE id = ?', [
            $g('classifier_accuracy'), $g('images_used'), $g('training_points'), $g('min_field_ha'),
            $g('start_date'), $g('end_date'), $g('yield_benchmark_t_ha'), $g('province'), $season['id'],
        ]);
        recalc_yield((int)$season['id']);
        finish_import($imp, 1, 'Season details updated from run info.');
        return import_progress(q1('SELECT * FROM imports WHERE id = ?', [$importId]));
    }

    // Replace mode: clear existing rows for this season (in slices)
    if ($imp['mode'] === 'replace' && empty($meta['cleared'])) {
        $table = $kind === 'points' ? 'training_points' : 'fields';
        do {
            $n = q("DELETE FROM $table WHERE season_id = ? LIMIT 20000", [$season['id']])->rowCount();
        } while ($n > 0 && microtime(true) - $t0 < IMPORT_TIME_BUDGET);
        if ($n > 0) {
            fclose($fh);
            return import_progress($imp, 'Removing previous data for this season…');
        }
        $meta['cleared'] = true;
        q('UPDATE imports SET header_json = ? WHERE id = ?', [json_encode($meta), $importId]);
    }

    fseek($fh, (int)$imp['bytes_done']);
    $done = (int)$imp['rows_done'];
    $skipped = (int)$imp['rows_skipped'];
    $bench = (float)qv('SELECT yield_benchmark_t_ha FROM seasons WHERE id = ?', [$season['id']]) ?: 2.2;
    $batch = [];
    $batchBytes = 0;
    $eof = false;

    $flush = function () use (&$batch, &$batchBytes, $kind) {
        if (!$batch) {
            return;
        }
        if ($kind === 'fields') {
            $sql = 'INSERT INTO fields (season_id, field_code, province, district, district_gee, area_ha, est_yield_t, ndvi_mean, lon, lat, minlon, minlat, maxlon, maxlat, geom, import_id) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
        } else {
            $sql = 'INSERT INTO training_points (season_id, class, lon, lat, import_id) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
        }
        q($sql, array_merge(...$batch));
        $batch = [];
        $batchBytes = 0;
    };

    db()->beginTransaction();
    try {
        while (true) {
            $row = fgetcsv($fh, 0, ',', '"', '');
            if ($row === false) {
                $eof = true;
                break;
            }
            if ($row === [null] || count($row) < 2) {
                continue;
            }
            $g = fn($k) => isset($col[$k]) ? ($row[$col[$k]] ?? '') : '';

            if ($kind === 'fields') {
                $cg = compact_geom((string)$g('.geo'));
                $area = (float)$g('area_ha');
                if (!$cg || $area <= 0) {
                    $skipped++;
                    continue;
                }
                [$geom, $bb] = $cg;
                $lon = $g('lon') !== '' ? (float)$g('lon') : ($bb[0] + $bb[2]) / 2;
                $lat = $g('lat') !== '' ? (float)$g('lat') : ($bb[1] + $bb[3]) / 2;
                $gee = trim((string)$g('district'));
                $district = locate_in('zwe_districts.geojson', $lon, $lat) ?? ($gee !== '' ? $gee : 'Unknown');
                $province = locate_in('zwe_provinces.geojson', $lon, $lat) ?? (trim((string)$g('province')) ?: $season['province']);
                $ndvi = $g('ndvi_mean');
                $batch[] = [
                    $season['id'], '', $province, $district, $gee ?: null, $area, $area * $bench,
                    $ndvi === '' ? null : (float)$ndvi, $lon, $lat, $bb[0], $bb[1], $bb[2], $bb[3], $geom, $importId,
                ];
                $batchBytes += strlen($geom) + 200;
            } else {
                $class = (int)$g('class');
                $lon = $g('lon');
                $lat = $g('lat');
                if ($lon === '' || $lat === '') {
                    $pt = json_decode((string)$g('.geo'), true);
                    if (($pt['type'] ?? '') !== 'Point') {
                        $skipped++;
                        continue;
                    }
                    [$lon, $lat] = $pt['coordinates'];
                }
                $batch[] = [$season['id'], $class === 1 ? 1 : 0, round((float)$lon, 6), round((float)$lat, 6), $importId];
                $batchBytes += 60;
            }
            $done++;
            if ($batchBytes >= IMPORT_BATCH_BYTES || count($batch) >= 1000) {
                $flush();
                if (microtime(true) - $t0 > IMPORT_TIME_BUDGET) {
                    break;
                }
            }
        }
        $flush();
        $pos = $eof ? (int)$imp['bytes_total'] : ftell($fh);
        q('UPDATE imports SET bytes_done = ?, rows_done = ?, rows_skipped = ? WHERE id = ?', [$pos, $done, $skipped, $importId]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        fclose($fh);
        fail_import($imp, 'Import error: ' . $ex->getMessage());
        throw $ex;
    }
    fclose($fh);

    if ($eof) {
        if ($kind === 'fields') {
            q("UPDATE fields SET field_code = CONCAT(?, LPAD(id, 7, '0')) WHERE import_id = ?",
                [field_code_prefix($season['code']), $importId]);
        }
        refresh_season_totals((int)$season['id']);
        $imp = q1('SELECT * FROM imports WHERE id = ?', [$importId]);
        finish_import($imp, $done, sprintf('Imported %s %s%s.', fmt_num($done), $kind === 'fields' ? 'fields' : 'points',
            $skipped ? ' (' . fmt_num($skipped) . ' rows skipped)' : ''));
    }
    return import_progress(q1('SELECT * FROM imports WHERE id = ?', [$importId]));
}

/** "2025-26" → "F2526-" so field IDs read like F2526-0001234. */
function field_code_prefix(string $code): string
{
    if (preg_match('/(\d{4})\D+(\d{2,4})/', $code, $m)) {
        return 'F' . substr($m[1], 2) . substr($m[2], -2) . '-';
    }
    return 'F' . (preg_replace('/[^0-9A-Za-z]/', '', $code) ?: 'X') . '-';
}

function import_progress(array $imp, string $note = ''): array
{
    $total = max(1, (int)$imp['bytes_total']);
    return [
        'id' => (int)$imp['id'],
        'status' => $imp['status'],
        'kind' => $imp['kind'],
        'percent' => $imp['status'] === 'done' ? 100 : min(99.9, round((int)$imp['bytes_done'] / $total * 100, 1)),
        'rows' => (int)$imp['rows_done'],
        'skipped' => (int)$imp['rows_skipped'],
        'message' => $note ?: (string)$imp['message'],
    ];
}

function fail_import(array $imp, string $msg): void
{
    q("UPDATE imports SET status = 'failed', message = ?, finished_at = NOW() WHERE id = ?", [$msg, $imp['id']]);
}

function finish_import(array $imp, int $rows, string $msg): void
{
    q("UPDATE imports SET status = 'done', rows_done = ?, message = ?, finished_at = NOW() WHERE id = ?", [$rows, $msg, $imp['id']]);
    @unlink(upload_dir() . '/' . $imp['stored_name']);
    audit('import_done', $imp['original_name'] . ': ' . $msg);
    $season = q1('SELECT * FROM seasons WHERE id = ?', [$imp['season_id']]);
    notify_admins('Import complete: ' . ($season['title'] ?? ''),
        '<p>The file <strong>' . e($imp['original_name']) . '</strong> has been imported into <strong>' . e($season['title'] ?? '') . '</strong>.</p><p>' . e($msg) . '</p>'
        . ($season && $season['status'] !== 'published' ? '<p>The season is still a <strong>draft</strong>. Publish it under Administration → Seasons to make it visible to users.</p>' : ''));
}

/** Recompute stored yields after a benchmark change. */
function recalc_yield(int $seasonId): void
{
    q('UPDATE fields f JOIN seasons s ON s.id = f.season_id SET f.est_yield_t = f.area_ha * s.yield_benchmark_t_ha WHERE f.season_id = ?', [$seasonId]);
    refresh_season_totals($seasonId);
}

function refresh_season_totals(int $seasonId): void
{
    $t = q1('SELECT COUNT(*) n, COALESCE(SUM(area_ha),0) ha, COALESCE(SUM(est_yield_t),0) y FROM fields WHERE season_id = ?', [$seasonId]);
    q('UPDATE seasons SET field_count = ?, total_ha = ?, total_yield_t = ?, data_version = data_version + 1, updated_at = NOW() WHERE id = ?',
        [$t['n'], $t['ha'], $t['y'], $seasonId]);
    foreach (glob(STORAGE . '/cache/points_' . $seasonId . '_*') ?: [] as $f) {
        @unlink($f);
    }
}

/** Remove abandoned partial uploads older than a day. */
function cleanup_uploads(): void
{
    foreach (glob(upload_dir() . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.htaccess') {
            @unlink($f);
        }
    }
}
