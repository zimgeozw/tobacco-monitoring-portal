<?php
/**
 * Authentication, roles, tokens (invite / password reset).
 * Designed by Zimgeo — www.zimgeo.co.zw
 */
declare(strict_types=1);

const ROLE_ADMIN = 'admin';
const ROLE_VIEWER = 'viewer';
const MAX_LOGIN_FAILS = 6;          // per email+IP in the window below
const LOGIN_WINDOW_MIN = 15;
const INVITE_HOURS = 72;
const RESET_MINUTES = 60;

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user !== false && !$refresh) {
        return $user;
    }
    $user = null;
    if ($GLOBALS['__config'] === null) {
        return null; // not installed yet
    }
    $id = $_SESSION['uid'] ?? null;
    if ($id) {
        $u = q1("SELECT * FROM users WHERE id = ? AND status = 'active'", [$id]);
        if ($u && (int)$u['session_version'] === (int)($_SESSION['sv'] ?? -1)) {
            $user = $u;
        } else {
            unset($_SESSION['uid'], $_SESSION['sv']);
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === ROLE_ADMIN;
}

function can_download(): bool
{
    $u = current_user();
    return $u && ($u['role'] === ROLE_ADMIN || (int)$u['can_download'] === 1);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (is_api()) {
            json_out(['error' => 'Please sign in.'], 401);
        }
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== ROLE_ADMIN) {
        if (is_api()) {
            json_out(['error' => 'Administrator access required.'], 403);
        }
        http_response_code(403);
        page_header('Access denied');
        echo '<div class="container narrow"><div class="card"><h1>Access denied</h1><p>This area is for administrators.</p><a class="btn" href="' . url('index.php') . '">Back to dashboard</a></div></div>';
        page_footer();
        exit;
    }
    return $u;
}

/** Districts this user may see, or null for all. */
function allowed_districts(?array $u = null): ?array
{
    $u = $u ?? current_user();
    if (!$u || $u['role'] === ROLE_ADMIN || empty($u['allowed_districts'])) {
        return null;
    }
    $list = json_decode((string)$u['allowed_districts'], true);
    return is_array($list) && $list ? array_values($list) : null;
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['sv'] = (int)$u['session_version'];
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    current_user(true);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function login_throttled(string $email): bool
{
    $window = 'created_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE) AND success = 0';
    $byPair = (int)qv("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND $window", [$email, client_ip()]);
    $byIp = (int)qv("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND $window", [client_ip()]);
    return $byPair >= MAX_LOGIN_FAILS || $byIp >= MAX_LOGIN_FAILS * 3;
}

function record_login_attempt(string $email, bool $ok): void
{
    q('INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?,?,?,NOW())', [$email, client_ip(), $ok ? 1 : 0]);
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)');
    }
}

function validate_password(string $p): ?string
{
    if (strlen($p) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
        return 'Password must contain letters and at least one number.';
    }
    return null;
}

/** Create an invite or reset token; returns the raw token for the email link. */
function issue_token(int $userId, string $purpose): string
{
    q('UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);
    $raw = random_token();
    $mins = $purpose === 'invite' ? INVITE_HOURS * 60 : RESET_MINUTES;
    q('INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at, created_at) VALUES (?,?,?, NOW() + INTERVAL ' . $mins . ' MINUTE, NOW())',
        [$userId, $purpose, hash('sha256', $raw)]);
    return $raw;
}

function find_token(string $raw): ?array
{
    if ($raw === '') {
        return null;
    }
    return q1('SELECT t.*, u.email, u.name, u.status FROM user_tokens t JOIN users u ON u.id = t.user_id
               WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()', [hash('sha256', $raw)]);
}

function send_invite(array $u): bool
{
    $raw = issue_token((int)$u['id'], 'invite');
    $link = abs_url('reset.php?token=' . $raw);
    $inviter = current_user()['name'] ?? 'The administrator';
    $body = '<p>Dear ' . e($u['name']) . ',</p>'
        . '<p>' . e($inviter) . ' has given you access to the <strong>' . e(app_name()) . '</strong>, '
        . 'which shows satellite-detected tobacco fields, hectarage and estimated yields.</p>'
        . '<p>Your sign-in email is <strong>' . e($u['email']) . '</strong>. Click the button below to set your password. '
        . 'The link is valid for ' . INVITE_HOURS . ' hours.</p>'
        . email_button($link, 'Set my password')
        . '<p style="color:#6b7280;font-size:13px">If the button does not work, copy this link into your browser:<br>' . e($link) . '</p>';
    return send_mail($u['email'], $u['name'], 'You have been invited to the ' . app_name(), $body);
}

function send_reset(array $u): bool
{
    $raw = issue_token((int)$u['id'], 'reset');
    $link = abs_url('reset.php?token=' . $raw);
    $body = '<p>Dear ' . e($u['name']) . ',</p>'
        . '<p>We received a request to reset the password for your account on the <strong>' . e(app_name()) . '</strong>.</p>'
        . email_button($link, 'Reset my password')
        . '<p>This link is valid for ' . RESET_MINUTES . ' minutes. If you did not request a reset, you can ignore this email — your password will not change.</p>'
        . '<p style="color:#6b7280;font-size:13px">Link: ' . e($link) . '</p>';
    return send_mail($u['email'], $u['name'], 'Reset your password — ' . app_name(), $body);
}

/** Seasons visible to the current user. */
function visible_seasons(): array
{
    $sql = 'SELECT * FROM seasons' . (is_admin() ? '' : " WHERE status = 'published'") . ' ORDER BY start_date DESC, id DESC';
    return q($sql)->fetchAll();
}

function season_for_user($id): ?array
{
    $s = q1('SELECT * FROM seasons WHERE id = ?', [(int)$id]);
    if (!$s || ($s['status'] !== 'published' && !is_admin())) {
        return null;
    }
    return $s;
}

function default_season_id(): ?int
{
    $seasons = visible_seasons();
    foreach ($seasons as $s) {
        if ((int)$s['is_default'] === 1 && $s['status'] === 'published') {
            return (int)$s['id'];
        }
    }
    return $seasons ? (int)$seasons[0]['id'] : null;
}
