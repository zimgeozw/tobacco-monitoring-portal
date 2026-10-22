<?php
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$seasons = q('SELECT * FROM seasons ORDER BY start_date DESC, id DESC')->fetchAll();
$selected = (int)($_GET['season'] ?? ($seasons[0]['id'] ?? 0));
$imports = q('SELECT i.*, s.title, u.name uname FROM imports i LEFT JOIN seasons s ON s.id = i.season_id LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT 15')->fetchAll();
$maxUp = ini_get('upload_max_filesize');

page_header('Upload data', 'admin-upload');
?>
<div class="container">
  <div class="page-head">
    <div><h1 class="page-title">Upload season data</h1>
    <p class="muted">Upload the CSV files exported from Google Earth Engine (Drive folder <code>Tobacco_Exports</code>). Large files are sent in small pieces and imported in the background — keep this page open until it finishes.</p></div>
  </div>

  <?php if (!$seasons): ?>
    <section class="card"><p>Create a season first (this gives the upload its heading, e.g. “2025-2026 Agriculture Season”).</p>
    <a class="btn primary" href="<?= url('admin/seasons.php?new=1') ?>">+ Create season</a></section>
  <?php else: ?>
  <div class="grid-upload">
    <section class="card">
      <form id="uploadForm" class="form" onsubmit="return false">
        <label>1. Season
          <select id="season" name="season_id">
            <?php foreach ($seasons as $s): ?>
              <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $selected ? 'selected' : '' ?>><?= e($s['title']) ?> (<?= $s['status'] === 'published' ? 'published' : 'draft' ?>, <?= fmt_num($s['field_count']) ?> fields)</option>
            <?php endforeach; ?>
          </select>
          <small><a href="<?= url('admin/seasons.php?new=1') ?>">+ Create a new season heading</a></small>
        </label>
        <fieldset class="radios"><legend>2. Existing data for this season</legend>
          <label class="check"><input type="radio" name="mode" value="replace" checked> <span><strong>Replace</strong> — remove this season's existing fields first (recommended)</span></label>
          <label class="check"><input type="radio" name="mode" value="append"> <span><strong>Append</strong> — add to existing fields (e.g. a second province)</span></label>
        </fieldset>
        <div class="dropzone" id="drop" tabindex="0" role="button" aria-label="Choose files to upload">
          <input type="file" id="files" accept=".csv,.zip" multiple hidden>
          <div class="dz-icon" aria-hidden="true">⇪</div>
          <strong>3. Drop CSV or ZIP files here</strong>
          <span class="muted">or click to choose · fields, run-info and training-point files can go together</span>
        </div>
        <div id="queue" class="queue"></div>
      </form>
    </section>
    <aside class="card help">
      <h2>Which files?</h2>
      <dl class="filetypes">
        <dt><span class="pill published">Fields</span> Tobacco_Fields_…csv</dt>
        <dd>One row per detected field with its outline, area, NDVI and location. Required.</dd>
        <dt><span class="pill gold">Run info</span> Tobacco_RunInfo_…csv</dt>
        <dd>Classifier accuracy, number of images and dates. Shown on the dashboard.</dd>
        <dt><span class="pill viewer">Points</span> Tobacco_TrainingPoints_…csv</dt>
        <dd>Optional. Tobacco / non-tobacco reference points for the map.</dd>
      </dl>
      <p class="muted small">The type is detected automatically. Zipping the fields CSV makes the upload around 5× smaller. Fields are assigned to current district boundaries from their centre point.</p>
      <p class="muted small">Server upload limit: <?= e($maxUp) ?> per request (files are chunked, so any size works).</p>
    </aside>
  </div>
  <?php endif; ?>

  <section class="card flush">
    <h2 class="pad">Recent imports</h2>
    <table class="table">
      <thead><tr><th>File</th><th>Season</th><th>Type</th><th class="num">Rows</th><th>Status</th><th>By</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$imports): ?><tr><td colspan="7" class="empty">No uploads yet.</td></tr><?php endif; ?>
      <?php foreach ($imports as $i): ?>
        <tr>
          <td><?= e($i['original_name']) ?><?php if ($i['message']): ?><br><small class="muted"><?= e($i['message']) ?></small><?php endif; ?></td>
          <td><?= e($i['title']) ?></td>
          <td><?= e($i['kind']) ?> · <?= e($i['mode']) ?></td>
          <td class="num"><?= fmt_num($i['rows_done']) ?></td>
          <td><span class="pill <?= $i['status'] === 'done' ? 'published' : ($i['status'] === 'failed' ? 'disabled' : 'draft') ?>"><?= e($i['status']) ?></span></td>
          <td><?= e($i['uname']) ?></td>
          <td><?= fmt_date($i['created_at'], 'j M Y H:i') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>window.PORTAL = { base: <?= json_encode(url('')) ?> };</script>
<?php page_footer([asset('js/upload.js')]);
