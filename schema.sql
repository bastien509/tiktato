-- TikTato (petiht.com/tiktato) — MySQL / MariaDB schema.
-- Database: u403534587_paypalakaton. Safe to re-run (IF NOT EXISTS).
-- Coins: 100 coins = $1. All amounts below are whole coins.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  avatar TINYINT UNSIGNED NOT NULL DEFAULT 0,
  avatar_color TINYINT UNSIGNED NOT NULL DEFAULT 0,
  coins BIGINT NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  losses INT UNSIGNED NOT NULL DEFAULT 0,
  draws INT UNSIGNED NOT NULL DEFAULT 0,
  weekly_limit_usd INT UNSIGNED NULL,
  limit_pending_usd INT UNSIGNED NULL,
  limit_pending_at DATETIME NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenges (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id INT UNSIGNED NOT NULL,
  stake INT UNSIGNED NOT NULL,
  status ENUM('open','matched','cancelled','expired') NOT NULL DEFAULT 'open',
  accepter_id INT UNSIGNED NULL,
  match_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ch_status (status, expires_at),
  KEY idx_ch_creator (creator_id, status),
  CONSTRAINT fk_ch_creator FOREIGN KEY (creator_id) REFERENCES users (id),
  CONSTRAINT fk_ch_accepter FOREIGN KEY (accepter_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge_id INT UNSIGNED NULL,
  player_x_id INT UNSIGNED NOT NULL,
  player_o_id INT UNSIGNED NOT NULL,
  stake INT UNSIGNED NOT NULL,
  pot INT UNSIGNED NOT NULL,
  board CHAR(9) NOT NULL DEFAULT '.........',
  turn ENUM('X','O') NOT NULL DEFAULT 'X',
  move_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('active','finished') NOT NULL DEFAULT 'active',
  result ENUM('win','draw','timeout','resign') NULL,
  winner_id INT UNSIGNED NULL,
  win_line VARCHAR(8) NULL,
  platform_fee INT UNSIGNED NOT NULL DEFAULT 0,
  winner_payout INT UNSIGNED NOT NULL DEFAULT 0,
  turn_deadline DATETIME NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_matches_challenge (challenge_id),
  KEY idx_m_status (status, turn_deadline),
  KEY idx_m_x (player_x_id, status),
  KEY idx_m_o (player_o_id, status),
  CONSTRAINT fk_m_x FOREIGN KEY (player_x_id) REFERENCES users (id),
  CONSTRAINT fk_m_o FOREIGN KEY (player_o_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  ply TINYINT UNSIGNED NOT NULL,
  cell TINYINT UNSIGNED NOT NULL,
  symbol ENUM('X','O') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_moves_ply (match_id, ply),
  UNIQUE KEY uq_moves_cell (match_id, cell),
  CONSTRAINT fk_mv_match FOREIGN KEY (match_id) REFERENCES matches (id),
  CONSTRAINT fk_mv_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paypal_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  paypal_order_id VARCHAR(64) NULL,
  mode ENUM('sandbox','live') NOT NULL,
  source ENUM('checkout','mandate') NOT NULL DEFAULT 'checkout',
  mandate_id INT UNSIGNED NULL,
  amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  coins_gross INT UNSIGNED NOT NULL,
  coins_credit INT UNSIGNED NOT NULL,
  coins_fee INT UNSIGNED NOT NULL,
  status ENUM('created','pending','completed','failed','cancelled') NOT NULL DEFAULT 'created',
  capture_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pp_order (paypal_order_id),
  UNIQUE KEY uq_pp_capture (capture_id),
  KEY idx_pp_user (user_id, id),
  CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every coin movement. account = whose balance moved:
--   user     -> users.coins of user_id (balance_after is that user's new balance)
--   escrow   -> stakes held for open challenges / active matches
--   platform -> platform revenue (5% top-up fee, 20% match fee)
-- SUM(amount) over all rows = coins bought through PayPal (gross).
-- idem_key makes every credit/debit happen at most once.
CREATE TABLE IF NOT EXISTS wallet_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account ENUM('user','escrow','platform') NOT NULL,
  user_id INT UNSIGNED NULL,
  type ENUM('topup','topup_fee','bet_stake','payout','platform_fee','refund') NOT NULL,
  amount BIGINT NOT NULL,
  balance_after BIGINT NULL,
  challenge_id INT UNSIGNED NULL,
  match_id INT UNSIGNED NULL,
  paypal_order_id INT UNSIGNED NULL,
  idem_key VARCHAR(80) NOT NULL,
  note VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_idem (idem_key),
  KEY idx_ledger_user (user_id, id),
  KEY idx_ledger_match (match_id),
  KEY idx_ledger_acct (account, type),
  CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(255) NOT NULL DEFAULT '',
  match_id INT UNSIGNED NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket CHAR(40) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Audit trail of everything the AI wallet agent (Tato) read, proposed or executed.
CREATE TABLE IF NOT EXISTS agent_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('message','tool','confirm') NOT NULL,
  tool VARCHAR(40) NOT NULL DEFAULT '',
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_user (user_id, id),
  CONSTRAINT fk_agent_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PayPal accounts saved with the player's approval (Vault v3), used only by agent mandates.
CREATE TABLE IF NOT EXISTS paypal_vault (
  user_id INT UNSIGNED NOT NULL,
  mode ENUM('sandbox','live') NOT NULL,
  status ENUM('pending','active','removed') NOT NULL DEFAULT 'pending',
  setup_token_id VARCHAR(64) NULL,
  payment_token_id VARCHAR(64) NULL,
  customer_id VARCHAR(64) NULL,
  payer_email VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_vault_setup (setup_token_id),
  CONSTRAINT fk_vault_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the player allowed Tato to spend on their behalf (Autopilot).
CREATE TABLE IF NOT EXISTS agent_mandates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('active','paused','revoked','expired') NOT NULL DEFAULT 'active',
  pack_usd INT UNSIGNED NOT NULL,
  refill_below INT UNSIGNED NOT NULL,
  weekly_cap_usd INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  consent_text VARCHAR(500) NOT NULL,
  failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mandate_user (user_id, status),
  CONSTRAINT fk_mandate_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
