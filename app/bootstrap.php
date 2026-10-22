<?php
/**
 * TIMB Tobacco Crop Monitoring Portal
 * Designed by Zimgeo (Map Tech Pvt Ltd) — www.zimgeo.co.zw
 *
 * Bootstrap: config, database, session, helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');
define('STORAGE', APP_ROOT . '/storage');

date_default_timezone_set('Africa/Harare');

$__cfgFile = APP_ROOT . '/config.php';
$GLOBALS['__config'] = is_file($__cfgFile) ? (require $__cfgFile) : null;

if ($GLOBALS['__config'] === null && !defined('INSTALLER')) {
    header('Location: ' . base_path_guess() . 'install.php');
    exit;
}

if (cfg('debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE . '/logs/php-error.log');

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    start_session();
}
if ($GLOBALS['__config'] !== null) {
    ensure_schema();
}

/**
 * Lightweight migrations for portals installed before a schema change.
 * A flag file in storage/cache records the applied version so this runs once.
 */
function ensure_schema(): void
{
    $version = 2;
    $flag = STORAGE . '/cache/schema_v' . $version . '.flag';
    if (is_file($flag)) {
        return;
    }
    try {
        $cols = array_column(q('SHOW COLUMNS FROM seasons')->fetchAll(), 'Field');
        $add = [
            'basemap_url' => 'VARCHAR(500) NULL',
            'basemap_maxzoom' => 'TINYINT UNSIGNED NULL',
            'default_view' => 'VARCHAR(120) NULL',
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $cols, true)) {
                db()->exec("ALTER TABLE seasons ADD COLUMN $col $def");
            }
        }
        @file_put_contents($flag, date('c'));
    } catch (Throwable $e) {
        error_log('ensure_schema: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Config & paths                                                       */
/* ------------------------------------------------------------------ */

function cfg(string $key, $default = null)
{
    $c = $GLOBALS['__config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($c) || !array_key_exists($part, $c)) {
            return $default;
        }
        $c = $c[$part];
    }
    return $c;
}

/** Guess the web path of the app root (used before install). */
function base_path_guess(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(dirname($script), '/');
    foreach (['/admin', '/api'] as $sub) {
        if (substr($dir, -strlen($sub)) === $sub) {
            $dir = substr($dir, 0, -strlen($sub));
        }
    }
    return $dir . '/';
}

/** Absolute URL (for emails). */
function abs_url(string $path = ''): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/' . ltrim($path, '/');
}

/** Root-relative URL (for links in pages). */
function url(string $path = ''): string
{
    $base = cfg('base_url', '');
    $p = $base ? (parse_url($base, PHP_URL_PATH) ?: '/') : base_path_guess();
    return rtrim($p, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? substr((string)filemtime($file), -6) : APP_VERSION;
    return url('assets/' . $path) . '?v=' . $v;
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/* ------------------------------------------------------------------ */
/* Database                                                             */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            cfg('db.host', 'localhost'), (int)cfg('db.port', 3306), cfg('db.name'));
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $pdo->exec("SET time_zone = '+02:00'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q1(string $sql, array $params = [])
{
    return q($sql, $params)->fetch() ?: null;
}

function qv(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ------------------------------------------------------------------ */
/* Session, CSRF, flash                                                 */
/* ------------------------------------------------------------------ */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('timb_portal');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (is_api()) {
            json_out(['error' => 'Session expired. Please reload the page.'], 419);
        }
        http_response_code(419);
        exit('Session expired. Please go back and reload the page.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/* Misc helpers                                                         */
/* ------------------------------------------------------------------ */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_api(): bool
{
    return strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function post(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function audit(string $action, string $details = ''): void
{
    try {
        q('INSERT INTO audit_log (user_id, action, details, ip, created_at) VALUES (?,?,?,?,NOW())',
            [current_user()['id'] ?? null, $action, mb_substr($details, 0, 1000), client_ip()]);
    } catch (Throwable $e) {
        error_log('audit failed: ' . $e->getMessage());
    }
}

function fmt_num($n, int $dec = 0): string
{
    return number_format((float)$n, $dec, '.', ',');
}

function fmt_date(?string $d, string $f = 'j M Y'): string
{
    return $d ? date($f, strtotime($d)) : '—';
}

function random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function app_name(): string
{
    return (string)cfg('app_name', 'Tobacco Crop Monitoring Portal');
}
