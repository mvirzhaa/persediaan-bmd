<?php
/**
 * Autentikasi & otorisasi
 */

function attempt_login($email, $password)
{
    $u = row('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    if (!$u || !$u['password'] || !password_verify($password, $u['password'])) return 'Email atau password salah.';
    if (!(int)$u['is_active']) return 'Akun belum aktif. Hubungi admin Anda.';
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['login_at'] = time();
    if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
        exec_sql('UPDATE users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
    }
    exec_sql('UPDATE users SET last_login = NOW() WHERE id = ?', [(int)$u['id']]);
    log_aktivitas('login', $u['email']);
    return true;
}

function logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Pengguna yang sedang login, lengkap dengan info unit & SKPD.
 * Keys tambahan: unit_level, unit_nama, unit_parent_id, skpd_nama
 */
function user()
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = row('SELECT u.*, uk.level AS unit_level, uk.nama AS unit_nama, uk.parent_id AS unit_parent_id,
                         COALESCE(s1.nama, s2.nama) AS skpd_nama, COALESCE(u.skpd_id, uk.skpd_id) AS skpd_ref
                  FROM users u
                  LEFT JOIN unit_kerja uk ON uk.id = u.unit_id
                  LEFT JOIN skpd s1 ON s1.id = u.skpd_id
                  LEFT JOIN skpd s2 ON s2.id = uk.skpd_id
                  WHERE u.id = ? AND u.is_active = 1', [(int)$_SESSION['uid']]);
        if ($u) {
            $u['id'] = (int)$u['id'];
            $u['unit_id'] = $u['unit_id'] ? (int)$u['unit_id'] : null;
            $u['skpd_id'] = $u['skpd_ref'] ? (int)$u['skpd_ref'] : null;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $u;
}

function require_login()
{
    if (!user()) {
        if (is_ajax()) json_out(['ok' => false, 'msg' => 'Sesi berakhir, silakan login kembali.'], 401);
        redirect('login.php');
    }
}

function has_role(...$roles)
{
    $u = user();
    return $u && in_array($u['role'], $roles, true);
}

function require_role(...$roles)
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        if (is_ajax()) json_out(['ok' => false, 'msg' => 'Akses ditolak.'], 403);
        include BASE_PATH . '/views/403.php';
        exit;
    }
}

function is_unit_user()
{
    return has_role('admin', 'operator');
}

/** id unit milik user + seluruh sub unit di bawahnya (untuk melihat stok/laporan) */
function unit_scope_ids($u = null)
{
    $u = $u ?: user();
    if (!$u || !$u['unit_id']) return [];
    $ids = [(int)$u['unit_id']];
    if ($u['unit_level'] === 'unit') {
        foreach (rows('SELECT id FROM unit_kerja WHERE parent_id = ?', [(int)$u['unit_id']]) as $r) $ids[] = (int)$r['id'];
    }
    return $ids;
}

/** Daftar unit yang boleh dilihat user (untuk dropdown) */
function unit_scope_list($u = null)
{
    $ids = unit_scope_ids($u);
    if (!$ids) return [];
    return rows('SELECT id, nama, level, parent_id FROM unit_kerja WHERE id IN (' . in_ph($ids) . ') ORDER BY level, nama', $ids);
}

/** Apakah user (admin) boleh memvalidasi transaksi ini */
function can_validate(array $trx, $u = null)
{
    $u = $u ?: user();
    if (!$u || $u['role'] !== 'admin') return false;
    if ($trx['status'] !== 'menunggu') return false;
    return in_array((int)$trx['unit_id'], unit_scope_ids($u), true);
}

/** Apakah user boleh melihat transaksi ini */
function can_view_trx(array $trx, $u = null)
{
    $u = $u ?: user();
    if (!$u) return false;
    if ($u['role'] === 'superadmin') return true;
    $unit = row('SELECT skpd_id FROM unit_kerja WHERE id=?', [(int)$trx['unit_id']]);
    if ($u['role'] === 'admin_skpd') return $unit && (int)$unit['skpd_id'] === (int)$u['skpd_id'];
    return in_array((int)$trx['unit_id'], unit_scope_ids($u), true);
}
