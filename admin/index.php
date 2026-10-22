<?php
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$seasons = q('SELECT * FROM seasons ORDER BY start_date DESC, id DESC')->fetchAll();
$userStats = q1("SELECT COUNT(*) total, SUM(status='active') active, SUM(status='invited') invited, SUM(role='admin') admins FROM users");
$fieldTotal = (int)qv('SELECT COUNT(*) FROM fields');
$recent = q('SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8')->fetchAll();
$exports30 = (int)qv("SELECT COUNT(*) FROM audit_log WHERE action = 'export' AND created_at > NOW() - INTERVAL 30 DAY");

page_header('Administration', 'admin');
?>
<div class="container">
  <h1 class="page-title">Administration</h1>
  <div class="kpis admin-kpis">
    <div class="kpi"><span class="kpi-label">Seasons</span><span class="kpi-value"><?= count($seasons) ?></span><span class="kpi-sub"><?= count(array_filter($seasons, fn($s) => $s['status'] === 'published')) ?> published</span></div>
    <div class="kpi"><span class="kpi-label">Fields stored</span><span class="kpi-value"><?= fmt_num($fieldTotal) ?></span><span class="kpi-sub">across all seasons</span></div>
    <div class="kpi"><span class="kpi-label">Users</span><span class="kpi-value"><?= (int)$userStats['total'] ?></span><span class="kpi-sub"><?= (int)$userStats['active'] ?> active · <?= (int)$userStats['invited'] ?> invited</span></div>
    <div class="kpi"><span class="kpi-label">Downloads (30 days)</span><span class="kpi-value"><?= $exports30 ?></span><span class="kpi-sub">Excel, CSV &amp; GeoJSON</span></div>
  </div>

  <div class="steps card">
    <h2>New season checklist</h2>
    <ol class="checklist">
      <li><strong>Earth Engine:</strong> set <code>SEASON</code>, dates and training points in <code>gee/tobacco_field_export.js</code>, run it, then run the export tasks.</li>
      <li><a href="<?= url('admin/seasons.php?new=1') ?>">Create the season heading</a>, e.g. “2026-2027 Agriculture Season”.</li>
      <li><a href="<?= url('admin/upload.php') ?>">Upload</a> the Fields, RunInfo (and optional TrainingPoints) CSVs from Google Drive.</li>
      <li>Preview it on the dashboard, then <a href="<?= url('admin/seasons.php') ?>">publish</a> — users who opted in get an email.</li>
    </ol>
  </div>

  <div class="grid-2">
    <section class="card flush">
      <h2 class="pad">Seasons</h2>
      <table class="table">
        <thead><tr><th>Heading</th><th class="num">Area (ha)</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$seasons): ?><tr><td colspan="3" class="empty"><a href="<?= url('admin/seasons.php?new=1') ?>">Create the first season</a></td></tr><?php endif; ?>
        <?php foreach ($seasons as $s): ?>
          <tr><td><a href="<?= url('admin/seasons.php?edit=' . $s['id']) ?>"><?= e($s['title']) ?></a></td><td class="num"><?= fmt_num($s['total_ha'], 1) ?></td>
          <td><span class="pill <?= $s['status'] ?>"><?= $s['status'] === 'published' ? 'Published' : 'Draft' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <section class="card flush">
      <h2 class="pad">Recent activity <a class="small" href="<?= url('admin/activity.php') ?>">View all</a></h2>
      <table class="table">
        <tbody>
        <?php foreach ($recent as $l): ?>
          <tr><td class="small nowrap"><?= fmt_date($l['created_at'], 'j M H:i') ?></td><td><?= e($l['name'] ?? '—') ?></td><td><code><?= e($l['action']) ?></code> <span class="small muted"><?= e(mb_strimwidth((string)$l['details'], 0, 60, '…')) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>
</div>
<?php page_footer();
