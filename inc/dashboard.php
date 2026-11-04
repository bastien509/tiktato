<?php
/**
 * Data for the AG Grid activity dashboard.
 * scope "me": the player's own ledger, PayPal orders, agent actions, matches.
 * scope "platform" (admins only): every account plus the ledger balance checks.
 */
declare(strict_types=1);

defined('TTT') || exit;

const DASH_LIMIT = 1000;

/** One readable line per agent_log row. */
function agent_summary(array $r): array
{
    $d = json_decode((string) $r['detail'], true) ?: [];
    $ok = !isset($d['error']) && ($d['ok'] ?? true) !== false;
    $usd = null;
    $label = $r['tool'] !== '' ? ucfirst(str_replace('_', ' ', $r['tool'])) : 'Chat with Tato';
    switch ($r['kind']) {
        case 'message':
            $text = '“' . mb_strimwidth((string) ($d['user'] ?? ''), 0, 110, '…') . '” → ' . mb_strimwidth((string) ($d['reply'] ?? ''), 0, 140, '…');
            break;
        case 'tool':
            if ($r['tool'] === 'autopilot_charge') {
                $label = 'Autopilot charge';
                $usd = isset($d['usd']) ? (int) $d['usd'] : null;
                $why = ['low_balance' => 'balance below refill level', 'contest' => 'covering a contest entry', 'tato' => 'player asked Tato'][$d['why'] ?? ''] ?? (string) ($d['why'] ?? '');
                $text = $ok ? "Charged saved PayPal ($why)" : "PayPal declined ($why)";
            } else {
                $in = $d['input'] ?? [];
                $text = $in ? 'Input ' . json_encode($in, JSON_UNESCAPED_SLASHES) : 'No input';
                if (isset($d['error'])) {
                    $text .= ' · refused: ' . $d['error'];
                }
                if ($r['tool'] === 'start_paypal_checkout' && isset($in['usd'])) {
                    $usd = (int) $in['usd'];
                }
            }
            break;
        default: // confirm
            $text = match ($r['tool']) {
                'mandate_created' => (string) ($d['terms'] ?? 'Mandate approved'),
                'vault_saved'     => 'Saved PayPal ' . ($d['payer'] ?? ''),
                'vault_removed'   => 'Removed saved PayPal',
                'mandate_revoked', 'mandate_paused', 'mandate_resumed' => 'Player changed Autopilot',
                default           => 'Player confirmed: ' . json_encode(array_diff_key($d, ['expires' => 1, 'uid' => 1]), JSON_UNESCAPED_SLASHES),
            };
            $label = ucfirst(str_replace('_', ' ', $r['tool']));
    }
    return ['ok' => $ok, 'action' => $label, 'details' => $text, 'usd' => $usd];
}

function dashboard_data(int $uid, bool $platform): array
{
    $where = $platform ? '1 = 1' : 'x.user_id = ' . $uid;

    $agent = [];
    foreach (q("SELECT x.*, u.username FROM agent_log x JOIN users u ON u.id = x.user_id WHERE $where ORDER BY x.id DESC LIMIT " . DASH_LIMIT)->fetchAll() as $r) {
        $agent[] = ['id' => (int) $r['id'], 'at' => $r['created_at'] . 'Z', 'user' => $r['username'], 'kind' => $r['kind']] + agent_summary($r);
    }

    $ledgerWhere = $platform ? '1 = 1' : "x.account = 'user' AND x.user_id = " . $uid;
    $ledger = array_map(fn ($r) => [
        'id'      => (int) $r['id'],
        'at'      => $r['created_at'] . 'Z',
        'user'    => $r['username'] ?? '—',
        'account' => $r['account'],
        'type'    => $r['type'],
        'amount'  => (int) $r['amount'],
        'balance' => $r['balance_after'] === null ? null : (int) $r['balance_after'],
        'match'   => $r['match_id'] ? (int) $r['match_id'] : null,
        'order'   => $r['paypal_order_id'] ? (int) $r['paypal_order_id'] : null,
        'note'    => (string) ($r['note'] ?? ''),
    ], q("SELECT x.*, u.username FROM wallet_ledger x LEFT JOIN users u ON u.id = x.user_id WHERE $ledgerWhere ORDER BY x.id DESC LIMIT " . DASH_LIMIT)->fetchAll());

    $orders = array_map(fn ($r) => [
        'id'      => (int) $r['id'],
        'at'      => $r['created_at'] . 'Z',
        'user'    => $r['username'],
        'source'  => $r['source'],
        'usd'     => (int) $r['amount_cents'] / 100,
        'coins'   => (int) $r['coins_credit'],
        'fee'     => (int) $r['coins_fee'],
        'status'  => $r['status'],
        'capture' => $r['capture_id'] ? substr($r['capture_id'], 0, 4) . '…' . substr($r['capture_id'], -3) : '',
    ], q("SELECT x.*, u.username FROM paypal_orders x JOIN users u ON u.id = x.user_id WHERE $where ORDER BY x.id DESC LIMIT " . DASH_LIMIT)->fetchAll());

    $mWhere = $platform ? "m.status = 'finished'" : "m.status = 'finished' AND (m.player_x_id = $uid OR m.player_o_id = $uid)";
    $matches = array_map(function ($m) use ($uid, $platform) {
        $draw = $m['winner_id'] === null;
        $won = !$draw && (int) $m['winner_id'] === $uid;
        $isX = (int) $m['player_x_id'] === $uid;
        return [
            'id'       => (int) $m['id'],
            'at'       => $m['finished_at'] . 'Z',
            'players'  => $m['xname'] . ' vs ' . $m['oname'],
            'opponent' => $isX ? $m['oname'] : $m['xname'],
            'winner'   => $draw ? 'Draw' : ((int) $m['winner_id'] === (int) $m['player_x_id'] ? $m['xname'] : $m['oname']),
            'entry'    => (int) $m['stake'],
            'how'      => $m['result'],
            'outcome'  => $platform ? null : ($draw ? 'draw' : ($won ? 'won' : 'lost')),
            'net'      => $platform ? null : ($draw ? 0 : ($won ? (int) $m['winner_payout'] - (int) $m['stake'] : -(int) $m['stake'])),
            'fee'      => (int) $m['platform_fee'],
        ];
    }, q("SELECT m.*, ux.username AS xname, uo.username AS oname FROM matches m
          JOIN users ux ON ux.id = m.player_x_id JOIN users uo ON uo.id = m.player_o_id
          WHERE $mWhere ORDER BY m.id DESC LIMIT " . DASH_LIMIT)->fetchAll());

    return ['agent' => $agent, 'ledger' => $ledger, 'orders' => $orders, 'matches' => $matches, 'kpis' => dashboard_kpis($uid, $platform)];
}

function dashboard_kpis(int $uid, bool $platform): array
{
    $n = fn (string $sql, array $p = []) => (int) q($sql, $p)->fetchColumn();
    if ($platform) {
        $escrowHeld = $n("SELECT COALESCE(SUM(stake), 0) FROM challenges WHERE status = 'open'") + $n("SELECT COALESCE(SUM(pot), 0) FROM matches WHERE status = 'active'");
        return [
            ['label' => 'PayPal received', 'value' => '$' . number_format($n("SELECT COALESCE(SUM(amount_cents), 0) FROM paypal_orders WHERE status = 'completed'") / 100, 2)],
            ['label' => 'Platform revenue', 'value' => number_format($n("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'platform'")) . ' coins'],
            ['label' => 'Autopilot charges', 'value' => (string) $n("SELECT COUNT(*) FROM paypal_orders WHERE source = 'mandate' AND status = 'completed'")],
            ['label' => 'Agent actions', 'value' => (string) $n('SELECT COUNT(*) FROM agent_log')],
            ['label' => 'Players', 'value' => (string) $n('SELECT COUNT(*) FROM users')],
            ['label' => 'Ledger balanced', 'value' => (
                $n('SELECT COALESCE(SUM(coins), 0) FROM users') === $n("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'user'")
                && $n("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE account = 'escrow'") === $escrowHeld
                && $n('SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger') === $n("SELECT COALESCE(SUM(coins_gross), 0) FROM paypal_orders WHERE status = 'completed'")
            ) ? '✓ yes' : '✕ no', 'good' => true],
        ];
    }
    $u = q('SELECT coins, wins, losses, draws FROM users WHERE id = ?', [$uid])->fetch();
    $lim = spending_limit($uid);
    $ap = mandate_status($uid)['mandate'];
    return [
        ['label' => 'Balance', 'value' => number_format((int) $u['coins']) . ' coins'],
        ['label' => 'PayPal, last 7 days', 'value' => '$' . number_format($lim['spent_7d_usd'], 2) . ($lim['weekly_limit_usd'] !== null ? ' of $' . $lim['weekly_limit_usd'] : '')],
        ['label' => 'Autopilot', 'value' => $ap ? ucfirst($ap['status']) . ' · $' . number_format($ap['spent_7d_usd'], 0) . '/$' . $ap['weekly_cap_usd'] : 'Off'],
        ['label' => 'Agent actions', 'value' => (string) $n('SELECT COUNT(*) FROM agent_log WHERE user_id = ?', [$uid])],
        ['label' => 'Record', 'value' => $u['wins'] . 'W · ' . $u['losses'] . 'L · ' . $u['draws'] . 'D'],
    ];
}
