<?php
defined('BASE_PATH') or exit;

if (is_post() && input('aksi') === 'hapus') {
    csrf_check();
    $id = input_int('id');
    $s = row('SELECT * FROM skpd WHERE id=?', [$id]);
    if (!$s) flash('error', 'SKPD tidak ditemukan.');
    elseif (skpd_punya_data($id)) flash('error', 'SKPD "' . $s['nama'] . '" tidak dapat dihapus karena sudah memiliki data persediaan/transaksi.');
    else {
        $uids = array_map('intval', array_column(rows('SELECT id FROM unit_kerja WHERE skpd_id=?', [$id]), 'id'));
        hapus_draft_unit($uids);
        exec_sql('DELETE FROM skpd WHERE id=?', [$id]);
        log_aktivitas('skpd_hapus', $s['nama']);
        flash('success', 'SKPD "' . $s['nama'] . '" beserta unit dan akunnya telah dihapus.');
    }
    redirect(url('skpd'));
}

$q = trim((string)input('q', ''));
$where = '1=1';
$params = [];
if ($q !== '') { $where = '(s.nama LIKE ? OR s.kode LIKE ? OR s.pic LIKE ?)'; $params = ["%$q%", "%$q%", "%$q%"]; }
$total = (int)val("SELECT COUNT(*) FROM skpd s WHERE $where", $params);
$pg = paginate($total, 25);
$list = rows("SELECT s.*,
                (SELECT COUNT(*) FROM unit_kerja WHERE skpd_id=s.id AND level='unit') n_unit,
                (SELECT COUNT(*) FROM unit_kerja WHERE skpd_id=s.id AND level='subunit') n_sub,
                (SELECT COUNT(*) FROM users WHERE skpd_id=s.id AND role='admin_skpd' AND is_active=1 AND email IS NOT NULL) n_aktif,
                (SELECT COALESCE(SUM(b.nilai),0) FROM barang b JOIN unit_kerja uk ON uk.id=b.unit_id WHERE uk.skpd_id=s.id) nilai
              FROM skpd s WHERE $where ORDER BY (s.kode IS NULL), s.kode+0, s.kode, s.nama LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

$pageTitle = 'SKPD & Akun Admin';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>SKPD &amp; Akun Admin</h1>
    <p>Setiap SKPD baru otomatis memiliki 2 akun Admin SKPD. Lengkapi email &amp; password akun agar dapat digunakan.</p>
  </div>
  <div class="actions"><a class="btn" href="<?= e(url('skpd_form')) ?>"><?= icon('plus') ?> Tambah SKPD</a></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="filters" method="get">
      <input type="hidden" name="page" value="skpd">
      <div class="field grow"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama / kode / PIC SKPD…"></div>
      <button class="btn btn-light" type="submit">Cari</button>
    </form>
  </div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama SKPD</th><th>PIC / Kontak</th><th class="num">Unit</th><th class="num">Sub Unit</th><th>Admin SKPD</th><th class="num">Nilai Persediaan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $s): ?>
        <tr>
          <td class="mono"><?= e($s['kode'] ?: '-') ?></td>
          <td><b><?= e($s['nama']) ?></b><?php if ($s['lokasi']): ?><div class="small muted"><?= e($s['lokasi']) ?></div><?php endif; ?></td>
          <td class="small"><?= e($s['pic'] ?: '-') ?><?php if ($s['email'] || $s['telp']): ?><div class="muted"><?= e(trim($s['email'] . ' ' . $s['telp'])) ?></div><?php endif; ?></td>
          <td class="num"><?= (int)$s['n_unit'] ?></td>
          <td class="num"><?= (int)$s['n_sub'] ?></td>
          <td><?= (int)$s['n_aktif'] === 2 ? '<span class="badge badge-green">2 aktif</span>' : '<span class="badge badge-amber">' . (int)$s['n_aktif'] . '/2 aktif</span>' ?></td>
          <td class="num"><?= rupiah($s['nilai']) ?></td>
          <td class="act">
            <a class="btn btn-light btn-sm" href="<?= e(url('skpd_form', ['id' => $s['id']])) ?>">Edit &amp; Akun</a>
            <a class="btn btn-light btn-sm" href="<?= e(url('unit', ['skpd' => $s['id']])) ?>">Unit</a>
            <form method="post" class="inline-form" data-confirm="Hapus SKPD ini beserta seluruh unit & akunnya?">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="8" class="empty">Belum ada SKPD.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($pg) ?>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
