<?php
require __DIR__ . '/app/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'profile') {
        $name = mb_substr((string)post('name'), 0, 120);
        if ($name === '') {
            flash('error', 'Name cannot be empty.');
        } else {
            q('UPDATE users SET name = ?, organisation = ?, phone = ?, notify_new_season = ?, notify_admin_events = ?, updated_at = NOW() WHERE id = ?', [
                $name, mb_substr((string)post('organisation'), 0, 160), mb_substr((string)post('phone'), 0, 40),
                isset($_POST['notify_new_season']) ? 1 : 0, isset($_POST['notify_admin_events']) ? 1 : 0, $u['id'],
            ]);
            audit('profile_updated');
            flash('success', 'Your details have been saved.');
        }
    } elseif ($action === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        if (!password_verify($cur, (string)$u['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif ($err = validate_password($p1)) {
            flash('error', $err);
        } elseif ($p1 !== (string)($_POST['password2'] ?? '')) {
            flash('error', 'The new passwords do not match.');
        } else {
            q('UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = NOW() WHERE id = ?',
                [password_hash($p1, PASSWORD_DEFAULT), $u['id']]);
            $u = q1('SELECT * FROM users WHERE id = ?', [$u['id']]);
            login_user($u); // keep this session, sign out all others
            audit('password_changed');
            send_mail($u['email'], $u['name'], 'Your password was changed',
                '<p>Dear ' . e($u['name']) . ',</p><p>The password for your account was changed on ' . date('j M Y \a\t H:i') . '. Other signed-in sessions have been signed out. If this was not you, reset your password immediately.</p>');
            flash('success', 'Password changed. Other devices have been signed out.');
        }
    }
    redirect('account.php');
}

$districts = allowed_districts($u);
page_header('My account', 'account');
?>
<div class="container narrow">
  <h1 class="page-title">My account</h1>
  <div class="grid-2">
    <section class="card">
      <h2>Profile &amp; notifications</h2>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <label>Full name <input name="name" value="<?= e($u['name']) ?>" required maxlength="120"></label>
        <label>Email <input value="<?= e($u['email']) ?>" disabled><small class="muted">Ask the administrator to change your sign-in email.</small></label>
        <label>Organisation <input name="organisation" value="<?= e($u['organisation']) ?>" maxlength="160"></label>
        <label>Phone <input name="phone" value="<?= e($u['phone']) ?>" maxlength="40"></label>
        <label class="check"><input type="checkbox" name="notify_new_season" <?= $u['notify_new_season'] ? 'checked' : '' ?>> Email me when new season data is published</label>
        <?php if ($u['role'] === ROLE_ADMIN): ?>
        <label class="check"><input type="checkbox" name="notify_admin_events" <?= $u['notify_admin_events'] ? 'checked' : '' ?>> Email me about user activations and completed imports</label>
        <?php endif; ?>
        <button class="btn primary" type="submit">Save</button>
      </form>
    </section>
    <section class="card">
      <h2>Change password</h2>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <input type="text" name="username" value="<?= e($u['email']) ?>" autocomplete="username" hidden>
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
        <button class="btn" type="submit">Change password</button>
      </form>
      <h2 class="mt">Access</h2>
      <dl class="kv">
        <dt>Role</dt><dd><?= $u['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></dd>
        <dt>Downloads</dt><dd><?= can_download() ? 'Allowed (Excel, CSV, GeoJSON)' : 'View only' ?></dd>
        <dt>Districts</dt><dd><?= $districts ? e(implode(', ', $districts)) : 'All districts' ?></dd>
        <dt>Last sign-in</dt><dd><?= fmt_date($u['last_login_at'], 'j M Y H:i') ?></dd>
      </dl>
    </section>
  </div>
</div>
<?php page_footer();
