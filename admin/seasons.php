<?php
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/importer.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $code = preg_replace('/[^0-9A-Za-z\-]/', '', (string)post('code'));
        $title = mb_substr((string)post('title'), 0, 160);
        $bench = (float)post('yield_benchmark_t_ha');
        if ($code === '' || $title === '' || $bench <= 0) {
            flash('error', 'Season code, heading and a yield benchmark above zero are required.');
            redirect('admin/seasons.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        $dup = qv('SELECT id FROM seasons WHERE code = ? AND id <> ?', [$code, $id]);
        if ($dup) {
            flash('error', "A season with code $code already exists.");
            redirect('admin/seasons.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        $basemap = trim((string)post('basemap_url'));
        if ($basemap !== '' && (!preg_match('#^https://#', $basemap) || strpos($basemap, '{z}') === false || strpos($basemap, '{x}') === false || strpos($basemap, '{y}') === false)) {
            flash('error', 'The season imagery address must start with https:// and contain {z}, {x} and {y}.');
            redirect('admin/seasons.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        $maxz = (int)post('basemap_maxzoom') ?: null;
        $vals = [$code, $title, (string)post('description'), (string)post('province') ?: 'Mashonaland West',
            post('start_date') ?: null, post('end_date') ?: null, $bench, $basemap ?: null, $maxz];
        if ($id) {
            $old = q1('SELECT * FROM seasons WHERE id = ?', [$id]);
            q('UPDATE seasons SET code=?, title=?, description=?, province=?, start_date=?, end_date=?, yield_benchmark_t_ha=?, basemap_url=?, basemap_maxzoom=?, updated_at=NOW() WHERE id=?', array_merge($vals, [$id]));
            if ($old && abs((float)$old['yield_benchmark_t_ha'] - $bench) > 0.0001) {
                recalc_yield($id);
                flash('info', 'Yield benchmark changed — estimated yields were recalculated for all fields.');
            }
            audit('season_updated', $title);
        } else {
            q('INSERT INTO seasons (code, title, description, province, start_date, end_date, yield_benchmark_t_ha, basemap_url, basemap_maxzoom, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
                array_merge($vals, [$admin['id']]));
            $id = (int)db()->lastInsertId();
            audit('season_created', $title);
        }
        flash('success', 'Season saved.');
        redirect(post('then') === 'upload' ? 'admin/upload.php?season=' . $id : 'admin/seasons.php');
    }

    $s = q1('SELECT * FROM seasons WHERE id = ?', [$id]);
    if (!$s) {
        redirect('admin/seasons.php');
    }
    if ($action === 'publish') {
        if ((int)$s['field_count'] === 0) {
            flash('error', 'Upload the field data before publishing this season.');
            redirect('admin/seasons.php');
        }
        q("UPDATE seasons SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?", [$id]);
        if (!qv("SELECT COUNT(*) FROM seasons WHERE is_default = 1 AND status = 'published'")) {
            q('UPDATE seasons SET is_default = (id = ?)', [$id]);
        }
        audit('season_published', $s['title']);
        $msg = 'Published “' . $s['title'] . '”.';
        if (isset($_POST['notify'])) {
            $n = notify_season_published(q1('SELECT * FROM seasons WHERE id = ?', [$id]));
            $msg .= " Notification emailed to $n user" . ($n === 1 ? '' : 's') . '.';
        }
        flash('success', $msg);
    } elseif ($action === 'unpublish') {
        q("UPDATE seasons SET status = 'draft', is_default = 0 WHERE id = ?", [$id]);
        audit('season_unpublished', $s['title']);
        flash('info', 'Season moved back to draft. Viewers can no longer see it.');
    } elseif ($action === 'default') {
        q('UPDATE seasons SET is_default = (id = ?)', [$id]);
        audit('season_default', $s['title']);
        flash('success', '“' . $s['title'] . '” is now the season users see first.');
    } elseif ($action === 'delete') {
        if (post('confirm') !== $s['code']) {
            flash('error', 'Type the season code to confirm deletion.');
            redirect('admin/seasons.php?edit=' . $id);
        }
        while (q('DELETE FROM fields WHERE season_id = ? LIMIT 20000', [$id])->rowCount() > 0) {
        }
        q('DELETE FROM training_points WHERE season_id = ?', [$id]);
        q('DELETE FROM imports WHERE season_id = ?', [$id]);
        q('DELETE FROM seasons WHERE id = ?', [$id]);
        refresh_season_totals($id);
        audit('season_deleted', $s['title']);
        flash('success', 'Season and its data were deleted.');
    }
    redirect('admin/seasons.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? q1('SELECT * FROM seasons WHERE id = ?', [$editId]) : null;
$isNew = isset($_GET['new']) && !$edit;
$seasons = q('SELECT * FROM seasons ORDER BY start_date DESC, id DESC')->fetchAll();

page_header('Seasons', 'admin-seasons');
?>
<div class="container">
  <div class="page-head">
    <div><h1 class="page-title">Seasons</h1><p class="muted">Each season has a heading (e.g. “2025-2026 Agriculture Season”) shown at the top of the dashboard. Upload data into a draft, check it, then publish.</p></div>
    <a class="btn primary" href="<?= url('admin/seasons.php?new=1') ?>">+ New season</a>
  </div>

<?php if ($edit || $isNew):
    $s = $edit ?: ['id' => 0, 'code' => '', 'title' => '', 'description' => '', 'province' => 'Mashonaland West', 'start_date' => '', 'end_date' => '', 'yield_benchmark_t_ha' => '2.2', 'basemap_url' => '', 'basemap_maxzoom' => 15, 'default_view' => null];
?>
  <section class="card">
    <h2><?= $edit ? 'Edit season' : 'New season' ?></h2>
    <form method="post" class="form grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <label class="span2">Season heading <small class="muted">(shown on the dashboard)</small>
        <input name="title" value="<?= e($s['title']) ?>" placeholder="2025-2026 Agriculture Season" required maxlength="160" id="title">
      </label>
      <label>Season code <small class="muted">(short, unique)</small>
        <input name="code" value="<?= e($s['code']) ?>" placeholder="2025-26" required maxlength="20" id="code">
      </label>
      <label>Province <input name="province" value="<?= e($s['province']) ?>"></label>
      <label>Imagery start date <input type="date" name="start_date" value="<?= e($s['start_date']) ?>"></label>
      <label>Imagery end date <input type="date" name="end_date" value="<?= e($s['end_date']) ?>"></label>
      <label>Yield benchmark (t/ha) <input type="number" step="0.01" min="0.1" name="yield_benchmark_t_ha" value="<?= e((float)$s['yield_benchmark_t_ha']) ?>" required>
        <small class="muted">Estimated yield = area × benchmark. Changing it recalculates all fields.</small></label>
      <label class="span2">Season imagery tiles <small class="muted">(optional — Sentinel-2 basemap for this season's dates, exported from Earth Engine; see gee/ script)</small>
        <input name="basemap_url" value="<?= e($s['basemap_url']) ?>" placeholder="https://storage.googleapis.com/YOUR-BUCKET/timb/2025-26/{z}/{x}/{y}" maxlength="500"></label>
      <label>Imagery max zoom <input type="number" name="basemap_maxzoom" min="8" max="18" value="<?= e($s['basemap_maxzoom'] ?: 15) ?>"></label>
      <label>Default map view <input value="<?= $s['default_view'] ? e(implode(', ', array_map(fn($k, $v) => "$k $v", array_keys(json_decode($s['default_view'], true)), json_decode($s['default_view'], true)))) : 'Whole season (set from the dashboard map)' ?>" disabled></label>
      <label class="span2">Notes for users <small class="muted">(optional, shown under the heading)</small>
        <textarea name="description" rows="2" maxlength="600" placeholder="e.g. Preliminary results — ground verification in progress."><?= e($s['description']) ?></textarea></label>
      <div class="span2 actions">
        <button class="btn primary" type="submit" name="then" value="list">Save season</button>
        <?php if (!$edit): ?><button class="btn" type="submit" name="then" value="upload">Save &amp; upload data →</button><?php endif; ?>
        <a class="btn ghost" href="<?= url('admin/seasons.php') ?>">Cancel</a>
      </div>
    </form>
    <?php if ($edit): ?>
    <details class="danger-zone">
      <summary>Delete this season</summary>
      <form method="post" class="form inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <p>This permanently removes the season and all <?= fmt_num($edit['field_count']) ?> fields. Type <code><?= e($edit['code']) ?></code> to confirm.</p>
        <input name="confirm" required autocomplete="off"><button class="btn danger" type="submit">Delete season</button>
      </form>
    </details>
    <?php endif; ?>
  </section>
<?php endif; ?>

  <section class="card flush">
    <table class="table">
      <thead><tr><th>Season heading</th><th>Code</th><th>Imagery</th><th class="num">Fields</th><th class="num">Area (ha)</th><th class="num">Accuracy</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$seasons): ?><tr><td colspan="8" class="empty">No seasons yet. Create the first one, e.g. “2025-2026 Agriculture Season”.</td></tr><?php endif; ?>
      <?php foreach ($seasons as $s): ?>
        <tr>
          <td><strong><?= e($s['title']) ?></strong><?= $s['is_default'] ? ' <span class="pill gold">Default</span>' : '' ?></td>
          <td><code><?= e($s['code']) ?></code></td>
          <td><?= fmt_date($s['start_date']) ?> – <?= fmt_date($s['end_date']) ?></td>
          <td class="num"><?= fmt_num($s['field_count']) ?></td>
          <td class="num"><?= fmt_num($s['total_ha'], 1) ?></td>
          <td class="num"><?= $s['classifier_accuracy'] !== null ? fmt_num($s['classifier_accuracy'] * 100, 1) . '%' : '—' ?></td>
          <td><span class="pill <?= $s['status'] ?>"><?= $s['status'] === 'published' ? 'Published' : 'Draft' ?></span></td>
          <td class="row-actions">
            <a class="btn sm ghost" href="<?= url('admin/seasons.php?edit=' . $s['id']) ?>">Edit</a>
            <a class="btn sm ghost" href="<?= url('admin/upload.php?season=' . $s['id']) ?>">Upload</a>
            <a class="btn sm ghost" href="<?= url('index.php?season=' . $s['id']) ?>">View</a>
            <?php if ($s['status'] === 'draft'): ?>
              <form method="post" class="publish-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="publish">
                <label class="check sm" title="Email users who opted in"><input type="checkbox" name="notify" checked> email users</label>
                <button class="btn sm primary" type="submit">Publish</button></form>
            <?php else: ?>
              <?php if (!$s['is_default']): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="default"><button class="btn sm" type="submit">Make default</button></form>
              <?php endif; ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="unpublish"><button class="btn sm ghost" type="submit">Unpublish</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
// Suggest a code from the heading, e.g. "2025-2026 Agriculture Season" → "2025-26"
(function () {
  var t = document.getElementById('title'), c = document.getElementById('code');
  if (!t || !c) return;
  t.addEventListener('input', function () {
    if (c.dataset.touched) return;
    var m = t.value.match(/(20\d\d)\s*[-–\/]\s*(?:20)?(\d\d)/);
    if (m) c.value = m[1] + '-' + m[2];
  });
  c.addEventListener('input', function () { c.dataset.touched = 1; });
})();
</script>
<?php page_footer();
