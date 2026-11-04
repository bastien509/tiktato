<?php
/**
 * PayPal Orders v2, fully server-side: the server sets the amount,
 * captures the order, checks amount + currency, and credits once.
 * 100 coins = $1; the platform keeps TOPUP_FEE_PCT of every top-up.
 */
declare(strict_types=1);

defined('TTT') || exit;

const TOPUP_PACKAGES_USD = [5, 10, 20, 50, 100];
const LIMIT_RAISE_DELAY_H = 24;   // raising a weekly limit waits this long

function paypal_ready(): bool
{
    return cfg('paypal.client_id') !== '' && cfg('paypal.secret') !== '';
}

function paypal_mode(): string
{
    return cfg('paypal.mode') === 'live' ? 'live' : 'sandbox';
}

function paypal_api_base(): string
{
    return paypal_mode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/** Coin split for a whole-dollar top-up. */
function topup_split(int $usd): array
{
    $gross = $usd * COINS_PER_USD;
    $fee = intdiv($gross * TOPUP_FEE_PCT, 100);
    return ['usd' => $usd, 'gross' => $gross, 'fee' => $fee, 'credit' => $gross - $fee];
}

/** Low-level HTTPS call. Returns [httpCode, decodedJson]. Never logs secrets. */
function paypal_http(string $method, string $path, $body = null, array $headers = [], ?string $basicAuth = null): array
{
    $ch = curl_init(paypal_api_base() . $path);
    $h = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($basicAuth !== null) {
        $opts[CURLOPT_USERPWD] = $basicAuth;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
        if (!is_string($body)) {
            $h[] = 'Content-Type: application/json';
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        error_log('TikTato PayPal curl error: ' . curl_error($ch));
    }
    curl_close($ch);
    return [$code, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

function paypal_token(): string
{
    [$code, $res] = paypal_http(
        'POST',
        '/v1/oauth2/token',
        'grant_type=client_credentials',
        ['Content-Type: application/x-www-form-urlencoded'],
        cfg('paypal.client_id') . ':' . cfg('paypal.secret')
    );
    if ($code !== 200 || empty($res['access_token'])) {
        error_log('TikTato PayPal auth failed: HTTP ' . $code);
        throw new GameError('Payments are unavailable right now. Please try later.');
    }
    return (string) $res['access_token'];
}

function paypal_api(string $method, string $path, $body = null, array $headers = []): array
{
    return paypal_http($method, $path, $body, array_merge(['Authorization: Bearer ' . paypal_token()], $headers));
}

/** Creates a local order row + PayPal order; returns the PayPal approval URL. */
function topup_create(int $userId, $rawUsd): string
{
    $usd = (int) $rawUsd;
    if ((string) $usd !== (string) $rawUsd || !in_array($usd, TOPUP_PACKAGES_USD, true)) {
        throw new GameError('Pick one of the coin packs.');
    }
    if (!paypal_ready()) {
        throw new GameError('Top-ups open soon. PayPal is not connected yet.');
    }
    assert_within_limit($userId, $usd);
    $s = topup_split($usd);
    $currency = (string) cfg('paypal.currency', 'USD');
    q(
        'INSERT INTO paypal_orders (user_id, mode, amount_cents, currency, coins_gross, coins_credit, coins_fee) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, paypal_mode(), $usd * 100, $currency, $s['gross'], $s['credit'], $s['fee']]
    );
    $localId = (int) db()->lastInsertId();

    [$code, $res] = paypal_api('POST', '/v2/checkout/orders', [
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'ttt-' . $localId,
            'custom_id'    => (string) $localId,
            'description'  => $s['gross'] . ' TikTato coins',
            'amount'       => ['currency_code' => $currency, 'value' => number_format($usd, 2, '.', '')],
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'brand_name'          => (string) cfg('app.name', 'TikTato'),
            'shipping_preference' => 'NO_SHIPPING',
            'user_action'         => 'PAY_NOW',
            'return_url'          => abs_url('paypal-return.php'),
            'cancel_url'          => abs_url('paypal-return.php?cancel=1'),
        ]]],
    ], ['PayPal-Request-Id: ttt-create-' . $localId]);

    $approve = '';
    foreach ($res['links'] ?? [] as $link) {
        if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
            $approve = (string) $link['href'];
        }
    }
    if (!in_array($code, [200, 201], true) || empty($res['id']) || $approve === '') {
        error_log('TikTato PayPal create failed: HTTP ' . $code . ' ' . ($res['name'] ?? ''));
        q("UPDATE paypal_orders SET status = 'failed' WHERE id = ?", [$localId]);
        throw new GameError('Could not start PayPal checkout. Please try again.');
    }
    q('UPDATE paypal_orders SET paypal_order_id = ? WHERE id = ?', [$res['id'], $localId]);
    return $approve;
}

/**
 * Credits a captured order once. $capture is PayPal's capture object.
 * Returns 'completed', 'already', 'pending' or throws.
 */
function topup_credit(int $localId, array $capture): string
{
    return tx(function (PDO $pdo) use ($localId, $capture) {
        $st = $pdo->prepare('SELECT * FROM paypal_orders WHERE id = ? FOR UPDATE');
        $st->execute([$localId]);
        $o = $st->fetch();
        if (!$o) {
            throw new GameError('Order not found.');
        }
        if ($o['status'] === 'completed') {
            return 'already';
        }
        $status = (string) ($capture['status'] ?? '');
        if ($status === 'PENDING') {
            $pdo->prepare("UPDATE paypal_orders SET status = 'pending', capture_id = ? WHERE id = ?")->execute([$capture['id'] ?? null, $localId]);
            return 'pending';
        }
        if ($status !== 'COMPLETED') {
            $pdo->prepare("UPDATE paypal_orders SET status = 'failed' WHERE id = ?")->execute([$localId]);
            throw new GameError('The payment was not completed.');
        }
        $value = (string) ($capture['amount']['value'] ?? '');
        $currency = (string) ($capture['amount']['currency_code'] ?? '');
        $expected = number_format(((int) $o['amount_cents']) / 100, 2, '.', '');
        if ($value !== $expected || $currency !== $o['currency']) {
            error_log("TikTato PayPal amount mismatch on order $localId: got $value $currency, expected $expected {$o['currency']}");
            $pdo->prepare("UPDATE paypal_orders SET status = 'failed' WHERE id = ?")->execute([$localId]);
            throw new GameError('Payment amount did not match. Contact support.');
        }
        $uid = (int) $o['user_id'];
        credit_coins($pdo, $uid, (int) $o['coins_credit']);
        $refs = ['paypal_order_id' => $localId];
        ledger($pdo, 'user', $uid, 'topup', (int) $o['coins_credit'], "pp:$localId:topup", $refs + ['note' => 'PayPal ' . $expected . ' ' . $currency]);
        ledger($pdo, 'platform', null, 'topup_fee', (int) $o['coins_fee'], "pp:$localId:fee", $refs);
        $pdo->prepare("UPDATE paypal_orders SET status = 'completed', capture_id = ?, completed_at = NOW() WHERE id = ?")
            ->execute([(string) ($capture['id'] ?? ''), $localId]);
        notify($uid, 'topup', '+' . $o['coins_credit'] . ' coins added', 'PayPal top-up of $' . $expected . ' completed.');
        return 'completed';
    });
}

/** Captures an approved order (idempotent) and credits it. */
function topup_capture(array $order): string
{
    if ($order['status'] === 'completed') {
        return 'already';
    }
    $ppId = (string) $order['paypal_order_id'];
    [$code, $res] = paypal_api('POST', '/v2/checkout/orders/' . rawurlencode($ppId) . '/capture', new stdClass(), [
        'PayPal-Request-Id: ttt-capture-' . $order['id'],
    ]);
    if ($code === 422 && (($res['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED')) {
        [$code, $res] = paypal_api('GET', '/v2/checkout/orders/' . rawurlencode($ppId));
    }
    if (!in_array($code, [200, 201], true)) {
        error_log('TikTato PayPal capture failed: HTTP ' . $code . ' ' . ($res['name'] ?? ''));
        throw new GameError('PayPal did not confirm the payment. You were not charged twice; please try again.');
    }
    $unit = $res['purchase_units'][0] ?? [];
    if ((string) ($unit['reference_id'] ?? '') !== 'ttt-' . $order['id']) {
        error_log('TikTato PayPal reference mismatch on order ' . $order['id']);
        throw new GameError('Payment reference mismatch. Contact support.');
    }
    $capture = $unit['payments']['captures'][0] ?? null;
    if (!$capture) {
        throw new GameError('The payment was not completed.');
    }
    return topup_credit((int) $order['id'], $capture);
}

/** Verifies a webhook with PayPal's verify-webhook-signature API. */
function paypal_verify_webhook(string $rawBody): bool
{
    $webhookId = (string) cfg('paypal.webhook_id', '');
    if ($webhookId === '' || !paypal_ready()) {
        return false;
    }
    $h = fn (string $name) => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        return false;
    }
    [$code, $res] = paypal_api('POST', '/v1/notifications/verify-webhook-signature', [
        'auth_algo'         => $h('PAYPAL-AUTH-ALGO'),
        'cert_url'          => $h('PAYPAL-CERT-URL'),
        'transmission_id'   => $h('PAYPAL-TRANSMISSION-ID'),
        'transmission_sig'  => $h('PAYPAL-TRANSMISSION-SIG'),
        'transmission_time' => $h('PAYPAL-TRANSMISSION-TIME'),
        'webhook_id'        => $webhookId,
        'webhook_event'     => $event,
    ]);
    return $code === 200 && ($res['verification_status'] ?? '') === 'SUCCESS';
}

/* ----------------------------------------------------- spending limits */

/** Applies a pending (raised) limit once its cool-down is over. */
function spending_limit(int $uid): array
{
    $u = q('SELECT weekly_limit_usd, limit_pending_usd, limit_pending_at, limit_pending_at <= NOW() AS due FROM users WHERE id = ?', [$uid])->fetch();
    if ($u['limit_pending_at'] !== null && (int) $u['due']) {
        $new = $u['limit_pending_usd'] === null ? null : (int) $u['limit_pending_usd'];
        q('UPDATE users SET weekly_limit_usd = ?, limit_pending_usd = NULL, limit_pending_at = NULL WHERE id = ?', [$new, $uid]);
        $u = ['weekly_limit_usd' => $new, 'limit_pending_usd' => null, 'limit_pending_at' => null];
    }
    $spent = (int) q(
        "SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders
         WHERE user_id = ? AND status IN ('completed', 'pending') AND created_at > NOW() - INTERVAL 7 DAY",
        [$uid]
    )->fetchColumn();
    $limit = $u['weekly_limit_usd'] === null ? null : (int) $u['weekly_limit_usd'];
    return [
        'weekly_limit_usd'   => $limit,
        'spent_7d_usd'       => round($spent / 100, 2),
        'remaining_usd'      => $limit === null ? null : max(0, round($limit - $spent / 100, 2)),
        'pending_limit'      => $u['limit_pending_at'] === null ? null : [
            'weekly_usd'   => $u['limit_pending_usd'] === null ? null : (int) $u['limit_pending_usd'],
            'effective_at' => $u['limit_pending_at'] . ' UTC',
        ],
    ];
}

/** Throws if a top-up of $usd would break the player's own weekly limit. */
function assert_within_limit(int $uid, int $usd): void
{
    $l = spending_limit($uid);
    if ($l['weekly_limit_usd'] === null) {
        return;
    }
    // Checkouts opened recently but not paid yet still count, so several can't be paid at once.
    $open = (int) q(
        "SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders
         WHERE user_id = ? AND status = 'created' AND created_at > NOW() - INTERVAL 3 HOUR",
        [$uid]
    )->fetchColumn() / 100;
    if ($l['spent_7d_usd'] + $open + $usd > $l['weekly_limit_usd']) {
        throw new GameError(sprintf(
            'This top-up would pass your weekly limit of $%d (spent $%.2f in the last 7 days%s).',
            $l['weekly_limit_usd'],
            $l['spent_7d_usd'],
            $open > 0 ? sprintf(', plus $%.2f in unpaid checkouts', $open) : ''
        ));
    }
}

/** Lowering a limit is instant; raising or removing it waits 24 h. */
function set_spending_limit(int $uid, int $weeklyUsd): array
{
    if ($weeklyUsd < 0 || $weeklyUsd > 1000) {
        throw new GameError('Pick a weekly limit between $0 and $1,000 (0 removes it).');
    }
    $new = $weeklyUsd === 0 ? null : $weeklyUsd;
    $cur = spending_limit($uid)['weekly_limit_usd'];
    $stricter = $new !== null && ($cur === null || $new <= $cur);
    if ($stricter) {
        q('UPDATE users SET weekly_limit_usd = ?, limit_pending_usd = NULL, limit_pending_at = NULL WHERE id = ?', [$new, $uid]);
        return ['status' => 'applied_now', 'weekly_limit_usd' => $new];
    }
    q(
        'UPDATE users SET limit_pending_usd = ?, limit_pending_at = NOW() + INTERVAL ' . LIMIT_RAISE_DELAY_H . ' HOUR WHERE id = ?',
        [$new, $uid]
    );
    return ['status' => 'scheduled', 'weekly_limit_usd_now' => $cur, 'new_limit_usd' => $new, 'takes_effect_in_hours' => LIMIT_RAISE_DELAY_H];
}
