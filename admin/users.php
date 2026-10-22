<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_admin();

$allDistricts = q('SELECT DISTINCT district FROM fields ORDER BY district')->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $email = mb_strtolower((string)post('email'));
        $name = mb_substr((string)post('name'), 0, 120);
        $role = post('role') === 'admin' ? 'admin' : 'viewer';
        $districts = array_values(array_intersect((array)($_POST['districts'] ?? []), $allDistricts));
        $access = post('access') === 'some' && $districts ? json_encode($districts) : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            flash('error', 'A name and valid email are required.');
            redirect('admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if (qv('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            flash('error', 'Another user already has that email address.');
            redirect('admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if ($id === (int)$admin['id'] && $role !== 'admin') {
            flash('error', 'You cannot remove your own administrator role.');
            redirect('admin/users.php?edit=' . $id);
        }
        $pwMode = post('pw_mode') === 'set' ? 'set' : 'invite';
        $newPw = (string)($_POST['password'] ?? '');
        $setPw = ($id && $newPw !== '') || (!$id && $pwMode === 'set');
        if ($setPw) {
            if ($err = validate_password($newPw)) {
                flash('error', $err);
                redirect('admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
            }
            if ($newPw !== (string)($_POST['password2'] ?? '')) {
                flash('error', 'The two passwords do not match.');
                redirect('admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
            }
        }
        $vals = [$name, $email, mb_substr((string)post('organisation'), 0, 160), mb_substr((string)post('phone'), 0, 40), $role,
            isset($_POST['can_download']) ? 1 : 0, $access, isset($_POST['notify_new_season']) ? 1 : 0];
        if ($id) {
            $old = q1('SELECT * FROM users WHERE id = ?', [$id]);
            q('UPDATE users SET name=?, email=?, organisation=?, phone=?, role=?, can_download=?, allowed_districts=?, notify_new_season=?, updated_at=NOW() WHERE id=?', array_merge($vals, [$id]));
            if ($old && ($old['email'] !== $email || $old['role'] !== $role)) {
                q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
            }
            audit('user_updated', $email);
            $msg = 'User updated.';
            if ($setPw) {
                q("UPDATE users SET password_hash = ?, status = IF(status = 'disabled', 'disabled', 'active'), session_version = session_version + 1 WHERE id = ?",
                    [password_hash($newPw, PASSWORD_DEFAULT), $id]);
                q('UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
                audit('password_set_by_admin', $email);
                $msg = 'User updated and password changed. They have been signed out of other sessions.';
                if (isset($_POST['email_login'])) {
                    $msg .= send_login_details(q1('SELECT * FROM users WHERE id = ?', [$id]), $newPw, false) ? ' Login details emailed.' : ' (The email could not be sent.)';
                }
            }
            flash('success', $msg);
        } else {
            q("INSERT INTO users (name, email, organisation, phone, role, can_download, allowed_districts, notify_new_season, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,'invited',?,NOW())",
                array_merge($vals, [$admin['id']]));
            $u = q1('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()]);
            if ($setPw) {
                q("UPDATE users SET password_hash = ?, status = 'active' WHERE id = ?", [password_hash($newPw, PASSWORD_DEFAULT), $u['id']]);
                audit('user_created', $email . ' (password set by admin)');
                $msg = "User $email created with the password you set — they can sign in now and change it under My account.";
                if (isset($_POST['email_login'])) {
                    $msg .= send_login_details($u, $newPw, true) ? ' Login details emailed.' : ' (The email could not be sent — share the password yourself.)';
                }
                flash('success', $msg);
            } else {
                $ok = send_invite($u);
                audit('user_invited', $email);
                flash($ok ? 'success' : 'error', $ok
                    ? "Invitation emailed to $email. They will set their own password."
                    : "User created, but the invitation email could not be sent. Check email settings under Activity & email, then use “Resend invite”.");
            }
        }
        redirect('admin/users.php');
    }

    $u = q1('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        redirect('admin/users.php');
    }
    $self = $id === (int)$admin['id'];
    switch ($action) {
        case 'invite':
            $ok = send_invite($u);
            audit('invite_resent', $u['email']);
            flash($ok ? 'success' : 'error', $ok ? 'Invitation re-sent to ' . $u['email'] . '.' : 'Email could not be sent — check email settings.');
            break;
        case 'reset':
            $ok = send_reset($u);
            audit('reset_sent', $u['email']);
            flash($ok ? 'success' : 'error', $ok ? 'Password reset link emailed to ' . $u['email'] . '.' : 'Email could not be sent — check email settings.');
            break;
        case 'disable':
            if ($self) {
                flash('error', 'You cannot disable your own account.');
                break;
            }
            q("UPDATE users SET status = 'disabled', session_version = session_version + 1 WHERE id = ?", [$id]);
            audit('user_disabled', $u['email']);
            flash('info', $u['name'] . ' has been disabled and signed out.');
            break;
        case 'enable':
            q("UPDATE users SET status = IF(password_hash IS NULL, 'invited', 'active') WHERE id = ?", [$id]);
            audit('user_enabled', $u['email']);
            flash('success', $u['name'] . ' has been re-enabled.');
            break;
        case 'delete':
            if ($self) {
                flash('error', 'You cannot delete your own account.');
                break;
            }
            q('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user_deleted', $u['email']);
            flash('success', 'User deleted.');
            break;
    }
    redirect('admin/users.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? q1('SELECT * FROM users WHERE id = ?', [$editId]) : null;
$isNew = isset($_GET['new']) && !$edit;
$filter = (string)($_GET['q'] ?? '');
$users = q("SELECT * FROM users WHERE (? = '' OR name LIKE ? OR email LIKE ? OR organisation LIKE ?) ORDER BY role = 'admin' DESC, name",
    [$filter, "%$filter%", "%$filter%", "%$filter%"])->fetchAll();

page_header('Users', 'admin-users');
?>
<div class="container">
  <div class="page-head">
    <div><h1 class="page-title">Users</h1><p class="muted">Add people by email. Either send them an invitation to choose their own password, or set a password for them. You can change any user’s password or delete them later.</p></div>
    <a class="btn primary" href="<?= url('admin/users.php?new=1') ?>">+ Add user</a>
  </div>

<?php if ($edit || $isNew):
    $u = $edit ?: ['id' => 0, 'name' => '', 'email' => '', 'organisation' => 'TIMB', 'phone' => '', 'role' => 'viewer', 'can_download' => 1, 'allowed_districts' => null, 'notify_new_season' => 1];
    $sel = $u['allowed_districts'] ? (json_decode($u['allowed_districts'], true) ?: []) : [];
?>
  <section class="card">
    <h2><?= $edit ? 'Edit user' : 'Add user' ?></h2>
    <form method="post" class="form grid-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <label>Full name <input name="name" value="<?= e($u['name']) ?>" required maxlength="120"></label>
      <label>Email (used to sign in) <input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
      <label>Organisation <input name="organisation" value="<?= e($u['organisation']) ?>" maxlength="160"></label>
      <label>Phone <input name="phone" value="<?= e($u['phone']) ?>" maxlength="40"></label>
      <fieldset class="radios"><legend>Role</legend>
        <label class="check"><input type="radio" name="role" value="viewer" <?= $u['role'] !== 'admin' ? 'checked' : '' ?>> <span><strong>Viewer</strong> — sees published seasons on the dashboard</span></label>
        <label class="check"><input type="radio" name="role" value="admin" <?= $u['role'] === 'admin' ? 'checked' : '' ?>> <span><strong>Administrator</strong> — also manages seasons, uploads and users</span></label>
      </fieldset>
      <fieldset class="radios"><legend>Permissions</legend>
        <label class="check"><input type="checkbox" name="can_download" <?= $u['can_download'] ? 'checked' : '' ?>> <span>Can download data (Excel, CSV, GeoJSON)</span></label>
        <label class="check"><input type="checkbox" name="notify_new_season" <?= $u['notify_new_season'] ? 'checked' : '' ?>> <span>Email when a new season is published</span></label>
      </fieldset>
      <fieldset class="radios span2"><legend>Districts this user can see</legend>
        <label class="check"><input type="radio" name="access" value="all" <?= !$sel ? 'checked' : '' ?>> <span>All districts</span></label>
        <label class="check"><input type="radio" name="access" value="some" <?= $sel ? 'checked' : '' ?>> <span>Only selected districts (e.g. a district officer)</span></label>
        <div class="district-picks">
          <?php if (!$allDistricts): ?><span class="muted small">Districts appear here once field data has been uploaded.</span><?php endif; ?>
          <?php foreach ($allDistricts as $d): ?>
            <label class="chip"><input type="checkbox" name="districts[]" value="<?= e($d) ?>" <?= in_array($d, $sel, true) ? 'checked' : '' ?>> <?= e($d) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="radios span2"><legend>Password</legend>
        <?php if (!$edit): ?>
          <label class="check"><input type="radio" name="pw_mode" value="invite" checked onchange="pwToggle()"> <span><strong>Send invitation email</strong> — the user chooses their own password</span></label>
          <label class="check"><input type="radio" name="pw_mode" value="set" onchange="pwToggle()"> <span><strong>Set the password now</strong> — the user can sign in straight away and change it later</span></label>
        <?php else: ?>
          <p class="muted small" style="margin:0">Leave blank to keep the current password. Setting a new one activates the account and signs the user out of other devices.</p>
        <?php endif; ?>
        <div class="row2 pw-fields" id="pwFields" <?= $edit ? '' : 'hidden' ?>>
          <label>New password <input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="<?= $edit ? 'Leave blank to keep' : 'At least 8 characters, letters + a number' ?>"></label>
          <label>Confirm password <input type="password" name="password2" minlength="8" autocomplete="new-password"></label>
          <label class="check span2"><input type="checkbox" name="email_login"> <span>Email the sign-in details (including this password) to the user</span></label>
          <button type="button" class="btn sm ghost" onclick="genPw()">Generate a password</button>
          <code id="genOut" hidden></code>
        </div>
      </fieldset>
      <div class="span2 actions">
        <button class="btn primary" type="submit"><?= $edit ? 'Save changes' : 'Add user' ?></button>
        <a class="btn ghost" href="<?= url('admin/users.php') ?>">Cancel</a>
      </div>
    </form>
  </section>
<?php endif; ?>

  <section class="card flush">
    <form class="pad toolbar" method="get"><input type="search" name="q" value="<?= e($filter) ?>" placeholder="Search name, email or organisation"><button class="btn" type="submit">Search</button></form>
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Organisation</th><th>Role</th><th>Access</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $d = $u['allowed_districts'] ? json_decode($u['allowed_districts'], true) : null; ?>
        <tr>
          <td><strong><?= e($u['name']) ?></strong></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['organisation']) ?></td>
          <td><span class="pill <?= $u['role'] ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Viewer' ?></span></td>
          <td class="small"><?= $d ? e(implode(', ', $d)) : 'All districts' ?><?= $u['can_download'] || $u['role'] === 'admin' ? ' · download' : ' · view only' ?></td>
          <td><span class="pill <?= $u['status'] === 'active' ? 'published' : ($u['status'] === 'disabled' ? 'disabled' : 'draft') ?>"><?= e(ucfirst($u['status'])) ?></span></td>
          <td class="small"><?= fmt_date($u['last_login_at'], 'j M Y H:i') ?></td>
          <td class="row-actions">
            <a class="btn sm ghost" href="<?= url('admin/users.php?edit=' . $u['id']) ?>">Edit</a>
            <?php foreach ($u['status'] === 'invited' ? ['invite' => 'Resend invite'] : ($u['status'] === 'active' ? ['reset' => 'Send reset'] : []) as $a => $l): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm" name="action" value="<?= $a ?>"><?= $l ?></button></form>
            <?php endforeach; ?>
            <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <?php if ($u['status'] === 'disabled'): ?>
                  <button class="btn sm" name="action" value="enable">Enable</button>
                <?php else: ?>
                  <button class="btn sm ghost" name="action" value="disable">Disable</button>
                <?php endif; ?>
                <button class="btn sm danger" name="action" value="delete" onclick="return confirm('Delete <?= e(addslashes($u['name'])) ?> permanently? This cannot be undone.')">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
function pwToggle() {
  var set = document.querySelector('input[name=pw_mode][value=set]');
  var f = document.getElementById('pwFields');
  if (set && f) { f.hidden = !set.checked; f.querySelectorAll('input[type=password]').forEach(function (i) { i.required = set.checked; }); }
}
function genPw() {
  var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', a = new Uint32Array(10), p = '';
  crypto.getRandomValues(a); a.forEach(function (x) { p += chars[x % chars.length]; });
  p = p.slice(0, 8) + (2 + a[0] % 8) + p.slice(8);
  document.querySelectorAll('#pwFields input[type=password]').forEach(function (i) { i.value = p; });
  var o = document.getElementById('genOut'); o.textContent = p; o.hidden = false;
}
</script>
<?php page_footer();
