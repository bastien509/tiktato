<?php
/**
 * Tato Autopilot: agent payments inside a player-approved mandate.
 *
 * 1. The player saves PayPal once (Vault v3 setup token -> approval on
 *    PayPal -> payment token). We never see card or account details.
 * 2. The player approves a mandate: pack size, "refill below N coins",
 *    weekly cap, expiry. The consent text is stored with it.
 * 3. Tato may then charge the saved PayPal without a redirect, but only
 *    inside that mandate, the player's weekly limit, a cool-down and a
 *    daily cap. Every charge is a normal PayPal order + capture, credited
 *    once through the same ledger as manual top-ups.
 */
declare(strict_types=1);

defined('TTT') || exit;

const MANDATE_PACKS_USD = [5, 10, 20];
const MANDATE_REFILL_BELOW = [100, 200, 500, 1000];
const MANDATE_CAPS_USD = [10, 20, 50, 100];
const MANDATE_DAYS = [7, 30];
const MANDATE_COOLDOWN_SEC = 120;   // between two auto top-ups
const MANDATE_MAX_PER_DAY = 3;
const MANDATE_MAX_FAILURES = 2;     // pause after this many declined charges

function vault_row(int $uid): ?array
{
    $v = q('SELECT * FROM paypal_vault WHERE user_id = ? AND mode = ?', [$uid, paypal_mode()])->fetch();
    return $v ?: null;
}

function mask_email(?string $email): ?string
{
    if (!$email || !str_contains($email, '@')) {
        return null;
    }
    [$name, $domain] = explode('@', $email, 2);
    return mb_substr($name, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($name) - 2))) . '@' . $domain;
}

/* ------------------------------------------------------- save PayPal */

/** Starts saving the player's PayPal account; returns PayPal's approval URL. */
function vault_setup_start(int $uid): string
{
    if (!paypal_ready()) {
        throw new GameError('PayPal is not connected yet.');
    }
    [$code, $res] = paypal_api('POST', '/v3/vault/setup-tokens', [
        'payment_source' => ['paypal' => [
            'description'                    => 'TikTato Autopilot: lets Tato buy coin packs for you within the limits you approve.',
            'permit_multiple_payment_tokens' => false,
            'usage_pattern'                  => 'IMMEDIATE',
            'usage_type'                     => 'MERCHANT',
            'customer_type'                  => 'CONSUMER',
            'experience_context'             => [
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name'          => (string) cfg('app.name', 'TikTato'),
                'return_url'          => abs_url('vault-return.php'),
                'cancel_url'          => abs_url('vault-return.php?cancel=1'),
            ],
        ]],
    ], ['PayPal-Request-Id: ttt-setup-' . $uid . '-' . bin2hex(random_bytes(6))]);
    $approve = '';
    foreach ($res['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') {
            $approve = (string) $link['href'];
        }
    }
    if (!in_array($code, [200, 201], true) || empty($res['id']) || $approve === '') {
        error_log('TikTato vault setup failed: HTTP ' . $code . ' ' . ($res['name'] ?? ''));
        throw new GameError('Could not start saving PayPal. Please try again.');
    }
    q(
        "INSERT INTO paypal_vault (user_id, mode, status, setup_token_id) VALUES (?, ?, 'pending', ?)
         ON DUPLICATE KEY UPDATE setup_token_id = IF(status = 'active', setup_token_id, VALUES(setup_token_id)),
                                 status = IF(status = 'active', status, 'pending')",
        [$uid, paypal_mode(), $res['id']]
    );
    // An active saved account keeps working; the new setup token replaces it after approval.
    $_SESSION['vault_setup'] = (string) $res['id'];
    return $approve;
}

/** Turns an approved setup token into a payment token and stores it. */
function vault_setup_finish(int $uid, string $setupId): array
{
    [$code, $res] = paypal_api('POST', '/v3/vault/payment-tokens', [
        'payment_source' => ['token' => ['id' => $setupId, 'type' => 'SETUP_TOKEN']],
    ], ['PayPal-Request-Id: ttt-token-' . $setupId]);
    if (!in_array($code, [200, 201], true) || empty($res['id'])) {
        error_log('TikTato vault token failed: HTTP ' . $code . ' ' . ($res['name'] ?? ''));
        throw new GameError('PayPal did not confirm the saved account. Please try again.');
    }
    $email = (string) ($res['payment_source']['paypal']['email_address'] ?? '');
    q(
        "INSERT INTO paypal_vault (user_id, mode, status, setup_token_id, payment_token_id, customer_id, payer_email)
         VALUES (?, ?, 'active', ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = 'active', setup_token_id = VALUES(setup_token_id), payment_token_id = VALUES(payment_token_id),
                                 customer_id = VALUES(customer_id), payer_email = VALUES(payer_email)",
        [$uid, paypal_mode(), $setupId, $res['id'], $res['customer']['id'] ?? null, mask_email($email)]
    );
    agent_log($uid, 'confirm', 'vault_saved', ['payer' => mask_email($email)]);
    return ['payer_email' => mask_email($email)];
}

/**
 * If a save is still pending, ask PayPal whether the player approved it and
 * finish it. Covers a lost redirect (closed tab, other browser).
 */
function vault_sync(int $uid): void
{
    $v = vault_row($uid);
    if (!$v || $v['status'] !== 'pending' || !$v['setup_token_id'] || !rate_limit("vault-sync:$uid", 6, 60)) {
        return;
    }
    [$code, $res] = paypal_api('GET', '/v3/vault/setup-tokens/' . rawurlencode($v['setup_token_id']));
    if ($code === 200 && ($res['status'] ?? '') === 'APPROVED') {
        vault_setup_finish($uid, $v['setup_token_id']);
    }
}

/** Removes the saved account at PayPal and ends any mandate. */
function vault_remove(int $uid): void
{
    $v = vault_row($uid);
    if ($v && $v['payment_token_id']) {
        [$code] = paypal_api('DELETE', '/v3/vault/payment-tokens/' . rawurlencode($v['payment_token_id']));
        if (!in_array($code, [204, 404], true)) {
            error_log('TikTato vault delete returned HTTP ' . $code);
        }
    }
    q("UPDATE paypal_vault SET status = 'removed', payment_token_id = NULL WHERE user_id = ? AND mode = ?", [$uid, paypal_mode()]);
    mandate_end($uid, 'revoked');
    agent_log($uid, 'confirm', 'vault_removed', []);
}

/* ---------------------------------------------------------- mandates */

function mandate_active(int $uid): ?array
{
    q("UPDATE agent_mandates SET status = 'expired', ended_at = NOW() WHERE user_id = ? AND status IN ('active','paused') AND expires_at < NOW()", [$uid]);
    $m = q("SELECT * FROM agent_mandates WHERE user_id = ? AND status IN ('active','paused') ORDER BY id DESC LIMIT 1", [$uid])->fetch();
    return $m ?: null;
}

function mandate_spent_usd(int $mandateId, string $interval): float
{
    return (int) q(
        "SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders
         WHERE mandate_id = ? AND status IN ('completed','pending') AND created_at > NOW() - INTERVAL $interval",
        [$mandateId]
    )->fetchColumn() / 100;
}

function mandate_terms_text(int $pack, int $below, int $cap, int $days): string
{
    return sprintf(
        'I allow Tato to buy a $%d coin pack with my saved PayPal account when my balance falls below %d coins, '
        . 'up to $%d per week, for %d days. I can pause or revoke this at any time.',
        $pack, $below, $cap, $days
    );
}

function mandate_validate(int $pack, int $below, int $cap, int $days): void
{
    if (!in_array($pack, MANDATE_PACKS_USD, true) || !in_array($below, MANDATE_REFILL_BELOW, true)
        || !in_array($cap, MANDATE_CAPS_USD, true) || !in_array($days, MANDATE_DAYS, true)) {
        throw new GameError('Choose a pack of $5/$10/$20, a refill level of 100/200/500/1000 coins, a weekly cap of $10/$20/$50/$100 and 7 or 30 days.');
    }
    if ($cap < $pack) {
        throw new GameError('The weekly cap must be at least one pack.');
    }
}

/** Creates a mandate. Only called after the player confirmed the exact terms. */
function mandate_create(int $uid, int $pack, int $below, int $cap, int $days): array
{
    mandate_validate($pack, $below, $cap, $days);
    $v = vault_row($uid);
    if (!$v || $v['status'] !== 'active') {
        throw new GameError('Save your PayPal account first.');
    }
    $text = mandate_terms_text($pack, $below, $cap, $days);
    tx(function (PDO $pdo) use ($uid, $pack, $below, $cap, $days, $text) {
        $pdo->prepare("UPDATE agent_mandates SET status = 'revoked', ended_at = NOW() WHERE user_id = ? AND status IN ('active','paused')")->execute([$uid]);
        $pdo->prepare(
            'INSERT INTO agent_mandates (user_id, pack_usd, refill_below, weekly_cap_usd, expires_at, consent_text)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . $days . ' DAY, ?)'
        )->execute([$uid, $pack, $below, $cap, $text]);
    });
    agent_log($uid, 'confirm', 'mandate_created', ['terms' => $text]);
    notify($uid, 'mandate', 'Tato Autopilot is on', $text);
    return mandate_status($uid);
}

function mandate_end(int $uid, string $status): void
{
    q("UPDATE agent_mandates SET status = ?, ended_at = NOW() WHERE user_id = ? AND status IN ('active','paused')", [$status, $uid]);
}

function mandate_set_paused(int $uid, bool $paused): void
{
    $m = mandate_active($uid);
    if (!$m) {
        throw new GameError('Autopilot is not set up.');
    }
    q('UPDATE agent_mandates SET status = ?, failures = 0 WHERE id = ?', [$paused ? 'paused' : 'active', $m['id']]);
    agent_log($uid, 'confirm', $paused ? 'mandate_paused' : 'mandate_resumed', ['mandate_id' => (int) $m['id']]);
}

/** Everything the UI and Tato need to describe Autopilot. */
function mandate_status(int $uid): array
{
    $v = vault_row($uid);
    $m = mandate_active($uid);
    $out = [
        'paypal_saved' => $v && $v['status'] === 'active',
        'payer_email'  => $v && $v['status'] === 'active' ? $v['payer_email'] : null,
        'mandate'      => null,
        'recent'       => [],
    ];
    if ($m) {
        $spent = mandate_spent_usd((int) $m['id'], '7 DAY');
        $out['mandate'] = [
            'id'              => (int) $m['id'],
            'status'          => $m['status'],
            'pack_usd'        => (int) $m['pack_usd'],
            'refill_below'    => (int) $m['refill_below'],
            'weekly_cap_usd'  => (int) $m['weekly_cap_usd'],
            'spent_7d_usd'    => $spent,
            'left_7d_usd'     => max(0, (int) $m['weekly_cap_usd'] - $spent),
            'expires_at'      => $m['expires_at'] . ' UTC',
            'terms'           => $m['consent_text'],
        ];
        $out['recent'] = array_map(fn ($r) => [
            'usd' => (int) $r['amount_cents'] / 100, 'coins' => (int) $r['coins_credit'], 'status' => $r['status'], 'at' => $r['created_at'] . 'Z',
        ], q('SELECT amount_cents, coins_credit, status, created_at FROM paypal_orders WHERE mandate_id = ? ORDER BY id DESC LIMIT 5', [$m['id']])->fetchAll());
    }
    return $out;
}

/**
 * Charges one mandate pack off-session. Every rule is checked here, on the
 * server; $why is recorded ('low_balance', 'contest', 'tato').
 * Returns ['charged' => bool, 'reason' => string, ...].
 */
function mandate_charge(int $uid, string $why): array
{
    $m = mandate_active($uid);
    if (!$m || $m['status'] !== 'active') {
        return ['charged' => false, 'reason' => 'Autopilot is off.'];
    }
    $v = vault_row($uid);
    if (!$v || $v['status'] !== 'active' || !$v['payment_token_id']) {
        return ['charged' => false, 'reason' => 'No saved PayPal account.'];
    }
    $pack = (int) $m['pack_usd'];
    if (mandate_spent_usd((int) $m['id'], '7 DAY') + $pack > (int) $m['weekly_cap_usd']) {
        return ['charged' => false, 'reason' => 'The Autopilot weekly cap of $' . $m['weekly_cap_usd'] . ' is used up.'];
    }
    $today = (int) q("SELECT COUNT(*) FROM paypal_orders WHERE mandate_id = ? AND created_at > NOW() - INTERVAL 1 DAY", [$m['id']])->fetchColumn();
    if ($today >= MANDATE_MAX_PER_DAY) {
        return ['charged' => false, 'reason' => 'Autopilot already bought ' . MANDATE_MAX_PER_DAY . ' packs in the last 24 hours.'];
    }
    try {
        assert_within_limit($uid, $pack);
    } catch (GameError $e) {
        return ['charged' => false, 'reason' => $e->getMessage()];
    }
    // Claim the cool-down slot atomically so two requests can't both charge.
    $st = db()->prepare(
        'UPDATE agent_mandates SET last_attempt_at = NOW()
         WHERE id = ? AND (last_attempt_at IS NULL OR last_attempt_at < NOW() - INTERVAL ' . MANDATE_COOLDOWN_SEC . ' SECOND)'
    );
    $st->execute([$m['id']]);
    if ($st->rowCount() !== 1) {
        return ['charged' => false, 'reason' => 'Autopilot topped up a moment ago. Try again in 2 minutes.'];
    }

    $s = topup_split($pack);
    $currency = (string) cfg('paypal.currency', 'USD');
    q(
        "INSERT INTO paypal_orders (user_id, mode, source, mandate_id, amount_cents, currency, coins_gross, coins_credit, coins_fee)
         VALUES (?, ?, 'mandate', ?, ?, ?, ?, ?, ?)",
        [$uid, paypal_mode(), $m['id'], $pack * 100, $currency, $s['gross'], $s['credit'], $s['fee']]
    );
    $localId = (int) db()->lastInsertId();
    [$code, $res] = paypal_api('POST', '/v2/checkout/orders', [
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'ttt-' . $localId,
            'custom_id'    => (string) $localId,
            'description'  => $s['gross'] . ' TikTato coins (Autopilot)',
            'amount'       => ['currency_code' => $currency, 'value' => number_format($pack, 2, '.', '')],
        ]],
        'payment_source' => ['paypal' => ['vault_id' => $v['payment_token_id']]],
    ], ['PayPal-Request-Id: ttt-auto-' . $localId]);

    $capture = $res['purchase_units'][0]['payments']['captures'][0] ?? null;
    $refOk = (string) ($res['purchase_units'][0]['reference_id'] ?? '') === 'ttt-' . $localId;
    if (!in_array($code, [200, 201], true) || !$capture || !$refOk) {
        error_log('TikTato mandate charge failed: HTTP ' . $code . ' ' . ($res['name'] ?? '') . ' ' . ($res['status'] ?? ''));
        q("UPDATE paypal_orders SET status = 'failed', paypal_order_id = ? WHERE id = ?", [$res['id'] ?? null, $localId]);
        q('UPDATE agent_mandates SET failures = failures + 1 WHERE id = ?', [$m['id']]);
        if ((int) $m['failures'] + 1 >= MANDATE_MAX_FAILURES) {
            q("UPDATE agent_mandates SET status = 'paused' WHERE id = ?", [$m['id']]);
            notify($uid, 'mandate', 'Autopilot paused', 'PayPal declined two automatic top-ups. Check your PayPal account, then resume Autopilot.');
        }
        agent_log($uid, 'tool', 'autopilot_charge', ['why' => $why, 'ok' => false, 'http' => $code]);
        return ['charged' => false, 'reason' => 'PayPal declined the automatic top-up.'];
    }
    q('UPDATE paypal_orders SET paypal_order_id = ? WHERE id = ?', [$res['id'], $localId]);
    $r = topup_credit($localId, $capture);
    q('UPDATE agent_mandates SET failures = 0 WHERE id = ?', [$m['id']]);
    agent_log($uid, 'tool', 'autopilot_charge', ['why' => $why, 'ok' => true, 'usd' => $pack, 'order' => $localId, 'result' => $r]);
    return ['charged' => $r === 'completed', 'pending' => $r === 'pending', 'usd' => $pack, 'coins' => $s['credit'], 'reason' => $why];
}

/** Lobby hook: refill when the balance dropped below the mandate threshold. */
function mandate_maybe_refill(int $uid, int $coins): ?array
{
    $m = q(
        "SELECT refill_below, last_attempt_at < NOW() - INTERVAL " . MANDATE_COOLDOWN_SEC . " SECOND OR last_attempt_at IS NULL AS ready
         FROM agent_mandates WHERE user_id = ? AND status = 'active' AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
        [$uid]
    )->fetch();
    if (!$m || $coins >= (int) $m['refill_below'] || !(int) $m['ready']) {
        return null;
    }
    $r = mandate_charge($uid, 'low_balance');
    return $r['charged'] ? $r : null;
}

/** Contest hook: if the player is short, let Autopilot top up once first. */
function mandate_cover(int $uid, int $needCoins): void
{
    $coins = (int) q('SELECT coins FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($coins < $needCoins && mandate_active($uid)) {
        mandate_charge($uid, 'contest');
    }
}
