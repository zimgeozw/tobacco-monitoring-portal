<?php
require __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower((string)post('email'));
    $pass = (string)($_POST['password'] ?? '');
    if (login_throttled($email)) {
        $error = 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MIN . ' minutes or reset your password.';
    } else {
        $u = q1('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $u && $u['password_hash'] && password_verify($pass, $u['password_hash']);
        record_login_attempt($email, $ok && $u['status'] === 'active');
        if (!$ok) {
            $error = 'Incorrect email or password.';
        } elseif ($u['status'] === 'disabled') {
            $error = 'Your account has been disabled. Please contact the administrator.';
        } elseif ($u['status'] !== 'active') {
            $error = 'Your account is not active yet. Use the link in your invitation email to set a password.';
        } else {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            login_user($u);
            audit('login');
            $to = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            if ($to && strpos($to, '/') === 0 && strpos($to, '//') !== 0) {
                header('Location: ' . $to);
                exit;
            }
            redirect('index.php');
        }
    }
}

auth_header('Sign in');
?>
<h1>Sign in</h1>
<p class="muted">Use the email address your administrator registered for you.</p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form" autocomplete="on">
  <?= csrf_field() ?>
  <label>Email address
    <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
  </label>
  <label>Password
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button class="btn primary block" type="submit">Sign in</button>
</form>
<p class="auth-links"><a href="<?= url('forgot.php') ?>">Forgot your password?</a></p>
<?php auth_footer();
