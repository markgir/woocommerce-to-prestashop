/**
 * WooCommerce → PrestaShop Migration Tool
 * Frontend application logic
 */

/* ── State ──────────────────────────────────────────────── */

const App = {
  sessionId: 'sess_' + crypto.randomUUID().replace(/-/g, '').slice(0, 12),
  logOffset : 0,
  pollTimer : null,
  logTimer  : null,
  migrating : false,

  wcConn : null,   // {host, port, dbname, user, password, prefix}
  psConn : null,   // {host, port, dbname, user, password, prefix, id_lang, id_shop, ps_version}
};

/* ── DOM helpers ─────────────────────────────────────────── */

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function showEl(el)   { if (el) el.style.display = ''; }
function hideEl(el)   { if (el) el.style.display = 'none'; }
function disableBtn(btn, label) {
  btn.disabled = true;
  if (label !== undefined) btn.innerHTML = `<span class="spinner"></span> ${label}`;
}
function enableBtn(btn, html) {
  btn.disabled = false;
  if (html !== undefined) btn.innerHTML = html;
}

function setConnStatus(el, status /* ok | err | idle */, msg = '') {
  el.className = 'conn-status ' + status;
  el.textContent = msg || { ok: 'Connected', err: 'Error', idle: 'Not tested' }[status];
}

function toast(msg, type = 'info') {
  const t = document.createElement('div');
  t.className = `alert alert-${type}`;
  t.textContent = msg;
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:999;max-width:340px;box-shadow:0 4px 20px rgba(0,0,0,.15);';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

/* ── API calls ───────────────────────────────────────────── */

async function api(action, body = {}) {
  let resp;
  try {
    resp = await fetch(`api.php?action=${action}`, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify(body),
    });
  } catch (_) {
    throw new Error('Unable to reach the server. Check that the URL is correct and the server is running.');
  }
  const text = await resp.text();
  if (!resp.ok) {
    let errMsg = `HTTP ${resp.status}`;
    try {
      const errData = JSON.parse(text);
      if (errData && errData.error) errMsg = errData.error;
    } catch (_) {
      if (text) errMsg += ' — ' + text.substring(0, 200);
    }
    if (resp.status === 500 && errMsg === 'HTTP 500') {
      errMsg = 'The server returned an internal error (HTTP 500). '
             + 'This usually means the database host is unreachable or PHP timed out. '
             + 'Verify the host, port and that the MySQL server is running.';
    }
    throw new Error(errMsg);
  }
  try {
    return JSON.parse(text);
  } catch (_) {
    throw new Error('Invalid server response (not JSON). Server output: ' + text.substring(0, 200));
  }
}

async function apiGet(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params }).toString();
  let resp;
  try {
    resp = await fetch(`api.php?${qs}`);
  } catch (_) {
    throw new Error('Unable to reach the server.');
  }
  const text = await resp.text();
  if (!resp.ok) {
    let errMsg = `HTTP ${resp.status}`;
    try {
      const errData = JSON.parse(text);
      if (errData && errData.error) errMsg = errData.error;
    } catch (_) {
      if (text) errMsg += ' — ' + text.substring(0, 200);
    }
    if (resp.status === 500 && errMsg === 'HTTP 500') {
      errMsg = 'The server returned an internal error (HTTP 500). '
             + 'This usually means the database host is unreachable or PHP timed out. '
             + 'Verify the host, port and that the MySQL server is running.';
    }
    throw new Error(errMsg);
  }
  try {
    return JSON.parse(text);
  } catch (_) {
    throw new Error('Invalid server response (not JSON). Server output: ' + text.substring(0, 200));
  }
}

/* ── Collect form values ─────────────────────────────────── */

function collectWcConfig() {
  return {
    host    : $('#wc-host').value.trim(),
    port    : $('#wc-port').value.trim() || '3306',
    dbname  : $('#wc-dbname').value.trim(),
    user    : $('#wc-user').value.trim(),
    password: $('#wc-pass').value,
    prefix  : $('#wc-prefix').value.trim() || 'wp_',
  };
}

function collectPsConfig() {
  return {
    host      : $('#ps-host').value.trim(),
    port      : $('#ps-port').value.trim() || '3306',
    dbname    : $('#ps-dbname').value.trim(),
    user      : $('#ps-user').value.trim(),
    password  : $('#ps-pass').value,
    prefix    : $('#ps-prefix').value.trim() || 'ps_',
    ps_version: $('#ps-version').value,
    id_lang   : parseInt($('#ps-id-lang').value) || 1,
    id_shop   : parseInt($('#ps-id-shop').value) || 1,
  };
}

function collectOptions() {
  return {
    migrate_categories: $('#opt-categories').checked,
    migrate_attributes: $('#opt-attributes').checked,
    migrate_products  : $('#opt-products').checked,
    migrate_images    : $('#opt-images').checked,
    ps_root_path      : $('#ps-root-path').value.trim(),
    batch_size        : parseInt($('#opt-batch').value) || 20,
  };
}

/* ── Step 1: Test connections ────────────────────────────── */

async function testWcConnection() {
  const btn = $('#btn-test-wc');
  const statusEl = $('#wc-status');
  const cfg = collectWcConfig();

  disableBtn(btn, 'Testing…');
  setConnStatus(statusEl, 'idle', 'Testing…');

  try {
    const res = await api('test_wc', cfg);
    if (res.success) {
      App.wcConn = cfg;
      setConnStatus(statusEl, 'ok', `v${res.version}`);
      $('#wc-site-url').textContent = res.site_url || '—';
      toast('WooCommerce connected!', 'success');
    } else {
      const msg = res.error || 'Connection failed';
      setConnStatus(statusEl, 'err', 'Failed');
      toast(msg, 'error');
    }
  } catch (e) {
    setConnStatus(statusEl, 'err', 'Error');
    toast(e.message, 'error');
  } finally {
    enableBtn(btn, 'Test Connection');
  }
}

async function testPsConnection() {
  const btn = $('#btn-test-ps');
  const statusEl = $('#ps-status');
  const cfg = collectPsConfig();

  disableBtn(btn, 'Testing…');
  setConnStatus(statusEl, 'idle', 'Testing…');

  try {
    const res = await api('test_ps', cfg);
    if (res.success) {
      App.psConn = cfg;
      setConnStatus(statusEl, 'ok', `v${res.version}`);
      // Pre-fill lang/shop if detected
      if (res.id_lang) $('#ps-id-lang').value = res.id_lang;
      if (res.id_shop) $('#ps-id-shop').value = res.id_shop;
      toast('PrestaShop connected!', 'success');
    } else {
      const msg = res.error || 'Connection failed';
      setConnStatus(statusEl, 'err', 'Failed');
      toast(msg, 'error');
    }
  } catch (e) {
    setConnStatus(statusEl, 'err', 'Error');
    toast(e.message, 'error');
  } finally {
    enableBtn(btn, 'Test Connection');
  }
}

/* ── Step 2: Analyse ─────────────────────────────────────── */

async function analyzeData() {
  if (!App.wcConn || !App.psConn) {
    toast('Please test both connections first.', 'warning');
    return;
  }

  const btn = $('#btn-analyze');
  disableBtn(btn, 'Analysing…');

  try {
    const res = await api('analyze', { wc: collectWcConfig(), ps: collectPsConfig() });
    if (res.success) {
      const info = res.info;
      $('#info-wc-version').textContent = info.wc_version;
      $('#info-ps-version').textContent = info.ps_version;
      $('#info-categories').textContent = info.categories;
      $('#info-products').textContent   = info.products;
      $('#info-variations').textContent = info.variations;
      $('#info-attributes').textContent = info.attributes;
      // Pre-fill lang/shop from detected values
      if (info.id_lang) $('#ps-id-lang').value = info.id_lang;
      if (info.id_shop) $('#ps-id-shop').value = info.id_shop;
      showEl($('#analysis-results'));
      expandCard($('#card-options'));
      expandCard($('#card-progress'));
      toast('Analysis complete!', 'success');
    } else {
      toast(res.error || 'Analysis failed', 'error');
    }
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  } finally {
    enableBtn(btn, '🔍 Analyse');
  }
}

/* ── Card collapse/expand ────────────────────────────────── */

function toggleCard(card) {
  card.classList.toggle('collapsed');
}

function expandCard(card) {
  card.classList.remove('collapsed');
}

function collapseCard(card) {
  card.classList.add('collapsed');
}

/* ── Step 4: Migration control ───────────────────────────── */

async function startMigration() {
  if (!App.wcConn || !App.psConn) {
    toast('Please test both connections first.', 'warning');
    return;
  }

  const btn = $('#btn-start');
  disableBtn(btn, 'Starting…');
  App.migrating = true;

  App.logOffset = 0;
  $('#debug-log').innerHTML = '';

  expandCard($('#card-progress'));
  expandCard($('#card-debug'));

  await runMigrationStep();
}

async function resumeMigration() {
  if (!App.wcConn || !App.psConn) {
    toast('Please test both connections first.', 'warning');
    return;
  }
  App.migrating = true;
  disableBtn($('#btn-resume'), 'Resuming…');
  await runMigrationStep();
}

async function runMigrationStep() {
  const body = {
    session_id: App.sessionId,
    wc        : collectWcConfig(),
    ps        : collectPsConfig(),
    options   : collectOptions(),
  };

  try {
    const res = await api('step', body);
    if (res.success) {
      updateProgressUI(res.state);
      await fetchNewLogs();

      if (res.state.status === 'running') {
        // Schedule next batch
        App.pollTimer = setTimeout(runMigrationStep, 300);
      } else {
        App.migrating = false;
        stopPolling();
        enableBtn($('#btn-start'), '▶ Start Migration');
        enableBtn($('#btn-resume'), '↺ Resume');
        if (res.state.status === 'completed') {
          toast('Migration completed successfully! 🎉', 'success');
        } else if (res.state.status === 'error') {
          toast('Migration stopped due to an error. Check the debug log.', 'error');
          showErrors(res.state.errors || []);
        }
      }
    } else {
      App.migrating = false;
      stopPolling();
      enableBtn($('#btn-start'), '▶ Start Migration');
      toast(res.error || 'Migration failed', 'error');
    }
  } catch (e) {
    App.migrating = false;
    stopPolling();
    enableBtn($('#btn-start'), '▶ Start Migration');
    toast('Network error during migration: ' + e.message, 'error');
  }
}

function stopPolling() {
  if (App.pollTimer) { clearTimeout(App.pollTimer); App.pollTimer = null; }
  if (App.logTimer)  { clearTimeout(App.logTimer);  App.logTimer  = null; }
}

async function resetMigration() {
  if (!confirm('Reset all migration progress? This cannot be undone.')) return;

  const body = {
    session_id: App.sessionId,
    wc        : collectWcConfig(),
    ps        : collectPsConfig(),
  };

  try {
    await api('reset', body);
    App.logOffset = 0;
    $('#debug-log').innerHTML = '';
    updateProgressUI({ status: 'idle', step: '', done_categories: 0, total_categories: 0,
                       done_products: 0, total_products: 0, done_attrs: 0, total_attrs: 0 });
    toast('Progress reset.', 'info');
  } catch (e) {
    toast('Reset failed: ' + e.message, 'error');
  }
}

/* ── Progress UI ─────────────────────────────────────────── */

function updateProgressUI(state) {
  // Status badge
  const badge = $('#migration-status');
  if (badge) {
    badge.className = 'status-badge ' + (state.status || 'idle');
    badge.textContent = (state.status || 'idle').toUpperCase();
  }

  setProgress('progress-categories',
    state.done_categories || 0, state.total_categories || 0);
  setProgress('progress-attributes',
    state.done_attrs      || 0, state.total_attrs      || 0);
  setProgress('progress-products',
    state.done_products   || 0, state.total_products   || 0);

  // Show/hide resume button
  const resumeBtn = $('#btn-resume');
  if (resumeBtn) {
    if (state.status === 'error' || state.status === 'paused') {
      showEl(resumeBtn);
    } else {
      hideEl(resumeBtn);
    }
    enableBtn(resumeBtn, '↺ Resume');
  }
}

function setProgress(id, done, total) {
  const wrap = $(`#${id}`);
  if (!wrap) return;
  const fill  = wrap.querySelector('.progress-bar-fill');
  const count = wrap.querySelector('.prog-count');
  const pct   = total > 0 ? Math.round((done / total) * 100) : 0;
  if (fill) {
    fill.style.width = pct + '%';
    if (done >= total && total > 0) fill.classList.add('done');
    else fill.classList.remove('done');
  }
  if (count) count.textContent = `${done} / ${total}`;
}

/* ── Debug log polling ───────────────────────────────────── */

async function fetchNewLogs() {
  try {
    const res = await apiGet('get_logs', {
      session_id: App.sessionId,
      offset    : App.logOffset,
    });
    if (res.success && res.entries && res.entries.length) {
      appendLogEntries(res.entries);
      App.logOffset = res.next_offset;
    }
  } catch (_) { /* silent */ }
}

function appendLogEntries(entries) {
  const log = $('#debug-log');
  if (!log) return;
  entries.forEach(entry => {
    const el = document.createElement('div');
    el.className = `log-entry ${entry.level}`;
    const ts  = entry.ts ? entry.ts.split(' ')[1] : '';
    const ctx = entry.context ? ' ' + JSON.stringify(entry.context) : '';
    el.innerHTML =
      `<span class="log-ts">${ts}</span>` +
      `<span class="log-level">[${entry.level.toUpperCase()}]</span>` +
      `<span class="log-msg">${escHtml(entry.message + ctx)}</span>`;
    log.appendChild(el);
  });
  log.scrollTop = log.scrollHeight;
}

function escHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function clearDebugLog() {
  const log = $('#debug-log');
  if (log) log.innerHTML = '';
  App.logOffset = 0;
}

/* ── Error list ─────────────────────────────────────────── */

function showErrors(errors) {
  const el = $('#error-list');
  if (!el) return;
  el.innerHTML = '';
  if (!errors.length) { hideEl(el.parentElement); return; }
  showEl(el.parentElement);
  errors.forEach(e => {
    const li = document.createElement('li');
    li.textContent = `[${e.step}] ${e.title || ''}: ${e.message}`;
    el.appendChild(li);
  });
}

/* ── Field mapping table ─────────────────────────────────── */

const FIELD_MAP = [
  { wc: 'post_title',          ps: 'ps_product_lang.name',              note: 'Product name' },
  { wc: 'post_content',        ps: 'ps_product_lang.description',        note: 'Long description (HTML stripped)' },
  { wc: 'post_excerpt',        ps: 'ps_product_lang.description_short',  note: 'Short description (HTML stripped)' },
  { wc: '_regular_price',      ps: 'ps_product.price',                  note: 'Base price' },
  { wc: '_sale_price',         ps: 'ps_specific_price.price',           note: 'Sale / special price' },
  { wc: '_sku',                ps: 'ps_product.reference',              note: 'Product reference' },
  { wc: '_weight',             ps: 'ps_product.weight',                 note: 'Weight' },
  { wc: '_length',             ps: 'ps_product.depth',                  note: 'Depth / length' },
  { wc: '_width',              ps: 'ps_product.width',                  note: 'Width' },
  { wc: '_height',             ps: 'ps_product.height',                 note: 'Height' },
  { wc: '_stock_quantity',     ps: 'ps_stock_available.quantity',       note: 'Stock quantity' },
  { wc: '_manage_stock',       ps: 'ps_product.advanced_stock_management', note: 'Stock management' },
  { wc: '_thumbnail_id',       ps: 'ps_image (cover)',                  note: 'Cover image' },
  { wc: '_product_image_gallery', ps: 'ps_image (gallery)',             note: 'Additional images' },
  { wc: 'term (product_cat)',  ps: 'ps_category',                       note: 'Category assignment' },
  { wc: 'pa_* (attributes)',   ps: 'ps_attribute / ps_attribute_group', note: 'Product attributes' },
  { wc: 'product_variation',   ps: 'ps_product_attribute (combination)', note: 'Variable product variants' },
];

function renderMappingTable() {
  const tbody = $('#mapping-tbody');
  if (!tbody) return;
  tbody.innerHTML = FIELD_MAP.map(row =>
    `<tr>
      <td><span class="badge-wc">${escHtml(row.wc)}</span></td>
      <td><span class="arrow">→</span></td>
      <td><span class="badge-ps">${escHtml(row.ps)}</span></td>
      <td style="color:var(--text-muted)">${escHtml(row.note)}</td>
    </tr>`
  ).join('');
}

/* ── Card toggle wiring ──────────────────────────────────── */

function wireCardToggles() {
  $$('.card-header').forEach(header => {
    header.addEventListener('click', () => {
      toggleCard(header.closest('.card'));
    });
  });
}

/* ── Init ────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
  wireCardToggles();
  renderMappingTable();

  // Wire buttons
  $('#btn-test-wc')?.addEventListener('click', testWcConnection);
  $('#btn-test-ps')?.addEventListener('click', testPsConnection);
  $('#btn-analyze')?.addEventListener('click', analyzeData);
  $('#btn-start')?.addEventListener('click', startMigration);
  $('#btn-resume')?.addEventListener('click', resumeMigration);
  $('#btn-reset')?.addEventListener('click', resetMigration);
  $('#btn-clear-log')?.addEventListener('click', clearDebugLog);

  // Hide resume button initially
  hideEl($('#btn-resume'));

  // Auto-scroll log
  setInterval(async () => {
    if (App.migrating) await fetchNewLogs();
  }, 1500);
});
