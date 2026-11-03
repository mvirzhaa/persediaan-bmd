<?php
defined('BASE_PATH') or exit;

$skpdF = input_int('skpd');
$roleF = in_array(input('role'), ['admin_skpd', 'admin_unit', 'admin_sub', 'op_unit', 'op_sub'], true) ? input('role') : '';
$statF = in_array(input('status'), ['aktif', 'belum'], true) ? input('status') : '';
$q = trim((string)input('q', ''));

$where = ["u.role<>'superadmin'"];
$params = [];
if ($skpdF) { $where[] = 'COALESCE(u.skpd_id, uk.skpd_id)=?'; $params[] = $skpdF; }
$roleMap = [
    'admin_skpd' => "u.role='admin_skpd'",
    'admin_unit' => "u.role='admin' AND uk.level='unit'",
    'admin_sub' => "u.role='admin' AND uk.level='subunit'",
    'op_unit' => "u.role='operator' AND uk.level='unit'",
    'op_sub' => "u.role='operator' AND uk.level='subunit'",
];
if ($roleF) $where[] = $roleMap[$roleF];
if ($statF === 'aktif') $where[] = 'u.is_active=1 AND u.email IS NOT NULL';
if ($statF === 'belum') $where[] = '(u.is_active=0 OR u.email IS NULL)';
if ($q !== '') { $where[] = '(u.nama LIKE ? OR u.email LIKE ? OR uk.nama LIKE ? OR s.nama LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$w = implode(' AND ', $where);
$from = 'FROM users u LEFT JOIN unit_kerja uk ON uk.id=u.unit_id LEFT JOIN unit_kerja p ON p.id=uk.parent_id LEFT JOIN skpd s ON s.id=COALESCE(u.skpd_id, uk.skpd_id)';
$total = (int)val("SELECT COUNT(*) $from WHERE $w", $params);
$pg = paginate($total, 30);
$list = rows("SELECT u.*, uk.nama AS unit_nama, uk.level AS unit_level, p.nama AS parent_nama, s.nama AS skpd_nama, s.id AS sid
              $from WHERE $w
              ORDER BY s.nama, FIELD(u.role,'admin_skpd','admin','operator'), COALESCE(uk.parent_id, uk.id), uk.level, uk.nama, u.slot
              LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
$skpdList = rows('SELECT id, nama FROM skpd ORDER BY nama');

$pageTitle = 'Pengguna & Login As';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Pengguna &amp; Login As</h1>
    <p>Semua akun Admin SKPD, Admin/Operator Unit dan Sub Unit. Klik <b>Masuk sebagai</b> untuk membuka aplikasi persis seperti yang dilihat akun tersebut, lalu <b>Kembali ke Super Admin</b> dari bilah ungu di atas.</p>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <form class="filters" method="get">
      <input type="hidden" name="page" value="pengguna">
      <div class="field" style="min-width:260px"><label>SKPD</label><select name="skpd"><option value="">Semua SKPD</option>
        <?php foreach ($skpdList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $skpdF ? 'selected' : '' ?>><?= e($s['nama']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Peran</label><select name="role"><option value="">Semua peran</option>
        <?php foreach (['admin_skpd' => 'Admin SKPD', 'admin_unit' => 'Admin Unit', 'admin_sub' => 'Admin Sub Unit', 'op_unit' => 'Operator Unit', 'op_sub' => 'Operator Sub Unit'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $roleF === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Status</label><select name="status"><option value="">Semua</option>
        <option value="aktif" <?= $statF === 'aktif' ? 'selected' : '' ?>>Aktif</option><option value="belum" <?= $statF === 'belum' ? 'selected' : '' ?>>Belum aktif</option></select></div>
      <div class="field grow"><label>Cari</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nama, email, unit, SKPD…"></div>
      <button class="btn btn-light" type="submit">Terapkan</button>
    </form>
  </div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Akun</th><th>Peran</th><th>SKPD / Unit</th><th>Status</th><th>Login terakhir</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><b><?= e($a['nama']) ?></b><div class="small muted"><?= $a['email'] ? e($a['email']) : 'email belum diisi' ?></div></td>
          <td><?= $a['role'] === 'admin_skpd' ? '<span class="badge badge-gray">Admin SKPD</span>' : ($a['role'] === 'admin' ? '<span class="badge badge-teal">' : '<span class="badge badge-blue">') . e(role_label($a['role'], $a['unit_level'])) . '</span>' ?></td>
          <td class="small"><?= e($a['skpd_nama']) ?><?php if ($a['unit_nama']): ?><div class="muted"><?= $a['parent_nama'] ? e($a['parent_nama']) . ' › ' : '' ?><?= e($a['unit_nama']) ?></div><?php endif; ?></td>
          <td><?= ($a['is_active'] && $a['email'] && $a['password']) ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Belum aktif</span>' ?></td>
          <td class="small nowrap"><?= $a['last_login'] ? tgl_indo($a['last_login'], true) : '<span class="muted">-</span>' ?></td>
          <td class="act">
            <a class="btn btn-light btn-sm" href="<?= e(url('akun', ['id' => $a['id']])) ?>">Atur</a>
            <?= login_as_btn($a['id'], 'Masuk sebagai', 'btn btn-sm') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="6" class="empty">Tidak ada akun yang cocok.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($pg) ?>
</div>
<p class="small muted">Login As juga dapat dipakai untuk akun yang belum aktif. Semua tindakan selama Login As tercatat atas nama akun yang diperankan dan ditandai di Log Aktivitas.</p>
<?php include BASE_PATH . '/views/footer.php'; ?>
