<?php
/**
 * TikTato bootstrap: config, PDO, schema install, session, CSRF, auth,
 * rate limiting, security headers and small helpers.
 */
declare(strict_types=1);

defined('TTT') || define('TTT', 1);

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'bootstrap.php') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

$GLOBALS['TTT_CFG'] = (static function (): array {
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        http_response_code(503);
        exit('TikTato is not configured yet.');
    }
    return require $file;
})();

const TTT_AVATARS = ['🦁', '🐯', '🦊', '🐼', '🐸', '🐵', '🦅', '🐬', '🦖', '🐺', '🐨', '🐧'];
const TTT_COLORS  = ['#F5C518', '#D21034', '#1E6BFF', '#14B8A6', '#22C55E', '#FF7A1A'];
const TTT_ASSET_VER = '2';

function cfg(string $path, $default = null)
{
    $node = $GLOBALS['TTT_CFG'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

/* ---------------------------------------------------------------- DB */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        cfg('db.host'),
        (int) cfg('db.port', 3306),
        cfg('db.name')
    );
    try {
        $pdo = new PDO($dsn, (string) cfg('db.user'), (string) cfg('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('TikTato DB connect failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Service temporarily unavailable.');
    }
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    install_schema($pdo);
    return $pdo;
}

/** Creates the tables once (idempotent); a lock file skips the work afterwards. */
function install_schema(PDO $pdo): void
{
    $lock = __DIR__ . '/schema-v2.lock';
    if (is_file($lock)) {
        return;
    }
    $sql = (string) file_get_contents(dirname(__DIR__) . '/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
    // v2: spending-limit columns on databases created before they existed.
    $have = array_map('strtolower', $pdo->query(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users'"
    )->fetchAll(PDO::FETCH_COLUMN));
    foreach (['weekly_limit_usd' => 'INT UNSIGNED NULL', 'limit_pending_usd' => 'INT UNSIGNED NULL', 'limit_pending_at' => 'DATETIME NULL'] as $col => $type) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $type AFTER draws");
        }
    }
    @file_put_contents($lock, gmdate('c'));
}

/** Runs $fn inside a transaction; retries once on deadlock. */
function tx(callable $fn)
{
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $deadlock = $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
            if ($deadlock && $attempt < 3) {
                usleep(50000 * $attempt);
                continue;
            }
            throw $e;
        }
    }
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ------------------------------------------------------------ request */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

/** URL path of the site folder, e.g. "/tiktato" (empty at the web root). */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    return rtrim((string) cfg('app.base_url'), '/') . '/' . ltrim($path, '/');
}

function asset(string $file): string
{
    return url('assets/' . $file) . '?v=' . TTT_ASSET_VER;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
        . "script-src 'self'; connect-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
    );
    // HTML and JSON are per-user: never let the CDN or browser cache them.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
}

/* ------------------------------------------------------------ session */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('TIKTATOSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (base_path() === '' ? '' : base_path()) . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function flash(string $msg, string $kind = 'info'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'kind' => $kind];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* --------------------------------------------------------------- auth */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid > 0) {
        $u = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
        if ($u && !(int) $u['is_banned']) {
            $cache = $u;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $cache;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
}

function require_login_page(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function public_profile(array $u): array
{
    $a = (int) $u['avatar'];
    $c = (int) $u['avatar_color'];
    return [
        'id'       => (int) $u['id'],
        'username' => (string) $u['username'],
        'avatar'   => TTT_AVATARS[$a] ?? TTT_AVATARS[0],
        'color'    => isset(TTT_COLORS[$c]) ? $c : 0,
        'wins'     => (int) ($u['wins'] ?? 0),
    ];
}

/* --------------------------------------------------------- rate limit */

/** Fixed-window counter. Returns false once $max hits are used in the window. */
function rate_limit(string $bucket, int $max, int $windowSec): bool
{
    $window = intdiv(time(), $windowSec) * $windowSec;
    $key = sha1($bucket);
    q(
        'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1',
        [$key, $window]
    );
    $hits = (int) q('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$key, $window])->fetchColumn();
    if (random_int(1, 500) === 1) {
        q('DELETE FROM rate_limits WHERE window_start < ?', [time() - 7200]);
    }
    return $hits <= $max;
}

/* ------------------------------------------------------------------ */

function notify(int $userId, string $type, string $title, string $body = '', ?int $matchId = null): void
{
    q(
        'INSERT INTO notifications (user_id, type, title, body, match_id) VALUES (?, ?, ?, ?, ?)',
        [$userId, $type, mb_substr($title, 0, 120), mb_substr($body, 0, 255), $matchId]
    );
}

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    if (!defined('TTT_NO_SESSION')) {
        start_session();
    }
}
