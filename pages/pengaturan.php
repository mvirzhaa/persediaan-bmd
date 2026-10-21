<?php
defined('BASE_PATH') or exit;
$keys = [
    'nama_pemerintah' => ['Nama Pemerintah (kop laporan)', 'PEMERINTAH KABUPATEN BOGOR'],
    'nama_pemda' => ['Nama Daerah (judul laporan)', 'KABUPATEN BOGOR'],
    'kota_ttd' => ['Tempat penandatanganan laporan', 'Cibinong'],
    'ttd_jabatan' => ['Jabatan penandatangan laporan tingkat Pemda', 'SEKRETARIS DAERAH'],
    'ttd_jabatan2' => ['Keterangan jabatan (baris kedua)', 'Selaku Pengelola Barang'],
    'ttd_nama' => ['Nama penandatangan', ''],
    'ttd_nip' => ['NIP penandatangan', ''],
];
if (is_post()) {
    csrf_check();
    foreach ($keys as $k => $meta) {
        $v = mb_substr(trim((string)input($k, '')), 0, 255);
        exec_sql('INSERT INTO pengaturan (kunci, nilai) VALUES (?,?) ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)', [$k, $v]);
    }
    log_aktivitas('pengaturan_ubah', '');
    flash('success', 'Pengaturan disimpan.');
    redirect(url('pengaturan'));
}
$pageTitle = 'Pengaturan';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head"><div><h1>Pengaturan</h1><p>Identitas pemerintah daerah dan penandatangan default pada laporan.</p></div></div>
<div class="card" style="max-width:820px">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <?php foreach ($keys as $k => $meta): ?>
          <div class="field col-6"><label><?= e($meta[0]) ?></label><input type="text" name="<?= e($k) ?>" value="<?= e(setting($k, '')) ?>" placeholder="<?= e($meta[1]) ?>"></div>
        <?php endforeach; ?>
      </div>
      <p class="help small muted">Penandatangan laporan SKPD/unit diisi langsung pada halaman Laporan (default: PIC SKPD/unit).</p>
      <div class="divider"></div>
      <button class="btn" type="submit">Simpan Pengaturan</button>
    </form>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
