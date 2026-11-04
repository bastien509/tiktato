<?php
/**
 * JSON API for the game client. GET = reads (polls), POST = actions.
 * Every call needs a logged-in session; every POST needs the CSRF header.
 */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/game.php';
require __DIR__ . '/inc/paypal.php';
require __DIR__ . '/inc/agent.php';
require __DIR__ . '/inc/autopilot.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['ok' => false, 'error' => $msg], $code);
}

$action = (string) ($_GET['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$me = current_user();
if (!$me) {
    fail('Please log in again.', 401);
}
$uid = (int) $me['id'];

if ($isPost && !csrf_valid()) {
    fail('Your session expired. Reload the page.', 419);
}
// Polls run every 1-2 s, so the read budget is generous; actions are tighter.
if (!rate_limit("api:$uid:" . ($isPost ? 'w' : 'r'), $isPost ? 40 : 150, 60)) {
    fail('Slow down a little and try again.', 429);
}

$in = $_POST;
if ($isPost && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
}

function me_payload(int $uid): array
{
    $u = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
    return public_profile($u) + [
        'coins'  => (int) $u['coins'],
        'losses' => (int) $u['losses'],
        'draws'  => (int) $u['draws'],
    ];
}

try {
    switch ($isPost ? "POST $action" : "GET $action") {
        case 'GET lobby':
            sweep();
            q('UPDATE users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 20 SECOND)', [$uid]);
            $rows = q(
                "SELECT c.id, c.stake, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), c.expires_at)) AS secs_left,
                        u.id AS uid, u.username, u.avatar, u.avatar_color, u.wins
                 FROM challenges c JOIN users u ON u.id = c.creator_id
                 WHERE c.status = 'open' AND c.expires_at > NOW() AND c.creator_id <> ?
                 ORDER BY c.id DESC LIMIT 30",
                [$uid]
            )->fetchAll();
            $challenges = array_map(fn ($r) => [
                'id'        => (int) $r['id'],
                'stake'     => (int) $r['stake'],
                'secs_left' => (int) $r['secs_left'],
                'creator'   => public_profile(['id' => $r['uid'], 'username' => $r['username'], 'avatar' => $r['avatar'], 'avatar_color' => $r['avatar_color'], 'wins' => $r['wins']]),
            ], $rows);
            $mine = q(
                "SELECT id, stake, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS secs_left
                 FROM challenges WHERE creator_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
                [$uid]
            )->fetch();
            $notes = q('SELECT id, type, title, body, match_id FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 10', [$uid])->fetchAll();
            $online = (int) q('SELECT COUNT(*) FROM users WHERE last_seen_at > NOW() - INTERVAL 60 SECOND')->fetchColumn();
            $me = me_payload($uid);
            $due = q(
                "SELECT 1 FROM agent_mandates WHERE user_id = ? AND status = 'active' AND expires_at > NOW() AND refill_below > ?
                   AND (last_attempt_at IS NULL OR last_attempt_at < NOW() - INTERVAL " . MANDATE_COOLDOWN_SEC . " SECOND) LIMIT 1",
                [$uid, $me['coins']]
            )->fetchColumn();
            out([
                'ok'            => true,
                'me'            => $me,
                'autopilot_due' => (bool) $due,
                'challenges'    => $challenges,
                'my_challenge'  => $mine ? ['id' => (int) $mine['id'], 'stake' => (int) $mine['stake'], 'secs_left' => (int) $mine['secs_left']] : null,
                'active_match'  => active_match_id($uid),
                'notifications' => array_map(fn ($n) => ['id' => (int) $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'], 'match_id' => $n['match_id'] ? (int) $n['match_id'] : null], $notes),
                'online'        => max(1, $online),
            ]);

        case 'GET match':
            out(['ok' => true, 'match' => match_view($uid, (int) ($_GET['id'] ?? 0)), 'me' => me_payload($uid)]);

        case 'GET wallet':
            vault_sync($uid);
            $rows = q(
                "SELECT id, type, amount, balance_after, match_id, note, created_at FROM wallet_ledger
                 WHERE account = 'user' AND user_id = ? ORDER BY id DESC LIMIT 40",
                [$uid]
            )->fetchAll();
            $packs = array_map('topup_split', TOPUP_PACKAGES_USD);
            out([
                'ok'       => true,
                'me'       => me_payload($uid),
                'ledger'   => array_map(fn ($r) => ['id' => (int) $r['id'], 'type' => $r['type'], 'amount' => (int) $r['amount'], 'balance' => (int) $r['balance_after'], 'match_id' => $r['match_id'] ? (int) $r['match_id'] : null, 'note' => $r['note'], 'at' => $r['created_at'] . 'Z'], $rows),
                'packs'    => $packs,
                'paypal'   => ['ready' => paypal_ready(), 'mode' => paypal_mode()],
                'limits'   => spending_limit($uid),
                'autopilot' => mandate_status($uid) + ['options' => [
                    'packs' => MANDATE_PACKS_USD, 'refill_below' => MANDATE_REFILL_BELOW, 'caps' => MANDATE_CAPS_USD, 'days' => MANDATE_DAYS,
                ]],
                'fees'     => ['topup_pct' => TOPUP_FEE_PCT, 'match_pct' => MATCH_FEE_PCT, 'coins_per_usd' => COINS_PER_USD],
            ]);

        case 'POST challenge_create':
            if (preg_match('/^\d{1,6}$/', (string) ($in['stake'] ?? ''))) {
                mandate_cover($uid, (int) $in['stake']);
            }
            $c = challenge_create($uid, $in['stake'] ?? '');
            out(['ok' => true, 'challenge' => $c, 'me' => me_payload($uid)]);

        case 'POST challenge_cancel':
            challenge_cancel($uid, (int) ($in['id'] ?? 0));
            out(['ok' => true, 'me' => me_payload($uid)]);

        case 'POST challenge_accept':
            $need = (int) q("SELECT stake FROM challenges WHERE id = ? AND status = 'open'", [(int) ($in['id'] ?? 0)])->fetchColumn();
            if ($need > 0) {
                mandate_cover($uid, $need);
            }
            $mid = challenge_accept($uid, (int) ($in['id'] ?? 0));
            out(['ok' => true, 'match_id' => $mid, 'me' => me_payload($uid)]);

        case 'POST move':
            $mid = (int) ($in['match_id'] ?? 0);
            match_move($uid, $mid, $in['cell'] ?? '');
            out(['ok' => true, 'match' => match_view($uid, $mid), 'me' => me_payload($uid)]);

        case 'POST resign':
            $mid = (int) ($in['match_id'] ?? 0);
            match_resign($uid, $mid);
            out(['ok' => true, 'match' => match_view($uid, $mid), 'me' => me_payload($uid)]);

        case 'POST notifications_read':
            q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id <= ?', [$uid, (int) ($in['up_to'] ?? 0)]);
            out(['ok' => true]);

        case 'POST topup':
            if (!rate_limit("topup:$uid", 10, 600)) {
                fail('Too many checkout attempts. Wait a few minutes.', 429);
            }
            out(['ok' => true, 'approve_url' => topup_create($uid, $in['usd'] ?? '')]);

        case 'POST agent':
            if (!agent_ready()) {
                fail('Tato is offline right now.', 503);
            }
            $text = trim((string) ($in['message'] ?? ''));
            if ($text === '' || mb_strlen($text) > AGENT_MAX_INPUT) {
                fail('Keep your message under ' . AGENT_MAX_INPUT . ' characters.');
            }
            if (!rate_limit("agent:$uid", 15, 300) || !rate_limit("agent-day:$uid", 150, 86400)) {
                fail('Tato needs a short break. Try again in a few minutes.', 429);
            }
            set_time_limit(120);
            out(['ok' => true] + agent_chat($uid, $text) + ['me' => me_payload($uid)]);

        case 'POST agent_confirm':
            $r = agent_confirm($uid, (string) ($in['proposal_id'] ?? ''));
            out(['ok' => true, 'result' => $r, 'me' => me_payload($uid)]);

        case 'POST autopilot_tick':
            $coins = (int) q('SELECT coins FROM users WHERE id = ?', [$uid])->fetchColumn();
            out(['ok' => true, 'topup' => mandate_maybe_refill($uid, $coins), 'me' => me_payload($uid)]);

        case 'POST vault_setup':
            if (!rate_limit("vault-setup:$uid", 6, 600)) {
                fail('Too many attempts. Wait a few minutes.', 429);
            }
            out(['ok' => true, 'approve_url' => vault_setup_start($uid)]);

        case 'POST vault_remove':
            vault_remove($uid);
            out(['ok' => true, 'autopilot' => mandate_status($uid)]);

        case 'POST mandate_create':
            if (empty($in['consent'])) {
                fail('Tick the box to approve the Autopilot terms.');
            }
            out(['ok' => true, 'autopilot' => mandate_create($uid, (int) ($in['pack_usd'] ?? 0), (int) ($in['refill_below'] ?? 0), (int) ($in['weekly_cap_usd'] ?? 0), (int) ($in['days'] ?? 0))]);

        case 'POST mandate_change':
            $act = (string) ($in['action'] ?? '');
            if ($act === 'revoke') {
                mandate_end($uid, 'revoked');
                agent_log($uid, 'confirm', 'mandate_revoked', ['via' => 'wallet']);
            } elseif ($act === 'pause' || $act === 'resume') {
                mandate_set_paused($uid, $act === 'pause');
            } else {
                fail('Unknown action.');
            }
            out(['ok' => true, 'autopilot' => mandate_status($uid)]);

        case 'POST agent_reset':
            unset($_SESSION['agent_history'], $_SESSION['agent_proposals']);
            out(['ok' => true]);

        default:
            fail('Unknown action.', 404);
    }
} catch (GameError $e) {
    fail($e->getMessage(), 409);
} catch (\Anthropic\Core\Exceptions\RateLimitException $e) {
    fail('Tato is busy right now. Try again in a moment.', 503);
} catch (\Anthropic\Core\Exceptions\APIException $e) {
    error_log('TikTato agent API error: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 300));
    fail('Tato could not answer right now. Please try again.', 503);
} catch (Throwable $e) {
    error_log('TikTato API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Something went wrong. Please try again.', 500);
}
