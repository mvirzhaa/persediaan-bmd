<?php
/**
 * KONFIGURASI APLIKASI
 * Sesuaikan bagian database dengan server Anda (XAMPP/Laragon default: root tanpa password).
 * Alternatif: buat file config.local.php berisi define('DB_PASS','rahasia'); dst.
 * Nilai di config.local.php akan dipakai lebih dulu.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
function _cfg($k, $v) { if (!defined($k)) define($k, $v); }

_cfg('DB_HOST', 'localhost');
_cfg('DB_USER', 'root');
_cfg('DB_PASS', '');
_cfg('DB_NAME', 'persediaan_bmd');
_cfg('DB_PORT', 3306);

_cfg('APP_NAME', 'SIPERSEDIAAN BMD');
_cfg('APP_DESC', 'Sistem Informasi Persediaan Barang Milik Daerah');

// Upload dokumen
_cfg('UPLOAD_DIR', __DIR__ . '/uploads');
_cfg('UPLOAD_MAX', 5 * 1024 * 1024); // 5 MB
_cfg('UPLOAD_EXT', 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx');

// Tampilkan error PHP (ubah ke false di server produksi)
_cfg('APP_DEBUG', true);

date_default_timezone_set('Asia/Jakarta');
