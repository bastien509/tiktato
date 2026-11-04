<?php
/** PayPal sends the buyer back here; the server captures and credits. */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/game.php';
require __DIR__ . '/inc/paypal.php';

// The session may have expired while the buyer was on PayPal. Capturing an
// approved order is safe without it: coins always go to the order's owner.
$me = current_user();
$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[A-Z0-9]{5,40}$/', $token)) {
    flash('Payment link not recognised.', 'error');
    redirect('index.php#wallet');
}
$order = q('SELECT * FROM paypal_orders WHERE paypal_order_id = ?', [$token])->fetch();
if (!$order || ($me && (int) $order['user_id'] !== (int) $me['id'])) {
    flash('Payment not found for this account.', 'error');
    redirect('index.php#wallet');
}
if (isset($_GET['cancel'])) {
    if ($me) {
        q("UPDATE paypal_orders SET status = 'cancelled' WHERE id = ? AND status = 'created'", [$order['id']]);
    }
    flash('Checkout cancelled. No coins were charged.', 'info');
    redirect('index.php#wallet');
}
if (!rate_limit('capture:' . client_ip(), 20, 600)) {
    flash('Too many attempts. Wait a few minutes.', 'error');
    redirect('index.php#wallet');
}
try {
    $r = topup_capture($order);
    if ($r === 'pending') {
        flash('PayPal is still reviewing this payment. Coins arrive as soon as it clears.', 'info');
    } else {
        $_SESSION['coin_rain'] = (int) $order['coins_credit'];
        flash('+' . number_format((int) $order['coins_credit']) . ' coins added to your wallet!', 'success');
    }
} catch (GameError $e) {
    flash($e->getMessage(), 'error');
} catch (Throwable $e) {
    error_log('TikTato capture error: ' . $e->getMessage());
    flash('We could not confirm the payment yet. If you were charged, coins will appear shortly.', 'error');
}
redirect('index.php#wallet');
