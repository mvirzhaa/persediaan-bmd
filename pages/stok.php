<?php
defined('BASE_PATH') or exit;
$u = user();
$scopeList = unit_scope_list($u);
$scopeIds = array_map('intval', array_column($scopeList, 'id'));
$unitId = input_int('unit', (int)$u['unit_id']);
if (!in_array($unitId, $scopeIds, true)) $unitId = (int)$u['unit_id'];
$unit = row('SELECT * FROM unit_kerja WHERE id=?', [$unitId]);

$q = trim((string)input('q', ''));
$sub = input_int('sub');
$habis = input('habis') ? 1 : 0;
$where = ['b.unit_id=?'];
$params = [$unitId];
if (!$habis) $where[] = 'b.stok>0';
if ($q !== '') { $where[] = '(b.nama_barang LIKE ? OR k.uraian LIKE ? OR k.kode LIKE ?)'; array_push($params, "%$q%", "%$q%", "$q%"); }
if ($sub) { $where[] = 'k.parent_id=?'; $params[] = $sub; }
$w = implode(' AND ', $where);
$sql = "SELECT b.*, k.kode, k.uraian AS kode_uraian, p.uraian AS sub_uraian,
          (SELECT COALESCE(SUM(d.jumlah),0) FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id WHERE d.barang_id=b.id AND t.jenis='keluar' AND t.status='menunggu') dipesan
        FROM barang b JOIN kode_barang k ON k.id=b.kode_barang_id JOIN kode_barang p ON p.id=k.parent_id
        WHERE $w ORDER BY k.kode, b.nama_barang";

if (input('export') === 'xlsx') {
    $data = rows($sql, $params);
    $x = new XlsxWriter();
    $x->addSheet('Stok');
    $x->set(1, 1, 'DAFTAR STOK PERSEDIAAN', 6)->merge(1, 1, 1, 9);
    $x->set(2, 1, $unit['nama'] . ' — per ' . tgl_indo(date('Y-m-d')), 9)->merge(2, 1, 2, 9);
    $x->row(4, ['No', 'Kode Barang', 'Sub Sub Rincian Objek', 'Nama Barang', 'Satuan', 'Stok', 'Menunggu Keluar', 'Harga Rata-rata', 'Nilai (Rp)'], 2);
    $r = 5; $tot = 0;
    foreach ($data as $i => $b) {
        $avg = (float)$b['stok'] > 0 ? (float)$b['nilai'] / (float)$b['stok'] : 0;
        $x->row($r++, [[$i + 1, 14], [$b['kode'], 3], [$b['kode_uraian'], 3], [$b['nama_barang'], 10], [$b['satuan'], 3], [(float)$b['stok'], 8], [(float)$b['dipesan'], 8], [round($avg, 2), 4], [(float)$b['nilai'], 4]]);
        $tot += (float)$b['nilai'];
    }
    $x->row($r, [['JUMLAH', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], [$tot, 5]]);
    $x->merge($r, 1, $r, 8);
    foreach ([1 => 5, 2 => 20, 3 => 28, 4 => 46, 5 => 9, 6 => 9, 7 => 11, 8 => 15, 9 => 17] as $c => $wd) $x->width($c, $wd);
    $x->landscape()->download('stok-' . preg_replace('/\W+/', '-', strtolower($unit['nama'])) . '-' . date('Ymd') . '.xlsx');
}

$total = (int)val("SELECT COUNT(*) FROM barang b JOIN kode_barang k ON k.id=b.kode_barang_id WHERE $w", $params);
$sum = (float)val("SELECT COALESCE(SUM(b.nilai),0) FROM barang b JOIN kode_barang k ON k.id=b.kode_barang_id WHERE $w", $params);
$pg = paginate($total, 50);
$list = rows($sql . " LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
$subs = rows('SELECT DISTINCT p.id, p.kode, p.uraian FROM barang b JOIN kode_barang k ON k.id=b.kode_barang_id JOIN kode_barang p ON p.id=k.parent_id WHERE b.unit_id=? ORDER BY p.kode', [$unitId]);

$pageTitle = 'Stok Barang';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Stok Barang</h1>
    <p><?= e($unit['nama']) ?><?= $unitId !== (int)$u['unit_id'] ? ' <span class="badge badge-blue">sub unit</span>' : '' ?> · <?= angka($total) ?> barang · nilai Rp <?= rupiah($sum) ?></p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="<?= e('?' . http_build_query(array_merge($_GET, ['export' => 'xlsx']))) ?>"><?= icon('excel') ?> Ekspor Excel</a>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <form class="filters" method="get">
      <input type="hidden" name="page" value="stok">
      <?php if (count($scopeList) > 1): ?>
        <div class="field"><label>Lokasi</label><select name="unit" data-autosubmit>
          <?php foreach ($scopeList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $unitId ? 'selected' : '' ?>><?= $s['level'] === 'subunit' ? '↳ ' : '' ?><?= e($s['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="field"><label>Sub Rincian Objek</label><select name="sub" data-autosubmit><option value="">Semua</option>
        <?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $sub ? 'selected' : '' ?>><?= e($s['kode'] . ' ' . judul($s['uraian'])) ?></option><?php endforeach; ?></select></div>
      <div class="field grow"><label>Cari</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nama barang / kode…"></div>
      <label class="check" style="margin-bottom:9px"><input type="checkbox" name="habis" value="1" <?= $habis ? 'checked' : '' ?> data-autosubmit> Tampilkan stok habis</label>
      <button class="btn btn-light" type="submit">Terapkan</button>
    </form>
  </div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama Barang</th><th>Satuan</th><th class="num">Stok</th><th class="num">Menunggu Keluar</th><th class="num">Harga Rata-rata</th><th class="num">Nilai (Rp)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $b): $avg = (float)$b['stok'] > 0 ? (float)$b['nilai'] / (float)$b['stok'] : 0; ?>
        <tr>
          <td><span class="mono"><?= e($b['kode']) ?></span><div class="small muted"><?= e($b['kode_uraian']) ?></div></td>
          <td><?= e($b['nama_barang']) ?></td>
          <td><?= e($b['satuan']) ?></td>
          <td class="num"><b><?= angka($b['stok']) ?></b></td>
          <td class="num"><?= (float)$b['dipesan'] > 0 ? '<span class="badge badge-amber">' . angka($b['dipesan']) . '</span>' : '-' ?></td>
          <td class="num"><?= rupiah($avg) ?></td>
          <td class="num"><?= rupiah($b['nilai']) ?></td>
          <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('kartu', ['id' => $b['id']])) ?>">Kartu Stok</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="8" class="empty">Belum ada stok barang<?= $q ? ' yang cocok' : '' ?>.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($list): ?><tfoot><tr><td colspan="6">Total nilai (semua halaman)</td><td class="num"><?= rupiah($sum) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
  <?= pager_html($pg) ?>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
