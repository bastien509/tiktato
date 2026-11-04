<?php
/** PayPal sends the player back here after approving (or cancelling) a saved account. */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/game.php';
require __DIR__ . '/inc/paypal.php';
require __DIR__ . '/inc/agent.php';
require __DIR__ . '/inc/autopilot.php';

$me = current_user();
$setupId = (string) ($_GET['approval_token_id'] ?? ($_SESSION['vault_setup'] ?? ''));
if ($setupId === '' && $me) {
    $setupId = (string) (vault_row((int) $me['id'])['setup_token_id'] ?? '');
}
unset($_SESSION['vault_setup']);

if (isset($_GET['cancel'])) {
    flash('PayPal was not saved. Autopilot stays off.', 'info');
    redirect('index.php#wallet');
}
if (!preg_match('/^[A-Z0-9]{5,40}$/', $setupId)) {
    flash('PayPal approval not recognised.', 'error');
    redirect('index.php#wallet');
}
// The setup token must belong to this player (or, if the session expired, to whoever started it).
$row = q("SELECT user_id FROM paypal_vault WHERE setup_token_id = ? AND mode = ?", [$setupId, paypal_mode()])->fetch();
if (!$row || ($me && (int) $row['user_id'] !== (int) $me['id'])) {
    flash('This PayPal approval is not for your account.', 'error');
    redirect('index.php#wallet');
}
if (!rate_limit('vault:' . client_ip(), 20, 600)) {
    flash('Too many attempts. Wait a few minutes.', 'error');
    redirect('index.php#wallet');
}
try {
    $r = vault_setup_finish((int) $row['user_id'], $setupId);
    flash('PayPal saved' . ($r['payer_email'] ? ' (' . $r['payer_email'] . ')' : '') . '. Now choose your Autopilot limits.', 'success');
} catch (GameError $e) {
    flash($e->getMessage(), 'error');
} catch (Throwable $e) {
    error_log('TikTato vault return: ' . $e->getMessage());
    flash('Could not save PayPal. Please try again.', 'error');
}
redirect('index.php#wallet');
