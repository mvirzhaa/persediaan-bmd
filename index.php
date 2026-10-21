<?php
/**
 * Front controller: index.php?page=<nama>
 */
require __DIR__ . '/core/bootstrap.php';
require_login();

// halaman => peran yang diizinkan
$routes = [
    'dashboard'        => ['superadmin', 'admin_skpd', 'admin', 'operator'],
    'profil'           => ['superadmin', 'admin_skpd', 'admin', 'operator'],
    'skpd'             => ['superadmin'],
    'skpd_form'        => ['superadmin'],
    'kode'             => ['superadmin'],
    'pengaturan'       => ['superadmin'],
    'unit'             => ['superadmin', 'admin_skpd'],
    'unit_form'        => ['superadmin', 'admin_skpd'],
    'akun'             => ['superadmin', 'admin_skpd'],
    'stok'             => ['admin', 'operator'],
    'kartu'            => ['admin', 'operator'],
    'pembelian'        => ['admin', 'operator'],
    'pengeluaran'      => ['admin', 'operator'],
    'transaksi'        => ['admin', 'operator'],
    'transaksi_detail' => ['superadmin', 'admin_skpd', 'admin', 'operator'],
    'validasi'         => ['admin'],
    'laporan'          => ['superadmin', 'admin_skpd', 'admin'],
    'stock_opname'     => ['superadmin', 'admin_skpd', 'admin', 'operator'],
    'log'              => ['superadmin'],
    'panduan'          => ['superadmin', 'admin_skpd', 'admin', 'operator'],
];

$page = isset($_GET['page']) ? preg_replace('/[^a-z_]/', '', $_GET['page']) : 'dashboard';
if (!isset($routes[$page])) {
    http_response_code(404);
    $page = 'dashboard';
}
require_role(...$routes[$page]);
require __DIR__ . '/pages/' . $page . '.php';
