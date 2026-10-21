<?php
/** @var string $pageTitle */
$u = user();
$curPage = $_GET['page'] ?? 'dashboard';

function icon($n)
{
    $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'building' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M8 7h4M8 11h4M8 15h4M2 21h20"/>',
        'tree' => '<path d="M5 3h5v5H5zM14 16h5v5h-5zM14 9h5v5h-5zM7.5 8v10.5H14M7.5 11.5H14"/>',
        'code' => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8"/>',
        'in' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'out' => '<path d="M12 15V3M7 8l5-5 5 5M4 21h16"/>',
        'check' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'report' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8M8 17h5"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'excel' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M9 12l6 6M15 12l-6 6"/>',
        'print' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5h.01"/>',
        'clip' => '<path d="m21.4 11-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

$menu = [];
$menu[] = ['dashboard', 'Dashboard', 'home'];
if ($u['role'] === 'superadmin') {
    $menu[] = ['skpd', 'SKPD & Akun Admin', 'building'];
    $menu[] = ['unit', 'Unit & Sub Unit', 'tree'];
    $menu[] = ['kode', 'Master Kode Barang', 'code'];
    $menu[] = ['stock_opname', 'Stock Opname', 'lock'];
    $menu[] = ['laporan', 'Laporan', 'report'];
    $menu[] = ['pengaturan', 'Pengaturan', 'gear'];
    $menu[] = ['log', 'Log Aktivitas', 'list'];
} elseif ($u['role'] === 'admin_skpd') {
    $menu[] = ['unit', 'Unit & Sub Unit', 'tree'];
    $menu[] = ['stock_opname', 'Stock Opname', 'lock'];
    $menu[] = ['laporan', 'Laporan', 'report'];
} else {
    $menu[] = ['stok', 'Stok Barang', 'box'];
    $menu[] = ['pembelian', 'Pembelian Barang', 'in'];
    $menu[] = ['pengeluaran', 'Pengeluaran Barang', 'out'];
    if ($u['role'] === 'admin') {
        $nPending = (int)val("SELECT COUNT(*) FROM transaksi WHERE status='menunggu' AND unit_id IN (" . in_ph(unit_scope_ids($u)) . ")", unit_scope_ids($u));
        $menu[] = ['validasi', 'Validasi', 'check', $nPending];
    }
    $menu[] = ['transaksi', 'Riwayat Transaksi', 'list'];
    $menu[] = ['stock_opname', 'Stock Opname', 'lock'];
    if ($u['role'] === 'admin') $menu[] = ['laporan', 'Laporan', 'report'];
}
$activeMap = ['skpd_form' => 'skpd', 'unit_form' => 'unit', 'akun' => ($u['role'] === 'superadmin' ? 'skpd' : 'unit'), 'kartu' => 'stok', 'transaksi_detail' => 'transaksi', 'kode_import' => 'kode'];
$active = $activeMap[$curPage] ?? $curPage;
$scopeLabel = $u['role'] === 'superadmin' ? 'Seluruh SKPD' : ($u['role'] === 'admin_skpd' ? $u['skpd_nama'] : $u['unit_nama']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(($pageTitle ?? 'Dashboard') . ' · ' . APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">BMD</div>
      <div>
        <div class="brand-name"><?= e(APP_NAME) ?></div>
        <div class="brand-sub"><?= e(setting('nama_pemda', 'Pemerintah Daerah')) ?></div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach ($menu as $m): ?>
        <a href="<?= e(url($m[0])) ?>" class="<?= $active === $m[0] ? 'active' : '' ?>">
          <?= icon($m[2]) ?><span><?= e($m[1]) ?></span>
          <?php if (!empty($m[3])): ?><b class="count"><?= (int)$m[3] ?></b><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="nav nav-bottom">
      <a href="<?= e(url('panduan')) ?>" class="<?= $active === 'panduan' ? 'active' : '' ?>"><?= icon('help') ?><span>Panduan</span></a>
      <a href="<?= e(url('profil')) ?>" class="<?= $active === 'profil' ? 'active' : '' ?>"><?= icon('user') ?><span>Profil &amp; Password</span></a>
      <a href="logout.php"><?= icon('logout') ?><span>Keluar</span></a>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn only-mobile" type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="scope">
        <span class="scope-label"><?= e(role_label($u['role'], $u['unit_level'])) ?></span>
        <span class="scope-name"><?= e($scopeLabel) ?></span>
        <?php if ($u['role'] !== 'superadmin' && $u['role'] !== 'admin_skpd'): ?><span class="scope-skpd"><?= e($u['skpd_nama']) ?></span><?php endif; ?>
      </div>
      <div class="who">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($u['nama'], 0, 1))) ?></div>
        <div class="who-text"><b><?= e($u['nama']) ?></b><small><?= e($u['email']) ?></small></div>
      </div>
    </header>
    <div class="nav-backdrop" onclick="document.body.classList.remove('nav-open')"></div>
    <main class="content">
      <?php if (is_unit_user() && ($soLock = so_aktif($u['unit_id'])) && $active !== 'stock_opname'): ?>
        <div class="alert alert-warning lock-banner">🔒 <b>Stock opname sedang berlangsung</b> (<?= e($soLock['nomor']) ?>, posisi <?= tgl_indo($soLock['tanggal']) ?>). Pembelian, pengeluaran, dan validasi di <?= e($u['unit_nama']) ?> dikunci sampai stock opname selesai.
          <a href="<?= e(url('stock_opname', ['id' => $soLock['id']])) ?>">Buka stock opname →</a></div>
      <?php endif; ?>
      <?php foreach (flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] /* sudah di-escape saat dibuat */ ?></div>
      <?php endforeach; ?>
