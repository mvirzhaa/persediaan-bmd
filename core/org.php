<?php
/**
 * Organisasi: SKPD, unit, sub unit, dan akun otomatis
 */

/** Singkatan nama untuk label akun, mis. "Badan Pengelolaan Keuangan dan Aset Daerah" -> "BPKAD" */
function singkatan($nama, $max = 40)
{
    $nama = trim($nama);
    if (mb_strlen($nama) <= 40) return $nama;
    $stop = ['dan', 'di', 'ke', 'dari', 'serta', 'untuk', 'pada', 'yang'];
    $s = '';
    foreach (preg_split('/[\s,\/]+/', $nama) as $w) {
        if ($w === '' || in_array(mb_strtolower($w), $stop, true)) continue;
        $s .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return mb_substr($s ?: $nama, 0, $max);
}

/** Buat SKPD + 2 akun admin SKPD (tanpa email/password, nonaktif) */
function skpd_create(array $d)
{
    return db_tx(function () use ($d) {
        exec_sql('INSERT INTO skpd (kode, nama, lokasi, pic, email, telp) VALUES (?,?,?,?,?,?)',
            [$d['kode'] ?: null, $d['nama'], $d['lokasi'] ?: null, $d['pic'] ?: null, $d['email'] ?: null, $d['telp'] ?: null]);
        $id = last_id();
        $sk = singkatan($d['nama']);
        for ($i = 1; $i <= 2; $i++) {
            exec_sql("INSERT INTO users (nama, role, skpd_id, slot, is_active) VALUES (?, 'admin_skpd', ?, ?, 0)", ['Admin SKPD ' . $i . ' - ' . $sk, $id, $i]);
        }
        log_aktivitas('skpd_tambah', $d['nama']);
        return $id;
    });
}

/** Buat unit / sub unit + 2 akun (admin & operator) */
function unit_create($skpdId, $parentId, array $d)
{
    return db_tx(function () use ($skpdId, $parentId, $d) {
        $level = $parentId ? 'subunit' : 'unit';
        exec_sql('INSERT INTO unit_kerja (skpd_id, parent_id, level, kode, nama, lokasi, pic, email, telp) VALUES (?,?,?,?,?,?,?,?,?)',
            [(int)$skpdId, $parentId ? (int)$parentId : null, $level, $d['kode'] ?: null, $d['nama'], $d['lokasi'] ?: null, $d['pic'] ?: null, $d['email'] ?: null, $d['telp'] ?: null]);
        $id = last_id();
        $lbl = $level === 'subunit' ? 'Sub Unit' : 'Unit';
        $sk = singkatan($d['nama']);
        exec_sql("INSERT INTO users (nama, role, skpd_id, unit_id, slot, is_active) VALUES (?, 'admin', NULL, ?, 1, 0)", ['Admin ' . $lbl . ' - ' . $sk, $id]);
        exec_sql("INSERT INTO users (nama, role, skpd_id, unit_id, slot, is_active) VALUES (?, 'operator', NULL, ?, 1, 0)", ['Operator ' . $lbl . ' - ' . $sk, $id]);
        log_aktivitas('unit_tambah', $level . ': ' . $d['nama']);
        return $id;
    });
}

/** Apakah unit/sub unit (beserta anaknya) sudah punya transaksi/barang */
function unit_punya_data($unitId)
{
    $ids = [(int)$unitId];
    foreach (rows('SELECT id FROM unit_kerja WHERE parent_id=?', [(int)$unitId]) as $r) $ids[] = (int)$r['id'];
    $ph = in_ph($ids);
    return (int)val("SELECT COUNT(*) FROM transaksi WHERE unit_id IN ($ph) AND status<>'draft'", $ids) > 0
        || (int)val("SELECT COUNT(*) FROM barang WHERE unit_id IN ($ph)", $ids) > 0;
}

function skpd_punya_data($skpdId)
{
    return (int)val("SELECT COUNT(*) FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id WHERE uk.skpd_id=? AND t.status<>'draft'", [(int)$skpdId]) > 0
        || (int)val('SELECT COUNT(*) FROM barang b JOIN unit_kerja uk ON uk.id=b.unit_id WHERE uk.skpd_id=?', [(int)$skpdId]) > 0;
}

/** Hapus draft milik unit-unit (sebelum menghapus unit) */
function hapus_draft_unit(array $unitIds)
{
    if (!$unitIds) return;
    $ph = in_ph($unitIds);
    foreach (rows("SELECT d.dokumen_file FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id WHERE t.unit_id IN ($ph) AND t.status='draft'", $unitIds) as $r) hapus_file($r['dokumen_file']);
    exec_sql("DELETE FROM transaksi WHERE unit_id IN ($ph) AND status='draft'", $unitIds);
}

/** Validasi data form organisasi (SKPD/unit) */
function org_form_data()
{
    $d = [
        'kode' => mb_substr(trim((string)input('kode', '')), 0, 30),
        'nama' => mb_substr(trim((string)input('nama', '')), 0, 200),
        'lokasi' => mb_substr(trim((string)input('lokasi', '')), 0, 255),
        'pic' => mb_substr(trim((string)input('pic', '')), 0, 150),
        'email' => mb_strtolower(mb_substr(trim((string)input('email', '')), 0, 150)),
        'telp' => mb_substr(trim((string)input('telp', '')), 0, 50),
    ];
    $err = [];
    if ($d['nama'] === '') $err[] = 'Nama wajib diisi.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Format email tidak valid.';
    return [$d, $err];
}

/** Bolehkah user mengelola akun target */
function can_manage_account(array $target, $u = null)
{
    $u = $u ?: user();
    if ($target['role'] === 'superadmin') return false;
    if ($u['role'] === 'superadmin') return true; // superadmin boleh semua (dukungan)
    if ($u['role'] === 'admin_skpd' && in_array($target['role'], ['admin', 'operator'], true)) {
        $sk = val('SELECT skpd_id FROM unit_kerja WHERE id=?', [(int)$target['unit_id']]);
        return (int)$sk === (int)$u['skpd_id'];
    }
    return false;
}
