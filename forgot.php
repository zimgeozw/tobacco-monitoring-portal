<?php
require __DIR__ . '/app/bootstrap.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower((string)post('email'));
    // Throttle reset requests using the login_attempts table (success=0 rows tagged with a prefix)
    $recent = (int)qv("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)", ['reset:' . $email]);
    if ($recent < 3 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        q('INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?,?,1,NOW())', ['reset:' . $email, client_ip()]);
        $u = q1("SELECT * FROM users WHERE email = ? AND status IN ('active','invited')", [$email]);
        if ($u) {
            $u['status'] === 'invited' ? send_invite($u) : send_reset($u);
            q('INSERT INTO audit_log (user_id, action, details, ip, created_at) VALUES (?,?,?,?,NOW())',
                [$u['id'], 'password_reset_requested', $email, client_ip()]);
        }
    }
    $sent = true; // Same message whether or not the account exists
}

auth_header('Reset password');
?>
<h1>Reset your password</h1>
<?php if ($sent): ?>
  <div class="flash success">If an account exists for that email, we have sent a link to reset the password. Please check your inbox (and spam folder).</div>
  <p class="auth-links"><a href="<?= url('login.php') ?>">Back to sign in</a></p>
<?php else: ?>
  <p class="muted">Enter your email address and we will send you a link to choose a new password.</p>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Email address
      <input type="email" name="email" required autofocus autocomplete="username">
    </label>
    <button class="btn primary block" type="submit">Send reset link</button>
  </form>
  <p class="auth-links"><a href="<?= url('login.php') ?>">Back to sign in</a></p>
<?php endif; ?>
<?php auth_footer();
