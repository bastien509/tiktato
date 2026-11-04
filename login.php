<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$ident = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ident = trim((string) ($_POST['ident'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!rate_limit('login-ip:' . client_ip(), 30, 600) || !rate_limit('login-id:' . strtolower($ident), 8, 600)) {
        $error = 'Too many attempts. Wait 10 minutes and try again.';
    } else {
        $u = q('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$ident, strtolower($ident)])->fetch();
        if ($u && password_verify($pass, (string) $u['password_hash']) && !(int) $u['is_banned']) {
            if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            login_user($u);
            redirect('index.php');
        }
        if (!$u) {
            // Same work as a real check so response time doesn't reveal which accounts exist.
            password_verify($pass, password_hash('timing-pad', PASSWORD_DEFAULT));
        }
        $error = 'Wrong username or password.';
    }
}

page_head('Log in · TikTato', 'auth');
echo glyph_field();
?>
<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-brand">
      <?= logo_svg('logo logo-lg') ?>
      <h1 class="brand-title">Tik<span>Tato</span></h1>
      <p class="brand-tag">Three in a row. Real rivals. Real coins.</p>
    </div>
    <?= flash_html() ?>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('login.php')) ?>" class="auth-form">
      <?= csrf_field() ?>
      <label class="field">
        <span class="field-label">Username or email</span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5Z"/></svg>
          <input name="ident" value="<?= e($ident) ?>" autocomplete="username" required maxlength="190" autocapitalize="off" spellcheck="false">
        </span>
      </label>
      <label class="field">
        <span class="field-label">Password</span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 9V7A5 5 0 0 0 7 7v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Zm4 9.7V18h-2v-1.3a2 2 0 1 1 2 0Z"/></svg>
          <input name="password" type="password" autocomplete="current-password" required maxlength="200">
          <button type="button" class="peek" data-peek aria-label="Show password">Show</button>
        </span>
      </label>
      <button class="btn btn-cta btn-block" type="submit">Log in &amp; play</button>
    </form>
    <p class="auth-switch">New here? <a href="<?= e(url('register.php')) ?>">Create your player</a></p>
  </section>
  <p class="auth-foot">18+ only · Coins are bought with PayPal · 100 coins = $1</p>
</main>
<?php
page_foot(['auth.js']);
