<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$v = ['username' => '', 'email' => '', 'avatar' => 0, 'color' => 0];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $v['username'] = trim((string) ($_POST['username'] ?? ''));
    $v['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $v['avatar'] = (int) ($_POST['avatar'] ?? 0);
    $v['color'] = (int) ($_POST['color'] ?? 0);
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!rate_limit('register-ip:' . client_ip(), 6, 3600)) {
        $errors[] = 'Too many sign-ups from your network. Try again later.';
    } else {
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $v['username'])) {
            $errors[] = 'Username: 3–20 letters, numbers or _.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) {
            $errors[] = 'Enter a valid email.';
        }
        if (strlen($pass) < 8 || strlen($pass) > 200) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $errors[] = 'Passwords do not match.';
        }
        if (!isset(TTT_AVATARS[$v['avatar']]) || !isset(TTT_COLORS[$v['color']])) {
            $errors[] = 'Pick an avatar.';
            $v['avatar'] = $v['color'] = 0;
        }
        if (empty($_POST['adult'])) {
            $errors[] = 'You must be 18 or older and accept the rules.';
        }
        if (!$errors) {
            try {
                q(
                    'INSERT INTO users (username, email, password_hash, avatar, avatar_color) VALUES (?, ?, ?, ?, ?)',
                    [$v['username'], $v['email'], password_hash($pass, PASSWORD_DEFAULT), $v['avatar'], $v['color']]
                );
                $u = q('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()])->fetch();
                login_user($u);
                flash('Welcome, ' . $u['username'] . '! Practice vs the AI for free, or top up to play for coins.', 'success');
                redirect('index.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That username or email is already taken.';
                } else {
                    error_log('TikTato register: ' . $e->getMessage());
                    $errors[] = 'Could not create your account. Please try again.';
                }
            }
        }
    }
}

page_head('Create player · TikTato', 'auth');
echo glyph_field();
?>
<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-brand auth-brand-sm">
      <?= logo_svg('logo') ?>
      <h1 class="brand-title">Create your <span>player</span></h1>
    </div>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" action="<?= e(url('register.php')) ?>" class="auth-form">
      <?= csrf_field() ?>
      <fieldset class="avatar-pick">
        <legend class="field-label">Choose your avatar</legend>
        <div class="avatar-preview avatar xl av-c<?= (int) $v['color'] ?>" data-preview><?= e(TTT_AVATARS[$v['avatar']]) ?></div>
        <div class="avatar-grid">
          <?php foreach (TTT_AVATARS as $i => $a): ?>
            <label class="avatar-opt"><input type="radio" name="avatar" value="<?= $i ?>"<?= $i === $v['avatar'] ? ' checked' : '' ?>><span><?= e($a) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="color-grid">
          <?php foreach (TTT_COLORS as $i => $c): ?>
            <label class="color-opt"><input type="radio" name="color" value="<?= $i ?>" aria-label="Color <?= $i + 1 ?>"<?= $i === $v['color'] ? ' checked' : '' ?>><span class="av-c<?= $i ?>"></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <label class="field">
        <span class="field-label">Username</span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5Z"/></svg>
          <input name="username" value="<?= e($v['username']) ?>" autocomplete="username" required minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" autocapitalize="off" spellcheck="false">
        </span>
      </label>
      <label class="field">
        <span class="field-label">Email</span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3V5Zm2 2v.5l7 4.5 7-4.5V7H5Zm14 10V9.9l-7 4.5-7-4.5V17h14Z"/></svg>
          <input name="email" type="email" value="<?= e($v['email']) ?>" autocomplete="email" required maxlength="190">
        </span>
      </label>
      <label class="field">
        <span class="field-label">Password <small>8+ characters</small></span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 9V7A5 5 0 0 0 7 7v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Zm4 9.7V18h-2v-1.3a2 2 0 1 1 2 0Z"/></svg>
          <input name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="200">
          <button type="button" class="peek" data-peek aria-label="Show password">Show</button>
        </span>
      </label>
      <label class="field">
        <span class="field-label">Confirm password</span>
        <span class="field-box">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>
          <input name="password2" type="password" autocomplete="new-password" required minlength="8" maxlength="200">
        </span>
      </label>
      <label class="check">
        <input type="checkbox" name="adult" value="1">
        <span>I am 18 or older and accept the rules: in a skill contest the loser loses their entry fee, the winner takes the prize pool minus a 20% fee, and a draw refunds both players.</span>
      </label>
      <button class="btn btn-cta btn-block" type="submit">Create player</button>
    </form>
    <p class="auth-switch">Already playing? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
  </section>
</main>
<?php
page_foot(['auth.js']);
