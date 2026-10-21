<?php
/**
 * Bootstrap: dipanggil oleh setiap entry point (index.php, login.php, ajax.php, unduh.php)
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config.php';

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// Sesi yang aman
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('SIPERSEDIAAN');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_start();
}

require BASE_PATH . '/core/db.php';
require BASE_PATH . '/core/helpers.php';
require BASE_PATH . '/core/auth.php';
require BASE_PATH . '/core/stok.php';
require BASE_PATH . '/core/xlsx.php';
require BASE_PATH . '/core/org.php';
require BASE_PATH . '/core/laporan.php';
require BASE_PATH . '/core/so.php';
require BASE_PATH . '/core/report_render.php';
