<?php
/**
 * "Tato" — the AI wallet agent (Claude via the Anthropic PHP SDK).
 *
 * Claude only ever calls the tools below. Every tool re-checks auth, limits
 * and balances on the server. Tools that move coins never act directly:
 * they create a proposal the player must confirm with a button, and PayPal
 * top-ups still need the player's approval on PayPal itself.
 */
declare(strict_types=1);

defined('TTT') || exit;

use Anthropic\Client;

const AGENT_MODEL = 'claude-opus-5-5';
const AGENT_MAX_STEPS = 6;
const AGENT_HISTORY_TURNS = 10;
const AGENT_MAX_INPUT = 600;

function agent_ready(): bool
{
    return (string) cfg('ai.anthropic_api_key', '') !== '';
}

function agent_client(): Client
{
    static $client = null;
    if ($client === null) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $client = new Client(
            apiKey: (string) cfg('ai.anthropic_api_key'),
            requestOptions: ['timeout' => 45.0, 'maxRetries' => 1],
        );
    }
    return $client;
}

function agent_log(int $uid, string $kind, string $tool, array $detail): void
{
    q(
        'INSERT INTO agent_log (user_id, kind, tool, detail) VALUES (?, ?, ?, ?)',
        [$uid, $kind, $tool, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
}

/* ------------------------------------------------------------- tools */

function agent_tools(): array
{
    $obj = fn (array $props, array $required) => [
        'type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false,
    ];
    return [
        [
            'name' => 'get_wallet',
            'description' => "Read the player's coin balance, win/loss record, weekly PayPal spending limit, money spent in the last 7 days, open contest, active match and latest wallet movements. Call this before answering any question about the player's money.",
            'inputSchema' => $obj([], []),
            'strict' => true,
        ],
        [
            'name' => 'list_coin_packs',
            'description' => 'List the coin packs sold through PayPal, with the exact coins credited and the 5% service fee for each.',
            'inputSchema' => $obj([], []),
            'strict' => true,
        ],
        [
            'name' => 'start_paypal_checkout',
            'description' => 'Create a PayPal checkout for one coin pack. This does NOT charge anything: it shows the player a "Pay with PayPal" button, and they must approve the payment on PayPal. Fails if the pack would pass their weekly limit.',
            'inputSchema' => $obj(['usd' => ['type' => 'integer', 'enum' => TOPUP_PACKAGES_USD, 'description' => 'Pack price in US dollars']], ['usd']),
            'strict' => true,
        ],
        [
            'name' => 'set_weekly_limit',
            'description' => 'Set the weekly PayPal top-up limit in US dollars (0 removes it). A lower limit applies immediately; raising or removing a limit only takes effect after a 24-hour cool-down.',
            'inputSchema' => $obj(['weekly_usd' => ['type' => 'integer', 'description' => 'Weekly limit in USD, 0 to 1000']], ['weekly_usd']),
            'strict' => true,
        ],
        [
            'name' => 'list_open_contests',
            'description' => 'List open 1v1 contests posted by other players (id, entry in coins, creator, prize if won) and the player\'s own open contest.',
            'inputSchema' => $obj([], []),
            'strict' => true,
        ],
        [
            'name' => 'propose_contest_action',
            'description' => 'Prepare a contest action for the player to confirm with a button: create a contest with an entry fee, accept an open contest, or cancel their own open contest for a refund. Nothing happens until the player presses Confirm.',
            'inputSchema' => $obj([
                'action'      => ['type' => 'string', 'enum' => ['create', 'accept', 'cancel']],
                'entry_coins' => ['type' => 'integer', 'description' => 'Entry fee in coins for create; 0 otherwise'],
                'contest_id'  => ['type' => 'integer', 'description' => 'Contest id for accept or cancel; 0 for create'],
            ], ['action', 'entry_coins', 'contest_id']),
            'strict' => true,
        ],
        [
            'name' => 'get_recent_matches',
            'description' => "List the player's most recent finished coin matches with opponent, entry, result and net coins.",
            'inputSchema' => $obj(['limit' => ['type' => 'integer', 'description' => '1 to 10']], ['limit']),
            'strict' => true,
        ],
        [
            'name' => 'get_autopilot',
            'description' => 'Read Tato Autopilot: whether the player saved a PayPal account, the active mandate (pack, refill level, weekly cap, spent this week, expiry, status) and recent automatic top-ups.',
            'inputSchema' => $obj([], []),
            'strict' => true,
        ],
        [
            'name' => 'propose_autopilot',
            'description' => 'Prepare an Autopilot mandate for the player to approve with a button: Tato may buy one pack with their saved PayPal when the balance falls below a level, up to a weekly cap, until it expires. If no PayPal account is saved yet, this shows a "Save PayPal" button instead.',
            'inputSchema' => $obj([
                'pack_usd'           => ['type' => 'integer', 'enum' => MANDATE_PACKS_USD],
                'refill_below_coins' => ['type' => 'integer', 'enum' => MANDATE_REFILL_BELOW],
                'weekly_cap_usd'     => ['type' => 'integer', 'enum' => MANDATE_CAPS_USD],
                'days'               => ['type' => 'integer', 'enum' => MANDATE_DAYS],
            ], ['pack_usd', 'refill_below_coins', 'weekly_cap_usd', 'days']),
            'strict' => true,
        ],
        [
            'name' => 'autopilot_topup_now',
            'description' => 'Buy one pack right now with the saved PayPal account, under the active Autopilot mandate. Allowed only inside the mandate (pack size, weekly cap, daily cap, cool-down, weekly limit); the server refuses anything else. Only use it when the player asks for coins and Autopilot is active.',
            'inputSchema' => $obj([], []),
            'strict' => true,
        ],
        [
            'name' => 'change_autopilot',
            'description' => 'Pause or revoke Autopilot immediately, or prepare a "resume" for the player to confirm with a button.',
            'inputSchema' => $obj(['action' => ['type' => 'string', 'enum' => ['pause', 'resume', 'revoke']]], ['action']),
            'strict' => true,
        ],
    ];
}

function agent_system_prompt(): string
{
    $fee = MATCH_FEE_PCT;
    $topup = TOPUP_FEE_PCT;
    $packs = implode(', ', array_map(fn ($u) => '$' . $u, TOPUP_PACKAGES_USD));
    return <<<TXT
You are Tato, the wallet and contest assistant inside TikTato, a 1v1 tic-tac-toe skill-contest app.

How TikTato works:
- Coins: 100 coins = \$1. Coins are bought only through PayPal, in packs of {$packs}. TikTato keeps a {$topup}% service fee, so a \$10 pack credits 950 coins.
- Contests: a player posts a contest with an entry fee (10 to 10,000 coins, steps of 5). The opponent who accepts pays the same entry. Both entries are held in escrow.
- The winner receives the prize pool (both entries) minus a {$fee}% platform fee: a 100-coin entry wins 160 coins. The loser loses their entry. A draw refunds both entries in full.
- 30 seconds per move; a player whose clock runs out forfeits. Unmatched contests refund after 10 minutes, or anytime on cancel.
- Practice games against the built-in AI are free.
- This deployment uses the PayPal sandbox: no real money moves.
- Tato Autopilot: the player can save their PayPal account once (approved on PayPal) and approve a mandate. Within it, you may buy one pack for them without a PayPal redirect when their balance drops below the chosen level or when they ask you. Hard limits: the mandate's pack size and weekly cap, at most 3 packs per day, a 2-minute cool-down, the player's weekly limit and the expiry date. The player can pause or revoke it anytime, and you pause or revoke it immediately when asked.

How you work:
- Use tools for every fact about the player's wallet, limits, contests or matches. Never guess numbers.
- Outside Autopilot you cannot move coins yourself. start_paypal_checkout only shows a PayPal button; propose_contest_action and propose_autopilot only show a Confirm button. After calling them, tell the player what will happen when they press the button, and never say it is already done.
- autopilot_topup_now really charges the saved PayPal account. Use it only when the player clearly asks for coins now and Autopilot is active, and report exactly what the tool returned.
- Encourage responsible play: suggest a weekly limit when a player buys often or chases losses, and explain the 24-hour cool-down for raising it. Never pressure anyone to buy coins or play for coins. Practice mode is always an option.
- Coins cannot be cashed out in this demo. Do not give financial or gambling advice beyond explaining TikTato's own rules.
- Keep replies short and friendly (2 to 4 sentences), in the player's language, plain text without markdown headings.
TXT;
}

/** Runs one tool call for $uid. Returns [resultForClaude, ?uiCard]. */
function agent_tool(int $uid, string $name, array $in): array
{
    switch ($name) {
        case 'get_wallet':
            $u = q('SELECT coins, wins, losses, draws FROM users WHERE id = ?', [$uid])->fetch();
            $recent = q(
                "SELECT type, amount, note, created_at FROM wallet_ledger WHERE account = 'user' AND user_id = ? ORDER BY id DESC LIMIT 6",
                [$uid]
            )->fetchAll();
            $mine = q("SELECT id, stake FROM challenges WHERE creator_id = ? AND status = 'open' LIMIT 1", [$uid])->fetch();
            return [[
                'coins'          => (int) $u['coins'],
                'usd_value'      => round($u['coins'] / COINS_PER_USD, 2),
                'record'         => ['wins' => (int) $u['wins'], 'losses' => (int) $u['losses'], 'draws' => (int) $u['draws']],
                'spending'       => spending_limit($uid),
                'open_contest'   => $mine ? ['id' => (int) $mine['id'], 'entry_coins' => (int) $mine['stake']] : null,
                'active_match'   => active_match_id($uid),
                'recent_moves'   => array_map(fn ($r) => ['type' => $r['type'], 'coins' => (int) $r['amount'], 'note' => $r['note'], 'at' => $r['created_at'] . ' UTC'], $recent),
            ], null];

        case 'list_coin_packs':
            return [['packs' => array_map('topup_split', TOPUP_PACKAGES_USD), 'fee_pct' => TOPUP_FEE_PCT, 'sandbox' => paypal_mode() === 'sandbox'], null];

        case 'start_paypal_checkout':
            $usd = (int) ($in['usd'] ?? 0);
            $url = topup_create($uid, $usd);
            $s = topup_split($usd);
            return [
                ['status' => 'checkout_ready', 'usd' => $usd, 'coins_credit' => $s['credit'], 'fee_coins' => $s['fee'], 'note' => 'Nothing is charged until the player approves on PayPal.'],
                ['type' => 'checkout', 'usd' => $usd, 'credit' => $s['credit'], 'fee' => $s['fee'], 'url' => $url],
            ];

        case 'set_weekly_limit':
            $r = set_spending_limit($uid, (int) ($in['weekly_usd'] ?? -1));
            return [$r, ['type' => 'limit', 'result' => $r]];

        case 'list_open_contests':
            $rows = q(
                "SELECT c.id, c.stake, c.creator_id, u.username, u.wins FROM challenges c JOIN users u ON u.id = c.creator_id
                 WHERE c.status = 'open' AND c.expires_at > NOW() ORDER BY c.id DESC LIMIT 15"
            )->fetchAll();
            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => (int) $r['id'], 'entry_coins' => (int) $r['stake'], 'creator' => $r['username'],
                    'creator_wins' => (int) $r['wins'], 'is_mine' => (int) $r['creator_id'] === $uid,
                    'prize_if_won' => 2 * (int) $r['stake'] - intdiv(2 * (int) $r['stake'] * MATCH_FEE_PCT, 100),
                ];
            }
            return [['contests' => $list], null];

        case 'propose_contest_action':
            return agent_propose($uid, (string) ($in['action'] ?? ''), (int) ($in['entry_coins'] ?? 0), (int) ($in['contest_id'] ?? 0));

        case 'get_recent_matches':
            $limit = max(1, min(10, (int) ($in['limit'] ?? 5)));
            $rows = q(
                "SELECT m.id, m.stake, m.result, m.winner_id, m.winner_payout, m.finished_at,
                        IF(m.player_x_id = ?, uo.username, ux.username) AS opponent
                 FROM matches m JOIN users ux ON ux.id = m.player_x_id JOIN users uo ON uo.id = m.player_o_id
                 WHERE m.status = 'finished' AND (m.player_x_id = ? OR m.player_o_id = ?)
                 ORDER BY m.id DESC LIMIT $limit",
                [$uid, $uid, $uid]
            )->fetchAll();
            return [['matches' => array_map(function ($m) use ($uid) {
                $won = $m['winner_id'] !== null && (int) $m['winner_id'] === $uid;
                $outcome = $m['winner_id'] === null ? 'draw' : ($won ? 'won' : 'lost');
                $net = $outcome === 'draw' ? 0 : ($won ? (int) $m['winner_payout'] - (int) $m['stake'] : -(int) $m['stake']);
                return ['match_id' => (int) $m['id'], 'opponent' => $m['opponent'], 'entry_coins' => (int) $m['stake'], 'outcome' => $outcome, 'how' => $m['result'], 'net_coins' => $net, 'at' => $m['finished_at'] . ' UTC'];
            }, $rows)], null];

        case 'get_autopilot':
            return [mandate_status($uid), null];

        case 'propose_autopilot':
            $pack = (int) ($in['pack_usd'] ?? 0);
            $below = (int) ($in['refill_below_coins'] ?? 0);
            $cap = (int) ($in['weekly_cap_usd'] ?? 0);
            $days = (int) ($in['days'] ?? 0);
            mandate_validate($pack, $below, $cap, $days);
            $v = vault_row($uid);
            if (!$v || $v['status'] !== 'active') {
                return [
                    ['status' => 'needs_saved_paypal', 'note' => 'The player must first save PayPal with the button shown; then ask again to approve the mandate.'],
                    ['type' => 'connect_paypal'],
                ];
            }
            $id = agent_park($uid, ['action' => 'mandate', 'pack' => $pack, 'below' => $below, 'cap' => $cap, 'days' => $days]);
            return [
                ['status' => 'awaiting_player_confirmation'],
                ['type' => 'confirm', 'proposal_id' => $id, 'title' => 'Turn on Tato Autopilot', 'terms' => mandate_terms_text($pack, $below, $cap, $days), 'lines' => [
                    ['Pack per top-up', '$' . $pack . ' → +' . topup_split($pack)['credit']],
                    ['When balance is below', $below . ' coins'],
                    ['Weekly cap', '$' . $cap],
                    ['Expires', 'in ' . $days . ' days'],
                ]],
            ];

        case 'autopilot_topup_now':
            $r = mandate_charge($uid, 'tato');
            return [$r, $r['charged'] ? ['type' => 'autotopup', 'usd' => $r['usd'], 'coins' => $r['coins']] : null];

        case 'change_autopilot':
            $action = (string) ($in['action'] ?? '');
            if ($action === 'pause' || $action === 'revoke') {
                if ($action === 'pause') {
                    mandate_set_paused($uid, true);
                } else {
                    mandate_end($uid, 'revoked');
                    agent_log($uid, 'confirm', 'mandate_revoked', []);
                }
                return [['status' => $action === 'pause' ? 'paused' : 'revoked'], ['type' => 'autopilot', 'status' => mandate_status($uid)]];
            }
            if ($action === 'resume') {
                $m = mandate_active($uid);
                if (!$m || $m['status'] !== 'paused') {
                    throw new GameError('There is no paused Autopilot to resume.');
                }
                $id = agent_park($uid, ['action' => 'resume']);
                return [['status' => 'awaiting_player_confirmation'], ['type' => 'confirm', 'proposal_id' => $id, 'title' => 'Resume Tato Autopilot', 'terms' => $m['consent_text'], 'lines' => []]];
            }
            throw new GameError('Unknown Autopilot action.');
    }
    throw new GameError('Unknown tool.');
}

/** Parks an action until the player confirms it in the UI (5 minutes, single use). */
function agent_park(int $uid, array $proposal): string
{
    $id = bin2hex(random_bytes(8));
    $_SESSION['agent_proposals'][$id] = $proposal + ['uid' => $uid, 'expires' => time() + 300];
    $_SESSION['agent_proposals'] = array_slice($_SESSION['agent_proposals'], -5, null, true);
    return $id;
}

/** Validates a contest action and parks it until the player confirms. */
function agent_propose(int $uid, string $action, int $entry, int $cid): array
{
    $coins = (int) q('SELECT coins FROM users WHERE id = ?', [$uid])->fetchColumn();
    // With Autopilot on, a short balance is topped up on confirm (inside the mandate).
    $autopilot = ($m = mandate_active($uid)) && $m['status'] === 'active';
    if ($autopilot) {
        $coins += topup_split((int) $m['pack_usd'])['credit'];
    }
    $lines = [];
    if ($action === 'create') {
        $entry = valid_stake((string) $entry);
        if ($coins < $entry) {
            throw new GameError("The player has $coins coins, not enough for a $entry-coin entry.");
        }
        $p = payoutFor($entry);
        $title = "Post a $entry-coin contest";
        $lines = [['Your entry', '−' . $entry], ['If you win', '+' . $p['win']], ['If you lose', '−' . $entry], ['Draw', 'full refund']];
    } elseif ($action === 'accept') {
        $ch = q("SELECT c.*, u.username FROM challenges c JOIN users u ON u.id = c.creator_id WHERE c.id = ? AND c.status = 'open' AND c.expires_at > NOW()", [$cid])->fetch();
        if (!$ch || (int) $ch['creator_id'] === $uid) {
            throw new GameError('That contest is not open to this player.');
        }
        $entry = (int) $ch['stake'];
        if ($coins < $entry) {
            throw new GameError("The player has $coins coins, not enough for this $entry-coin entry.");
        }
        $p = payoutFor($entry);
        $title = "Accept {$ch['username']}'s $entry-coin contest";
        $lines = [['Your entry', '−' . $entry], ['If you win', '+' . $p['win']], ['If you lose', '−' . $entry], ['Draw', 'full refund']];
    } elseif ($action === 'cancel') {
        $ch = q("SELECT * FROM challenges WHERE id = ? AND creator_id = ? AND status = 'open'", [$cid, $uid])->fetch();
        if (!$ch) {
            throw new GameError('The player has no open contest with that id.');
        }
        $entry = (int) $ch['stake'];
        $title = "Cancel your $entry-coin contest";
        $lines = [['Refund', '+' . $entry]];
    } else {
        throw new GameError('Unknown contest action.');
    }
    $id = agent_park($uid, ['action' => $action, 'entry' => $entry, 'contest_id' => $cid]);
    return [
        ['status' => 'awaiting_player_confirmation', 'summary' => $title],
        ['type' => 'confirm', 'proposal_id' => $id, 'title' => $title, 'lines' => $lines],
    ];
}

function payoutFor(int $entry): array
{
    $pot = 2 * $entry;
    $fee = intdiv($pot * MATCH_FEE_PCT, 100);
    return ['pot' => $pot, 'fee' => $fee, 'win' => $pot - $fee];
}

/** Executes a proposal the player confirmed in the UI. */
function agent_confirm(int $uid, string $id): array
{
    $p = $_SESSION['agent_proposals'][$id] ?? null;
    unset($_SESSION['agent_proposals'][$id]);
    if (!$p || $p['expires'] < time() || ($p['uid'] ?? $uid) !== $uid) {
        throw new GameError('This confirmation expired. Ask Tato again.');
    }
    agent_log($uid, 'confirm', $p['action'], $p);
    switch ($p['action']) {
        case 'create':
            mandate_cover($uid, (int) $p['entry']);
            $c = challenge_create($uid, (string) $p['entry']);
            return ['go' => 'wait', 'challenge' => $c, 'message' => 'Contest posted. Waiting for an opponent.'];
        case 'accept':
            mandate_cover($uid, (int) $p['entry']);
            return ['go' => 'game', 'match_id' => challenge_accept($uid, (int) $p['contest_id']), 'message' => 'Contest accepted. Game on!'];
        case 'mandate':
            mandate_create($uid, (int) $p['pack'], (int) $p['below'], (int) $p['cap'], (int) $p['days']);
            return ['go' => 'wallet', 'message' => 'Autopilot is on. You can pause or revoke it anytime.'];
        case 'resume':
            mandate_set_paused($uid, false);
            return ['go' => 'wallet', 'message' => 'Autopilot resumed.'];
        case 'cancel':
            challenge_cancel($uid, (int) $p['contest_id']);
            return ['go' => 'arena', 'message' => $p['entry'] . ' coins refunded.'];
    }
    throw new GameError('Unknown action.');
}

/* ------------------------------------------------------------- loop */

function agent_chat(int $uid, string $text): array
{
    $history = $_SESSION['agent_history'] ?? [];
    $messages = $history;
    $messages[] = ['role' => 'user', 'content' => $text];
    $cards = [];
    $reply = '';
    $client = agent_client();

    for ($step = 0; $step < AGENT_MAX_STEPS; $step++) {
        $resp = $client->beta->messages->create(
            model: AGENT_MODEL,
            maxTokens: 4096,
            system: [['type' => 'text', 'text' => agent_system_prompt(), 'cacheControl' => ['type' => 'ephemeral']]],
            tools: agent_tools(),
            messages: $messages,
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
        if ($resp->stopReason === 'refusal') {
            $reply = "Sorry, I can't help with that. I can check your wallet, buy coins, set limits or set up contests.";
            break;
        }
        $results = [];
        foreach ($resp->content as $block) {
            if ($block->type === 'text') {
                $reply .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $in = is_array($block->input) ? $block->input : (array) json_decode(json_encode($block->input), true);
                try {
                    [$out, $card] = agent_tool($uid, $block->name, $in);
                    if ($card) {
                        $cards[] = $card;
                    }
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode($out, JSON_UNESCAPED_SLASHES)];
                    agent_log($uid, 'tool', $block->name, ['input' => $in, 'ok' => true]);
                } catch (GameError $e) {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $e->getMessage(), 'isError' => true];
                    agent_log($uid, 'tool', $block->name, ['input' => $in, 'error' => $e->getMessage()]);
                }
            }
        }
        if ($resp->stopReason !== 'tool_use') {
            break;
        }
        $messages[] = ['role' => 'assistant', 'content' => $resp->content];
        $messages[] = ['role' => 'user', 'content' => $results];
        $reply = '';
    }
    $reply = trim($reply) !== '' ? trim($reply) : 'Done. Use the buttons below.';

    // Only plain text goes into the saved history, so no SDK objects sit in the session.
    $history[] = ['role' => 'user', 'content' => $text];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    // The API released the session lock before this slow loop; store the results now.
    session_store([
        'agent_history'   => array_slice($history, -2 * AGENT_HISTORY_TURNS),
        'agent_proposals' => $_SESSION['agent_proposals'] ?? null,
    ]);
    agent_log($uid, 'message', '', ['user' => $text, 'reply' => $reply]);
    return ['reply' => $reply, 'cards' => $cards];
}
