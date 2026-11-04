<?php
/**
 * Optional PayPal webhook (set paypal.webhook_id in config). Covers buyers
 * who pay but never come back to paypal-return.php. Signature is verified
 * with PayPal before anything is credited; crediting is idempotent.
 */
declare(strict_types=1);

define('TTT_NO_SESSION', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/game.php';
require __DIR__ . '/inc/paypal.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !rate_limit('webhook:' . client_ip(), 120, 60)) {
    http_response_code(405);
    exit('{}');
}
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 200000 || !paypal_verify_webhook($raw)) {
    http_response_code(400);
    exit('{}');
}
$event = json_decode($raw, true);
$type = (string) ($event['event_type'] ?? '');
$res = $event['resource'] ?? [];

try {
    if ($type === 'CHECKOUT.ORDER.APPROVED') {
        $order = q('SELECT * FROM paypal_orders WHERE paypal_order_id = ?', [(string) ($res['id'] ?? '')])->fetch();
        if ($order) {
            topup_capture($order);
        }
    } elseif ($type === 'PAYMENT.CAPTURE.COMPLETED') {
        $ppOrder = (string) ($res['supplementary_data']['related_ids']['order_id'] ?? '');
        $order = q('SELECT * FROM paypal_orders WHERE paypal_order_id = ?', [$ppOrder])->fetch();
        if ($order && (string) ($res['custom_id'] ?? '') === (string) $order['id']) {
            topup_credit((int) $order['id'], $res);
        }
    }
} catch (Throwable $e) {
    error_log('TikTato webhook: ' . $e->getMessage());
}
http_response_code(200);
echo '{}';
