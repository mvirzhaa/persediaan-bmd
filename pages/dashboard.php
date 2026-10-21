<?php
defined('BASE_PATH') or exit;
$u = user();
$pageTitle = 'Dashboard';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Selamat datang, <?= e($u['nama']) ?></h1>
    <p><?= e(role_label($u['role'], $u['unit_level'])) ?> · <?= tgl_indo(date('Y-m-d')) ?></p>
  </div>
</div>

<?php if ($u['role'] === 'superadmin'):
    $nSkpd = (int)val('SELECT COUNT(*) FROM skpd');
    $nUnit = (int)val("SELECT COUNT(*) FROM unit_kerja WHERE level='unit'");
    $nSub = (int)val("SELECT COUNT(*) FROM unit_kerja WHERE level='subunit'");
    $nilai = (float)val('SELECT COALESCE(SUM(nilai),0) FROM barang');
    $pending = (int)val("SELECT COUNT(*) FROM transaksi WHERE status='menunggu'");
    $akunBelum = (int)val("SELECT COUNT(*) FROM users WHERE role='admin_skpd' AND (email IS NULL OR is_active=0)");
    $perSkpd = rows('SELECT s.id, s.nama, COALESCE(SUM(b.nilai),0) nilai, COUNT(DISTINCT CASE WHEN b.stok>0 THEN b.id END) item
                     FROM skpd s LEFT JOIN unit_kerja uk ON uk.skpd_id=s.id LEFT JOIN barang b ON b.unit_id=uk.id
                     GROUP BY s.id, s.nama ORDER BY nilai DESC LIMIT 12');
    $max = $perSkpd ? max(1, (float)$perSkpd[0]['nilai']) : 1;
?>
  <div class="stats">
    <div class="stat accent"><div class="label">SKPD</div><div class="value"><?= angka($nSkpd) ?></div><div class="hint"><?= angka($nUnit) ?> unit · <?= angka($nSub) ?> sub unit</div></div>
    <div class="stat green"><div class="label">Nilai Persediaan</div><div class="value">Rp <?= rupiah($nilai, 0) ?></div><div class="hint">saldo saat ini, seluruh SKPD</div></div>
    <div class="stat amber"><div class="label">Menunggu Validasi</div><div class="value"><?= angka($pending) ?></div><div class="hint">transaksi dari operator</div></div>
    <div class="stat blue"><div class="label">Akun Admin SKPD Belum Aktif</div><div class="value"><?= angka($akunBelum) ?></div><div class="hint"><a href="<?= e(url('skpd')) ?>">atur email &amp; password</a></div></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Nilai persediaan per SKPD (12 terbesar)</h2><a class="btn btn-light btn-sm" href="<?= e(url('laporan')) ?>">Lihat laporan</a></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>SKPD</th><th class="num">Jenis Barang</th><th class="num">Nilai (Rp)</th><th style="width:30%"></th></tr></thead>
        <tbody>
        <?php foreach ($perSkpd as $r): ?>
          <tr><td><?= e($r['nama']) ?></td><td class="num"><?= angka($r['item']) ?></td><td class="num"><?= rupiah($r['nilai']) ?></td>
            <td><div class="bar"><span style="width:<?= round((float)$r['nilai'] / $max * 100, 1) ?>%"></span></div></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($u['role'] === 'admin_skpd'):
    $sid = (int)$u['skpd_id'];
    $units = rows('SELECT uk.*, p.nama AS parent_nama,
                     (SELECT COALESCE(SUM(nilai),0) FROM barang WHERE unit_id=uk.id) nilai,
                     (SELECT COUNT(*) FROM barang WHERE unit_id=uk.id AND stok>0) item,
                     (SELECT COUNT(*) FROM transaksi WHERE unit_id=uk.id AND status=\'menunggu\') pending
                   FROM unit_kerja uk LEFT JOIN unit_kerja p ON p.id=uk.parent_id
                   WHERE uk.skpd_id=? ORDER BY COALESCE(uk.parent_id, uk.id), uk.level, uk.nama', [$sid]);
    $nilai = array_sum(array_column($units, 'nilai'));
    $pending = array_sum(array_column($units, 'pending'));
    $nUnit = count(array_filter($units, function ($x) { return $x['level'] === 'unit'; }));
    $belum = (int)val("SELECT COUNT(*) FROM users u JOIN unit_kerja uk ON uk.id=u.unit_id WHERE uk.skpd_id=? AND (u.email IS NULL OR u.is_active=0)", [$sid]);
?>
  <div class="stats">
    <div class="stat accent"><div class="label">Unit / Sub Unit</div><div class="value"><?= $nUnit ?> / <?= count($units) - $nUnit ?></div><div class="hint"><a href="<?= e(url('unit')) ?>">kelola unit</a></div></div>
    <div class="stat green"><div class="label">Nilai Persediaan SKPD</div><div class="value">Rp <?= rupiah($nilai, 0) ?></div><div class="hint">saldo saat ini</div></div>
    <div class="stat amber"><div class="label">Menunggu Validasi</div><div class="value"><?= angka($pending) ?></div><div class="hint">di seluruh unit</div></div>
    <div class="stat blue"><div class="label">Akun Belum Aktif</div><div class="value"><?= angka($belum) ?></div><div class="hint">admin/operator unit &amp; sub unit</div></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Ringkasan per unit &amp; sub unit</h2></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>Unit / Sub Unit</th><th class="num">Jenis Barang</th><th class="num">Nilai (Rp)</th><th class="num">Menunggu</th></tr></thead>
        <tbody>
        <?php foreach ($units as $r): ?>
          <tr class="<?= $r['level'] === 'subunit' ? 'row-sub' : '' ?>"><td><?= $r['level'] === 'subunit' ? '↳ ' : '<b>' ?><?= e($r['nama']) ?><?= $r['level'] === 'unit' ? '</b>' : '' ?></td>
            <td class="num"><?= angka($r['item']) ?></td><td class="num"><?= rupiah($r['nilai']) ?></td><td class="num"><?= $r['pending'] ? '<span class="badge badge-amber">' . (int)$r['pending'] . '</span>' : '-' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$units): ?><tr><td colspan="4" class="empty">Belum ada unit. <a href="<?= e(url('unit_form')) ?>">Tambah unit</a></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else:
    $scope = unit_scope_ids($u);
    $ph = in_ph($scope);
    $nItem = (int)val('SELECT COUNT(*) FROM barang WHERE unit_id=? AND stok>0', [$u['unit_id']]);
    $nilai = (float)val('SELECT COALESCE(SUM(nilai),0) FROM barang WHERE unit_id=?', [$u['unit_id']]);
    $pending = (int)val("SELECT COUNT(*) FROM transaksi WHERE status='menunggu' AND unit_id IN ($ph)", $scope);
    $beliBulan = (float)val("SELECT COALESCE(SUM(d.nilai),0) FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id
                             WHERE t.unit_id=? AND t.jenis='masuk' AND t.status='disetujui' AND DATE_FORMAT(t.tanggal,'%Y-%m')=?", [$u['unit_id'], date('Y-m')]);
    $recent = rows("SELECT t.*, us.nama AS pembuat, (SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id=t.id) n,
                    (SELECT COALESCE(SUM(nilai),0) FROM transaksi_detail WHERE transaksi_id=t.id) total, uk.nama AS unit_nama
                    FROM transaksi t JOIN users us ON us.id=t.created_by JOIN unit_kerja uk ON uk.id=t.unit_id
                    WHERE t.unit_id IN ($ph) AND t.status<>'draft' ORDER BY t.id DESC LIMIT 8", $scope);
    $subs = $u['unit_level'] === 'unit' ? rows("SELECT uk.id, uk.nama, (SELECT COALESCE(SUM(nilai),0) FROM barang WHERE unit_id=uk.id) nilai,
                    (SELECT COUNT(*) FROM barang WHERE unit_id=uk.id AND stok>0) item,
                    (SELECT COUNT(*) FROM transaksi WHERE unit_id=uk.id AND status='menunggu') pending
                    FROM unit_kerja uk WHERE uk.parent_id=? ORDER BY uk.nama", [$u['unit_id']]) : [];
?>
  <div class="stats">
    <div class="stat accent"><div class="label">Jenis Barang Tersedia</div><div class="value"><?= angka($nItem) ?></div><div class="hint"><a href="<?= e(url('stok')) ?>">lihat stok</a></div></div>
    <div class="stat green"><div class="label">Nilai Persediaan</div><div class="value">Rp <?= rupiah($nilai, 0) ?></div><div class="hint"><?= e($u['unit_nama']) ?></div></div>
    <div class="stat amber"><div class="label">Menunggu Validasi</div><div class="value"><?= angka($pending) ?></div><div class="hint"><?= $u['role'] === 'admin' ? '<a href="' . e(url('validasi')) . '">validasi sekarang</a>' : 'transaksi Anda &amp; rekan' ?></div></div>
    <div class="stat blue"><div class="label">Pembelian Bulan Ini</div><div class="value">Rp <?= rupiah($beliBulan, 0) ?></div><div class="hint"><?= bulan_indo(date('n')) . ' ' . date('Y') ?></div></div>
  </div>

  <div class="actions" style="margin-bottom:18px">
    <a class="btn" href="<?= e(url('pembelian')) ?>"><?= icon('in') ?> Catat Pembelian</a>
    <a class="btn btn-light" href="<?= e(url('pengeluaran')) ?>"><?= icon('out') ?> Catat Pengeluaran</a>
  </div>

  <?php if ($subs): ?>
  <div class="card">
    <div class="card-head"><h2>Kondisi stok sub unit</h2></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>Sub Unit</th><th class="num">Jenis Barang</th><th class="num">Nilai (Rp)</th><th class="num">Menunggu</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subs as $s): ?>
          <tr><td><?= e($s['nama']) ?></td><td class="num"><?= angka($s['item']) ?></td><td class="num"><?= rupiah($s['nilai']) ?></td>
            <td class="num"><?= $s['pending'] ? '<span class="badge badge-amber">' . (int)$s['pending'] . '</span>' : '-' ?></td>
            <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('stok', ['unit' => $s['id']])) ?>">Lihat stok</a></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head"><h2>Transaksi terakhir</h2><a class="btn btn-light btn-sm" href="<?= e(url('transaksi')) ?>">Semua transaksi</a></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Unit</th><th>Oleh</th><th class="num">Item</th><th class="num">Nilai (Rp)</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $t): ?>
          <tr><td><a href="<?= e(url('transaksi_detail', ['id' => $t['id']])) ?>" class="mono"><?= e($t['nomor']) ?></a></td><td class="nowrap"><?= tgl_indo($t['tanggal']) ?></td>
            <td><?= $t['jenis'] === 'masuk' ? '<span class="badge badge-teal">Pembelian</span>' : '<span class="badge badge-blue">Pengeluaran</span>' ?></td>
            <td><?= e($t['unit_nama']) ?></td><td><?= e($t['pembuat']) ?></td><td class="num"><?= (int)$t['n'] ?></td>
            <td class="num"><?= $t['status'] === 'disetujui' || $t['jenis'] === 'masuk' ? rupiah($t['total']) : '-' ?></td><td><?= badge_status($t['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="8" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php include BASE_PATH . '/views/footer.php'; ?>
