<?php
defined('BASE_PATH') or exit;
$id = input_int('id');
$s = $id ? row('SELECT * FROM skpd WHERE id=?', [$id]) : null;
if ($id && !$s) { flash('error', 'SKPD tidak ditemukan.'); redirect(url('skpd')); }
$errors = [];

if (is_post()) {
    csrf_check();
    list($d, $errors) = org_form_data();
    if (!$errors) {
        if ($s) {
            exec_sql('UPDATE skpd SET kode=?, nama=?, lokasi=?, pic=?, email=?, telp=? WHERE id=?',
                [$d['kode'] ?: null, $d['nama'], $d['lokasi'] ?: null, $d['pic'] ?: null, $d['email'] ?: null, $d['telp'] ?: null, $id]);
            log_aktivitas('skpd_ubah', $d['nama']);
            flash('success', 'Data SKPD disimpan.');
            redirect(url('skpd_form', ['id' => $id]));
        } else {
            $nid = skpd_create($d);
            flash('success', 'SKPD dibuat. 2 akun Admin SKPD otomatis terbentuk — lengkapi email & password di bawah.');
            redirect(url('skpd_form', ['id' => $nid]));
        }
    }
    $s = array_merge($s ?: [], $d);
}
$akun = $id ? rows("SELECT * FROM users WHERE skpd_id=? AND role='admin_skpd' ORDER BY slot, id", [$id]) : [];

$pageTitle = $id ? 'Edit SKPD' : 'Tambah SKPD';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('skpd')) ?>">SKPD</a> / <?= $id ? 'Edit' : 'Tambah' ?></div>
    <h1><?= $id ? e($s['nama']) : 'Tambah SKPD' ?></h1>
  </div>
  <?php if ($id): ?><div class="actions"><a class="btn btn-light" href="<?= e(url('unit', ['skpd' => $id])) ?>"><?= icon('tree') ?> Kelola Unit &amp; Sub Unit</a></div><?php endif; ?>
</div>

<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2>Data SKPD</h2></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field col-4"><label>Kode</label><input type="text" name="kode" value="<?= e($s['kode'] ?? '') ?>" placeholder="opsional"></div>
          <div class="field col-8"><label>Nama SKPD <span class="req">*</span></label><input type="text" name="nama" value="<?= e($s['nama'] ?? '') ?>" required></div>
          <div class="field col-12"><label>Lokasi / Alamat</label><input type="text" name="lokasi" value="<?= e($s['lokasi'] ?? '') ?>"></div>
          <div class="field col-12"><label>PIC (Penanggung Jawab)</label><input type="text" name="pic" value="<?= e($s['pic'] ?? '') ?>"></div>
          <div class="field col-6"><label>Email</label><input type="email" name="email" value="<?= e($s['email'] ?? '') ?>"></div>
          <div class="field col-6"><label>Telepon</label><input type="text" name="telp" value="<?= e($s['telp'] ?? '') ?>"></div>
        </div>
        <div class="divider"></div>
        <button class="btn" type="submit"><?= $id ? 'Simpan Perubahan' : 'Simpan &amp; Buat Akun Admin' ?></button>
        <a class="btn btn-light" href="<?= e(url('skpd')) ?>">Batal</a>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Akun Admin SKPD</h2></div>
    <div class="card-body<?= $akun ? ' tight' : '' ?>">
      <?php if (!$id): ?>
        <p class="muted">Setelah SKPD disimpan, sistem otomatis membuat <b>2 akun Admin SKPD</b>. Anda tinggal mengisi email &amp; password masing-masing akun.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Akun</th><th>Email</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($akun as $a): ?>
            <tr>
              <td><b><?= e($a['nama']) ?></b><?php if ($a['nip']): ?><div class="small muted">NIP <?= e($a['nip']) ?></div><?php endif; ?></td>
              <td><?= $a['email'] ? e($a['email']) : '<span class="muted">belum diisi</span>' ?></td>
              <td><?= ($a['is_active'] && $a['email'] && $a['password']) ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Belum aktif</span>' ?></td>
              <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('akun', ['id' => $a['id']])) ?>">Atur Akun</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
