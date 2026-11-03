<?php
/**
 * Login As: Super Admin masuk sebagai akun lain, dan kembali.
 * POST aksi=masuk&id=<user_id>  |  POST aksi=kembali
 */
require __DIR__ . '/core/bootstrap.php';
require_login();
if (!is_post()) redirect('index.php');
csrf_check();
try {
    if (input('aksi') === 'kembali') {
        $imp = impersonator();
        stop_login_as();
        flash('success', 'Anda kembali ke akun ' . ($imp ? role_label($imp['role']) : 'semula') . '.');
        $back = $_SESSION['login_as_back'] ?? url('dashboard');
        unset($_SESSION['login_as_back']);
        redirect($back);
    }
    $back = (string)input('back', '');
    if (!impersonator_id()) $_SESSION['login_as_back'] = preg_match('/^index\.php(\?[\w=&%.+\-]*)?$/', $back) ? $back : url('dashboard');
    login_as(input_int('id'));
    $t = user();
    flash('info', 'Anda sekarang masuk sebagai ' . $t['nama'] . ' (' . role_label($t['role'], $t['unit_level']) . '). Semua tindakan tercatat atas nama akun ini dan ditandai Login As di log.');
    redirect('index.php');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect('index.php');
}
