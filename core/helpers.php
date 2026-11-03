<?php
/**
 * Fungsi bantu umum
 */

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url($page = 'dashboard', array $params = [])
{
    $params = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

function is_post()
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input($key, $default = null)
{
    if (isset($_POST[$key])) return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
    if (isset($_GET[$key])) return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    return $default;
}

function input_int($key, $default = 0)
{
    $v = input($key, null);
    return ($v === null || $v === '') ? $default : (int)$v;
}

/** Angka format Indonesia "1.250,50" atau "1250.5" -> float */
function parse_num($v)
{
    if (is_int($v) || is_float($v)) return (float)$v;
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace([' ', 'Rp', 'rp'], '', $v);
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        // anggap titik = ribuan, koma = desimal
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (strpos($v, ',') !== false) {
        $v = str_replace(',', '.', $v);
    } elseif (substr_count($v, '.') > 1) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function rupiah($n, $dec = 2)
{
    return number_format((float)$n, $dec, ',', '.');
}

function angka($n)
{
    $n = (float)$n;
    return (floor($n) == $n) ? number_format($n, 0, ',', '.') : number_format($n, 2, ',', '.');
}

function bulan_indo($m)
{
    $b = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $b[(int)$m] ?? '';
}

function tgl_indo($date, $withTime = false)
{
    if (!$date || $date === '0000-00-00') return '-';
    $t = strtotime($date);
    $s = date('j', $t) . ' ' . bulan_indo(date('n', $t)) . ' ' . date('Y', $t);
    if ($withTime) $s .= ' ' . date('H:i', $t);
    return $s;
}

function valid_date($d)
{
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/** Title case untuk uraian kode yang ditulis kapital semua */
function judul($s)
{
    $s = (string)$s;
    if (function_exists('mb_strtoupper') && mb_strtoupper($s) !== $s) return $s;
    if (!function_exists('mb_strtoupper') && strtoupper($s) !== $s) return $s;
    $s = strtolower($s);
    $s = preg_replace_callback('/(^|[\s\/\(\-])([a-z])/', function ($m) { return $m[1] . strtoupper($m[2]); }, $s);
    return str_replace(['(Dci)', '(Acp)', '(Upvc)', 'Pvc', ' Dan ', ' Di ', ' Ke '], ['(DCI)', '(ACP)', '(UPVC)', 'PVC', ' dan ', ' di ', ' ke '], $s);
}

// ---------------------------------------------------------------- flash
/** Pesan sekali tampil. $msg di-escape kecuali $isHtml = true (hanya untuk HTML buatan sistem). */
function flash($type, $msg, $isHtml = false)
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $isHtml ? $msg : e($msg)];
}

function flashes()
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ---------------------------------------------------------------- CSRF
function csrf_token()
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (is_ajax()) json_out(['ok' => false, 'msg' => 'Sesi kedaluwarsa, muat ulang halaman.'], 419);
        http_response_code(419);
        exit('Token keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.');
    }
}

function is_ajax()
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- upload
/**
 * Simpan file upload. Mengembalikan ['file' => path relatif, 'nama' => nama asli] atau null.
 * Melempar RuntimeException bila file tidak valid.
 */
function simpan_upload($field, $subdir = 'dokumen')
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || is_array($_FILES[$field]['name'])) return null;
    $f = $_FILES[$field];
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode ' . $f['error'] . '). Ukuran maksimal ' . (UPLOAD_MAX / 1048576) . ' MB.');
    if ($f['size'] > UPLOAD_MAX) throw new RuntimeException('Ukuran file melebihi ' . (UPLOAD_MAX / 1048576) . ' MB.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = explode(',', UPLOAD_EXT);
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Tipe file tidak diizinkan. Gunakan: ' . strtoupper(implode(', ', $allowed)));
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('File upload tidak valid.');
    $dir = UPLOAD_DIR . '/' . $subdir . '/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Folder upload tidak dapat dibuat.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Gagal menyimpan file.');
    $orig = preg_replace('/[^\w\-. ()]+/u', '_', basename($f['name']));
    return ['file' => $subdir . '/' . date('Y/m') . '/' . $name, 'nama' => mb_substr($orig, 0, 200)];
}

function hapus_file($rel)
{
    if (!$rel) return;
    $p = realpath(UPLOAD_DIR . '/' . $rel);
    $base = realpath(UPLOAD_DIR);
    if ($p && $base && strpos($p, $base) === 0 && is_file($p)) @unlink($p);
}

function log_aktivitas($aksi, $ket = '')
{
    try {
        $impNote = '';
        if (!empty($_SESSION['impersonator'])) {
            $imp = function_exists('impersonator') ? impersonator() : null;
            $lbl = $imp ? role_label($imp['role']) : 'Super Admin';
            $impNote = ' [Login As oleh ' . $lbl . ' #' . (int)$_SESSION['impersonator'] . ']';
        }
        exec_sql('INSERT INTO log_aktivitas (user_id, aksi, keterangan, ip) VALUES (?,?,?,?)', [
            $_SESSION['uid'] ?? null, $aksi, mb_substr($ket . $impNote, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* abaikan */ }
}

// ---------------------------------------------------------------- pengaturan
function setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT kunci, nilai FROM pengaturan') as $r) $cache[$r['kunci']] = $r['nilai'];
    }
    return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
}

function badge_status($s)
{
    $map = [
        'draft' => ['Draft', 'gray'],
        'menunggu' => ['Menunggu Validasi', 'amber'],
        'disetujui' => ['Disetujui', 'green'],
        'ditolak' => ['Ditolak', 'red'],
    ];
    $m = $map[$s] ?? [$s, 'gray'];
    return '<span class="badge badge-' . $m[1] . '">' . e($m[0]) . '</span>';
}

function role_label($role, $unitLevel = null)
{
    switch ($role) {
        case 'superadmin': return 'Super Admin';
        case 'admin_skpd': return 'Admin SKPD';
        case 'admin': return $unitLevel === 'subunit' ? 'Admin Sub Unit' : 'Admin Unit';
        case 'operator': return $unitLevel === 'subunit' ? 'Operator Sub Unit' : 'Operator Unit';
    }
    return $role;
}

/** Paginasi sederhana */
function paginate($total, $perPage = 25)
{
    $page = max(1, input_int('hal', 1));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'limit' => $perPage, 'total' => $total];
}

function pager_html($p)
{
    if ($p['pages'] <= 1) return '';
    $q = $_GET;
    $h = '<nav class="pager">';
    $start = max(1, $p['page'] - 3);
    $end = min($p['pages'], $p['page'] + 3);
    if ($p['page'] > 1) { $q['hal'] = $p['page'] - 1; $h .= '<a href="?' . e(http_build_query($q)) . '">&lsaquo;</a>'; }
    for ($i = $start; $i <= $end; $i++) {
        $q['hal'] = $i;
        $h .= $i == $p['page'] ? '<span class="cur">' . $i . '</span>' : '<a href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
    }
    if ($p['page'] < $p['pages']) { $q['hal'] = $p['page'] + 1; $h .= '<a href="?' . e(http_build_query($q)) . '">&rsaquo;</a>'; }
    $h .= '<span class="muted">' . angka($p['total']) . ' data</span></nav>';
    return $h;
}
