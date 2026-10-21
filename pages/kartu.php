<?php
defined('BASE_PATH') or exit;
$u = user();
$id = input_int('id');
$b = row('SELECT b.*, uk.nama AS unit_nama FROM barang b JOIN unit_kerja uk ON uk.id=b.unit_id WHERE b.id=?', [$id]);
if (!$b || !in_array((int)$b['unit_id'], unit_scope_ids($u), true)) { flash('error', 'Barang tidak ditemukan.'); redirect(url('stok')); }
$hier = kode_hierarki($b['kode_barang_id']);

$mut = rows("SELECT t.id AS tid, t.nomor, t.tanggal, t.jenis, t.no_dokumen, t.sumber, d.jumlah, d.harga_satuan, d.nilai, d.keterangan
             FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id
             WHERE d.barang_id=? AND t.status='disetujui'
             ORDER BY t.tanggal, FIELD(t.jenis,'masuk','keluar'), t.id, d.id", [$id]);
$pending = rows("SELECT t.id AS tid, t.nomor, t.tanggal, t.jenis, d.jumlah FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id
                 WHERE d.barang_id=? AND t.status='menunggu'", [$id]);
$lots = rows('SELECT * FROM stok_lot WHERE barang_id=? AND qty_sisa>0 ORDER BY tanggal, id', [$id]);

$pageTitle = 'Kartu Stok';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('stok', ['unit' => $b['unit_id']])) ?>">Stok Barang</a> / Kartu Stok</div>
    <h1><?= e($b['nama_barang']) ?></h1>
    <p><?= e($b['unit_nama']) ?> · Stok <b><?= angka($b['stok']) ?> <?= e($b['satuan']) ?></b> · Nilai Rp <?= rupiah($b['nilai']) ?></p>
  </div>
  <div class="actions"><button class="btn btn-light" onclick="window.print()"><?= icon('print') ?> Cetak</button></div>
</div>

<div class="grid grid-2">
  <div class="card"><div class="card-head"><h3>Klasifikasi Barang</h3></div><div class="card-body">
    <div class="hier"><?php foreach ($hier as $lv => $k): ?><div class="hl"><?= e(level_nama($lv)) ?></div><div class="hv"><b><?= e($k['kode']) ?></b><?= e(judul($k['uraian'])) ?></div><?php endforeach; ?></div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Lot persediaan tersisa (FIFO)</h3></div><div class="card-body tight">
    <table class="tbl compact"><thead><tr><th>Tanggal Masuk</th><th class="num">Sisa</th><th class="num">Harga Satuan</th><th class="num">Nilai</th></tr></thead><tbody>
    <?php foreach ($lots as $l): ?><tr><td><?= tgl_indo($l['tanggal']) ?></td><td class="num"><?= angka($l['qty_sisa']) ?></td><td class="num"><?= rupiah($l['harga_satuan']) ?></td><td class="num"><?= rupiah($l['qty_sisa'] * $l['harga_satuan']) ?></td></tr><?php endforeach; ?>
    <?php if (!$lots): ?><tr><td colspan="4" class="empty">Stok habis.</td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($pending): ?><div class="alert alert-warning" style="margin:12px">Ada <?= count($pending) ?> transaksi menunggu validasi untuk barang ini.</div><?php endif; ?>
  </div></div>
</div>

<div class="card">
  <div class="card-head"><h2>Mutasi Barang</h2></div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Tanggal</th><th>Nomor</th><th>Uraian</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Saldo</th><th class="num">Harga Satuan</th><th class="num">Nilai Mutasi</th><th class="num">Nilai Saldo</th></tr></thead>
      <tbody>
      <?php $sq = 0; $sv = 0; foreach ($mut as $m):
          $in = $m['jenis'] === 'masuk';
          $sq += $in ? $m['jumlah'] : -$m['jumlah'];
          $sv += $in ? $m['nilai'] : -$m['nilai']; ?>
        <tr>
          <td class="nowrap"><?= tgl_indo($m['tanggal']) ?></td>
          <td><a class="mono" href="<?= e(url('transaksi_detail', ['id' => $m['tid']])) ?>"><?= e($m['nomor']) ?></a></td>
          <td><?= $m['sumber'] === 'saldo_awal' ? 'Saldo awal' : ($in ? 'Pembelian/Penerimaan' : 'Pemakaian') ?><?= $m['no_dokumen'] ? ' · ' . e($m['no_dokumen']) : '' ?><?= $m['keterangan'] ? '<div class="small muted">' . e($m['keterangan']) . '</div>' : '' ?></td>
          <td class="num"><?= $in ? angka($m['jumlah']) : '' ?></td>
          <td class="num"><?= !$in ? angka($m['jumlah']) : '' ?></td>
          <td class="num"><b><?= angka($sq) ?></b></td>
          <td class="num"><?= rupiah($m['harga_satuan']) ?></td>
          <td class="num"><?= ($in ? '' : '−') . rupiah($m['nilai']) ?></td>
          <td class="num"><?= rupiah($sv) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mut): ?><tr><td colspan="9" class="empty">Belum ada mutasi.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
