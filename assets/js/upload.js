/* Chunked upload + resumable import driver for the admin upload page. Designed by Zimgeo. */
(function () {
  'use strict';
  var CHUNK = 1.5 * 1024 * 1024;
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var base = window.PORTAL.base;
  var drop = document.getElementById('drop');
  var input = document.getElementById('files');
  var queueEl = document.getElementById('queue');
  if (!drop) return;
  var queue = [];
  var busy = false;

  function fmtBytes(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function uid() { var a = new Uint8Array(16); crypto.getRandomValues(a); return Array.from(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }

  drop.addEventListener('click', function () { input.click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
  input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });

  // Import run-info last so totals are already in place; fields first.
  function rank(name) { name = name.toLowerCase(); return name.indexOf('field') >= 0 ? 0 : name.indexOf('runinfo') >= 0 ? 2 : 1; }

  function addFiles(list) {
    var files = Array.prototype.slice.call(list).filter(function (f) { return /\.(csv|zip)$/i.test(f.name); });
    files.sort(function (a, b) { return rank(a.name) - rank(b.name); });
    files.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'qitem';
      row.innerHTML = '<div class="qhead"><strong></strong><span class="muted"></span></div>' +
        '<div class="bar"><span></span></div><div class="qstatus muted">Waiting…</div>';
      row.querySelector('strong').textContent = f.name;
      row.querySelector('.qhead .muted').textContent = fmtBytes(f.size);
      queueEl.appendChild(row);
      queue.push({ file: f, el: row });
    });
    next();
  }

  function setBar(item, pct, text, cls) {
    item.el.querySelector('.bar span').style.width = Math.min(100, pct) + '%';
    item.el.querySelector('.qstatus').textContent = text;
    if (cls) item.el.className = 'qitem ' + cls;
  }

  function post(url, data) {
    return fetch(base + url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { throw new Error('Server error (' + r.status + ')'); }).then(function (j) { if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status)); return j; }); });
  }

  function retry(fn, tries) {
    return fn().catch(function (e) {
      if (tries <= 0 || /out of sequence|Only \.csv|Choose a season|Bad upload/.test(e.message)) throw e;
      return new Promise(function (res) { setTimeout(res, 2500); }).then(function () { return retry(fn, tries - 1); });
    });
  }

  function next() {
    if (busy || !queue.length) return;
    busy = true;
    var item = queue.shift();
    var f = item.file, id = uid(), total = Math.max(1, Math.ceil(f.size / CHUNK));
    var season = document.getElementById('season').value;
    var mode = document.querySelector('input[name="mode"]:checked').value;
    window.onbeforeunload = function () { return 'An upload is still running.'; };

    function sendChunk(i) {
      var fd = new FormData();
      fd.append('upload_id', id); fd.append('index', i); fd.append('total', total);
      fd.append('filename', f.name); fd.append('season_id', season); fd.append('mode', mode);
      fd.append('chunk', f.slice(i * CHUNK, Math.min(f.size, (i + 1) * CHUNK)), 'chunk');
      return retry(function () { return post('api/upload_chunk.php', fd); }, 3).then(function (j) {
        setBar(item, (i + 1) / total * 100, 'Uploading… ' + Math.round((i + 1) / total * 100) + '% (' + fmtBytes(Math.min(f.size, (i + 1) * CHUNK)) + ' of ' + fmtBytes(f.size) + ')', 'uploading');
        return i + 1 < total ? sendChunk(i + 1) : j.import_id;
      });
    }

    function step(importId) {
      var fd = new FormData(); fd.append('import_id', importId);
      return retry(function () { return post('api/import_step.php', fd); }, 4).then(function (p) {
        if (p.status === 'failed') throw new Error(p.message || 'Import failed');
        if (p.status === 'done') return p;
        var label = p.kind && p.kind !== 'unknown' ? p.kind : 'file';
        setBar(item, p.percent, 'Importing ' + label + '… ' + p.percent + '% · ' + p.rows.toLocaleString() + ' rows' + (p.message && p.percent < 1 ? ' · ' + p.message : ''), 'importing');
        return step(importId);
      });
    }

    setBar(item, 0, 'Uploading…', 'uploading');
    sendChunk(0).then(function (importId) {
      setBar(item, 0, 'Upload complete — importing…', 'importing');
      return step(importId);
    }).then(function (p) {
      setBar(item, 100, '✓ ' + (p.message || 'Imported'), 'done');
    }).catch(function (e) {
      setBar(item, 100, '✕ ' + e.message, 'failed');
    }).then(function () {
      busy = false;
      if (queue.length) { next(); } else {
        window.onbeforeunload = null;
        var done = document.createElement('p');
        done.className = 'qdone';
        done.innerHTML = 'All files processed. <a href="' + base + 'admin/seasons.php">Review &amp; publish the season →</a> · <a href="' + base + 'index.php?season=' + season + '">Preview dashboard</a>';
        queueEl.appendChild(done);
      }
    });
  }
})();
