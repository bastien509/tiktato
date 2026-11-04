<?php
/**
 * Coins, escrow and match rules. Everything here runs on the server;
 * the client only sends intents (stake, accept, cell).
 *
 * Lock order inside transactions: users -> challenges -> matches.
 * Settlement locks a match, then updates users rows (single direction).
 */
declare(strict_types=1);

defined('TTT') || exit;

const STAKE_MIN = 10;
const STAKE_MAX = 10000;
const STAKE_STEP = 5;
const MATCH_FEE_PCT = 20;          // platform share of the pot on a win
const TOPUP_FEE_PCT = 5;           // platform share of every PayPal top-up
const COINS_PER_USD = 100;
const CHALLENGE_TTL_SEC = 600;     // unmatched challenges refund after 10 min
const TURN_SEC = 30;               // a player who lets the clock run out forfeits
const FIRST_TURN_SEC = 45;         // extra time while both players load the board

const WIN_LINES = [[0, 1, 2], [3, 4, 5], [6, 7, 8], [0, 3, 6], [1, 4, 7], [2, 5, 8], [0, 4, 8], [2, 4, 6]];

class GameError extends RuntimeException
{
}

/* ------------------------------------------------------------- ledger */

/** Inserts a ledger row; duplicate idem_key means it already happened. */
function ledger(PDO $pdo, string $account, ?int $userId, string $type, int $amount, string $idem, array $refs = []): void
{
    $balance = null;
    if ($account === 'user') {
        $st = $pdo->prepare('SELECT coins FROM users WHERE id = ?');
        $st->execute([$userId]);
        $balance = (int) $st->fetchColumn();
    }
    $st = $pdo->prepare(
        'INSERT INTO wallet_ledger (account, user_id, type, amount, balance_after, challenge_id, match_id, paypal_order_id, idem_key, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $account, $userId, $type, $amount, $balance,
        $refs['challenge_id'] ?? null, $refs['match_id'] ?? null, $refs['paypal_order_id'] ?? null,
        $idem, $refs['note'] ?? null,
    ]);
}

/** Atomic debit: fails instead of going below zero. */
function debit_coins(PDO $pdo, int $userId, int $amount): bool
{
    $st = $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ? AND coins >= ?');
    $st->execute([$amount, $userId, $amount]);
    return $st->rowCount() === 1;
}

function credit_coins(PDO $pdo, int $userId, int $amount): void
{
    $st = $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?');
    $st->execute([$amount, $userId]);
}

/* --------------------------------------------------------- challenges */

function valid_stake($raw): int
{
    if (!preg_match('/^\d{1,6}$/', (string) $raw)) {
        throw new GameError('Choose a valid entry fee.');
    }
    $stake = (int) $raw;
    if ($stake < STAKE_MIN || $stake > STAKE_MAX || $stake % STAKE_STEP !== 0) {
        throw new GameError('Entries go from ' . STAKE_MIN . ' to ' . STAKE_MAX . ' coins, in steps of ' . STAKE_STEP . '.');
    }
    return $stake;
}

function active_match_id(int $userId): ?int
{
    $id = q(
        "SELECT id FROM matches WHERE status = 'active' AND (player_x_id = ? OR player_o_id = ?) ORDER BY id DESC LIMIT 1",
        [$userId, $userId]
    )->fetchColumn();
    return $id ? (int) $id : null;
}

function challenge_create(int $userId, $rawStake): array
{
    $stake = valid_stake($rawStake);
    return tx(function (PDO $pdo) use ($userId, $stake) {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        if (active_match_id($userId)) {
            throw new GameError('Finish your current match first.');
        }
        $open = q("SELECT id FROM challenges WHERE creator_id = ? AND status = 'open' LIMIT 1", [$userId])->fetchColumn();
        if ($open) {
            throw new GameError('You already have an open contest.');
        }
        if (!debit_coins($pdo, $userId, $stake)) {
            throw new GameError('Not enough coins. Top up your wallet first.');
        }
        $pdo->prepare('INSERT INTO challenges (creator_id, stake, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . CHALLENGE_TTL_SEC . ' SECOND)')
            ->execute([$userId, $stake]);
        $cid = (int) $pdo->lastInsertId();
        ledger($pdo, 'user', $userId, 'bet_stake', -$stake, "c:$cid:stake:creator", ['challenge_id' => $cid]);
        ledger($pdo, 'escrow', null, 'bet_stake', $stake, "c:$cid:escrow:creator", ['challenge_id' => $cid]);
        return ['id' => $cid, 'stake' => $stake];
    });
}

/** Closes an open challenge and refunds the creator. Caller holds a transaction. */
function challenge_close_refund(PDO $pdo, array $ch, string $status): bool
{
    $st = $pdo->prepare("UPDATE challenges SET status = ?, closed_at = NOW() WHERE id = ? AND status = 'open'");
    $st->execute([$status, $ch['id']]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    $cid = (int) $ch['id'];
    $stake = (int) $ch['stake'];
    credit_coins($pdo, (int) $ch['creator_id'], $stake);
    ledger($pdo, 'escrow', null, 'refund', -$stake, "c:$cid:refund:escrow", ['challenge_id' => $cid]);
    ledger($pdo, 'user', (int) $ch['creator_id'], 'refund', $stake, "c:$cid:refund:creator", ['challenge_id' => $cid, 'note' => $status]);
    return true;
}

function challenge_cancel(int $userId, int $cid): void
{
    tx(function (PDO $pdo) use ($userId, $cid) {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $st = $pdo->prepare('SELECT * FROM challenges WHERE id = ? FOR UPDATE');
        $st->execute([$cid]);
        $ch = $st->fetch();
        if (!$ch || (int) $ch['creator_id'] !== $userId) {
            throw new GameError('Contest not found.');
        }
        if ($ch['status'] !== 'open') {
            throw new GameError('This contest is no longer open.');
        }
        challenge_close_refund($pdo, $ch, 'cancelled');
    });
}

function challenge_accept(int $userId, int $cid): int
{
    return tx(function (PDO $pdo) use ($userId, $cid) {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        if (active_match_id($userId)) {
            throw new GameError('Finish your current match first.');
        }
        $st = $pdo->prepare('SELECT *, expires_at < NOW() AS expired FROM challenges WHERE id = ? FOR UPDATE');
        $st->execute([$cid]);
        $ch = $st->fetch();
        if (!$ch || $ch['status'] !== 'open' || (int) $ch['expired']) {
            throw new GameError('Too late, this contest is gone.');
        }
        if ((int) $ch['creator_id'] === $userId) {
            throw new GameError('You cannot accept your own contest.');
        }
        $stake = (int) $ch['stake'];
        if (!debit_coins($pdo, $userId, $stake)) {
            throw new GameError('Not enough coins for this entry fee.');
        }
        // Your own waiting challenge is withdrawn when you join someone else's.
        $own = $pdo->prepare("SELECT * FROM challenges WHERE creator_id = ? AND status = 'open' FOR UPDATE");
        $own->execute([$userId]);
        foreach ($own->fetchAll() as $mine) {
            challenge_close_refund($pdo, $mine, 'cancelled');
        }

        $creator = (int) $ch['creator_id'];
        [$x, $o] = random_int(0, 1) ? [$creator, $userId] : [$userId, $creator];
        $pdo->prepare(
            'INSERT INTO matches (challenge_id, player_x_id, player_o_id, stake, pot, turn_deadline)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ' . FIRST_TURN_SEC . ' SECOND)'
        )->execute([$cid, $x, $o, $stake, $stake * 2]);
        $mid = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE challenges SET status = 'matched', accepter_id = ?, match_id = ?, closed_at = NOW() WHERE id = ?")
            ->execute([$userId, $mid, $cid]);
        ledger($pdo, 'user', $userId, 'bet_stake', -$stake, "c:$cid:stake:accepter", ['challenge_id' => $cid, 'match_id' => $mid]);
        ledger($pdo, 'escrow', null, 'bet_stake', $stake, "c:$cid:escrow:accepter", ['challenge_id' => $cid, 'match_id' => $mid]);

        $name = (string) q('SELECT username FROM users WHERE id = ?', [$userId])->fetchColumn();
        notify($creator, 'challenge_accepted', 'Contest accepted!', "$name accepted your $stake-coin contest. Game on!", $mid);
        return $mid;
    });
}

/* ------------------------------------------------------------ matches */

function board_winner(string $board): ?array
{
    foreach (WIN_LINES as $line) {
        [$a, $b, $c] = $line;
        if ($board[$a] !== '.' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
            return ['symbol' => $board[$a], 'line' => $line];
        }
    }
    return null;
}

function lock_match(PDO $pdo, int $mid): ?array
{
    $st = $pdo->prepare('SELECT *, turn_deadline < NOW() AS overdue FROM matches WHERE id = ? FOR UPDATE');
    $st->execute([$mid]);
    return $st->fetch() ?: null;
}

/**
 * Ends a locked match and moves the escrow exactly once.
 * $winnerId null = draw (both stakes refunded, no fee).
 */
function settle_match(PDO $pdo, array $m, string $result, ?int $winnerId, ?array $line = null): void
{
    $mid = (int) $m['id'];
    $pot = (int) $m['pot'];
    $stake = (int) $m['stake'];
    $fee = $winnerId ? intdiv($pot * MATCH_FEE_PCT, 100) : 0;
    $payout = $winnerId ? $pot - $fee : 0;

    $st = $pdo->prepare(
        "UPDATE matches SET status = 'finished', result = ?, winner_id = ?, win_line = ?, platform_fee = ?,
                winner_payout = ?, finished_at = NOW(), version = version + 1
         WHERE id = ? AND status = 'active'"
    );
    $st->execute([$result, $winnerId, $line ? implode(',', $line) : null, $fee, $payout, $mid]);
    if ($st->rowCount() !== 1) {
        return; // already settled
    }

    $x = (int) $m['player_x_id'];
    $o = (int) $m['player_o_id'];
    $refs = ['match_id' => $mid, 'challenge_id' => $m['challenge_id'] ? (int) $m['challenge_id'] : null];

    if ($winnerId) {
        $loserId = $winnerId === $x ? $o : $x;
        credit_coins($pdo, $winnerId, $payout);
        ledger($pdo, 'escrow', null, 'payout', -$pot, "m:$mid:escrow:release", $refs);
        ledger($pdo, 'user', $winnerId, 'payout', $payout, "m:$mid:payout", $refs + ['note' => $result]);
        ledger($pdo, 'platform', null, 'platform_fee', $fee, "m:$mid:fee", $refs);
        $pdo->prepare('UPDATE users SET wins = wins + 1 WHERE id = ?')->execute([$winnerId]);
        $pdo->prepare('UPDATE users SET losses = losses + 1 WHERE id = ?')->execute([$loserId]);
        $why = ['timeout' => ' (opponent ran out of time)', 'resign' => ' (opponent resigned)'][$result] ?? '';
        notify($winnerId, 'match_won', 'You won ' . $payout . ' coins!', 'Prize pool ' . $pot . ' − ' . MATCH_FEE_PCT . '% fee' . $why, $mid);
        notify($loserId, 'match_lost', 'You lost ' . $stake . ' coins', $result === 'timeout' ? 'Your turn timed out.' : 'Better luck next round.', $mid);
    } else {
        foreach (['x' => $x, 'o' => $o] as $side => $uid) {
            credit_coins($pdo, $uid, $stake);
            ledger($pdo, 'escrow', null, 'refund', -$stake, "m:$mid:draw:escrow:$side", $refs);
            ledger($pdo, 'user', $uid, 'refund', $stake, "m:$mid:draw:$side", $refs + ['note' => 'draw']);
            $pdo->prepare('UPDATE users SET draws = draws + 1 WHERE id = ?')->execute([$uid]);
            notify($uid, 'match_draw', "Draw! $stake coins refunded", 'Nobody wins a draw, so both stakes go back.', $mid);
        }
    }
}

function settle_if_overdue(int $mid): void
{
    $due = q("SELECT 1 FROM matches WHERE id = ? AND status = 'active' AND turn_deadline < NOW()", [$mid])->fetchColumn();
    if (!$due) {
        return;
    }
    tx(function (PDO $pdo) use ($mid) {
        $m = lock_match($pdo, $mid);
        if ($m && $m['status'] === 'active' && (int) $m['overdue']) {
            $loser = $m['turn'] === 'X' ? (int) $m['player_x_id'] : (int) $m['player_o_id'];
            $winner = $loser === (int) $m['player_x_id'] ? (int) $m['player_o_id'] : (int) $m['player_x_id'];
            settle_match($pdo, $m, 'timeout', $winner);
        }
    });
}

/** Lazy housekeeping run from polls: expire challenges and forfeit abandoned matches. */
function sweep(): void
{
    $chs = q("SELECT id FROM challenges WHERE status = 'open' AND expires_at < NOW() LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($chs as $cid) {
        tx(function (PDO $pdo) use ($cid) {
            $st = $pdo->prepare('SELECT * FROM challenges WHERE id = ? FOR UPDATE');
            $st->execute([$cid]);
            $ch = $st->fetch();
            if ($ch && $ch['status'] === 'open' && challenge_close_refund($pdo, $ch, 'expired')) {
                notify((int) $ch['creator_id'], 'challenge_expired', 'No opponent this time', $ch['stake'] . ' coins were refunded to your wallet.');
            }
        });
    }
    $mids = q("SELECT id FROM matches WHERE status = 'active' AND turn_deadline < NOW() LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($mids as $mid) {
        settle_if_overdue((int) $mid);
    }
}

function match_move(int $userId, int $mid, $rawCell): void
{
    if (!preg_match('/^[0-8]$/', (string) $rawCell)) {
        throw new GameError('Invalid cell.');
    }
    $cell = (int) $rawCell;
    tx(function (PDO $pdo) use ($userId, $mid, $cell) {
        $m = lock_match($pdo, $mid);
        if (!$m || ((int) $m['player_x_id'] !== $userId && (int) $m['player_o_id'] !== $userId)) {
            throw new GameError('Match not found.');
        }
        if ($m['status'] !== 'active') {
            throw new GameError('This match is over.');
        }
        if ((int) $m['overdue']) {
            $loser = $m['turn'] === 'X' ? (int) $m['player_x_id'] : (int) $m['player_o_id'];
            settle_match($pdo, $m, 'timeout', $loser === (int) $m['player_x_id'] ? (int) $m['player_o_id'] : (int) $m['player_x_id']);
            return;
        }
        $mine = (int) $m['player_x_id'] === $userId ? 'X' : 'O';
        if ($m['turn'] !== $mine) {
            throw new GameError('Wait for your turn.');
        }
        $board = (string) $m['board'];
        if ($board[$cell] !== '.') {
            throw new GameError('That square is taken.');
        }
        $board[$cell] = $mine;
        $ply = (int) $m['move_count'] + 1;
        $pdo->prepare('INSERT INTO moves (match_id, user_id, ply, cell, symbol) VALUES (?, ?, ?, ?, ?)')
            ->execute([$mid, $userId, $ply, $cell, $mine]);
        $pdo->prepare(
            'UPDATE matches SET board = ?, move_count = ?, turn = ?, turn_deadline = NOW() + INTERVAL ' . TURN_SEC . ' SECOND,
                    version = version + 1 WHERE id = ?'
        )->execute([$board, $ply, $mine === 'X' ? 'O' : 'X', $mid]);

        $m['board'] = $board;
        if ($win = board_winner($board)) {
            settle_match($pdo, $m, 'win', $userId, $win['line']);
        } elseif (strpos($board, '.') === false) {
            settle_match($pdo, $m, 'draw', null);
        }
    });
}

function match_resign(int $userId, int $mid): void
{
    tx(function (PDO $pdo) use ($userId, $mid) {
        $m = lock_match($pdo, $mid);
        if (!$m || ((int) $m['player_x_id'] !== $userId && (int) $m['player_o_id'] !== $userId)) {
            throw new GameError('Match not found.');
        }
        if ($m['status'] !== 'active') {
            throw new GameError('This match is over.');
        }
        $winner = (int) $m['player_x_id'] === $userId ? (int) $m['player_o_id'] : (int) $m['player_x_id'];
        settle_match($pdo, $m, 'resign', $winner);
    });
}

function match_view(int $userId, int $mid): array
{
    settle_if_overdue($mid);
    $m = q('SELECT *, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), turn_deadline)) AS secs_left FROM matches WHERE id = ?', [$mid])->fetch();
    if (!$m || ((int) $m['player_x_id'] !== $userId && (int) $m['player_o_id'] !== $userId)) {
        throw new GameError('Match not found.');
    }
    $users = q('SELECT * FROM users WHERE id IN (?, ?)', [$m['player_x_id'], $m['player_o_id']])->fetchAll();
    $byId = [];
    foreach ($users as $u) {
        $byId[(int) $u['id']] = public_profile($u);
    }
    $you = (int) $m['player_x_id'] === $userId ? 'X' : 'O';
    $winnerId = $m['winner_id'] !== null ? (int) $m['winner_id'] : null;
    $stake = (int) $m['stake'];
    $delta = 0; // net coin change for the viewer once the match is over
    if ($m['status'] === 'finished' && $winnerId) {
        $delta = $winnerId === $userId ? (int) $m['winner_payout'] - $stake : -$stake;
    }
    return [
        'id'          => (int) $m['id'],
        'status'      => $m['status'],
        'board'       => $m['board'],
        'turn'        => $m['turn'],
        'you'         => $you,
        'players'     => ['X' => $byId[(int) $m['player_x_id']] ?? null, 'O' => $byId[(int) $m['player_o_id']] ?? null],
        'stake'       => $stake,
        'pot'         => (int) $m['pot'],
        'fee_pct'     => MATCH_FEE_PCT,
        'secs_left'   => (int) $m['secs_left'],
        'turn_sec'    => TURN_SEC,
        'result'      => $m['result'],
        'winner'      => $winnerId ? ((int) $m['player_x_id'] === $winnerId ? 'X' : 'O') : null,
        'win_line'    => $m['win_line'] ? array_map('intval', explode(',', $m['win_line'])) : null,
        'payout'      => (int) $m['winner_payout'],
        'fee'         => (int) $m['platform_fee'],
        'net'         => $delta,
        'version'     => (int) $m['version'],
    ];
}
