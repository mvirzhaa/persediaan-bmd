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
                  WHERE u.id = ? AND (u.is_active = 1 OR ?)', [(int)$_SESSION['uid'], impersonator_id() ? 1 : 0]);
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

// ---------------------------------------------------------------- Login As (Super Admin & Admin SKPD)

/** id pengguna asli bila sedang "login as", selain itu 0 */
function impersonator_id()
{
    return (int)($_SESSION['impersonator'] ?? 0);
}

/** Data pengguna asli (Super Admin / Admin SKPD) yang sedang login as (atau null) */
function impersonator()
{
    static $r = false;
    if ($r === false) $r = impersonator_id() ? row("SELECT id, nama, email, role FROM users WHERE id=? AND role IN ('superadmin','admin_skpd')", [impersonator_id()]) : null;
    return $r;
}

/** Data pengguna sebenarnya (sebelum Login As) — akun yang sedang login_at, bukan akun yang sedang diperankan */
function real_user()
{
    static $r = false;
    if ($r === false) {
        $rid = impersonator_id() ?: (int)($_SESSION['uid'] ?? 0);
        $r = $rid ? row('SELECT * FROM users WHERE id=?', [$rid]) : null;
    }
    return $r;
}

/** Apakah pengguna sebenarnya (bukan akun yang sedang diperankan) adalah Super Admin */
function real_is_superadmin()
{
    $r = real_user();
    return $r && $r['role'] === 'superadmin';
}

/**
 * Apakah pengguna sebenarnya berwenang menggunakan "Login As" ke akun target.
 * Super Admin: boleh ke semua akun non-superadmin.
 * Admin SKPD: boleh ke akun admin/operator unit & sub unit di bawah SKPD-nya sendiri (lihat can_manage_account()).
 */
function can_login_as(array $target)
{
    $r = real_user();
    if (!$r) return false;
    return can_manage_account($target, $r);
}

/** Masuk sebagai akun lain (admin SKPD / admin unit / admin sub unit / operator) */
function login_as($targetId)
{
    $t = row('SELECT * FROM users WHERE id=?', [(int)$targetId]);
    if (!$t || $t['role'] === 'superadmin') throw new RuntimeException('Akun tujuan tidak valid.');
    if (!can_login_as($t)) throw new RuntimeException('Anda tidak berwenang menggunakan Login As untuk akun ini.');
    $sa = impersonator_id() ?: (int)$_SESSION['uid'];
    session_regenerate_id(true);
    $_SESSION['impersonator'] = $sa;
    $_SESSION['uid'] = (int)$t['id'];
    exec_sql('INSERT INTO log_aktivitas (user_id, aksi, keterangan, ip) VALUES (?,?,?,?)',
        [$sa, 'login_as', 'Masuk sebagai #' . $t['id'] . ' ' . $t['nama'] . ($t['email'] ? ' <' . $t['email'] . '>' : ''), $_SERVER['REMOTE_ADDR'] ?? null]);
}

/** Kembali ke akun Super Admin */
function stop_login_as()
{
    $sa = impersonator_id();
    if (!$sa) return;
    $from = (int)$_SESSION['uid'];
    session_regenerate_id(true);
    $_SESSION['uid'] = $sa;
    unset($_SESSION['impersonator']);
    exec_sql('INSERT INTO log_aktivitas (user_id, aksi, keterangan, ip) VALUES (?,?,?,?)',
        [$sa, 'login_as_selesai', 'Kembali dari akun #' . $from, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/** Tombol "Masuk sebagai" (tampil untuk Super Admin, serta Admin SKPD ke akun di bawah SKPD-nya) */
function login_as_btn($userId, $label = 'Masuk sebagai', $cls = 'btn btn-light btn-sm')
{
    if (!$userId || impersonator_id()) return '';
    $t = row('SELECT id, role, unit_id FROM users WHERE id=?', [(int)$userId]);
    if (!$t || !can_login_as($t)) return '';
    return '<form method="post" action="login_as.php" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="aksi" value="masuk"><input type="hidden" name="id" value="' . (int)$userId . '">'
        . '<input type="hidden" name="back" value="' . e('index.php?' . ($_SERVER['QUERY_STRING'] ?? '')) . '">'
        . '<button class="' . e($cls) . '" type="submit" title="Login As: masuk ke aplikasi sebagai akun ini">&#8594; ' . e($label) . '</button></form>';
}
