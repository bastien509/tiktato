<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/game.php';
require __DIR__ . '/inc/paypal.php';
require __DIR__ . '/inc/agent.php';
require __DIR__ . '/inc/layout.php';

$me = require_login_page();
$p = public_profile($me);
$coinRain = (int) ($_SESSION['coin_rain'] ?? 0);
unset($_SESSION['coin_rain']);

page_head('TikTato · Arena', 'app', [
    'coin-rain' => (string) $coinRain,
    'stake-min' => (string) STAKE_MIN,
    'stake-max' => (string) STAKE_MAX,
    'stake-step' => (string) STAKE_STEP,
    'fee' => (string) MATCH_FEE_PCT,
]);
?>
<header class="topbar">
  <a class="brand" href="#arena" aria-label="TikTato home"><?= logo_svg('logo') ?><span class="brand-word">Tik<span>Tato</span></span></a>
  <div class="topbar-right">
    <button class="coin-chip" id="coinChip" type="button" data-go="wallet" aria-label="Wallet">
      <span class="coin" aria-hidden="true"></span><b id="coinCount"><?= number_format((int) $me['coins']) ?></b><span class="chip-plus" aria-hidden="true">+</span>
    </button>
    <button class="icon-btn" id="bellBtn" type="button" aria-label="Open contests">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2Zm7-6V11a7 7 0 0 0-5.5-6.8V3a1.5 1.5 0 0 0-3 0v1.2A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg>
      <span class="badge" id="bellBadge" hidden>0</span>
    </button>
  </div>
</header>
<?php if (paypal_mode() === 'sandbox'): ?>
<div class="demo-strip">Sandbox demo · PayPal test money only · coins have no cash value</div>
<?php endif; ?>

<div class="flash-host"><?= flash_html() ?></div>

<main class="app-main">
  <!-- ARENA -->
  <section class="screen" id="screen-arena" data-screen="arena">
    <div class="hero-card">
      <div class="hero-top">
        <div class="avatar lg av-c<?= $p['color'] ?>" id="meAvatar"><?= e($p['avatar']) ?></div>
        <div>
          <p class="hero-hello">Ready, <b id="meName"><?= e($p['username']) ?></b>?</p>
          <p class="hero-stats"><span><b id="statW"><?= (int) $me['wins'] ?></b> W</span><span><b id="statL"><?= (int) $me['losses'] ?></b> L</span><span><b id="statD"><?= (int) $me['draws'] ?></b> D</span></p>
        </div>
        <span class="online-pill"><i></i><b id="onlineCount">1</b> online</span>
      </div>
      <button class="play-btn" id="playBtn" type="button"><span>PLAY</span></button>
    </div>

    <div class="section-head">
      <h2>Live contests</h2>
      <span class="live-dot">LIVE</span>
    </div>
    <ul class="challenge-list" id="challengeList" aria-live="polite"></ul>
    <div class="empty" id="challengeEmpty">
      <p class="empty-icon" aria-hidden="true">⚔️</p>
      <p>No open contests right now.</p>
      <button class="btn btn-ghost" type="button" data-go="bet">Post a contest</button>
    </div>
  </section>

  <!-- BET -->
  <section class="screen" id="screen-bet" data-screen="bet" hidden>
    <button class="back" type="button" data-go="arena">‹ Arena</button>
    <h1 class="screen-title">1v1 skill contest</h1>
    <p class="screen-sub">Pick an entry fee. The first player who accepts pays the same entry, and the winner takes the prize pool.</p>
    <div class="bet-chips" id="betChips"></div>
    <label class="field">
      <span class="field-label">Custom entry (coins)</span>
      <span class="field-box"><span class="coin sm" aria-hidden="true"></span><input id="betInput" type="number" inputmode="numeric"></span>
    </label>
    <div class="payout-card" id="payoutCard"></div>
    <button class="btn btn-cta btn-block" id="betGo" type="button">Find an opponent</button>
    <p class="note" id="betNote"></p>
  </section>

  <!-- WAITING -->
  <section class="screen" id="screen-wait" data-screen="wait" hidden>
    <div class="radar">
      <span class="radar-sweep"></span><span class="radar-ring r1"></span><span class="radar-ring r2"></span><span class="radar-ring r3"></span>
      <div class="avatar xl av-c<?= $p['color'] ?>" id="waitAvatar"><?= e($p['avatar']) ?></div>
    </div>
    <h1 class="screen-title center">Waiting for an opponent<span class="dots"></span></h1>
    <p class="wait-stake"><span class="coin" aria-hidden="true"></span><b id="waitStake">0</b> coin contest</p>
    <p class="screen-sub center">Players online can see your contest now. Expires in <b id="waitClock">10:00</b>.</p>
    <button class="btn btn-ghost btn-block" id="waitCancel" type="button">Cancel &amp; refund</button>
  </section>

  <!-- GAME -->
  <section class="screen" id="screen-game" data-screen="game" hidden>
    <div class="versus">
      <div class="pcard" id="pX"><div class="avatar md ring"><i class="timer"></i><span class="av-face"></span></div><p class="pname"></p><span class="pmark mark-x">X</span></div>
      <div class="pot" id="potBox"><span class="pot-label">PRIZE POOL</span><span class="pot-val"><span class="coin sm" aria-hidden="true"></span><b id="potVal">0</b></span><span class="pot-sub" id="potSub"></span></div>
      <div class="pcard" id="pO"><div class="avatar md ring"><i class="timer"></i><span class="av-face"></span></div><p class="pname"></p><span class="pmark mark-o">O</span></div>
    </div>
    <p class="turn-line" id="turnLine">…</p>
    <div class="board" id="board" role="grid" aria-label="Tic-tac-toe board">
      <?php for ($i = 0; $i < 9; $i++): ?><button class="cell" type="button" data-cell="<?= $i ?>" aria-label="Square <?= $i + 1 ?>"></button><?php endfor; ?>
      <svg class="strike" id="strike" viewBox="0 0 300 300" aria-hidden="true"><line x1="0" y1="0" x2="0" y2="0"/></svg>
    </div>
    <div class="game-actions">
      <button class="btn btn-ghost" id="resignBtn" type="button">Resign</button>
      <button class="btn btn-ghost" id="leaveBtn" type="button" hidden>Back to arena</button>
      <button class="btn btn-cta" id="againBtn" type="button" hidden>Play again</button>
    </div>
    <p class="note center" id="gameNote"></p>
  </section>

  <!-- WALLET -->
  <section class="screen" id="screen-wallet" data-screen="wallet" hidden>
    <div class="balance-card">
      <p class="balance-label">Your balance</p>
      <p class="balance-val"><span class="coin lg" aria-hidden="true"></span><b id="walletCoins">0</b></p>
      <p class="balance-usd" id="walletUsd">≈ $0.00</p>
    </div>
    <div class="section-head"><h2>Buy coins</h2><span class="mode-pill" id="ppMode" hidden>SANDBOX</span></div>
    <p class="note">100 coins = $1. A 5% service fee is kept from each top-up.</p>
    <div class="packs" id="packs"></div>
    <p class="note" id="ppNote"></p>
    <div class="limit-card" id="limitCard"></div>
    <div class="section-head"><h2>History</h2></div>
    <ul class="ledger" id="ledger"></ul>
  </section>

  <!-- PROFILE -->
  <section class="screen" id="screen-profile" data-screen="profile" hidden>
    <div class="profile-card">
      <div class="avatar xl av-c<?= $p['color'] ?>"><?= e($p['avatar']) ?></div>
      <h1 class="screen-title center"><?= e($p['username']) ?></h1>
      <p class="hero-stats center"><span><b id="statW2"><?= (int) $me['wins'] ?></b> wins</span><span><b id="statL2"><?= (int) $me['losses'] ?></b> losses</span><span><b id="statD2"><?= (int) $me['draws'] ?></b> draws</span></p>
    </div>
    <div class="rules">
      <h2>How skill contests work</h2>
      <ul>
        <li><b>Entry:</b> you and your opponent each pay the same entry fee. Both entries are held in escrow until the match ends.</li>
        <li><b>Win:</b> you get the prize pool (both entries) minus a <?= MATCH_FEE_PCT ?>% platform fee. Enter 100 → win 160.</li>
        <li><b>Lose:</b> you lose your entry fee.</li>
        <li><b>Draw:</b> both entries are refunded in full, no fee.</li>
        <li><b>Clock:</b> <?= TURN_SEC ?> seconds per move. Let it run out and you forfeit the match.</li>
        <li><b>No opponent?</b> Cancel any time for a full refund. Unmatched contests refund after <?= intdiv(CHALLENGE_TTL_SEC, 60) ?> minutes.</li>
        <li><b>Practice:</b> games against the AI are free and use no coins.</li>
        <li><b>Limits:</b> set a weekly PayPal limit with Tato. Lowering it is instant; raising it takes 24 hours.</li>
        <li><b>Demo:</b> this build runs on the PayPal sandbox. Coins have no cash value and cannot be withdrawn.</li>
      </ul>
    </div>
    <form method="post" action="<?= e(url('logout.php')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-block" type="submit">Log out</button>
    </form>
  </section>
</main>

<nav class="tabbar" id="tabbar">
  <button type="button" data-go="arena" class="tab"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3v6H3V3h6Zm12 0v6h-6V3h6ZM9 15v6H3v-6h6Zm12 0v6h-6v-6h6Z"/></svg><span>Arena</span></button>
  <button type="button" data-go="wallet" class="tab"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h15a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Zm-2-2v2H5a1 1 0 0 1 0-2h13Zm-1 10a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg><span>Wallet</span></button>
  <button type="button" data-go="profile" class="tab"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5Z"/></svg><span>Profile</span></button>
</nav>

<!-- Play mode sheet -->
<div class="sheet" id="playSheet" hidden>
  <div class="sheet-scrim" data-close></div>
  <div class="sheet-body" role="dialog" aria-modal="true" aria-labelledby="playSheetTitle">
    <span class="sheet-grip" aria-hidden="true"></span>
    <div data-step="mode">
      <h2 class="sheet-title" id="playSheetTitle">Choose your rival</h2>
      <button class="mode-card mode-ai" type="button" data-mode="ai">
        <span class="mode-icon" aria-hidden="true">🤖</span>
        <span><b>vs AI</b><small>Free practice · no coins</small></span>
      </button>
      <button class="mode-card mode-human" type="button" data-mode="human">
        <span class="mode-icon" aria-hidden="true">⚔️</span>
        <span><b>vs Human</b><small>Skill contest · winner takes the prize pool</small></span>
      </button>
    </div>
    <div data-step="ai" hidden>
      <h2 class="sheet-title">Practice vs AI</h2>
      <p class="field-label">Difficulty</p>
      <div class="seg" id="aiLevel">
        <button type="button" data-level="easy" class="on">Easy</button>
        <button type="button" data-level="medium">Medium</button>
        <button type="button" data-level="hard">Hard</button>
      </div>
      <p class="field-label">Who goes first?</p>
      <div class="seg" id="aiFirst">
        <button type="button" data-first="me" class="on">Me (X)</button>
        <button type="button" data-first="ai">AI (X)</button>
      </div>
      <button class="btn btn-cta btn-block" id="aiStart" type="button">Start practice</button>
    </div>
  </div>
</div>

<!-- Confirm sheet -->
<div class="sheet" id="confirmSheet" hidden>
  <div class="sheet-scrim" data-close></div>
  <div class="sheet-body" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <span class="sheet-grip" aria-hidden="true"></span>
    <h2 class="sheet-title" id="confirmTitle"></h2>
    <div id="confirmBody"></div>
    <div class="sheet-actions">
      <button class="btn btn-ghost" type="button" data-close>Not now</button>
      <button class="btn btn-cta" type="button" id="confirmOk">Confirm</button>
    </div>
  </div>
</div>

<!-- Winner celebration -->
<div class="win-overlay" id="winOverlay" hidden>
  <canvas class="confetti" id="confetti"></canvas>
  <div class="win-card" role="dialog" aria-modal="true" aria-labelledby="winTitle">
    <div class="rays" aria-hidden="true"></div>
    <p class="win-kicker" id="winKicker">We have a</p>
    <h2 class="win-title" id="winTitle">Winner</h2>
    <div class="win-avatars" id="winAvatars"></div>
    <p class="win-name" id="winName"></p>
    <div class="win-amount" id="winAmountBox"><span class="coin lg" aria-hidden="true"></span><b id="winAmount">0</b></div>
    <p class="win-sub" id="winSub"></p>
    <div class="win-actions">
      <button class="btn btn-ghost" type="button" id="winArena">Arena</button>
      <button class="btn btn-cta" type="button" id="winAgain">Play again</button>
    </div>
  </div>
</div>

<?php if (agent_ready()): ?>
<!-- Tato, the AI wallet agent -->
<button class="tato-fab" id="tatoFab" type="button" aria-label="Ask Tato, your AI wallet agent">
  <span class="tato-face" aria-hidden="true"><i></i><i></i></span><span class="tato-fab-label">Ask Tato</span>
</button>
<div class="sheet tato-sheet" id="tatoSheet" hidden>
  <div class="sheet-scrim" data-close></div>
  <div class="sheet-body tato-body" role="dialog" aria-modal="true" aria-labelledby="tatoTitle">
    <div class="tato-head">
      <span class="tato-face lg" aria-hidden="true"><i></i><i></i></span>
      <div>
        <h2 id="tatoTitle">Tato</h2>
        <p>AI wallet agent · powered by Claude</p>
      </div>
      <button class="icon-btn sm" type="button" id="tatoReset" aria-label="Start a new conversation">↺</button>
      <button class="icon-btn sm" type="button" data-close aria-label="Close">✕</button>
    </div>
    <div class="tato-log" id="tatoLog" aria-live="polite"></div>
    <div class="tato-chips" id="tatoChips">
      <button type="button">What's my balance?</button>
      <button type="button">Buy $10 of coins</button>
      <button type="button">Set a $20 weekly limit</button>
      <button type="button">Find me a contest</button>
      <button type="button">How did my last games go?</button>
    </div>
    <form class="tato-form" id="tatoForm" autocomplete="off">
      <input id="tatoInput" maxlength="<?= AGENT_MAX_INPUT ?>" placeholder="Ask Tato about coins, limits or contests…" aria-label="Message Tato">
      <button class="btn btn-cta" type="submit" id="tatoSend" aria-label="Send">➤</button>
    </form>
    <p class="tato-note">Tato can prepare payments and contests, but only you can approve them.</p>
  </div>
</div>
<?php endif; ?>
<div class="toast-host" id="toasts" aria-live="polite"></div>
<div class="fx-layer" id="fx" aria-hidden="true"></div>
<?php
page_foot(['app.js']);
