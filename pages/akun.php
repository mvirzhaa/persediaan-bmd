<?php
defined('BASE_PATH') or exit;
$id = input_int('id');
$a = row('SELECT u.*, uk.nama AS unit_nama, uk.level AS unit_level, uk.skpd_id AS unit_skpd, s.nama AS skpd_nama
          FROM users u LEFT JOIN unit_kerja uk ON uk.id=u.unit_id LEFT JOIN skpd s ON s.id=COALESCE(u.skpd_id, uk.skpd_id) WHERE u.id=?', [$id]);
if (!$a || !can_manage_account($a)) { flash('error', 'Akun tidak ditemukan atau Anda tidak berhak mengelolanya.'); redirect(url('dashboard')); }

$back = $a['role'] === 'admin_skpd' ? url('skpd_form', ['id' => $a['skpd_id']]) : url('unit_form', ['id' => $a['unit_id']]);
$errors = [];
if (is_post()) {
    csrf_check();
    $nama = mb_substr(trim((string)input('nama', '')), 0, 150);
    $nip = mb_substr(trim((string)input('nip', '')), 0, 30);
    $email = mb_strtolower(trim((string)input('email', '')));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $aktif = input('is_active') ? 1 : 0;
    if ($nama === '') $errors[] = 'Nama wajib diisi.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if ($email !== '' && (int)val('SELECT COUNT(*) FROM users WHERE email=? AND id<>?', [$email, $id]) > 0) $errors[] = 'Email sudah dipakai akun lain.';
    if ($pass !== '' && mb_strlen($pass) < 8) $errors[] = 'Password minimal 8 karakter.';
    if ($pass !== $pass2) $errors[] = 'Konfirmasi password tidak sama.';
    if ($aktif && ($email === '' || ($pass === '' && !$a['password']))) $errors[] = 'Akun hanya dapat diaktifkan bila email dan password sudah diisi.';
    if (!$errors) {
        exec_sql('UPDATE users SET nama=?, nip=?, email=?, is_active=? WHERE id=?', [$nama, $nip ?: null, $email ?: null, $aktif, $id]);
        if ($pass !== '') exec_sql('UPDATE users SET password=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        log_aktivitas('akun_ubah', $nama . ' <' . $email . '>');
        flash('success', 'Akun "' . $nama . '" disimpan' . ($aktif ? ' dan aktif.' : '.'));
        redirect($back);
    }
    $a = array_merge($a, ['nama' => $nama, 'nip' => $nip, 'email' => $email, 'is_active' => $aktif]);
}

$pageTitle = 'Atur Akun';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e($back) ?>">Kembali</a> / Atur Akun</div>
    <h1><?= e($a['nama']) ?></h1>
    <p><?= e(role_label($a['role'], $a['unit_level'])) ?> · <?= e($a['unit_nama'] ?: $a['skpd_nama']) ?></p>
  </div>
</div>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>
<div class="card" style="max-width:720px">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field col-8"><label>Nama Pengguna <span class="req">*</span></label><input type="text" name="nama" value="<?= e($a['nama']) ?>" required></div>
        <div class="field col-4"><label>NIP</label><input type="text" name="nip" value="<?= e($a['nip']) ?>"></div>
        <div class="field col-12"><label>Email (untuk login)</label><input type="email" name="email" value="<?= e($a['email']) ?>" autocomplete="off"></div>
        <div class="field col-6"><label>Password <?= $a['password'] ? '<span class="muted">(kosongkan bila tidak diubah)</span>' : '' ?></label><input type="password" name="password" autocomplete="new-password"></div>
        <div class="field col-6"><label>Ulangi Password</label><input type="password" name="password2" autocomplete="new-password"></div>
        <div class="field col-12"><label class="check"><input type="checkbox" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>> Akun aktif (dapat login)</label>
          <div class="help">Akun baru otomatis tidak aktif. Isi email &amp; password lalu centang “Akun aktif”.</div></div>
      </div>
      <div class="divider"></div>
      <button class="btn" type="submit">Simpan Akun</button>
      <a class="btn btn-light" href="<?= e($back) ?>">Batal</a>
    </form>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
