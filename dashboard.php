<?php
/** Activity & audit dashboard: AG Grid views of coins, PayPal and every AI agent action. */
declare(strict_types=1);

define('TTT_GRID_PAGE', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login_page();
$admin = (int) $me['is_admin'] === 1;

page_head('TikTato · Activity', 'app dash', ['admin' => $admin ? '1' : '0', 'nonce' => csp_nonce()]);
?>
<header class="topbar">
  <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="Back to TikTato"><?= logo_svg('logo') ?><span class="brand-word">Tik<span>Tato</span></span></a>
  <a class="btn btn-ghost dash-back" href="<?= e(url('index.php')) ?>">‹ Arena</a>
</header>

<main class="dash-main">
  <div class="dash-head">
    <div>
      <h1 class="screen-title">Activity &amp; audit</h1>
      <p class="screen-sub">Every coin, every PayPal payment and every action Tato took, updated live.</p>
    </div>
    <div class="dash-head-right">
      <?php if ($admin): ?>
      <div class="seg dash-scope" id="scopeSeg">
        <button type="button" data-scope="me" class="on">My account</button>
        <button type="button" data-scope="platform">Platform</button>
      </div>
      <?php endif; ?>
      <span class="live-dot">LIVE</span>
    </div>
  </div>

  <section class="kpis" id="kpis" aria-label="Key numbers"></section>

  <div class="dash-tabs" id="dashTabs" role="tablist">
    <button type="button" role="tab" data-tab="agent" class="on">🤖 Agent actions <b data-count="agent"></b></button>
    <button type="button" role="tab" data-tab="ledger">🧾 Wallet ledger <b data-count="ledger"></b></button>
    <button type="button" role="tab" data-tab="orders">💳 PayPal orders <b data-count="orders"></b></button>
    <button type="button" role="tab" data-tab="matches">⚔️ Matches <b data-count="matches"></b></button>
  </div>

  <div class="dash-tools">
    <label class="field-box dash-search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3a7 7 0 0 1 5.6 11.2l5.1 5.1-1.4 1.4-5.1-5.1A7 7 0 1 1 10 3Zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10Z"/></svg>
      <input id="dashSearch" type="search" placeholder="Search every column…" aria-label="Search the table">
    </label>
    <button class="btn btn-ghost" id="dashCsv" type="button">Export CSV</button>
  </div>
  <p class="note" id="dashHint"></p>

  <div id="grid" class="dash-grid"></div>
  <p class="note center dash-foot">Tables powered by AG Grid Community (MIT). Amounts are PayPal sandbox test money.</p>
</main>
<?php
page_foot(['vendor/ag-grid-community-36.2.0.min.js', 'dashboard.js']);
