<?php
/** Set a password from an invitation or reset link. */
require __DIR__ . '/app/bootstrap.php';

$raw = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$tok = find_token($raw);
$error = '';

if ($tok && $tok['status'] === 'disabled') {
    $tok = null;
}

if ($tok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($err = validate_password($p1)) {
        $error = $err;
    } elseif ($p1 !== $p2) {
        $error = 'The two passwords do not match.';
    } else {
        $wasInvite = $tok['purpose'] === 'invite' || $tok['status'] === 'invited';
        q("UPDATE users SET password_hash = ?, status = 'active', session_version = session_version + 1, updated_at = NOW() WHERE id = ?",
            [password_hash($p1, PASSWORD_DEFAULT), $tok['user_id']]);
        q('UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$tok['user_id']]);
        $u = q1('SELECT * FROM users WHERE id = ?', [$tok['user_id']]);
        login_user($u);
        audit($wasInvite ? 'account_activated' : 'password_reset');
        if ($wasInvite) {
            notify_admins('User activated: ' . $u['name'],
                '<p><strong>' . e($u['name']) . '</strong> (' . e($u['email']) . ') has accepted the invitation and activated their account.</p>');
            flash('success', 'Welcome, ' . $u['name'] . '. Your account is ready.');
        } else {
            send_mail($u['email'], $u['name'], 'Your password was changed',
                '<p>Dear ' . e($u['name']) . ',</p><p>The password for your account was changed on ' . date('j M Y \a\t H:i') . '. If this was not you, please reset your password immediately and contact the administrator.</p>');
            flash('success', 'Your password has been changed.');
        }
        redirect('index.php');
    }
}

auth_header($tok && $tok['purpose'] === 'invite' ? 'Set your password' : 'Choose a new password');
if (!$tok): ?>
  <h1>Link expired</h1>
  <div class="flash error">This link is invalid or has expired. Links are single-use: invitations last <?= INVITE_HOURS ?> hours and reset links <?= RESET_MINUTES ?> minutes.</div>
  <p class="auth-links"><a href="<?= url('forgot.php') ?>">Request a new link</a> · <a href="<?= url('login.php') ?>">Sign in</a></p>
<?php else: ?>
  <h1><?= $tok['purpose'] === 'invite' ? 'Welcome, ' . e($tok['name']) : 'Choose a new password' ?></h1>
  <p class="muted">Account: <strong><?= e($tok['email']) ?></strong>. Use at least 8 characters with letters and a number.</p>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($raw) ?>">
    <input type="text" name="username" value="<?= e($tok['email']) ?>" autocomplete="username" hidden>
    <label>New password
      <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
    </label>
    <label>Confirm password
      <input type="password" name="password2" required minlength="8" autocomplete="new-password">
    </label>
    <button class="btn primary block" type="submit">Save password &amp; sign in</button>
  </form>
<?php endif;
auth_footer();
