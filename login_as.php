<?php
/**
 * Login As: Super Admin / Admin SKPD masuk sebagai akun lain, dan kembali.
 * POST aksi=masuk&id=<user_id>  |  POST aksi=kembali
 */
require __DIR__ . '/core/bootstrap.php';
require_login();
if (!is_post()) redirect('index.php');
csrf_check();
try {
    if (input('aksi') === 'kembali') {
        if (!impersonator_id()) redirect('index.php');
        $asal = impersonator();
        stop_login_as();
        flash('success', 'Anda kembali ke akun ' . ($asal ? $asal['nama'] . ' (' . role_label($asal['role']) . ')' : 'semula') . '.');
        $back = $_SESSION['login_as_back'] ?? 'index.php';
        unset($_SESSION['login_as_back']);
        redirect($back);
    }
    $r = real_user();
    $def = ($r && $r['role'] === 'admin_skpd') ? url('unit') : url('pengguna');
    $back = (string)input('back', '');
    if (!impersonator_id()) $_SESSION['login_as_back'] = preg_match('/^index\.php(\?[\w=&%.+\-]*)?$/', $back) ? $back : $def;
    $t = login_as(input_int('id'));
    flash('info', 'Anda sekarang masuk sebagai ' . $t['nama'] . ' (' . role_label($t['role'], $t['unit_level']) . '). Semua tindakan tercatat atas nama akun ini dan ditandai Login As di log.');
    redirect('index.php');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect('index.php');
}
