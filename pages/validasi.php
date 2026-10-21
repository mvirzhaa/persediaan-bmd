<?php
defined('BASE_PATH') or exit;
$u = user();
$scope = unit_scope_ids($u);

if (is_post() && input('aksi') === 'setujui') {
    csrf_check();
    $ok = 0; $err = [];
    foreach ((array)($_POST['ids'] ?? []) as $tid) {
        try { validasi_transaksi((int)$tid, true, null, $u); $ok++; }
        catch (RuntimeException $e) { $err[] = '#' . (int)$tid . ': ' . $e->getMessage(); }
    }
    if ($ok) flash('success', "$ok transaksi disetujui.");
    if ($err) flash('error', 'Sebagian gagal:<ul><li>' . implode('</li><li>', array_map('e', $err)) . '</li></ul>', true);
    redirect(url('validasi'));
}

$list = rows("SELECT t.*, uk.nama AS unit_nama, uk.level AS unit_level, us.nama AS pembuat,
                (SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id=t.id) n,
                (SELECT COALESCE(SUM(nilai),0) FROM transaksi_detail WHERE transaksi_id=t.id) total,
                (SELECT GROUP_CONCAT(nama_barang ORDER BY id SEPARATOR ', ') FROM transaksi_detail WHERE transaksi_id=t.id) barang
              FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id JOIN users us ON us.id=t.created_by
              WHERE t.status='menunggu' AND t.unit_id IN (" . in_ph($scope) . ") ORDER BY t.submitted_at, t.id", $scope);

$pageTitle = 'Validasi';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div><h1>Validasi Transaksi Operator</h1><p>Transaksi dari Operator <?= e($u['unit_nama']) ?><?= count($scope) > 1 ? ' dan sub unit di bawahnya' : '' ?> yang menunggu persetujuan.</p></div>
</div>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="aksi" value="setujui">
  <div class="card">
    <div class="card-head"><h2><?= count($list) ?> transaksi menunggu</h2>
      <?php if ($list): ?><button class="btn btn-green" type="submit" data-confirm="1" onclick="return confirm('Setujui semua transaksi yang dicentang?')"><?= icon('check') ?> Setujui yang dicentang</button><?php endif; ?></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th style="width:36px"><input type="checkbox" onclick="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)"></th><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Unit</th><th>Barang</th><th>Operator</th><th class="num">Nilai (Rp)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $t): ?>
          <tr>
            <td><input type="checkbox" class="cb" name="ids[]" value="<?= (int)$t['id'] ?>"></td>
            <td class="mono"><?= e($t['nomor']) ?></td>
            <td class="nowrap"><?= tgl_indo($t['tanggal']) ?></td>
            <td><?= $t['jenis'] === 'masuk' ? '<span class="badge badge-teal">Pembelian</span>' : '<span class="badge badge-blue">Pengeluaran</span>' ?></td>
            <td><?= e($t['unit_nama']) ?><?= $t['unit_level'] === 'subunit' ? ' <span class="badge badge-gray">sub unit</span>' : '' ?></td>
            <td class="small"><?= (int)$t['n'] ?> item: <?= e(mb_strimwidth((string)$t['barang'], 0, 80, '…')) ?></td>
            <td class="small"><?= e($t['pembuat']) ?></td>
            <td class="num"><?= $t['jenis'] === 'masuk' ? rupiah($t['total']) : '<span class="muted">FIFO</span>' ?></td>
            <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('transaksi_detail', ['id' => $t['id']])) ?>"><?= icon('eye') ?> Periksa</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="9" class="empty">Tidak ada transaksi yang menunggu validasi. 🎉</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php include BASE_PATH . '/views/footer.php'; ?>
