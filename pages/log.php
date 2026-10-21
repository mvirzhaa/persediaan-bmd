<?php
defined('BASE_PATH') or exit;
$q = trim((string)input('q', ''));
$where = '1=1';
$params = [];
if ($q !== '') { $where = '(l.aksi LIKE ? OR l.keterangan LIKE ? OR u.nama LIKE ? OR u.email LIKE ?)'; $params = ["%$q%", "%$q%", "%$q%", "%$q%"]; }
$total = (int)val("SELECT COUNT(*) FROM log_aktivitas l LEFT JOIN users u ON u.id=l.user_id WHERE $where", $params);
$pg = paginate($total, 50);
$list = rows("SELECT l.*, u.nama, u.email FROM log_aktivitas l LEFT JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
$pageTitle = 'Log Aktivitas';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head"><div><h1>Log Aktivitas</h1><p>Jejak login, perubahan data master, transaksi, validasi, dan stock opname.</p></div></div>
<div class="card">
  <div class="card-head"><form class="filters" method="get"><input type="hidden" name="page" value="log">
    <div class="field grow"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari aksi, keterangan, pengguna…"></div><button class="btn btn-light">Cari</button></form></div>
  <div class="card-body tight table-wrap">
    <table class="tbl compact">
      <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Keterangan</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($list as $l): ?>
        <tr><td class="nowrap small"><?= e(date('d/m/Y H:i:s', strtotime($l['created_at']))) ?></td><td class="small"><?= e($l['nama'] ?: '-') ?><div class="muted"><?= e($l['email']) ?></div></td>
          <td><span class="badge badge-gray"><?= e($l['aksi']) ?></span></td><td class="small"><?= e($l['keterangan']) ?></td><td class="small mono"><?= e($l['ip']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="5" class="empty">Belum ada log.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($pg) ?>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
