<?php
defined('BASE_PATH') or exit;
$u = user();
$id = input_int('id');
$parentId = input_int('parent');
$unit = $id ? row('SELECT * FROM unit_kerja WHERE id=?', [$id]) : null;
if ($id && !$unit) { flash('error', 'Unit tidak ditemukan.'); redirect(url('unit')); }
if ($unit) { $parentId = (int)$unit['parent_id']; $sid = (int)$unit['skpd_id']; }
elseif ($parentId) {
    $par = row("SELECT * FROM unit_kerja WHERE id=? AND level='unit'", [$parentId]);
    if (!$par) { flash('error', 'Unit induk tidak ditemukan.'); redirect(url('unit')); }
    $sid = (int)$par['skpd_id'];
} else {
    $sid = $u['role'] === 'superadmin' ? input_int('skpd') : (int)$u['skpd_id'];
}
if ($u['role'] === 'admin_skpd' && $sid !== (int)$u['skpd_id']) { flash('error', 'Akses ditolak.'); redirect(url('unit')); }
$skpd = row('SELECT * FROM skpd WHERE id=?', [$sid]);
if (!$skpd) { flash('error', 'SKPD tidak ditemukan.'); redirect(url('unit')); }
$parent = $parentId ? row('SELECT * FROM unit_kerja WHERE id=?', [$parentId]) : null;
$isSub = (bool)$parent;
$label = $isSub ? 'Sub Unit' : 'Unit';
$errors = [];

if (is_post()) {
    csrf_check();
    list($d, $errors) = org_form_data();
    if (!$errors) {
        if ($unit) {
            exec_sql('UPDATE unit_kerja SET kode=?, nama=?, lokasi=?, pic=?, email=?, telp=? WHERE id=?',
                [$d['kode'] ?: null, $d['nama'], $d['lokasi'] ?: null, $d['pic'] ?: null, $d['email'] ?: null, $d['telp'] ?: null, $id]);
            flash('success', 'Data ' . $label . ' disimpan.');
            redirect(url('unit_form', ['id' => $id]));
        } else {
            $nid = unit_create($sid, $parentId ?: null, $d);
            flash('success', $label . ' dibuat. Akun Admin & Operator otomatis terbentuk — lengkapi email & password-nya.');
            redirect(url('unit_form', ['id' => $nid]));
        }
    }
    $unit = array_merge($unit ?: [], $d);
}
$akun = $id ? rows("SELECT * FROM users WHERE unit_id=? ORDER BY FIELD(role,'admin','operator'), slot, id", [$id]) : [];
$subs = ($id && !$isSub) ? rows('SELECT uk.*, (SELECT COUNT(*) FROM users WHERE unit_id=uk.id AND is_active=1 AND email IS NOT NULL) n_aktif FROM unit_kerja uk WHERE parent_id=? ORDER BY nama', [$id]) : [];

$pageTitle = ($id ? 'Edit ' : 'Tambah ') . $label;
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('unit', ['skpd' => $sid])) ?>">Unit &amp; Sub Unit</a> / <?= e($skpd['nama']) ?><?= $parent ? ' / ' . e($parent['nama']) : '' ?></div>
    <h1><?= $id ? e($unit['nama']) : 'Tambah ' . $label ?></h1>
    <p><?= $label ?> pada <?= e($parent ? $parent['nama'] : $skpd['nama']) ?></p>
  </div>
  <?php if ($id && !$isSub): ?><div class="actions"><a class="btn" href="<?= e(url('unit_form', ['parent' => $id])) ?>"><?= icon('plus') ?> Tambah Sub Unit</a></div><?php endif; ?>
</div>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2>Data <?= $label ?></h2></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field col-4"><label>Kode</label><input type="text" name="kode" value="<?= e($unit['kode'] ?? '') ?>" placeholder="opsional"></div>
          <div class="field col-8"><label>Nama <?= $label ?> <span class="req">*</span></label><input type="text" name="nama" value="<?= e($unit['nama'] ?? '') ?>" required></div>
          <div class="field col-12"><label>Lokasi</label><input type="text" name="lokasi" value="<?= e($unit['lokasi'] ?? '') ?>"></div>
          <div class="field col-12"><label>PIC</label><input type="text" name="pic" value="<?= e($unit['pic'] ?? '') ?>"></div>
          <div class="field col-6"><label>Email</label><input type="email" name="email" value="<?= e($unit['email'] ?? '') ?>"></div>
          <div class="field col-6"><label>Telepon</label><input type="text" name="telp" value="<?= e($unit['telp'] ?? '') ?>"></div>
        </div>
        <div class="divider"></div>
        <button class="btn" type="submit"><?= $id ? 'Simpan Perubahan' : 'Simpan &amp; Buat Akun' ?></button>
        <a class="btn btn-light" href="<?= e(url('unit', ['skpd' => $sid])) ?>">Batal</a>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Akun <?= $label ?></h2></div>
      <div class="card-body<?= $akun ? ' tight' : '' ?>">
        <?php if (!$id): ?>
          <p class="muted">Setelah disimpan, sistem otomatis membuat <b>1 akun Admin <?= $label ?></b> dan <b>1 akun Operator <?= $label ?></b>.</p>
          <ul class="small muted"><li><b>Admin</b>: input langsung menambah/mengurangi stok, memvalidasi input operator, melihat laporan.</li><li><b>Operator</b>: input pembelian/pengeluaran yang perlu divalidasi admin.</li></ul>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Akun</th><th>Peran</th><th>Email</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($akun as $a): ?>
              <tr>
                <td><b><?= e($a['nama']) ?></b></td>
                <td><?= $a['role'] === 'admin' ? '<span class="badge badge-teal">Admin</span>' : '<span class="badge badge-blue">Operator</span>' ?></td>
                <td class="small"><?= $a['email'] ? e($a['email']) : '<span class="muted">belum diisi</span>' ?></td>
                <td><?= ($a['is_active'] && $a['email'] && $a['password']) ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Belum aktif</span>' ?></td>
                <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('akun', ['id' => $a['id']])) ?>">Atur</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($id && !$isSub): ?>
    <div class="card">
      <div class="card-head"><h2>Sub Unit</h2><a class="btn btn-light btn-sm" href="<?= e(url('unit_form', ['parent' => $id])) ?>"><?= icon('plus') ?> Tambah</a></div>
      <div class="card-body tight">
        <table class="tbl">
          <tbody>
          <?php foreach ($subs as $sx): ?>
            <tr><td><?= e($sx['nama']) ?></td><td><?= (int)$sx['n_aktif'] ?>/2 akun aktif</td><td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('unit_form', ['id' => $sx['id']])) ?>">Edit &amp; Akun</a></td></tr>
          <?php endforeach; ?>
          <?php if (!$subs): ?><tr><td class="empty">Belum ada sub unit.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
