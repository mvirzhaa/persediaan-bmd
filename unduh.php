<?php
/**
 * Unduh dokumen transaksi dengan pemeriksaan hak akses.
 * unduh.php?t=<transaksi_id>            -> dokumen header
 * unduh.php?d=<transaksi_detail_id>     -> dokumen per barang
 */
require __DIR__ . '/core/bootstrap.php';
require_login();

$file = $nama = null;
if (!empty($_GET['t'])) {
    $t = row('SELECT * FROM transaksi WHERE id=?', [(int)$_GET['t']]);
    if ($t) { $file = $t['dokumen_file']; $nama = $t['dokumen_nama']; }
} elseif (!empty($_GET['d'])) {
    $d = row('SELECT d.dokumen_file, d.dokumen_nama, t.* FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id WHERE d.id=?', [(int)$_GET['d']]);
    if ($d) { $t = $d; $file = $d['dokumen_file']; $nama = $d['dokumen_nama']; }
}
if (empty($t) || !$file || !can_view_trx($t)) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }

$path = realpath(UPLOAD_DIR . '/' . $file);
$base = realpath(UPLOAD_DIR);
if (!$path || strpos($path, $base) !== 0 || !is_file($path)) { http_response_code(404); exit('File tidak ditemukan di server.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $nama ?: basename($path)) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
