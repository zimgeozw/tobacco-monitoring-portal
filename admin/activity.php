<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'testmail') {
        $to = filter_var(post('to'), FILTER_VALIDATE_EMAIL) ?: $admin['email'];
        $ok = send_mail($to, $admin['name'], 'Test email from the portal',
            '<p>This is a test message from the ' . e(app_name()) . '. If you can read this, outgoing email is working.</p>');
        audit('test_email', $to);
        flash($ok ? 'success' : 'error', $ok ? "Test email sent to $to." : 'Sending failed. See the mail log below and check the SMTP settings in config.php.');
    }
    redirect('admin/activity.php');
}

$log = q('SELECT a.*, u.name, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
$mailLog = is_file(STORAGE . '/logs/mail.log') ? array_slice(file(STORAGE . '/logs/mail.log', FILE_IGNORE_NEW_LINES), -25) : [];
$mc = cfg('mail', []);

page_header('Activity & email', 'admin-activity');
?>
<div class="container">
  <h1 class="page-title">Activity &amp; email</h1>
  <div class="grid-2">
    <section class="card">
      <h2>Outgoing email</h2>
      <dl class="kv">
        <dt>Method</dt><dd><?= !empty($mc['host']) ? 'SMTP ' . e($mc['host']) . ':' . (int)$mc['port'] . ' (' . e(strtoupper((string)$mc['secure'])) . ')' : 'PHP mail()' ?></dd>
        <dt>Sends as</dt><dd><?= e(($mc['from_name'] ?? '') . ' <' . ($mc['from_email'] ?? '') . '>') ?></dd>
      </dl>
      <form method="post" class="form inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="testmail">
        <input type="email" name="to" value="<?= e($admin['email']) ?>" aria-label="Send test to">
        <button class="btn" type="submit">Send test email</button>
      </form>
      <p class="muted small">Emails sent: invitations, password resets, password-change alerts, new-season notifications, and admin alerts (activations, completed imports). Settings live in <code>config.php</code> on the server.</p>
    </section>
    <section class="card">
      <h2>Mail log <small class="muted">(latest 25)</small></h2>
      <pre class="log"><?= $mailLog ? e(implode("\n", array_reverse($mailLog))) : 'No emails sent yet.' ?></pre>
    </section>
  </div>
  <section class="card flush">
    <h2 class="pad">Activity log</h2>
    <table class="table">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr><td class="small nowrap"><?= fmt_date($l['created_at'], 'j M Y H:i') ?></td><td><?= e($l['name'] ?? '—') ?></td>
        <td><code><?= e($l['action']) ?></code></td><td class="small"><?= e($l['details']) ?></td><td class="small muted"><?= e($l['ip']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php page_footer();
