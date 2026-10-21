<?php
defined('BASE_PATH') or exit;
$u = user();
$errors = [];
if (is_post()) {
    csrf_check();
    $nama = mb_substr(trim((string)input('nama', '')), 0, 150);
    $nip = mb_substr(trim((string)input('nip', '')), 0, 30);
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');
    if ($nama === '') $errors[] = 'Nama wajib diisi.';
    if ($new !== '') {
        $hash = val('SELECT password FROM users WHERE id=?', [$u['id']]);
        if (!password_verify($old, $hash)) $errors[] = 'Password lama salah.';
        if (mb_strlen($new) < 8) $errors[] = 'Password baru minimal 8 karakter.';
        if ($new !== $new2) $errors[] = 'Konfirmasi password baru tidak sama.';
    }
    if (!$errors) {
        exec_sql('UPDATE users SET nama=?, nip=? WHERE id=?', [$nama, $nip ?: null, $u['id']]);
        if ($new !== '') exec_sql('UPDATE users SET password=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        flash('success', 'Profil disimpan' . ($new !== '' ? ' dan password diganti.' : '.'));
        redirect(url('profil'));
    }
}
$pageTitle = 'Profil';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head"><div><h1>Profil &amp; Password</h1><p><?= e($u['email']) ?> · <?= e(role_label($u['role'], $u['unit_level'])) ?></p></div></div>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>
<div class="card" style="max-width:720px">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field col-8"><label>Nama</label><input type="text" name="nama" value="<?= e($u['nama']) ?>" required></div>
        <div class="field col-4"><label>NIP</label><input type="text" name="nip" value="<?= e($u['nip']) ?>"></div>
        <div class="field col-12"><h3 style="margin-top:8px">Ganti Password</h3><div class="help">Kosongkan bila tidak ingin mengganti password.</div></div>
        <div class="field col-4"><label>Password lama</label><input type="password" name="old" autocomplete="current-password"></div>
        <div class="field col-4"><label>Password baru</label><input type="password" name="new" autocomplete="new-password"></div>
        <div class="field col-4"><label>Ulangi password baru</label><input type="password" name="new2" autocomplete="new-password"></div>
      </div>
      <div class="divider"></div>
      <button class="btn" type="submit">Simpan</button>
    </form>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
