<?php
/** Platform revenue + ledger checks. Only users with is_admin = 1 (set in SQL). */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login_page();
if (!(int) $me['is_admin']) {
    http_response_code(404);
    exit('Not found');
}

$sum = fn (string $sql) => (int) q($sql)->fetchColumn();
$userCoins = $sum('SELECT COALESCE(SUM(coins), 0) FROM users');
$userLedger = $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'user'");
$escrowLedger = $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'escrow'");
$escrowHeld = $sum("SELECT COALESCE(SUM(stake), 0) FROM challenges WHERE status = 'open'")
    + $sum("SELECT COALESCE(SUM(pot), 0) FROM matches WHERE status = 'active'");
$topupFees = $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'platform' AND type = 'topup_fee'");
$matchFees = $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'platform' AND type = 'platform_fee'");
$allLedger = $sum('SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger');
$bought = $sum("SELECT COALESCE(SUM(coins_gross), 0) FROM paypal_orders WHERE status = 'completed'");
$usd = $sum("SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders WHERE status = 'completed'");

$checks = [
    ['Users: SUM(coins) = user ledger', $userCoins, $userLedger],
    ['Escrow ledger = open stakes + active pots', $escrowLedger, $escrowHeld],
    ['Whole ledger = coins bought via PayPal', $allLedger, $bought],
];

page_head('Admin · TikTato', 'app');
?>
<main class="app-main">
  <h1 class="screen-title">Platform ledger</h1>
  <div class="payout-card">
    <div><small>PayPal received</small><b>$<?= number_format($usd / 100, 2) ?></b></div>
    <div><small>Coins in user wallets</small><b><?= number_format($userCoins) ?></b></div>
    <div class="win"><small>Top-up fees (5%)</small><b><?= number_format($topupFees) ?> = $<?= number_format($topupFees / 100, 2) ?></b></div>
    <div class="win"><small>Match fees (20%)</small><b><?= number_format($matchFees) ?> = $<?= number_format($matchFees / 100, 2) ?></b></div>
    <div><small>Held in escrow</small><b><?= number_format($escrowHeld) ?></b></div>
  </div>
  <div class="rules">
    <h2>Balance checks</h2>
    <ul>
      <?php foreach ($checks as [$label, $a, $b]): ?>
        <li><?= $a === $b ? '✅' : '❌' ?> <?= e($label) ?>: <b><?= number_format($a) ?></b> / <b><?= number_format($b) ?></b></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <a class="btn btn-ghost btn-block" href="<?= e(url('index.php')) ?>">Back to the game</a>
</main>
<?php
page_foot([]);
