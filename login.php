<?php
require __DIR__ . '/core/bootstrap.php';
if (user()) redirect('index.php');

$error = '';
$email = '';
if (is_post()) {
    csrf_check();
    $email = (string)input('email', '');
    // pembatasan percobaan login sederhana per sesi
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? ['n' => 0, 't' => time()];
    if ($_SESSION['login_fail']['n'] >= 5 && time() - $_SESSION['login_fail']['t'] < 300) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 5 menit.';
    } else {
        $r = attempt_login($email, (string)($_POST['password'] ?? ''));
        if ($r === true) {
            unset($_SESSION['login_fail']);
            redirect('index.php');
        }
        $_SESSION['login_fail'] = ['n' => $_SESSION['login_fail']['n'] + 1, 't' => time()];
        $error = $r;
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body>
<div class="login-wrap">
  <section class="login-side">
    <div class="grid-bg"></div>
    <div style="position:relative">
      <div class="brand-mark" style="background:#fff;color:var(--accent-2)">BMD</div>
    </div>
    <div style="position:relative">
      <h1><?= e(APP_DESC) ?></h1>
      <p>Pencatatan pembelian, pemakaian, dan saldo persediaan per SKPD, unit, dan sub unit, lengkap dengan validasi berjenjang dan laporan sesuai format penatausahaan BMD.</p>
    </div>
    <div style="position:relative;font-size:12.5px;color:#9cc7cb"><?= e(setting('nama_pemda', '')) ?></div>
  </section>
  <section class="login-form">
    <form class="login-box" method="post" autocomplete="on">
      <?= csrf_field() ?>
      <h2>Masuk ke <?= e(APP_NAME) ?></h2>
      <p class="muted">Gunakan email dan password yang diberikan admin Anda.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <div class="field" style="margin-bottom:12px">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
      </div>
      <div class="field" style="margin-bottom:18px">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button class="btn btn-lg" style="width:100%;justify-content:center" type="submit">Masuk</button>
    </form>
  </section>
</div>
</body>
</html>
