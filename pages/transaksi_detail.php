<?php
defined('BASE_PATH') or exit;
$u = user();
$id = input_int('id');
$t = row('SELECT t.*, uk.nama AS unit_nama, uk.level AS unit_level, s.nama AS skpd_nama, c.nama AS pembuat, c.role AS pembuat_role, v.nama AS validator
          FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id JOIN skpd s ON s.id=uk.skpd_id
          JOIN users c ON c.id=t.created_by LEFT JOIN users v ON v.id=t.validated_by WHERE t.id=? AND t.status<>\'draft\'', [$id]);
if (!$t || !can_view_trx($t)) { flash('error', 'Transaksi tidak ditemukan.'); redirect(url('dashboard')); }

if (is_post() && in_array(input('aksi'), ['setujui', 'tolak'], true)) {
    csrf_check();
    try {
        $setuju = input('aksi') === 'setujui';
        $cat = trim((string)input('catatan', ''));
        if (!$setuju && $cat === '') throw new RuntimeException('Isi alasan penolakan pada kolom catatan.');
        validasi_transaksi($id, $setuju, $cat ?: null, $u);
        flash('success', $setuju ? 'Transaksi disetujui. Stok sudah diperbarui.' : 'Transaksi ditolak.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('transaksi_detail', ['id' => $id]));
}

$items = rows('SELECT d.*, k.kode, k.uraian AS kode_uraian FROM transaksi_detail d JOIN kode_barang k ON k.id=d.kode_barang_id WHERE d.transaksi_id=? ORDER BY d.id', [$id]);
$canVal = can_validate($t, $u);
$isMasuk = $t['jenis'] === 'masuk';
$showNilai = $isMasuk || $t['status'] === 'disetujui';
$pageTitle = ($isMasuk ? 'Pembelian ' : 'Pengeluaran ') . $t['nomor'];
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><?php if (is_unit_user()): ?><a href="<?= e(url('transaksi')) ?>">Riwayat Transaksi</a> / <?php endif; ?>Detail</div>
    <h1><?= $isMasuk ? ($t['sumber'] === 'saldo_awal' ? 'Saldo Awal' : 'Pembelian Barang') : 'Pengeluaran Barang' ?> <span class="mono" style="font-size:1rem;color:var(--ink-3)"><?= e($t['nomor']) ?></span></h1>
    <p><?= e($t['unit_nama']) ?> · <?= e($t['skpd_nama']) ?></p>
  </div>
  <div class="actions"><?= badge_status($t['status']) ?> <button class="btn btn-light" onclick="window.print()"><?= icon('print') ?> Cetak</button></div>
</div>

<div class="grid grid-2">
  <div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Tanggal</dt><dd><?= tgl_indo($t['tanggal']) ?></dd>
      <dt>No. Dokumen</dt><dd><?= e($t['no_dokumen'] ?: '-') ?></dd>
      <dt>Dokumen</dt><dd><?= $t['dokumen_file'] ? '<a href="unduh.php?t=' . (int)$t['id'] . '" target="_blank">' . icon('clip') . ' ' . e($t['dokumen_nama']) . '</a>' : '<span class="muted">tidak ada</span>' ?></dd>
      <dt>Keterangan</dt><dd><?= nl2br(e($t['keterangan'] ?: '-')) ?></dd>
      <dt>Sumber</dt><dd><?= ['input' => 'Input manual', 'import' => 'Impor Excel', 'saldo_awal' => 'Saldo awal / migrasi data'][$t['sumber']] ?></dd>
    </dl>
  </div></div>
  <div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Diinput oleh</dt><dd><?= e($t['pembuat']) ?> <span class="muted">(<?= e(role_label($t['pembuat_role'], $t['unit_level'])) ?>)</span></dd>
      <dt>Waktu input</dt><dd><?= tgl_indo($t['submitted_at'] ?: $t['created_at'], true) ?></dd>
      <dt>Status</dt><dd><?= badge_status($t['status']) ?></dd>
      <?php if ($t['validated_by']): ?>
        <dt><?= $t['status'] === 'ditolak' ? 'Ditolak oleh' : 'Disetujui oleh' ?></dt><dd><?= e($t['validator']) ?> · <?= tgl_indo($t['validated_at'], true) ?></dd>
      <?php endif; ?>
      <?php if ($t['catatan_validasi']): ?><dt>Catatan</dt><dd><?= nl2br(e($t['catatan_validasi'])) ?></dd><?php endif; ?>
    </dl>
  </div></div>
</div>

<div class="card">
  <div class="card-head"><h2>Daftar Barang (<?= count($items) ?>)</h2></div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="num">Jumlah</th><th>Satuan</th><th class="num">Harga Satuan</th><th class="num">Nilai (Rp)</th><th>Dokumen</th></tr></thead>
      <tbody>
      <?php $tot = 0; foreach ($items as $i => $d): $tot += $d['nilai']; ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><span class="mono"><?= e($d['kode']) ?></span><div class="small muted"><?= e($d['kode_uraian']) ?></div></td>
          <td><?= e($d['nama_barang']) ?><?= $d['keterangan'] ? '<div class="small muted">' . e($d['keterangan']) . '</div>' : '' ?></td>
          <td class="num"><?= angka($d['jumlah']) ?></td>
          <td><?= e($d['satuan']) ?></td>
          <td class="num"><?= $showNilai ? rupiah($d['harga_satuan']) : '<span class="muted">FIFO</span>' ?></td>
          <td class="num"><?= $showNilai ? rupiah($d['nilai']) : '-' ?></td>
          <td class="small"><?= $d['dokumen_file'] ? '<a href="unduh.php?d=' . (int)$d['id'] . '" target="_blank">📎 ' . e($d['dokumen_nama']) . '</a>' : '<span class="muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($showNilai): ?><tfoot><tr><td colspan="6">Total</td><td class="num"><?= rupiah($tot) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
</div>

<?php if ($canVal): ?>
<div class="card no-print" style="border-color:#f1d9a7">
  <div class="card-head" style="background:var(--amber-soft)"><h2>Validasi Admin</h2></div>
  <div class="card-body">
    <p class="small">Transaksi ini diinput oleh Operator dan belum mempengaruhi stok. <?= $isMasuk ? 'Jika disetujui, stok bertambah sesuai daftar di atas.' : 'Jika disetujui, stok berkurang (nilai dihitung FIFO).' ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field" style="margin-bottom:12px"><label>Catatan (wajib bila ditolak)</label><textarea name="catatan" maxlength="1000"></textarea></div>
      <button class="btn btn-green" name="aksi" value="setujui" type="submit" onclick="return confirm('Setujui transaksi ini?')"><?= icon('check') ?> Setujui</button>
      <button class="btn btn-danger" name="aksi" value="tolak" type="submit" onclick="return confirm('Tolak transaksi ini?')">Tolak</button>
    </form>
  </div>
</div>
<?php elseif ($t['status'] === 'menunggu'): ?>
  <div class="alert alert-warning">Transaksi ini menunggu validasi Admin <?= e($t['unit_level'] === 'subunit' ? 'Sub Unit / Admin Unit' : 'Unit') ?>.</div>
<?php endif; ?>
<?php include BASE_PATH . '/views/footer.php'; ?>
