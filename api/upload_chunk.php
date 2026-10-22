<?php
/**
 * Chunked upload receiver (admin). The browser splits large CSV/ZIP files into
 * ~1.5 MB pieces so uploads work within shared-hosting PHP upload limits.
 * POST: upload_id, index, total, filename, season_id, mode, chunk (file)
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/importer.php';
$user = require_admin();
csrf_check();

$id = preg_replace('/[^a-f0-9]/', '', (string)post('upload_id'));
$index = (int)post('index');
$total = (int)post('total');
$name = basename((string)post('filename'));
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (strlen($id) < 16 || $total < 1 || $index < 0 || $index >= $total) {
    json_out(['error' => 'Bad upload request.'], 400);
}
if (!in_array($ext, ['csv', 'zip'], true)) {
    json_out(['error' => 'Only .csv or .zip files are accepted.'], 400);
}
if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['chunk']['error'] ?? -1;
    json_out(['error' => 'Chunk upload failed (code ' . $code . '). The server upload limit may be below 2 MB.'], 400);
}
$part = upload_dir() . "/$id.part";
if ($index === 0) {
    @unlink($part);
    cleanup_uploads();
} elseif (!is_file($part) || (int)($_SESSION['upload_next'][$id] ?? -1) !== $index) {
    json_out(['error' => 'Upload out of sequence. Please start again.'], 409);
}
$in = fopen($_FILES['chunk']['tmp_name'], 'rb');
$out = fopen($part, 'ab');
stream_copy_to_stream($in, $out);
fclose($in);
fclose($out);
$_SESSION['upload_next'][$id] = $index + 1;

if ($index < $total - 1) {
    json_out(['ok' => true, 'received' => $index + 1]);
}

// Last chunk: register the import
unset($_SESSION['upload_next'][$id]);
$seasonId = (int)post('season_id');
if (!q1('SELECT id FROM seasons WHERE id = ?', [$seasonId])) {
    @unlink($part);
    json_out(['error' => 'Choose a season first.'], 400);
}
$stored = "$id.$ext";
rename($part, upload_dir() . '/' . $stored);
q("INSERT INTO imports (season_id, mode, original_name, stored_name, bytes_total, status, created_by, created_at) VALUES (?,?,?,?,?,'uploaded',?,NOW())", [
    $seasonId, post('mode') === 'append' ? 'append' : 'replace', mb_substr($name, 0, 255), $stored,
    filesize(upload_dir() . '/' . $stored), $user['id'],
]);
$importId = (int)db()->lastInsertId();
audit('upload', $name . ' → season #' . $seasonId);
json_out(['ok' => true, 'import_id' => $importId]);
