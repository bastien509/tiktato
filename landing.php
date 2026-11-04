<?php
/**
 * Public landing page (logged-out visitors, hackathon judges).
 * Included by index.php; every number in the proof strip is live from the database.
 */
declare(strict_types=1);

if (!defined('TTT')) {
    require __DIR__ . '/inc/bootstrap.php';
    require __DIR__ . '/inc/game.php';
    require __DIR__ . '/inc/paypal.php';
    require __DIR__ . '/inc/layout.php';
}

$n = fn (string $sql) => (int) q($sql)->fetchColumn();
$stats = [
    'usd'       => $n("SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders WHERE status = 'completed'") / 100,
    'orders'    => $n("SELECT COUNT(*) FROM paypal_orders WHERE status = 'completed'"),
    'autopilot' => $n("SELECT COUNT(*) FROM paypal_orders WHERE source = 'mandate' AND status = 'completed'"),
    'agent'     => $n('SELECT COUNT(*) FROM agent_log'),
    'matches'   => $n("SELECT COUNT(*) FROM matches WHERE status = 'finished'"),
];
$escrowHeld = $n("SELECT COALESCE(SUM(stake), 0) FROM challenges WHERE status = 'open'") + $n("SELECT COALESCE(SUM(pot), 0) FROM matches WHERE status = 'active'");
$balanced = $n('SELECT COALESCE(SUM(coins), 0) FROM users') === $n("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'user'")
    && $n("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'escrow'") === $escrowHeld
    && $n('SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger') === $n("SELECT COALESCE(SUM(coins_gross), 0) FROM paypal_orders WHERE status = 'completed'");

$img = fn (string $f) => e(url('assets/landing/' . $f)) . '?v=' . TTT_ASSET_VER;

page_head(
    'TikTato · Agentic payments with PayPal + Claude',
    'landing',
    [],
    ['landing.css'],
    'TikTato: an AI wallet agent that pays with PayPal for you, only inside a mandate you approve. Live 1v1 tic-tac-toe contests with guardrails and an audit trail.'
);
?>
<header class="ln-nav" id="lnNav">
  <a class="brand" href="#top" aria-label="TikTato"><?= logo_svg('logo') ?><span class="brand-word">Tik<span>Tato</span></span></a>
  <nav class="ln-links" aria-label="Sections">
    <a href="#autopilot">Autopilot</a>
    <a href="#paypal">PayPal</a>
    <a href="#tato">AI agent</a>
    <a href="#game">Game</a>
    <a href="#watch">Watch</a>
    <a href="#judges">For judges</a>
  </nav>
  <a class="btn btn-cta ln-nav-cta" href="<?= e(url('login.php')) ?>">Play now</a>
</header>

<main id="top">
  <!-- HERO -->
  <section class="ln-hero">
    <div class="ln-hero-copy">
      <span class="ln-pill reveal">PayPal AI Hackathon · live in the PayPal sandbox</span>
      <h1 class="reveal">The agent can pay.<br><span>Only inside the mandate you approve.</span></h1>
      <p class="ln-lead reveal">TikTato is a live 1v1 tic-tac-toe arena where <b>Tato</b>, a Claude-powered wallet agent, buys coins with your <b>saved PayPal account</b>, with no redirect and never beyond the limits you set.</p>
      <div class="ln-ctas reveal">
        <a class="btn btn-cta" href="<?= e(url('login.php')) ?>">Try the live demo →</a>
        <a class="btn btn-ghost" href="#watch">▶ Watch the 2-min demo</a>
      </div>
      <div class="ln-creds reveal">
        <div>
          <small>Demo login</small>
          <b><span id="credUser">alice545</span> · <span id="credPass">Rimpel12</span></b>
          <small>PayPal is already saved and Autopilot is on.</small>
        </div>
        <button class="btn btn-ghost" type="button" id="copyCreds">Copy</button>
      </div>
    </div>
    <div class="ln-hero-art" aria-hidden="true">
      <div class="ln-glow"></div>
      <figure class="ln-phone p1"><img src="<?= $img('tato-checkout.jpg') ?>" alt=""></figure>
      <figure class="ln-phone p2"><img src="<?= $img('autopilot-charge.jpg') ?>" alt=""></figure>
      <figure class="ln-phone p3"><img src="<?= $img('winner.jpg') ?>" alt=""></figure>
      <span class="ln-float f1">+475 coins · no redirect</span>
      <span class="ln-float f2">PayPal Vault v3</span>
      <span class="ln-float f3">Claude Opus 5.5</span>
    </div>
  </section>

  <!-- LIVE PROOF -->
  <section class="ln-proof" aria-label="Live numbers from the production database">
    <div class="reveal"><b data-count="<?= e(number_format($stats['usd'], 0, '.', '')) ?>" data-prefix="$">$0</b><small>received through PayPal (sandbox)</small></div>
    <div class="reveal"><b data-count="<?= $stats['orders'] ?>">0</b><small>completed PayPal orders</small></div>
    <div class="reveal"><b data-count="<?= $stats['autopilot'] ?>">0</b><small>🤖 Autopilot charges, no redirect</small></div>
    <div class="reveal"><b data-count="<?= $stats['agent'] ?>">0</b><small>agent actions logged</small></div>
    <div class="reveal"><b data-count="<?= $stats['matches'] ?>">0</b><small>live matches played</small></div>
    <div class="reveal ln-ok"><b><?= $balanced ? '✓ Balanced' : '✕ Check' ?></b><small>ledger right now</small></div>
    <p class="ln-proof-note">Live numbers from the production database, refreshed on every visit.</p>
  </section>

  <!-- PROBLEM -->
  <section class="ln-section">
    <span class="ln-kicker reveal">The problem</span>
    <h2 class="reveal">Small top-ups break the game.<br>Agents could help, if they couldn't overspend.</h2>
    <div class="ln-cards3">
      <article class="ln-card reveal"><span class="ln-em">✍️</span><h3>Mandate = consent</h3><p>The player approves exact terms once, in plain words: pack size, refill level, weekly cap and expiry.</p></article>
      <article class="ln-card reveal"><span class="ln-em">🧱</span><h3>Caps = safety</h3><p>Every charge is checked on the server. The agent physically can't spend outside the mandate.</p></article>
      <article class="ln-card gold reveal"><span class="ln-em">🔎</span><h3>Audit = trust</h3><p>Every agent action, every coin and every PayPal order is visible in a live dashboard.</p></article>
    </div>
  </section>

  <!-- AUTOPILOT -->
  <section class="ln-section ln-split" id="autopilot">
    <div>
      <span class="ln-kicker reveal">Headline feature</span>
      <h2 class="reveal">Tato <span class="gold">Autopilot</span></h2>
      <p class="ln-sub reveal">Agentic payments under a mandate the player approved.</p>
      <ol class="ln-steps">
        <li class="reveal"><i>1</i><div><b>Save PayPal once</b><span>Vault v3 setup token → the buyer approves on PayPal → payment token. No card or account data is stored.</span></div></li>
        <li class="reveal"><i>2</i><div><b>Approve a mandate</b><span>“Buy a $5 pack when my balance falls below 200 coins, up to $20 per week, for 60 days.” The exact consent text is stored.</span></div></li>
        <li class="reveal"><i>3</i><div><b>The agent pays inside it</b><span>Orders v2 with the saved <code>vault_id</code>: no redirect, and never beyond the mandate.</span></div></li>
      </ol>
      <ul class="ln-rails reveal">
        <li>Pack size and weekly cap from the mandate</li>
        <li>At most 3 packs per day</li>
        <li>2-minute cool-down, claimed atomically</li>
        <li>The player's own weekly PayPal limit</li>
        <li>Expiry date and auto-pause after declines</li>
        <li>Pause or revoke in one tap</li>
      </ul>
    </div>
    <div class="ln-shots2 reveal">
      <figure class="ln-phone"><img src="<?= $img('autopilot-card.jpg') ?>" alt="Autopilot mandate card in the wallet: $5 per top-up when below 200 coins, $20 weekly cap"><figcaption>The approved mandate</figcaption></figure>
      <figure class="ln-phone"><img src="<?= $img('autopilot-charge.jpg') ?>" alt="Tato charging the saved PayPal account for 475 coins with no redirect"><figcaption>A real charge, no redirect</figcaption></figure>
    </div>
  </section>

  <!-- PAYPAL -->
  <section class="ln-section" id="paypal">
    <span class="ln-kicker reveal">Built deep on PayPal</span>
    <h2 class="reveal">Every PayPal call runs on the server</h2>
    <p class="ln-sub reveal">The browser never sees a secret, and the server sets every amount and credits it exactly once.</p>
    <div class="ln-api">
      <article class="reveal"><h3>OAuth 2.0</h3><code>POST /v1/oauth2/token</code><p>Client-credentials token. The secret stays in a server-only config.</p></article>
      <article class="reveal"><h3>Orders v2 · create</h3><code>POST /v2/checkout/orders</code><p>Amount set by the server, <code>PayPal-Request-Id</code> idempotency, English experience context.</p></article>
      <article class="reveal"><h3>Orders v2 · capture</h3><code>POST /v2/checkout/orders/{id}/capture</code><p>Checks COMPLETED, amount, currency and reference, then credits once. Handles <code>ORDER_ALREADY_CAPTURED</code>.</p></article>
      <article class="reveal hl"><h3>Vault v3 · setup token</h3><code>POST /v3/vault/setup-tokens</code><p>The buyer approves saving PayPal for Autopilot. A lost redirect is recovered with <code>GET</code>.</p></article>
      <article class="reveal hl"><h3>Vault v3 · payment token</h3><code>POST · DELETE /v3/vault/payment-tokens</code><p>Only the token and a masked email are kept. “Remove saved PayPal” deletes it at PayPal.</p></article>
      <article class="reveal hl"><h3>Off-session order</h3><code>payment_source.paypal.vault_id</code><p>Autopilot charges with no redirect, inside the mandate's caps and cool-down.</p></article>
      <article class="reveal"><h3>Webhooks</h3><code>POST /v1/notifications/verify-webhook-signature</code><p>Signature verified. Handles <code>CHECKOUT.ORDER.APPROVED</code> and <code>PAYMENT.CAPTURE.COMPLETED</code> as a backup credit path.</p></article>
      <article class="reveal"><h3>Exactly-once money</h3><code>idem_key UNIQUE · WHERE coins &gt;= ?</code><p>A double-entry ledger (player, escrow, platform), atomic debits and row-locked settlement.</p></article>
    </div>
    <div class="ln-flow reveal" aria-label="Payment flow">
      <span>📱 Player</span><i>→</i><span>🤖 Tato proposes</span><i>→</i><span class="gold">🛡️ Server checks mandate &amp; limits</span><i>→</i><span>💳 PayPal Orders / Vault</span><i>→</i><span>🧾 Ledger, once</span>
    </div>
  </section>

  <!-- TATO -->
  <section class="ln-section ln-split rev" id="tato">
    <figure class="ln-phone big reveal"><img src="<?= $img('tato-checkout.jpg') ?>" alt="Tato preparing a $10 PayPal checkout card in chat"><figcaption>Tato prepares a checkout and stops: you approve on PayPal.</figcaption></figure>
    <div>
      <span class="ln-kicker reveal">The AI</span>
      <h2 class="reveal">Tato, the AI wallet agent</h2>
      <p class="ln-sub reveal">Claude Opus 5.5 through the Anthropic SDK, in a server-side agent loop.</p>
      <ul class="ln-checks reveal">
        <li><b>11 strict tools</b>, run for the logged-in player only</li>
        <li><b>Confirm cards</b> for contests and mandates; PayPal approval for checkouts</li>
        <li><b>Server-side fallback</b> on refusals, low effort for fast chat, rate limits</li>
        <li><b>Audit log</b> of every message, tool call and approval</li>
        <li>Ask for “free coins” and it can't help: <b>no tool can create coins</b></li>
      </ul>
      <div class="ln-chips reveal">
        <span>get_wallet</span><span>list_coin_packs</span><span>start_paypal_checkout</span><span>set_weekly_limit</span><span>list_open_contests</span><span>propose_contest_action</span><span>get_recent_matches</span><span>get_autopilot</span><span>propose_autopilot</span><span>autopilot_topup_now</span><span>change_autopilot</span>
      </div>
    </div>
  </section>

  <!-- GAME -->
  <section class="ln-section" id="game">
    <span class="ln-kicker reveal">And it's a real game</span>
    <h2 class="reveal">Live 1v1 skill contests</h2>
    <p class="ln-sub reveal">Post an entry, an opponent accepts, both play live. Every move is validated on the server.</p>
    <div class="ln-shots3">
      <figure class="ln-phone reveal"><img src="<?= $img('arena.jpg') ?>" alt="TikTato arena with the Play button and live contests"><figcaption>Arena · live contests</figcaption></figure>
      <figure class="ln-phone reveal"><img src="<?= $img('game.jpg') ?>" alt="Live tic-tac-toe match with prize pool and move clock"><figcaption>Real-time board · 30 s clock</figcaption></figure>
      <figure class="ln-phone reveal"><img src="<?= $img('winner.jpg') ?>" alt="We have a winner screen showing +80 coins"><figcaption>Winner takes the pool − 20%</figcaption></figure>
    </div>
    <div class="ln-math reveal">
      <div><small>Entry</small><b>100</b></div><i>×2 →</i>
      <div><small>Pool in escrow</small><b>200</b></div><i>−20% →</i>
      <div class="win"><small>Winner receives</small><b>160</b></div>
      <div class="draw"><small>Draw</small><b>Full refund</b></div>
    </div>
    <p class="ln-note reveal">Also: free practice against a minimax AI, weekly spending limits with a 24 h cool-down to raise them, 18+ only.</p>
  </section>

  <!-- AUDIT -->
  <section class="ln-section" id="audit">
    <span class="ln-kicker reveal">Trust</span>
    <h2 class="reveal">Every action is auditable</h2>
    <p class="ln-sub reveal">A live AG Grid dashboard: agent actions, the double-entry ledger, PayPal orders (🤖 Autopilot vs checkout) and matches, with filters, search, totals and CSV export.</p>
    <figure class="ln-laptop reveal"><div class="lid"><img src="<?= $img('dashboard.jpg') ?>" alt="Activity and audit dashboard built with AG Grid, showing KPI tiles and the ledger"></div><div class="base"></div></figure>
  </section>

  <!-- WATCH -->
  <section class="ln-section" id="watch">
    <span class="ln-kicker reveal">2:33 demo</span>
    <h2 class="reveal">Watch it work, live footage only</h2>
    <div class="ln-video reveal">
      <video controls preload="metadata" playsinline poster="<?= $img('video-poster.jpg') ?>">
        <source src="<?= $img('tiktato-demo-720p.mp4') ?>" type="video/mp4">
      </video>
    </div>
  </section>

  <!-- JUDGES -->
  <section class="ln-section" id="judges">
    <span class="ln-kicker reveal">For judges</span>
    <h2 class="reveal">Test it in 5 minutes</h2>
    <div class="ln-judges">
      <ol class="ln-try reveal">
        <li>Log in as <b>alice545</b> and tap <b>Ask Tato</b>: “What's my balance?”</li>
        <li>Say <b>“Top me up now”</b>. Autopilot charges the saved PayPal with no redirect. Say it again, and the cool-down refuses.</li>
        <li>Say <b>“Buy $10 of coins”</b> to get a real PayPal sandbox checkout. Pay with any sandbox <i>personal</i> account.</li>
        <li>Post a contest. In a <b>private window</b>, log in as <b>judge_blue</b> and accept it. Play it out.</li>
        <li>Open <b>Activity</b> → <b>Platform</b>: every agent action, PayPal order and ledger row, with “ledger balanced ✓”.</li>
      </ol>
      <div class="ln-accounts reveal">
        <table>
          <thead><tr><th>Account</th><th>Password</th><th>Use</th></tr></thead>
          <tbody>
            <tr><td><b>alice545</b></td><td><code>Rimpel12</code></td><td>Main demo · Autopilot on · admin view</td></tr>
            <tr><td>judge_blue</td><td><code>TikTato-Judge-2026</code></td><td>Second player</td></tr>
            <tr><td>judge_red</td><td><code>TikTato-Judge-2026</code></td><td>Set up Autopilot from scratch</td></tr>
          </tbody>
        </table>
        <div class="ln-ctas">
          <a class="btn btn-cta" href="<?= e(url('login.php')) ?>">Open the live demo</a>
          <a class="btn btn-ghost" href="https://github.com/bastien509/tiktato" rel="noopener">Code on GitHub (MIT)</a>
        </div>
      </div>
    </div>
  </section>
</main>

<footer class="ln-foot">
  <p><b>TikTato</b> · built for the PayPal AI Hackathon with PayPal REST APIs, Claude and AG Grid.</p>
  <p>PayPal sandbox only: no real money moves. Coins have no cash value and can't be withdrawn. 18+.</p>
</footer>
<?php
page_foot(['landing.js']);
