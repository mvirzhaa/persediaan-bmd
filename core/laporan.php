<?php
/**
 * Fungsi data laporan persediaan
 */

/** Hitung rentang periode laporan */
function lap_periode($tahun, $periode, $dari = '', $sampai = '')
{
    $y = (int)$tahun ?: (int)date('Y');
    $map = [
        'tw1' => ["$y-01-01", "$y-03-31", 'TRIWULAN I'],
        'tw2' => ["$y-04-01", "$y-06-30", 'TRIWULAN II'],
        'tw3' => ["$y-07-01", "$y-09-30", 'TRIWULAN III'],
        'tw4' => ["$y-10-01", "$y-12-31", 'TRIWULAN IV'],
        'smt1' => ["$y-01-01", "$y-06-30", 'SEMESTER I'],
        'smt2' => ["$y-07-01", "$y-12-31", 'SEMESTER II'],
        'tahun' => ["$y-01-01", "$y-12-31", 'TAHUNAN'],
    ];
    if ($periode === 'ytd') {
        // awal tahun s.d. hari ini (untuk tahun lalu: s.d. 31 Desember)
        $end = ($y === (int)date('Y')) ? date('Y-m-d') : "$y-12-31";
        if ($y > (int)date('Y')) $end = "$y-01-01";
        return ["$y-01-01", $end, 'S.D. ' . mb_strtoupper(tgl_indo($end)), $y];
    }
    if ($periode === 'custom' && valid_date($dari) && valid_date($sampai) && $dari <= $sampai) {
        return [$dari, $sampai, 'PERIODE ' . mb_strtoupper(tgl_indo($dari)) . ' S.D. ' . mb_strtoupper(tgl_indo($sampai)), $y];
    }
    $p = $map[$periode] ?? $map[(int)date('n') <= 6 ? 'smt1' : 'smt2'];
    return [$p[0], $p[1], $p[2], $y];
}

/**
 * Tentukan cakupan laporan sesuai peran & filter.
 * Mengembalikan: unit_ids, entity ('skpd'|'unit'), entities [id => ['nama','level']], skpd (row|null), unit (row|null), label
 */
function lap_scope($u, $skpdF, $unitF)
{
    $out = ['unit_ids' => [], 'entity' => 'unit', 'entities' => [], 'skpd' => null, 'unit' => null, 'label' => ''];
    if ($u['role'] === 'superadmin') {
        if (!$skpdF) {
            $out['entity'] = 'skpd';
            $out['unit_ids'] = array_map('intval', array_column(rows('SELECT id FROM unit_kerja'), 'id'));
            foreach (rows('SELECT id, nama FROM skpd ORDER BY (kode IS NULL), kode+0, kode, nama') as $s) $out['entities'][(int)$s['id']] = ['nama' => $s['nama'], 'level' => 'skpd'];
            $out['label'] = 'SELURUH SKPD';
            return $out;
        }
        $skpdId = (int)$skpdF;
    } elseif ($u['role'] === 'admin_skpd') {
        $skpdId = (int)$u['skpd_id'];
    } else {
        $skpdId = (int)$u['skpd_id'];
    }
    $out['skpd'] = row('SELECT * FROM skpd WHERE id=?', [$skpdId]);

    // daftar unit yang boleh dilihat
    if (in_array($u['role'], ['superadmin', 'admin_skpd'], true)) {
        $allowed = rows('SELECT * FROM unit_kerja WHERE skpd_id=? ORDER BY COALESCE(parent_id,id), level, nama', [$skpdId]);
    } else {
        $ids = unit_scope_ids($u);
        $allowed = rows('SELECT * FROM unit_kerja WHERE id IN (' . in_ph($ids) . ') ORDER BY COALESCE(parent_id,id), level, nama', $ids);
    }
    $sel = $allowed;
    if ($unitF) {
        $sel = array_values(array_filter($allowed, function ($x) use ($unitF) { return (int)$x['id'] === (int)$unitF || (int)$x['parent_id'] === (int)$unitF; }));
        foreach ($allowed as $x) if ((int)$x['id'] === (int)$unitF) $out['unit'] = $x;
    } elseif (!in_array($u['role'], ['superadmin', 'admin_skpd'], true)) {
        foreach ($allowed as $x) if ((int)$x['id'] === (int)$u['unit_id']) $out['unit'] = $x;
    }
    foreach ($sel as $x) {
        $out['unit_ids'][] = (int)$x['id'];
        $out['entities'][(int)$x['id']] = ['nama' => $x['nama'], 'level' => $x['level'], 'parent_id' => $x['parent_id']];
    }
    $out['allowed'] = $allowed;
    $out['label'] = $out['unit'] ? $out['unit']['nama'] : ($out['skpd']['nama'] ?? '');
    return $out;
}

/**
 * Laporan mutasi per barang.
 * $tahun (opsional): bila diisi, turut dihitung rincian penerimaan per triwulan (tw1_q..tw4_q)
 * pada tahun tsb, terlepas dari $dari/$sampai yang dipilih — dipakai untuk kolom rincian
 * TW I..IV pada Laporan Mutasi & Stock Opname (Semester I/II/Tahunan).
 */
function lap_mutasi(array $unitIds, $dari, $sampai, $subId = 0, $tahun = null)
{
    if (!$unitIds) return [];
    $y = (int)($tahun ?: substr((string)$dari, 0, 4));
    $tw = [
        1 => ["$y-01-01", "$y-03-31"],
        2 => ["$y-04-01", "$y-06-30"],
        3 => ["$y-07-01", "$y-09-30"],
        4 => ["$y-10-01", "$y-12-31"],
    ];
    $twSql = ''; $twParams = [];
    foreach ($tw as $n => $r) {
        $twSql .= ",\n              SUM(CASE WHEN t.tanggal>=? AND t.tanggal<=? AND t.jenis='masuk' AND t.sumber<>'saldo_awal' THEN d.jumlah ELSE 0 END) tw{$n}_q";
        $twParams[] = $r[0]; $twParams[] = $r[1];
    }
    $params = array_merge(
        [$dari, $dari, $dari, $dari, $dari, $sampai, $dari, $sampai, $dari, $sampai, $dari, $sampai],
        $twParams,
        [$sampai],
        $unitIds
    );
    $extra = '';
    if ($subId) { $extra = ' AND k.parent_id=?'; $params[] = (int)$subId; }
    $sql = "SELECT b.id, b.unit_id, b.nama_barang, b.satuan, k.kode, k.uraian AS kode_uraian, p.id AS sub_id, p.kode AS sub_kode, p.uraian AS sub_uraian,
              SUM(CASE WHEN (t.tanggal<? OR t.sumber='saldo_awal') AND t.jenis='masuk' THEN d.jumlah WHEN t.tanggal<? AND t.jenis='keluar' THEN -d.jumlah ELSE 0 END) awal_q,
              SUM(CASE WHEN (t.tanggal<? OR t.sumber='saldo_awal') AND t.jenis='masuk' THEN d.nilai WHEN t.tanggal<? AND t.jenis='keluar' THEN -d.nilai ELSE 0 END) awal_v,
              SUM(CASE WHEN t.tanggal>=? AND t.tanggal<=? AND t.jenis='masuk' AND t.sumber<>'saldo_awal' THEN d.jumlah ELSE 0 END) masuk_q,
              SUM(CASE WHEN t.tanggal>=? AND t.tanggal<=? AND t.jenis='masuk' AND t.sumber<>'saldo_awal' THEN d.nilai ELSE 0 END) masuk_v,
              SUM(CASE WHEN t.tanggal>=? AND t.tanggal<=? AND t.jenis='keluar' THEN d.jumlah ELSE 0 END) keluar_q,
              SUM(CASE WHEN t.tanggal>=? AND t.tanggal<=? AND t.jenis='keluar' THEN d.nilai ELSE 0 END) keluar_v,
              MAX(CASE WHEN t.jenis='masuk' THEN d.harga_satuan END) harga_akhir{$twSql}
            FROM transaksi_detail d
            JOIN transaksi t ON t.id=d.transaksi_id AND t.status='disetujui' AND t.tanggal<=?
            JOIN barang b ON b.id=d.barang_id
            JOIN kode_barang k ON k.id=b.kode_barang_id
            JOIN kode_barang p ON p.id=k.parent_id
            WHERE b.unit_id IN (" . in_ph($unitIds) . ")$extra
            GROUP BY b.id, b.unit_id, b.nama_barang, b.satuan, k.kode, k.uraian, p.id, p.kode, p.uraian
            ORDER BY p.kode, k.kode, b.nama_barang";
    $out = [];
    foreach (rows($sql, $params) as $r) {
        foreach (['awal_q', 'awal_v', 'masuk_q', 'masuk_v', 'keluar_q', 'keluar_v', 'tw1_q', 'tw2_q', 'tw3_q', 'tw4_q'] as $f) $r[$f] = round((float)$r[$f], 2);
        if ($r['awal_q'] == 0 && $r['masuk_q'] == 0 && $r['keluar_q'] == 0 && $r['awal_v'] == 0) continue;
        $r['akhir_q'] = round($r['awal_q'] + $r['masuk_q'] - $r['keluar_q'], 2);
        $r['akhir_v'] = round($r['awal_v'] + $r['masuk_v'] - $r['keluar_v'], 2);
        $r['harga'] = $r['akhir_q'] > 0 ? round($r['akhir_v'] / $r['akhir_q'], 2) : (float)$r['harga_akhir'];
        $out[] = $r;
    }
    return $out;
}

/**
 * Nilai per sub rincian objek (level 6) per entitas (skpd/unit) dalam periode.
 * @return array [entity_id][sub_kode] = ['awal','masuk','keluar','akhir']
 */
function lap_nilai_sub(array $unitIds, $dari, $sampai, $entity = 'unit')
{
    if (!$unitIds) return [];
    $ent = $entity === 'skpd' ? 'uk.skpd_id' : 't.unit_id';
    $sql = "SELECT $ent AS ent, p.kode AS sub_kode,
              SUM(CASE WHEN t.tanggal<? OR (t.sumber='saldo_awal' AND t.jenis='masuk') THEN (CASE WHEN t.jenis='masuk' THEN d.nilai ELSE -d.nilai END) ELSE 0 END) awal,
              SUM(CASE WHEN t.tanggal>=? AND t.jenis='masuk' AND t.sumber<>'saldo_awal' THEN d.nilai ELSE 0 END) masuk,
              SUM(CASE WHEN t.tanggal>=? AND t.jenis='keluar' THEN d.nilai ELSE 0 END) keluar
            FROM transaksi_detail d
            JOIN transaksi t ON t.id=d.transaksi_id AND t.status='disetujui' AND t.tanggal<=?
            JOIN unit_kerja uk ON uk.id=t.unit_id
            JOIN kode_barang k ON k.id=d.kode_barang_id
            JOIN kode_barang p ON p.id=k.parent_id
            WHERE t.unit_id IN (" . in_ph($unitIds) . ")
            GROUP BY $ent, p.kode";
    $out = [];
    foreach (rows($sql, array_merge([$dari, $dari, $dari, $sampai], $unitIds)) as $r) {
        $a = round((float)$r['awal'], 2); $m = round((float)$r['masuk'], 2); $k = round((float)$r['keluar'], 2);
        $out[(int)$r['ent']][$r['sub_kode']] = ['awal' => $a, 'masuk' => $m, 'keluar' => $k, 'akhir' => round($a + $m - $k, 2)];
    }
    return $out;
}

/** Struktur kode level 2..6 di bawah akun persediaan, untuk kolom/baris laporan */
function lap_kode_tree()
{
    $list = rows('SELECT id, parent_id, level, kode, uraian FROM kode_barang WHERE level BETWEEN 2 AND 6 ORDER BY kode');
    return $list;
}

/** Tanda tangan default */
function lap_ttd_default($u, $scope)
{
    $kota = setting('kota_ttd', '');
    if ($u['role'] === 'superadmin' && !$scope['skpd']) {
        return ['kota' => $kota, 'kiri_jabatan' => '', 'kiri_nama' => '', 'kiri_nip' => '',
            'kanan_jabatan' => trim(setting('ttd_jabatan', 'SEKRETARIS DAERAH') . "\n" . setting('ttd_jabatan2', 'Selaku Pengelola Barang')),
            'kanan_nama' => setting('ttd_nama', ''), 'kanan_nip' => setting('ttd_nip', '')];
    }
    $pic = $scope['unit']['pic'] ?? ($scope['skpd']['pic'] ?? '');
    return ['kota' => $kota, 'kiri_jabatan' => 'Mengetahui,' . "\n" . 'Kepala ' . ($scope['skpd']['nama'] ?? 'SKPD'), 'kiri_nama' => $scope['skpd']['pic'] ?? '', 'kiri_nip' => '',
        'kanan_jabatan' => 'Pengurus Barang' . ($scope['unit'] ? "\n" . $scope['unit']['nama'] : ''), 'kanan_nama' => $scope['unit'] ? $pic : '', 'kanan_nip' => ''];
}

/** Info transaksi yang TIDAK ikut dihitung: setelah tanggal akhir periode & yang masih menunggu validasi */
function lap_info_luar($unitIds, $sampai)
{
    if (!$unitIds) return ['setelah' => 0, 'tgl_terakhir' => null, 'menunggu' => 0];
    $ph = in_ph($unitIds);
    $r = row("SELECT COUNT(*) n, MAX(tanggal) mx FROM transaksi WHERE status='disetujui' AND tanggal>? AND unit_id IN ($ph)", array_merge([$sampai], $unitIds));
    $p = (int)val("SELECT COUNT(*) FROM transaksi WHERE status='menunggu' AND tanggal<=? AND unit_id IN ($ph)", array_merge([$sampai], $unitIds));
    return ['setelah' => (int)$r['n'], 'tgl_terakhir' => $r['mx'], 'menunggu' => $p];
}
