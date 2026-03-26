<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WooCommerce → PrestaShop Migration</title>
  <meta name="description" content="Migrate products, categories, attributes and images from WooCommerce to PrestaShop directly via database.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-wrapper">

  <!-- ── Header ──────────────────────────────────────────── -->
  <header class="app-header">
    <div class="app-logo">
      <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="40" height="40" rx="10" fill="#6c63ff"/>
        <path d="M8 14h8l2 8 3-10 3 10 2-8h6" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M26 22l4-4-4-4" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="30" cy="22" r="2" fill="#22c55e"/>
      </svg>
    </div>
    <div>
      <div class="app-title">WooCommerce → PrestaShop</div>
      <div class="app-subtitle">Direct database migration tool · v1.0</div>
    </div>
  </header>

  <!-- ── Step 1: Database Connections ────────────────────── -->
  <div class="card" id="card-connections">
    <div class="card-header">
      <h2>
        <span class="step-badge" id="badge-1">1</span>
        Database Connections
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body">

      <!-- WooCommerce -->
      <div class="db-section-label">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="#7f54b3"><path d="M2 3h20v14H2z" opacity=".2"/><path d="M2 3h20a1 1 0 011 1v12a1 1 0 01-1 1H2a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v10h18V5H3zm3 7l2-5.5 2.5 4 1.5-2 2 3.5H6zm11.5-1a1.5 1.5 0 110-3 1.5 1.5 0 010 3z"/></svg>
        WooCommerce Database
        <span id="wc-status" class="conn-status idle">Not tested</span>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Host</label>
          <input type="text" id="wc-host" value="localhost" placeholder="e.g. localhost or 127.0.0.1">
          <small class="field-hint">Use <code>localhost</code> for Unix socket or <code>127.0.0.1</code> for TCP. MySQL treats them as different hosts for authentication.</small>
        </div>
        <div class="form-group">
          <label>Port</label>
          <input type="text" id="wc-port" value="3306" placeholder="3306">
        </div>
        <div class="form-group">
          <label>Database Name</label>
          <input type="text" id="wc-dbname" placeholder="wordpress_db">
        </div>
        <div class="form-group">
          <label>Username</label>
          <input type="text" id="wc-user" placeholder="db_user" autocomplete="username">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="wc-pass" placeholder="••••••••" autocomplete="current-password">
        </div>
        <div class="form-group">
          <label data-tip="WordPress table prefix (e.g. wp_)">Table Prefix</label>
          <input type="text" id="wc-prefix" value="wp_" placeholder="wp_">
        </div>
        <div class="form-group full">
          <label data-tip="Optional: absolute path to the MySQL Unix socket file (e.g. /var/run/mysqld/mysqld.sock). Leave empty to use the default.">Socket Path <small>(optional)</small></label>
          <input type="text" id="wc-socket" placeholder="/var/run/mysqld/mysqld.sock">
        </div>
        <div class="form-group full">
          <small id="wc-site-url" style="color:var(--text-muted);font-size:.8rem;"></small>
        </div>
      </div>

      <div class="btn-row">
        <button class="btn btn-secondary" id="btn-test-wc">Test Connection</button>
      </div>

      <!-- PrestaShop -->
      <div class="db-section-label" style="margin-top:24px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="#df0067"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
        PrestaShop Database
        <span id="ps-status" class="conn-status idle">Not tested</span>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Host</label>
          <input type="text" id="ps-host" value="localhost" placeholder="e.g. localhost or 127.0.0.1">
          <small class="field-hint">Use <code>localhost</code> for Unix socket or <code>127.0.0.1</code> for TCP.</small>
        </div>
        <div class="form-group">
          <label>Port</label>
          <input type="text" id="ps-port" value="3306" placeholder="3306">
        </div>
        <div class="form-group">
          <label>Database Name</label>
          <input type="text" id="ps-dbname" placeholder="prestashop_db">
        </div>
        <div class="form-group">
          <label>Username</label>
          <input type="text" id="ps-user" placeholder="db_user" autocomplete="username">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="ps-pass" placeholder="••••••••" autocomplete="current-password">
        </div>
        <div class="form-group">
          <label data-tip="PrestaShop table prefix (e.g. ps_)">Table Prefix</label>
          <input type="text" id="ps-prefix" value="ps_" placeholder="ps_">
        </div>
        <div class="form-group full">
          <label data-tip="Optional: absolute path to the MySQL Unix socket file. Leave empty to use the default.">Socket Path <small>(optional)</small></label>
          <input type="text" id="ps-socket" placeholder="/var/run/mysqld/mysqld.sock">
        </div>
        <div class="form-group">
          <label data-tip="PrestaShop major version">PS Version</label>
          <select id="ps-version">
            <option value="8">PrestaShop 8.x</option>
            <option value="1.7" selected>PrestaShop 1.7.x</option>
            <option value="1.6">PrestaShop 1.6.x</option>
          </select>
        </div>
        <div class="form-group">
          <label data-tip="Default language ID (ps_lang table)">Language ID</label>
          <input type="number" id="ps-id-lang" value="1" min="1">
        </div>
        <div class="form-group">
          <label data-tip="Default shop ID (ps_shop table)">Shop ID</label>
          <input type="number" id="ps-id-shop" value="1" min="1">
        </div>
      </div>

      <div class="btn-row">
        <button class="btn btn-secondary" id="btn-test-ps">Test Connection</button>
      </div>

    </div>
  </div>

  <!-- ── Step 2: Analyse ──────────────────────────────────── -->
  <div class="card collapsed" id="card-analyse">
    <div class="card-header">
      <h2>
        <span class="step-badge" id="badge-2">2</span>
        Analysis &amp; Overview
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body">
      <p style="color:var(--text-muted);font-size:.88rem;margin-bottom:14px;">
        Connect to both databases above, then click <strong>Analyse</strong> to see a summary of what will be migrated.
      </p>

      <div class="btn-row" style="margin-top:0;">
        <button class="btn btn-primary" id="btn-analyze">🔍 Analyse</button>
      </div>

      <div id="analysis-results" style="display:none;margin-top:20px;">
        <div class="info-grid">
          <div class="info-box">
            <div class="val" id="info-categories">0</div>
            <div class="lbl">Categories</div>
          </div>
          <div class="info-box">
            <div class="val" id="info-products">0</div>
            <div class="lbl">Products</div>
          </div>
          <div class="info-box">
            <div class="val" id="info-variations">0</div>
            <div class="lbl">Variations</div>
          </div>
          <div class="info-box">
            <div class="val" id="info-attributes">0</div>
            <div class="lbl">Attribute Groups</div>
          </div>
        </div>
        <div style="margin-top:12px;display:flex;gap:16px;flex-wrap:wrap;">
          <small style="color:var(--text-muted);">
            <strong>WooCommerce:</strong> <span id="info-wc-version">—</span>
          </small>
          <small style="color:var(--text-muted);">
            <strong>PrestaShop:</strong> <span id="info-ps-version">—</span>
            · Lang <span id="info-ps-lang">—</span>
            · Shop <span id="info-ps-shop">—</span>
          </small>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Step 3: Field Mapping ─────────────────────────────── -->
  <div class="card collapsed" id="card-mapping">
    <div class="card-header">
      <h2>
        <span class="step-badge" id="badge-3">3</span>
        Field Mapping
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body">
      <div class="alert alert-info">
        The table below shows how WooCommerce fields are mapped to PrestaShop fields during migration. HTML is automatically stripped from text fields.
      </div>
      <div style="overflow-x:auto;">
        <table class="mapping-table">
          <thead>
            <tr>
              <th>WooCommerce Field</th>
              <th></th>
              <th>PrestaShop Field</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody id="mapping-tbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Step 4: Migration Options ────────────────────────── -->
  <div class="card collapsed" id="card-options">
    <div class="card-header">
      <h2>
        <span class="step-badge" id="badge-4">4</span>
        Migration Options
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body">

      <div class="options-grid">
        <label class="option-item">
          <input type="checkbox" id="opt-categories" checked>
          <div>
            <div class="opt-label">📁 Categories</div>
            <div class="opt-desc">Migrate category hierarchy</div>
          </div>
        </label>
        <label class="option-item">
          <input type="checkbox" id="opt-attributes" checked>
          <div>
            <div class="opt-label">🏷️ Attributes</div>
            <div class="opt-desc">Color, size, and other attributes</div>
          </div>
        </label>
        <label class="option-item">
          <input type="checkbox" id="opt-products" checked>
          <div>
            <div class="opt-label">📦 Products</div>
            <div class="opt-desc">Simple and variable products</div>
          </div>
        </label>
        <label class="option-item">
          <input type="checkbox" id="opt-images">
          <div>
            <div class="opt-label">🖼️ Images</div>
            <div class="opt-desc">Download &amp; register product images</div>
          </div>
        </label>
      </div>

      <div class="form-grid" style="margin-top:18px;">
        <div class="form-group">
          <label data-tip="Number of products processed per API call">Batch Size</label>
          <input type="number" id="opt-batch" value="20" min="1" max="100">
        </div>
        <div class="form-group full">
          <label data-tip="Absolute path on server to PrestaShop root (for image download, e.g. /var/www/prestashop). Leave empty to skip file download.">
            PrestaShop Root Path (for images)
          </label>
          <input type="text" id="ps-root-path" placeholder="/var/www/html/prestashop  (optional)">
        </div>
      </div>

    </div>
  </div>

  <!-- ── Step 5: Run Migration ─────────────────────────────── -->
  <div class="card collapsed" id="card-progress">
    <div class="card-header">
      <h2>
        <span class="step-badge" id="badge-5">5</span>
        Migration
        <span class="status-badge idle" id="migration-status">IDLE</span>
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body">

      <!-- Progress bars -->
      <div class="progress-wrap">

        <div class="progress-item" id="progress-categories">
          <div class="progress-row">
            <span class="prog-label">📁 Categories</span>
            <span class="prog-count">0 / 0</span>
          </div>
          <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width:0%"></div>
          </div>
        </div>

        <div class="progress-item" id="progress-attributes">
          <div class="progress-row">
            <span class="prog-label">🏷️ Attributes</span>
            <span class="prog-count">0 / 0</span>
          </div>
          <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width:0%"></div>
          </div>
        </div>

        <div class="progress-item" id="progress-products">
          <div class="progress-row">
            <span class="prog-label">📦 Products</span>
            <span class="prog-count">0 / 0</span>
          </div>
          <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width:0%"></div>
          </div>
        </div>

      </div>

      <!-- Error list (hidden until errors occur) -->
      <div id="error-section" style="display:none;margin-top:16px;">
        <div class="db-section-label">⚠️ Errors</div>
        <ul class="error-list" id="error-list"></ul>
      </div>

      <div class="btn-row">
        <button class="btn btn-primary" id="btn-start">▶ Start Migration</button>
        <button class="btn btn-secondary" id="btn-resume" style="display:none;">↺ Resume</button>
        <button class="btn btn-danger btn-sm" id="btn-reset">✕ Reset Progress</button>
      </div>

    </div>
  </div>

  <!-- ── Debug Console ─────────────────────────────────────── -->
  <div class="card collapsed" id="card-debug">
    <div class="card-header">
      <h2>
        <span class="step-badge" style="background:var(--text-muted)">🐛</span>
        Debug Console
      </h2>
      <svg class="chevron" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/>
      </svg>
    </div>
    <div class="card-body" style="padding:0;">
      <div class="debug-console">
        <div class="debug-toolbar">
          <span>Migration log — real-time output</span>
          <button class="btn btn-sm btn-secondary" id="btn-clear-log"
                  style="font-size:.72rem;padding:3px 10px;">Clear</button>
        </div>
        <div class="debug-log" id="debug-log">
          <div class="log-entry info" style="opacity:.5;">
            <span class="log-ts"></span>
            <span class="log-level">[INFO]</span>
            <span class="log-msg">Waiting for migration to start…</span>
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /.app-wrapper -->

<script src="assets/js/app.js"></script>
</body>
</html>
