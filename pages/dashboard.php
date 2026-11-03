<?php
defined('BASE_PATH') or exit;
$u = user();
$pageTitle = 'Dashboard';

// Super Admin & Admin SKPD: dashboard dengan filter (SKPD, tahun, periode, jenis barang)
if (in_array($u['role'], ['superadmin', 'admin_skpd'], true)) {
    $f = dash_filter($u);
    $isSkpd = dash_is_skpd($f);
    $ring = dash_ringkasan($f);
    $bl = dash_belum_ringkas(dash_status_input($f));
    $nilaiF = array_sum(array_column($ring, 'akhir'));
    $tot = ['item' => 0, 'n_barang' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0, 'pending' => 0];
    foreach ($ring as $r) foreach ($tot as $k => $_) $tot[$k] += $r[$k];
    $semuaBaris = (bool)input('semua');
    $skpdList = $u['role'] === 'superadmin' ? rows('SELECT id, nama FROM skpd ORDER BY nama') : [];
    $subOpts = rows('SELECT id, kode, uraian FROM kode_barang WHERE level=6 ORDER BY kode');
    $filterAktif = $f['skpd'] || $f['sub'] || $f['periode'] !== 'ytd' || (int)$f['tahun'] !== (int)date('Y');
    $scopeNama = $f['scope']['skpd'] ? $f['scope']['skpd']['nama'] : 'seluruh SKPD';
}
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Selamat datang, <?= e($u['nama']) ?></h1>
    <p><?= e(role_label($u['role'], $u['unit_level'])) ?> · <?= tgl_indo(date('Y-m-d')) ?></p>
  </div>
</div>

<?php if (in_array($u['role'], ['superadmin', 'admin_skpd'], true)): ?>
  <form method="get" class="card no-print" id="fDash">
    <input type="hidden" name="page" value="dashboard">
    <div class="card-body">
      <div class="filters">
        <?php if ($u['role'] === 'superadmin'): ?>
          <div class="field grow" style="min-width:280px"><label>SKPD</label><select name="skpd">
            <option value="0">— Seluruh SKPD —</option>
            <?php foreach ($skpdList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$f['skpd'] ? 'selected' : '' ?>><?= e($s['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="field" style="min-width:110px"><label>Tahun</label><select name="tahun"><?php for ($y = (int)date('Y') + 1; $y >= 2020; $y--): ?><option <?= $y === (int)$f['tahun'] ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
        <div class="field" style="min-width:170px"><label>Periode</label><select name="periode" id="dPeriode">
          <?php foreach (dash_periode_opsi() as $k => $v): ?><option value="<?= $k ?>" <?= $f['periode'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select></div>
        <div class="field d-range" style="min-width:140px"><label>Dari</label><input type="date" name="dari" value="<?= e($f['dari']) ?>"></div>
        <div class="field d-range" style="min-width:140px"><label>Sampai</label><input type="date" name="sampai" value="<?= e($f['sampai']) ?>"></div>
        <div class="field" style="min-width:260px"><label>Jenis Barang</label><select name="sub"><option value="0">Semua sub rincian objek</option>
          <?php foreach ($subOpts as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$f['sub'] ? 'selected' : '' ?>><?= e($s['kode'] . ' ' . judul($s['uraian'])) ?></option><?php endforeach; ?>
        </select></div>
        <button class="btn" type="submit">Tampilkan</button>
        <?php if ($filterAktif): ?><a class="btn btn-light" href="<?= e(url('dashboard')) ?>">Reset</a><?php endif; ?>
      </div>
      <div class="filter-note">Kartu bertanda <b>&#9673;</b> dan tabel di bawah mengikuti filter: periode <b><?= tgl_indo($f['dari']) ?> s.d. <?= tgl_indo($f['sampai']) ?></b>, cakupan <b><?= e($scopeNama) ?></b>, jenis barang <b><?= e(dash_sub_label($f)) ?></b>. Klik angka jenis barang atau nilai untuk melihat rinciannya.</div>
    </div>
  </form>

  <div class="stats stats-5">
  <?php if ($u['role'] === 'superadmin'):
      $nSkpd = (int)val('SELECT COUNT(*) FROM skpd');
      $nUnit = (int)val("SELECT COUNT(*) FROM unit_kerja WHERE level='unit'");
      $nSub = (int)val("SELECT COUNT(*) FROM unit_kerja WHERE level='subunit'");
      $akunBelum = (int)val("SELECT COUNT(*) FROM users WHERE role='admin_skpd' AND (email IS NULL OR is_active=0)");
  ?>
    <div class="stat accent"><div class="label">SKPD</div><div class="value"><?= angka($nSkpd) ?></div><div class="hint"><?= angka($nUnit) ?> unit · <?= angka($nSub) ?> sub unit</div></div>
  <?php else:
      $nUnitA = 0; foreach ($ring as $r) if ($r['level'] === 'unit') $nUnitA++;
      $akunBelum = (int)val("SELECT COUNT(*) FROM users u JOIN unit_kerja uk ON uk.id=u.unit_id WHERE uk.skpd_id=? AND (u.email IS NULL OR u.is_active=0)", [(int)$u['skpd_id']]);
  ?>
    <div class="stat accent"><div class="label">Unit / Sub Unit</div><div class="value"><?= $nUnitA ?> / <?= count($ring) - $nUnitA ?></div><div class="hint"><a href="<?= e(url('unit')) ?>">kelola unit</a></div></div>
  <?php endif; ?>
    <a class="stat green" href="<?= e(dash_rinci_url($f, 'nilai', 0)) ?>" data-rinci title="Lihat rincian nilai persediaan">
      <div class="label">Nilai Persediaan &#9673;</div><div class="value">Rp <?= rupiah($nilaiF, 0) ?></div>
      <div class="hint">posisi <?= tgl_indo($f['sampai']) ?> · <span class="link">lihat rincian</span></div></a>
    <div class="stat amber"><div class="label">Menunggu Validasi</div><div class="value"><?= angka($tot['pending']) ?></div><div class="hint"><?= $isSkpd ? 'transaksi dari operator' : 'di seluruh unit' ?></div></div>
    <?php if ($u['role'] === 'superadmin'): ?>
      <div class="stat blue"><div class="label">Akun Admin SKPD Belum Aktif</div><div class="value"><?= angka($akunBelum) ?></div><div class="hint"><a href="<?= e(url('skpd')) ?>">atur email &amp; password</a></div></div>
    <?php else: ?>
      <div class="stat blue"><div class="label">Akun Belum Aktif</div><div class="value"><?= angka($akunBelum) ?></div><div class="hint">admin/operator unit &amp; sub unit</div></div>
    <?php endif; ?>
    <a class="stat red" href="<?= e(dash_rinci_url($f, 'belum')) ?>" data-rinci title="Lihat daftar <?= $isSkpd ? 'SKPD' : 'unit' ?> yang belum menginput">
      <div class="label"><?= $isSkpd ? 'SKPD' : 'Unit' ?> Belum Input Pembelian / Pemakaian &#9673;</div>
      <div class="value"><?= angka($bl['belum']) ?> <span class="of">/ <?= angka($bl['total']) ?></span></div>
      <div class="hint"><?= angka($bl['kosong']) ?> belum input sama sekali · <span class="link">lihat daftar</span></div></a>
  </div>

  <?php
  $rows = $ring;
  if ($isSkpd) {
      uasort($rows, function ($a, $b) { return [$b['akhir'], $b['item']] <=> [$a['akhir'], $a['item']]; });
      if (!$semuaBaris) $rows = array_slice($rows, 0, 12, true);
  }
  $max = 1;
  foreach ($rows as $r) $max = max($max, (float)$r['akhir']);
  $lapUrl = url('laporan', array_merge(['jenis' => 'mutasi'], dash_qs($f)));
  ?>
  <div class="card">
    <div class="card-head">
      <h2><?php if ($isSkpd): ?>Nilai persediaan per SKPD<?= $semuaBaris ? '' : ' (12 terbesar)' ?><?php else: ?>Ringkasan per unit &amp; sub unit<?= $u['role'] === 'superadmin' ? ' — ' . e($scopeNama) : '' ?><?php endif; ?></h2>
      <div class="actions">
        <?php if ($isSkpd && count($ring) > 12): ?>
          <a class="btn btn-light btn-sm" href="<?= e(url('dashboard', array_merge(dash_qs($f), $semuaBaris ? [] : ['semua' => 1]))) ?>"><?= $semuaBaris ? 'Tampilkan 12 terbesar' : 'Tampilkan semua (' . count($ring) . ')' ?></a>
        <?php endif; ?>
        <a class="btn btn-light btn-sm" href="<?= e(dash_rinci_url($f, 'nilai', 0)) ?>" data-rinci>Rincian nilai</a>
        <a class="btn btn-light btn-sm" href="<?= e($lapUrl) ?>">Lihat laporan</a>
      </div>
    </div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th><?= $isSkpd ? 'SKPD' : 'Unit / Sub Unit' ?></th><th class="num">Jenis Barang</th><th class="num">Penerimaan (Rp)</th><th class="num">Pengeluaran (Rp)</th><th class="num">Nilai (Rp)</th>
          <?= $isSkpd ? '<th style="width:22%"></th>' : '<th class="num">Menunggu</th>' ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['level'] === 'subunit' ? 'row-sub' : '' ?>">
            <td><?php if ($isSkpd && $u['role'] === 'superadmin'): ?><a class="plain-link" href="<?= e(url('dashboard', array_merge(dash_qs($f), ['skpd' => $r['id']]))) ?>" title="Tampilkan unit &amp; sub unit SKPD ini"><?= e($r['nama']) ?></a>
              <?php else: ?><?= $r['level'] === 'subunit' ? '↳ ' : '<b>' ?><?= e($r['nama']) ?><?= $r['level'] === 'unit' ? '</b>' : '' ?><?php endif; ?></td>
            <td class="num"><?php if ($r['n_barang']): ?><a class="cell-link" href="<?= e(dash_rinci_url($f, 'barang', $r['id'])) ?>" data-rinci title="Lihat rincian barang"><?= angka($r['item']) ?></a><?php else: ?><span class="muted">0</span><?php endif; ?></td>
            <td class="num"><?= $r['masuk'] ? rupiah($r['masuk']) : '<span class="muted">-</span>' ?></td>
            <td class="num"><?= $r['keluar'] ? rupiah($r['keluar']) : '<span class="muted">-</span>' ?></td>
            <td class="num"><?php if ($r['n_barang']): ?><a class="cell-link" href="<?= e(dash_rinci_url($f, 'nilai', $r['id'])) ?>" data-rinci title="Lihat rincian nilai"><?= rupiah($r['akhir']) ?></a><?php else: ?><span class="muted"><?= rupiah(0) ?></span><?php endif; ?></td>
            <?php if ($isSkpd): ?>
              <td><div class="bar"><span style="width:<?= round(max(0, (float)$r['akhir']) / $max * 100, 1) ?>%"></span></div></td>
            <?php else: ?>
              <td class="num"><?= $r['pending'] ? '<span class="badge badge-amber">' . (int)$r['pending'] . '</span>' : '-' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= $isSkpd ? 'Belum ada SKPD.' : 'Belum ada unit.' ?><?php if ($u['role'] === 'admin_skpd'): ?> <a href="<?= e(url('unit_form')) ?>">Tambah unit</a><?php endif; ?></td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><tr>
          <td>Jumlah <?= $isSkpd ? 'seluruh SKPD' : 'seluruh unit &amp; sub unit' ?></td>
          <td class="num"><?php if ($tot['n_barang']): ?><a class="cell-link" href="<?= e(dash_rinci_url($f, 'barang', 0)) ?>" data-rinci><?= angka($tot['item']) ?></a><?php else: ?>0<?php endif; ?></td>
          <td class="num"><?= rupiah($tot['masuk']) ?></td>
          <td class="num"><?= rupiah($tot['keluar']) ?></td>
          <td class="num"><?php if ($tot['n_barang']): ?><a class="cell-link" href="<?= e(dash_rinci_url($f, 'nilai', 0)) ?>" data-rinci><?= rupiah($tot['akhir']) ?></a><?php else: ?><?= rupiah(0) ?><?php endif; ?></td>
          <td class="num"><?= $isSkpd ? '' : ($tot['pending'] ? '<span class="badge badge-amber">' . (int)$tot['pending'] . '</span>' : '-') ?></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
<?php
$pageScript = <<<'JS'
<script>
(function(){
  var p = document.getElementById('dPeriode');
  if (!p) return;
  function t(){ document.querySelectorAll('.d-range').forEach(function(el){ el.style.display = p.value === 'custom' ? '' : 'none'; }); }
  p.addEventListener('change', t); t();
})();
</script>
JS;
?>

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
