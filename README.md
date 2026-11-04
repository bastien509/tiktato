# TikTato: 1v1 tic-tac-toe skill contests with PayPal and an AI wallet agent

TikTato is a mobile-first web game where two players pay the same coin entry fee and play tic-tac-toe live, and the winner takes the prize pool. Players buy coins with **PayPal** (Orders v2, server-side), and **Tato**, an AI wallet agent built on **Claude**, helps them buy coins, set spending limits and start contests. Tato can prepare payments and contests, but only the player can approve them.

**Live demo:** https://petiht.com/tiktato/ (PayPal **sandbox**, so no real money moves)

> Built for the PayPal AI Hackathon (Oct–Nov 2026). Coins have no cash value and cannot be withdrawn in this demo.

---

## For judges: test it in 5 minutes

| Account | Password | Coins |
|---|---|---|
| `judge_red` | `TikTato-Judge-2026` | 1,900 (pre-funded) |
| `judge_blue` | `TikTato-Judge-2026` | 1,900 (pre-funded) |

1. **Talk to the AI agent.** Log in as `judge_red` and tap **Ask Tato**. Try:
   - "What's my balance?"
   - "Set a $15 weekly limit", then "Buy the $20 pack". Tato refuses because the server enforces the limit.
   - "Buy $10 of coins". Tato opens a real PayPal sandbox checkout. Pay with any **PayPal sandbox personal account** (create one at developer.paypal.com → Testing Tools → Sandbox Accounts). 950 coins arrive (5% fee).
   - "Post a 50-coin contest". Tato shows a **Confirm** card; nothing happens until you press it.
2. **Play a contest.** Open a second browser or a private window and log in as `judge_blue`. Accept the open contest (a notification pops up). Play the game; the winner sees the "We have a winner" screen and a coin animation.
3. **Practice vs AI.** Tap **PLAY → vs AI**. Easy makes mistakes; Hard uses minimax and never loses.

---

## What it does

- **Accounts:** own register, login and logout, `password_hash`, CSRF on every POST, and session regeneration on login.
- **Coins via PayPal:** 100 coins = $1. Packs are $5, $10, $20, $50 and $100. TikTato keeps 5% (a $10 pack credits 950 coins).
- **1v1 skill contests:** a player posts an entry fee (10–10,000 coins). Other players see it live and one accepts. Both entries go into escrow. The winner gets the pool minus a 20% platform fee (a 100-coin entry wins 160). The loser loses their entry. **A draw refunds both entries.**
- **Fair play:** every move is checked on the server, there is a 30-second move clock (a player whose time runs out forfeits), resigning is allowed, and unmatched contests refund after 10 minutes or on cancel.
- **Responsible play:** weekly PayPal limits, enforced on every top-up. Lowering a limit is instant; raising it takes a 24-hour cool-down.
- **Game feel:** animated board, a "money received" coin animation, a "We have a winner" celebration with the winner's avatar, live notifications, and a mobile-first layout.

## How PayPal is used

All PayPal calls happen on the server (`inc/paypal.php`). The browser never sees the client secret, and the amount always comes from the server's pack list.

1. `POST /v1/oauth2/token` obtains an access token with the client credentials.
2. `POST /v2/checkout/orders` creates an order with `intent: CAPTURE`, an amount set by the server, a `reference_id`/`custom_id` linking it to the local order, and `experience_context` return URLs. A `PayPal-Request-Id` header makes the call idempotent.
3. The buyer approves on PayPal and is sent back to `paypal-return.php`.
4. `POST /v2/checkout/orders/{id}/capture` (also with `PayPal-Request-Id`) captures the payment. The server then checks the capture **status = COMPLETED**, the **amount and currency**, and the **reference id**, and only then credits coins. This happens once per order: the order row is locked in a database transaction, and a unique key on each ledger entry blocks double credits. `ORDER_ALREADY_CAPTURED` is handled by reading the order back.
5. Optional webhook (`paypal-webhook.php`): the signature is checked with `/v1/notifications/verify-webhook-signature`. It handles `CHECKOUT.ORDER.APPROVED` (capture) and `PAYMENT.CAPTURE.COMPLETED` (credit), so buyers who close the tab still get their coins.

## How AI is used

**Tato** (`inc/agent.php`) is a tool-using agent on **Claude Opus 5.5** (`claude-opus-5-5`), called through the official **Anthropic PHP SDK**. Each chat message runs a short agent loop (up to 6 steps). Claude chooses tools, the server runs them for the logged-in player only, and the results go back to Claude.

| Tool | What it does | Safety |
|---|---|---|
| `get_wallet` | Balance, record, limits, open contest, recent ledger | Read-only |
| `list_coin_packs` | Packs with the exact coins and fee | Read-only |
| `start_paypal_checkout` | Creates a real PayPal order and shows a **Pay with PayPal** card | Nothing is charged until the player approves on PayPal; the weekly limit is enforced on the server |
| `set_weekly_limit` | Sets the weekly top-up limit | Lowering is instant; raising waits 24 hours, so the AI can't be used to remove a limit on impulse |
| `list_open_contests` | Live contests with the prize if won | Read-only |
| `propose_contest_action` | Prepares create, accept or cancel | Shows a **Confirm** card; it only runs when the player presses it (one use, expires after 5 minutes) |
| `get_recent_matches` | Results and net coins | Read-only |

Design choices:
- **The player stays in control.** The model can't move coins. Every action that spends money needs the player to approve it on PayPal or press Confirm.
- **Server-side checks.** Every tool re-checks auth, ownership, balance and limits. The model only sees the player's own data.
- **Audit trail.** Every message, tool call and confirmation is written to `agent_log`.
- **Strict tool schemas** (`strict: true`), `effort: low` for fast chat replies, server-side refusal fallbacks, and rate limits (15 messages per 5 minutes, 150 per day).
- **Prompt-injection resistance.** Asking Tato for "free coins" fails because no tool can create coins.

The built-in practice opponent is a classic game AI: random play on Easy, win/block heuristics on Medium, and full minimax on Hard.

## Money safety

- Coins move only on the server. Stakes are debited atomically with `UPDATE users SET coins = coins - ? WHERE id = ? AND coins >= ?` inside a transaction.
- **Double-entry ledger** (`wallet_ledger`) with three accounts: `user`, `escrow` and `platform`. Every row has a unique `idem_key`, so each credit or debit happens at most once. The invariant `SUM(ledger) = coins bought via PayPal` holds, and `admin.php` checks it live.
- Payouts are idempotent: settling a match locks it and finishes it only once (`WHERE status = 'active'`).
- Lock ordering (users → challenges → matches) and a deadlock retry keep concurrent accepts and moves safe.
- Security headers include a strict CSP (`script-src 'self'`, no inline JS), `no-store` caching for HTML and JSON, and a session cookie scoped to the app path. Config, schema and `vendor/` are blocked from the web.

## Fee math

| Event | Player | Platform |
|---|---|---|
| $10 PayPal top-up (1,000 coins) | +950 | +50 (5%) |
| Win with a 100-coin entry (pool 200) | +160 (net +60) | +40 (20% of the pool) |
| Loss with a 100-coin entry | −100 | – |
| Draw | both entries refunded | 0 |

## Tech stack

- PHP 8.2 (no framework) and MySQL/MariaDB, running on shared hosting
- Real time through short polling (1–2 s), since the host has no websockets
- PayPal REST APIs: OAuth2, Orders v2, Webhooks
- Anthropic Claude (`claude-opus-5-5`) with tool use, via `anthropic-ai/sdk` (Composer) and Guzzle
- Vanilla JS and CSS: animations use the Web Animations API, confetti is drawn on a canvas, and there is no build step

## Run it yourself

Requirements: PHP 8.1+ with `pdo_mysql`, `curl` and `zip`, MySQL 5.7+ or MariaDB 10.4+, and Composer.

```bash
git clone https://github.com/bastien509/tiktato.git
cd tiktato
composer install
cp inc/config.sample.php inc/config.php
```

Edit `inc/config.php`:
- `db.*`: an empty MySQL database. The tables are created on the first request (or run `schema.sql` yourself).
- `paypal.client_id` / `paypal.secret`: from developer.paypal.com → Apps & Credentials (Sandbox).
- `ai.anthropic_api_key`: from console.anthropic.com.
- `app.base_url`: the public URL of the site (PayPal return URLs use it).

Then serve the folder:

```bash
php -S 127.0.0.1:8099
```

On Apache or LiteSpeed, the included `.htaccess` files block `inc/`, `vendor/`, `schema.sql` and the Composer files. With `php -S`, keep the server on localhost.

To see the admin ledger checks at `/admin.php`, run: `UPDATE users SET is_admin = 1 WHERE username = 'you';`

## Project layout

```
index.php            game app (arena, contests, game, wallet, profile, Tato chat)
login.php / register.php / logout.php
api.php              JSON API (polls + actions + agent)
paypal-return.php    PayPal capture after approval
paypal-webhook.php   verified PayPal webhooks
admin.php            platform revenue + ledger balance checks
inc/bootstrap.php    config, PDO, schema install, session, CSRF, rate limits, headers
inc/game.php         contests, escrow, moves, win detection, settlement
inc/paypal.php       PayPal Orders v2 + spending limits
inc/agent.php        Tato: Claude tool-use agent
schema.sql           MySQL schema
assets/              app.js, app.css, auth.js, logo.svg
```

## Responsible use

This is a hackathon demo running on the PayPal sandbox. Real-money skill contests are regulated differently in each country, and PayPal's Acceptable Use Policy requires pre-approval for them. Do not take real money without that approval and the licences your country requires. The 18+ check, weekly limits and cool-downs show how the product would handle this.

## License

[MIT](LICENSE)
