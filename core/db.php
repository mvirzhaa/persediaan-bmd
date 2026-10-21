<?php
/**
 * Lapisan database tipis di atas MySQLi (prepared statement).
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db()
{
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
            $conn->set_charset('utf8mb4');
            $conn->query("SET time_zone = '+07:00'");
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            echo '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #f1c0c0;border-radius:8px;background:#fff7f7">'
                . '<h2 style="margin-top:0;color:#b42318">Koneksi database gagal</h2>'
                . '<p>Periksa pengaturan <code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_NAME</code> pada file <code>config.php</code> '
                . 'dan pastikan database sudah diimpor dari folder <code>database/</code>.</p>'
                . (APP_DEBUG ? '<pre style="white-space:pre-wrap">' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
                . '</div>';
            exit;
        }
    }
    return $conn;
}

/** Eksekusi query dengan parameter. Mengembalikan mysqli_stmt. */
function q($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $types = '';
        $vals = [];
        foreach ($params as $p) {
            if (is_int($p) || is_bool($p)) { $types .= 'i'; $vals[] = (int)$p; }
            elseif (is_float($p)) { $types .= 'd'; $vals[] = $p; }
            elseif ($p === null) { $types .= 's'; $vals[] = null; }
            else { $types .= 's'; $vals[] = (string)$p; }
        }
        $refs = [];
        foreach ($vals as $k => $v) { $refs[$k] = &$vals[$k]; }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    return $stmt;
}

/** Ambil semua baris (array asosiatif) */
function rows($sql, array $params = [])
{
    $stmt = q($sql, $params);
    $res = $stmt->get_result();
    $out = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $out;
}

/** Ambil satu baris atau null */
function row($sql, array $params = [])
{
    $r = rows($sql, $params);
    return $r ? $r[0] : null;
}

/** Ambil satu nilai skalar */
function val($sql, array $params = [])
{
    $r = row($sql, $params);
    return $r ? reset($r) : null;
}

/** INSERT/UPDATE/DELETE. Mengembalikan jumlah baris terpengaruh. */
function exec_sql($sql, array $params = [])
{
    $stmt = q($sql, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

function last_id()
{
    return (int)db()->insert_id;
}

/** Jalankan callback dalam transaksi database */
function db_tx(callable $fn)
{
    $db = db();
    $db->begin_transaction();
    try {
        $r = $fn();
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Placeholder IN (?,?,?) */
function in_ph(array $arr)
{
    return implode(',', array_fill(0, max(1, count($arr)), '?'));
}
