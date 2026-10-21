<?php
defined('BASE_PATH') or exit;
$u = user();
if ($u['role'] === 'superadmin') {
    $skpdList = rows('SELECT id, nama FROM skpd ORDER BY nama');
    $sid = input_int('skpd', $skpdList ? (int)$skpdList[0]['id'] : 0);
} else {
    $sid = (int)$u['skpd_id'];
}
$skpd = row('SELECT * FROM skpd WHERE id=?', [$sid]);

if (is_post() && input('aksi') === 'hapus') {
    csrf_check();
    $id = input_int('id');
    $unit = row('SELECT * FROM unit_kerja WHERE id=? AND skpd_id=?', [$id, $sid]);
    if (!$unit) flash('error', 'Unit tidak ditemukan.');
    elseif (unit_punya_data($id)) flash('error', '"' . $unit['nama'] . '" tidak dapat dihapus karena sudah memiliki data persediaan/transaksi.');
    else {
        $ids = [$id];
        foreach (rows('SELECT id FROM unit_kerja WHERE parent_id=?', [$id]) as $r) $ids[] = (int)$r['id'];
        hapus_draft_unit($ids);
        exec_sql('DELETE FROM unit_kerja WHERE id=?', [$id]);
        log_aktivitas('unit_hapus', $unit['nama']);
        flash('success', '"' . $unit['nama'] . '" beserta akunnya telah dihapus.');
    }
    redirect(url('unit', ['skpd' => $sid]));
}

$units = $skpd ? rows("SELECT uk.*,
            (SELECT COUNT(*) FROM users WHERE unit_id=uk.id AND is_active=1 AND email IS NOT NULL) n_aktif,
            (SELECT COUNT(*) FROM users WHERE unit_id=uk.id) n_akun,
            (SELECT COALESCE(SUM(nilai),0) FROM barang WHERE unit_id=uk.id) nilai
          FROM unit_kerja uk WHERE uk.skpd_id=? ORDER BY uk.nama", [$sid]) : [];
$tree = [];
foreach ($units as $x) if ($x['level'] === 'unit') $tree[$x['id']] = ['u' => $x, 'subs' => []];
foreach ($units as $x) if ($x['level'] === 'subunit' && isset($tree[$x['parent_id']])) $tree[$x['parent_id']]['subs'][] = $x;

$pageTitle = 'Unit & Sub Unit';
include BASE_PATH . '/views/header.php';

function akun_badge($x)
{
    $n = (int)$x['n_aktif'];
    $t = (int)$x['n_akun'];
    return $n >= $t && $t > 0 ? '<span class="badge badge-green">' . $n . '/' . $t . ' aktif</span>' : '<span class="badge badge-amber">' . $n . '/' . $t . ' aktif</span>';
}
?>
<div class="page-head">
  <div>
    <h1>Unit &amp; Sub Unit</h1>
    <p><?= $skpd ? e($skpd['nama']) : 'Pilih SKPD' ?> — setiap unit/sub unit otomatis memiliki 1 akun Admin dan 1 akun Operator.</p>
  </div>
  <div class="actions">
    <?php if ($u['role'] === 'superadmin'): ?>
      <form method="get" class="filters"><input type="hidden" name="page" value="unit">
        <div class="field" style="min-width:320px"><select name="skpd" data-autosubmit>
          <?php foreach ($skpdList as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $x['id'] == $sid ? 'selected' : '' ?>><?= e($x['nama']) ?></option><?php endforeach; ?>
        </select></div></form>
    <?php endif; ?>
    <?php if ($skpd): ?><a class="btn" href="<?= e(url('unit_form', ['skpd' => $sid])) ?>"><?= icon('plus') ?> Tambah Unit</a><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Unit / Sub Unit</th><th>PIC</th><th>Akun</th><th class="num">Nilai Persediaan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tree as $node): $x = $node['u']; ?>
        <tr class="row-group">
          <td><?= e($x['nama']) ?> <?php if ($x['kode']): ?><span class="muted mono">(<?= e($x['kode']) ?>)</span><?php endif; ?><div class="small muted" style="font-weight:400"><?= count($node['subs']) ?> sub unit<?= $x['lokasi'] ? ' · ' . e($x['lokasi']) : '' ?></div></td>
          <td class="small" style="font-weight:400"><?= e($x['pic'] ?: '-') ?></td>
          <td><?= akun_badge($x) ?></td>
          <td class="num"><?= rupiah($x['nilai']) ?></td>
          <td class="act">
            <a class="btn btn-light btn-sm" href="<?= e(url('unit_form', ['id' => $x['id']])) ?>">Edit &amp; Akun</a>
            <a class="btn btn-light btn-sm" href="<?= e(url('unit_form', ['parent' => $x['id']])) ?>"><?= icon('plus') ?> Sub Unit</a>
            <form method="post" class="inline-form" data-confirm="Hapus unit ini beserta seluruh sub unit & akunnya?"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
          </td>
        </tr>
        <?php foreach ($node['subs'] as $sx): ?>
          <tr class="row-sub">
            <td>↳ <?= e($sx['nama']) ?> <?php if ($sx['kode']): ?><span class="muted mono">(<?= e($sx['kode']) ?>)</span><?php endif; ?></td>
            <td class="small"><?= e($sx['pic'] ?: '-') ?></td>
            <td><?= akun_badge($sx) ?></td>
            <td class="num"><?= rupiah($sx['nilai']) ?></td>
            <td class="act">
              <a class="btn btn-light btn-sm" href="<?= e(url('unit_form', ['id' => $sx['id']])) ?>">Edit &amp; Akun</a>
              <form method="post" class="inline-form" data-confirm="Hapus sub unit ini beserta akunnya?"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$sx['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$tree): ?><tr><td colspan="5" class="empty">Belum ada unit. Klik <b>Tambah Unit</b> untuk memulai.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
