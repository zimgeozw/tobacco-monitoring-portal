<?php
/** Advance an import by one time-boxed slice (admin). Called repeatedly by the upload page. */
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/importer.php';
require_admin();
csrf_check();
session_write_close(); // don't block other requests from this admin while importing

try {
    json_out(import_step((int)post('import_id')));
} catch (Throwable $e) {
    error_log('import_step: ' . $e->getMessage());
    json_out(['error' => $e->getMessage(), 'status' => 'failed'], 500);
}
