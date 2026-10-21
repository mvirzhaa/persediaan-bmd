<?php
defined('BASE_PATH') or exit;
$u = user();
$scopeList = unit_scope_list($u);
$scopeIds = array_map('intval', array_column($scopeList, 'id'));

$jenis = in_array(input('jenis'), ['masuk', 'keluar'], true) ? input('jenis') : '';
$status = in_array(input('status'), ['menunggu', 'disetujui', 'ditolak'], true) ? input('status') : '';
$unitF = input_int('unit');
$dari = valid_date((string)input('dari', '')) ? input('dari') : '';
$sampai = valid_date((string)input('sampai', '')) ? input('sampai') : '';
$q = trim((string)input('q', ''));

$where = ["t.status<>'draft'", 't.unit_id IN (' . in_ph($scopeIds) . ')'];
$params = $scopeIds;
if ($jenis) { $where[] = 't.jenis=?'; $params[] = $jenis; }
if ($status) { $where[] = 't.status=?'; $params[] = $status; }
if ($unitF && in_array($unitF, $scopeIds, true)) { $where[] = 't.unit_id=?'; $params[] = $unitF; }
if ($dari) { $where[] = 't.tanggal>=?'; $params[] = $dari; }
if ($sampai) { $where[] = 't.tanggal<=?'; $params[] = $sampai; }
if ($q !== '') { $where[] = '(t.nomor LIKE ? OR t.no_dokumen LIKE ? OR t.keterangan LIKE ? OR EXISTS(SELECT 1 FROM transaksi_detail x WHERE x.transaksi_id=t.id AND x.nama_barang LIKE ?))'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM transaksi t WHERE $w", $params);
$pg = paginate($total, 25);
$list = rows("SELECT t.*, uk.nama AS unit_nama, us.nama AS pembuat, us.role AS pembuat_role,
                (SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id=t.id) n,
                (SELECT COALESCE(SUM(nilai),0) FROM transaksi_detail WHERE transaksi_id=t.id) total
              FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id JOIN users us ON us.id=t.created_by
              WHERE $w ORDER BY t.tanggal DESC, t.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

$pageTitle = 'Riwayat Transaksi';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div><h1>Riwayat Transaksi</h1><p>Pembelian dan pengeluaran barang di <?= e($u['unit_nama']) ?><?= count($scopeIds) > 1 ? ' dan sub unit di bawahnya' : '' ?>.</p></div>
</div>
<div class="tabs">
  <?php foreach (['' => 'Semua', 'masuk' => 'Pembelian', 'keluar' => 'Pengeluaran'] as $k => $v): ?>
    <a class="<?= $jenis === $k ? 'active' : '' ?>" href="<?= e(url('transaksi', array_filter(['jenis' => $k, 'status' => $status]))) ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="card-head">
    <form class="filters" method="get">
      <input type="hidden" name="page" value="transaksi"><input type="hidden" name="jenis" value="<?= e($jenis) ?>">
      <div class="field"><label>Status</label><select name="status"><option value="">Semua</option>
        <?php foreach (['menunggu' => 'Menunggu Validasi', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'] as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <?php if (count($scopeList) > 1): ?>
      <div class="field"><label>Unit</label><select name="unit"><option value="">Semua</option>
        <?php foreach ($scopeList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $unitF ? 'selected' : '' ?>><?= e($s['nama']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field" style="min-width:140px"><label>Dari</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
      <div class="field" style="min-width:140px"><label>Sampai</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
      <div class="field grow"><label>Cari</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nomor, dokumen, nama barang…"></div>
      <button class="btn btn-light" type="submit">Terapkan</button>
    </form>
  </div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Unit</th><th>No. Dokumen</th><th>Diinput oleh</th><th class="num">Item</th><th class="num">Nilai (Rp)</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($list as $t): ?>
        <tr>
          <td><a class="mono" href="<?= e(url('transaksi_detail', ['id' => $t['id']])) ?>"><?= e($t['nomor']) ?></a></td>
          <td class="nowrap"><?= tgl_indo($t['tanggal']) ?></td>
          <td><?= $t['jenis'] === 'masuk' ? '<span class="badge badge-teal">' . ($t['sumber'] === 'saldo_awal' ? 'Saldo Awal' : 'Pembelian') . '</span>' : '<span class="badge badge-blue">Pengeluaran</span>' ?></td>
          <td><?= e($t['unit_nama']) ?></td>
          <td class="small"><?= e($t['no_dokumen'] ?: '-') ?><?= $t['dokumen_file'] ? ' 📎' : '' ?></td>
          <td class="small"><?= e($t['pembuat']) ?></td>
          <td class="num"><?= (int)$t['n'] ?></td>
          <td class="num"><?= ($t['jenis'] === 'keluar' && $t['status'] !== 'disetujui') ? '-' : rupiah($t['total']) ?></td>
          <td><?= badge_status($t['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="9" class="empty">Tidak ada transaksi.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($pg) ?>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
