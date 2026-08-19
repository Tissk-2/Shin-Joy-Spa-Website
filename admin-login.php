<?php
require __DIR__ . '/includes/init.php';
if (is_admin()) {
    header('Location: admin.php');
    exit;
}
$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $u = authenticate($username, $_POST['password'] ?? '');
    if ($u && $u['role'] === 'admin') {
        login_user($u);
        header('Location: admin.php');
        exit;
    } elseif ($u) {
        $error = t('auth.err.admin');
    } else {
        $error = t('auth.err.invalid');
    }
}

$PAGE_TITLE = t('auth.admin.title') . ' · Shin Joy Spa Malang';
$AUTH_BODY_CLASS = 'auth-body auth-body--admin';
include __DIR__ . '/includes/auth_head.php';
?>
  <main class="auth--admin">
    <div class="auth-bar">
      <a class="brand" href="index.php" aria-label="Shin Joy Spa Malang"><?= brand_mark() ?><span class="wordmark"><b>Shin Joy</b><span>Spa · Malang</span></span></a>
      <?= auth_lang_toggle() ?>
    </div>

    <div class="auth-card">
      <span class="auth-badge"><?= te('auth.admin.badge') ?></span>
      <h1><?= te('auth.admin.title') ?></h1>
      <p class="auth-sub"><?= te('auth.admin.sub') ?></p>
      <?php if ($error): ?><div class="auth-notice" style="background:var(--rose-soft);color:var(--rose-deep)"><?= e($error) ?></div><?php endif; ?>

      <form class="auth-form" method="post" action="admin-login.php">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username"><?= te('auth.f.username') ?></label>
          <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required>
        </div>
        <?= password_field('password', 'auth.f.password', 'current-password') ?>
        <button type="submit" class="btn btn--primary btn--lg btn--block"><?= te('auth.admin.submit') ?></button>
      </form>

      <p class="auth-alt" style="margin-top:1.4rem">
        <a class="auth-back" href="index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= te('auth.backsite') ?></span></a>
      </p>
    </div>
    <div></div>
  </main>
<?php include __DIR__ . '/includes/auth_foot.php'; ?>
