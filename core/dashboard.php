<?php
/**
 * Data dashboard Super Admin & Admin SKPD:
 *  - filter (SKPD, tahun, periode, jenis barang)
 *  - ringkasan per SKPD / unit sesuai filter
 *  - rincian barang & nilai (tabel + unduh Excel)
 *  - daftar SKPD / unit yang belum menginput pembelian atau pemakaian
 */

/** Daftar pilihan periode (sama dengan halaman Laporan) */
function dash_periode_opsi()
{
    return ['ytd' => 'Awal tahun s.d. hari ini', 'tw1' => 'Triwulan I', 'tw2' => 'Triwulan II', 'tw3' => 'Triwulan III', 'tw4' => 'Triwulan IV',
        'smt1' => 'Semester I', 'smt2' => 'Semester II', 'tahun' => 'Tahunan', 'custom' => 'Rentang tanggal…'];
}

/**
 * Baca filter dashboard dari request.
 * @return array ['tahun','periode','dari','sampai','plabel','skpd','sub','sub_row','scope']
 */
function dash_filter($u)
{
    $periode = (string)input('periode', 'ytd');
    if (!array_key_exists($periode, dash_periode_opsi())) $periode = 'ytd';
    list($dari, $sampai, $plabel, $tahun) = lap_periode(input_int('tahun', (int)date('Y')), $periode, (string)input('dari', ''), (string)input('sampai', ''));

    $subF = input_int('sub');
    $subRow = $subF ? row('SELECT id, kode, uraian FROM kode_barang WHERE id=? AND level=6', [$subF]) : null;
    if (!$subRow) $subF = 0;

    $skpdF = $u['role'] === 'superadmin' ? input_int('skpd') : 0;
    $scope = lap_scope($u, $skpdF, 0);
    if ($skpdF && !$scope['skpd']) { // SKPD tidak ditemukan -> kembali ke seluruh SKPD
        $skpdF = 0;
        $scope = lap_scope($u, 0, 0);
    }
    return ['tahun' => $tahun, 'periode' => $periode, 'dari' => $dari, 'sampai' => $sampai, 'plabel' => $plabel,
        'skpd' => $skpdF, 'sub' => $subF, 'sub_row' => $subRow, 'scope' => $scope];
}

/** Parameter query string filter (untuk tautan rincian / ekspor) */
function dash_qs(array $f)
{
    $q = ['tahun' => $f['tahun'], 'periode' => $f['periode']];
    if ($f['periode'] === 'custom') { $q['dari'] = $f['dari']; $q['sampai'] = $f['sampai']; }
    if ($f['skpd']) $q['skpd'] = $f['skpd'];
    if ($f['sub']) $q['sub'] = $f['sub'];
    return $q;
}

/** URL halaman rincian */
function dash_rinci_url(array $f, $tipe, $ent = 0, array $extra = [])
{
    return url('rincian', array_merge(dash_qs($f), ['tipe' => $tipe, 'ent' => (int)$ent], $extra));
}

/** Label jenis barang aktif */
function dash_sub_label(array $f)
{
    return $f['sub_row'] ? $f['sub_row']['kode'] . ' ' . judul($f['sub_row']['uraian']) : 'Semua sub rincian objek';
}

/** Apakah entitas dashboard = SKPD (Super Admin tanpa filter SKPD) atau unit */
function dash_is_skpd(array $f)
{
    return $f['scope']['entity'] === 'skpd';
}

/** Peta unit_id => id entitas (skpd_id atau unit_id) */
function dash_unit_map(array $f)
{
    $m = [];
    if (dash_is_skpd($f)) {
        foreach (rows('SELECT id, skpd_id FROM unit_kerja') as $r) $m[(int)$r['id']] = (int)$r['skpd_id'];
    } else {
        foreach ($f['scope']['unit_ids'] as $id) $m[(int)$id] = (int)$id;
    }
    return $m;
}

/**
 * Daftar unit untuk satu entitas. $ent = 0 berarti seluruh cakupan.
 * Mengembalikan null bila entitas di luar hak akses pengguna.
 */
function dash_ent_units(array $f, $ent)
{
    $ent = (int)$ent;
    if (!$ent) return $f['scope']['unit_ids'];
    if (!isset($f['scope']['entities'][$ent])) return null;
    if (dash_is_skpd($f)) return array_map('intval', array_column(rows('SELECT id FROM unit_kerja WHERE skpd_id=?', [$ent]), 'id'));
    return [$ent];
}

/** Meta (label : nilai) untuk judul rincian */
function dash_ent_meta(array $f, $ent)
{
    $scope = $f['scope'];
    $meta = [];
    if ($ent && dash_is_skpd($f)) {
        $meta[] = ['SKPD', $scope['entities'][$ent]['nama']];
    } elseif ($ent) {
        $e = $scope['entities'][$ent];
        if ($scope['skpd']) $meta[] = ['SKPD', $scope['skpd']['nama']];
        $meta[] = [$e['level'] === 'subunit' ? 'SUB UNIT' : 'UNIT', $e['nama']];
    } else {
        $meta[] = ['CAKUPAN', $scope['skpd'] ? $scope['skpd']['nama'] . ' (seluruh unit & sub unit)' : 'Seluruh SKPD'];
    }
    $meta[] = ['PERIODE', $f['plabel'] . ' TAHUN ' . $f['tahun'] . ' (' . tgl_indo($f['dari']) . ' s.d. ' . tgl_indo($f['sampai']) . ')'];
    $meta[] = ['JENIS BARANG', dash_sub_label($f)];
    return $meta;
}

/** Nama singkat entitas untuk judul / nama file */
function dash_ent_nama(array $f, $ent)
{
    if ($ent) return $f['scope']['entities'][$ent]['nama'];
    return $f['scope']['skpd'] ? $f['scope']['skpd']['nama'] : 'Seluruh SKPD';
}

/**
 * Ringkasan per entitas sesuai filter.
 * @return array [id => ['id','nama','level','item','n_barang','awal','masuk','keluar','akhir','pending']]
 */
function dash_ringkasan(array $f)
{
    $scope = $f['scope'];
    $ents = [];
    foreach ($scope['entities'] as $id => $e) {
        $ents[$id] = ['id' => (int)$id, 'nama' => $e['nama'], 'level' => $e['level'], 'item' => 0, 'n_barang' => 0,
            'awal' => 0.0, 'masuk' => 0.0, 'keluar' => 0.0, 'akhir' => 0.0, 'pending' => 0];
    }
    if (!$scope['unit_ids']) return $ents;
    $map = dash_unit_map($f);
    foreach (lap_mutasi($scope['unit_ids'], $f['dari'], $f['sampai'], $f['sub']) as $r) {
        $eid = $map[(int)$r['unit_id']] ?? null;
        if ($eid === null || !isset($ents[$eid])) continue;
        $ents[$eid]['n_barang']++;
        if ($r['akhir_q'] > 0) $ents[$eid]['item']++;
        $ents[$eid]['awal'] += $r['awal_v'];
        $ents[$eid]['masuk'] += $r['masuk_v'];
        $ents[$eid]['keluar'] += $r['keluar_v'];
        $ents[$eid]['akhir'] += $r['akhir_v'];
    }
    // transaksi menunggu validasi (kondisi saat ini, tidak terpengaruh filter)
    $ent = dash_is_skpd($f) ? 'uk.skpd_id' : 't.unit_id';
    foreach (rows("SELECT $ent AS ent, COUNT(*) n FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id
                   WHERE t.status='menunggu' AND t.unit_id IN (" . in_ph($scope['unit_ids']) . ") GROUP BY $ent", $scope['unit_ids']) as $r) {
        if (isset($ents[(int)$r['ent']])) $ents[(int)$r['ent']]['pending'] = (int)$r['n'];
    }
    foreach ($ents as &$x) foreach (['awal', 'masuk', 'keluar', 'akhir'] as $k) $x[$k] = round($x[$k], 2);
    unset($x);
    return $ents;
}

/**
 * Jumlah transaksi pembelian & pemakaian per entitas dalam periode filter.
 * Dihitung: transaksi input/impor berstatus menunggu atau disetujui
 * (saldo awal & penyesuaian stock opname tidak dihitung).
 * @return array [id => ['beli' => n, 'pakai' => n]]
 */
function dash_status_input(array $f)
{
    $scope = $f['scope'];
    $out = [];
    foreach ($scope['entities'] as $id => $e) $out[$id] = ['beli' => 0, 'pakai' => 0];
    if (!$scope['unit_ids']) return $out;
    $ent = dash_is_skpd($f) ? 'uk.skpd_id' : 't.unit_id';
    $params = [$f['dari'], $f['sampai']];
    $subSql = '';
    if ($f['sub']) {
        $subSql = ' AND EXISTS (SELECT 1 FROM transaksi_detail d JOIN kode_barang k ON k.id=d.kode_barang_id WHERE d.transaksi_id=t.id AND k.parent_id=?)';
        $params[] = (int)$f['sub'];
    }
    $params = array_merge($params, $scope['unit_ids']);
    $sql = "SELECT $ent AS ent, SUM(t.jenis='masuk') beli, SUM(t.jenis='keluar') pakai
            FROM transaksi t JOIN unit_kerja uk ON uk.id=t.unit_id
            WHERE t.status IN ('menunggu','disetujui') AND t.sumber IN ('input','import')
              AND t.tanggal BETWEEN ? AND ?$subSql
              AND t.unit_id IN (" . in_ph($scope['unit_ids']) . ")
            GROUP BY $ent";
    foreach (rows($sql, $params) as $r) {
        if (isset($out[(int)$r['ent']])) $out[(int)$r['ent']] = ['beli' => (int)$r['beli'], 'pakai' => (int)$r['pakai']];
    }
    return $out;
}

/** Ringkasan status input: jumlah entitas belum lengkap / belum sama sekali */
function dash_belum_ringkas(array $status)
{
    $n = ['total' => count($status), 'belum' => 0, 'kosong' => 0, 'tanpa_beli' => 0, 'tanpa_pakai' => 0];
    foreach ($status as $s) {
        if (!$s['beli']) $n['tanpa_beli']++;
        if (!$s['pakai']) $n['tanpa_pakai']++;
        if (!$s['beli'] || !$s['pakai']) $n['belum']++;
        if (!$s['beli'] && !$s['pakai']) $n['kosong']++;
    }
    return $n;
}

// ======================================================================
// Model tabel rincian (dipakai untuk tampilan HTML & ekspor XLSX)
// ======================================================================

/** Rincian per barang untuk entitas */
function dash_model_barang(array $f, $ent, array $unitIds)
{
    $data = lap_mutasi($unitIds, $f['dari'], $f['sampai'], $f['sub']);
    $multi = count($unitIds) > 1;
    $units = [];
    if ($multi && $unitIds) {
        foreach (rows('SELECT uk.id, uk.nama, uk.level, s.nama AS skpd_nama FROM unit_kerja uk JOIN skpd s ON s.id=uk.skpd_id WHERE uk.id IN (' . in_ph($unitIds) . ')', $unitIds) as $x) {
            $units[(int)$x['id']] = (!$ent && dash_is_skpd($f) ? $x['skpd_nama'] . ' — ' : '') . ($x['level'] === 'subunit' ? '↳ ' : '') . $x['nama'];
        }
    }
    $nc = $multi ? 11 : 10;
    $m = [
        'titles' => ['RINCIAN JENIS BARANG PERSEDIAAN', 'POSISI ' . mb_strtoupper(tgl_indo($f['sampai']))],
        'meta' => dash_ent_meta($f, $ent),
        'cols' => $multi ? [5, 34, 20, 40, 9, 11, 11, 11, 11, 15, 18] : [5, 20, 44, 9, 11, 11, 11, 11, 15, 18],
        'head' => [array_merge(['NO'], $multi ? ['UNIT / SUB UNIT'] : [], ['KODE BARANG', 'NAMA BARANG', 'SATUAN', 'SALDO AWAL', 'PENERIMAAN', 'PENGELUARAN', 'SALDO AKHIR', "HARGA SATUAN\n(Rp)", "NILAI\n(Rp)"])],
        'body' => [], 'landscape' => true,
    ];
    $curSub = null; $no = 0; $tot = 0.0; $tersedia = 0;
    foreach ($data as $r) {
        if ($curSub !== $r['sub_kode']) {
            $curSub = $r['sub_kode'];
            $m['body'][] = ['cls' => 'g', 'cells' => [rc($r['sub_kode'] . '  ' . judul($r['sub_uraian']), 's', $nc)]];
        }
        $no++;
        if ($r['akhir_q'] > 0) $tersedia++;
        $cells = [rc($no, 'c')];
        if ($multi) $cells[] = $units[(int)$r['unit_id']] ?? '-';
        array_push($cells, rc($r['kode'], 'c'), $r['nama_barang'], rc($r['satuan'], 'c'), rc($r['awal_q'], 'q'), rc($r['masuk_q'], 'q'),
            rc($r['keluar_q'], 'q'), rc($r['akhir_q'], 'q'), rc($r['harga'], 'n'), rc($r['akhir_v'], 'n'));
        $m['body'][] = ['cells' => $cells];
        $tot += $r['akhir_v'];
    }
    if ($data) {
        $m['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH', 's', $nc - 1), rc($tot, 'n')]];
        $m['notes'] = ['Jenis barang tersedia (saldo akhir > 0): ' . angka($tersedia) . ' dari ' . angka($no) . ' barang yang memiliki saldo atau mutasi pada periode ini.'];
    } else {
        $m['body'][] = ['cells' => [rc('Tidak ada data persediaan pada periode dan jenis barang ini.', 'c', $nc)]];
    }
    return $m;
}

/**
 * Rincian nilai: rekap per jenis barang (sub rincian objek) dan, bila cakupan
 * berisi lebih dari satu SKPD/unit, rekap per SKPD/unit.
 * @return array [ ['sheet' => nama, 'judul' => judul tampilan, 'model' => model], ... ]
 */
function dash_model_nilai(array $f, $ent, array $unitIds)
{
    $data = lap_mutasi($unitIds, $f['dari'], $f['sampai'], $f['sub']);
    $meta = dash_ent_meta($f, $ent);
    $titleTgl = 'POSISI ' . mb_strtoupper(tgl_indo($f['sampai']));

    // --- per jenis barang
    $rek = [];
    foreach ($data as $r) {
        $k = $r['sub_kode'];
        if (!isset($rek[$k])) $rek[$k] = ['uraian' => $r['sub_uraian'], 'n' => 0, 'awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0];
        $rek[$k]['n']++;
        foreach (['awal', 'masuk', 'keluar', 'akhir'] as $x) $rek[$k][$x] += $r[$x . '_v'];
    }
    ksort($rek);
    $m1 = [
        'titles' => ['RINCIAN NILAI PERSEDIAAN PER JENIS BARANG', $titleTgl],
        'meta' => $meta,
        'cols' => [5, 20, 46, 11, 19, 19, 19, 19],
        'head' => [['NO', 'KODE', 'JENIS BARANG (SUB RINCIAN OBJEK)', 'JML BARANG', 'SALDO AWAL (Rp)', 'PENERIMAAN (Rp)', 'PENGELUARAN (Rp)', 'SALDO AKHIR (Rp)']],
        'body' => [],
    ];
    $t = ['n' => 0, 'awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0];
    $i = 0;
    foreach ($rek as $k => $v) {
        $i++;
        $m1['body'][] = ['cells' => [rc($i, 'c'), rc($k, 'c'), judul($v['uraian']), rc($v['n'], 'q'), rc($v['awal'], 'n'), rc($v['masuk'], 'n'), rc($v['keluar'], 'n'), rc($v['akhir'], 'n')]];
        foreach ($t as $kk => $_) $t[$kk] += $v[$kk];
    }
    if ($rek) $m1['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH', 's', 3), rc($t['n'], 'q'), rc($t['awal'], 'n'), rc($t['masuk'], 'n'), rc($t['keluar'], 'n'), rc($t['akhir'], 'n')]];
    else $m1['body'][] = ['cells' => [rc('Tidak ada data persediaan pada periode dan jenis barang ini.', 'c', 8)]];
    $out = [['sheet' => 'Per Jenis Barang', 'judul' => 'Per jenis barang', 'model' => $m1]];

    // --- per SKPD / unit
    if (count($unitIds) > 1) {
        $groups = []; // key => ['nama', 'level']
        $keyOf = [];  // unit_id => key
        if (!$ent && dash_is_skpd($f)) {
            foreach ($f['scope']['entities'] as $id => $e) $groups[$id] = ['nama' => $e['nama'], 'level' => 'skpd'];
            foreach (dash_unit_map($f) as $uid => $sid) $keyOf[$uid] = $sid;
            $lbl = 'SKPD';
        } else {
            foreach (rows('SELECT id, nama, level, parent_id FROM unit_kerja WHERE id IN (' . in_ph($unitIds) . ') ORDER BY COALESCE(parent_id,id), level, nama', $unitIds) as $x) {
                $groups[(int)$x['id']] = ['nama' => $x['nama'], 'level' => $x['level']];
                $keyOf[(int)$x['id']] = (int)$x['id'];
            }
            $lbl = 'UNIT / SUB UNIT';
        }
        $agg = [];
        foreach ($groups as $k => $_) $agg[$k] = ['n' => 0, 'awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0];
        foreach ($data as $r) {
            $k = $keyOf[(int)$r['unit_id']] ?? null;
            if ($k === null || !isset($agg[$k])) continue;
            $agg[$k]['n']++;
            foreach (['awal', 'masuk', 'keluar', 'akhir'] as $x) $agg[$k][$x] += $r[$x . '_v'];
        }
        $m2 = [
            'titles' => ['RINCIAN NILAI PERSEDIAAN PER ' . $lbl, $titleTgl],
            'meta' => $meta,
            'cols' => [5, 56, 11, 19, 19, 19, 19],
            'head' => [['NO', $lbl, 'JML BARANG', 'SALDO AWAL (Rp)', 'PENERIMAAN (Rp)', 'PENGELUARAN (Rp)', 'SALDO AKHIR (Rp)']],
            'body' => [],
        ];
        $t = ['n' => 0, 'awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0];
        $i = 0;
        foreach ($groups as $k => $g) {
            $i++;
            $v = $agg[$k];
            $m2['body'][] = ['cells' => [rc($i, 'c'), ($g['level'] === 'subunit' ? '   ↳ ' : '') . $g['nama'], rc($v['n'], 'q'), rc($v['awal'], 'n'), rc($v['masuk'], 'n'), rc($v['keluar'], 'n'), rc($v['akhir'], 'n')]];
            foreach ($t as $kk => $_) $t[$kk] += $v[$kk];
        }
        $m2['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH', 's', 2), rc($t['n'], 'q'), rc($t['awal'], 'n'), rc($t['masuk'], 'n'), rc($t['keluar'], 'n'), rc($t['akhir'], 'n')]];
        $out[] = ['sheet' => 'Per ' . ($lbl === 'SKPD' ? 'SKPD' : 'Unit'), 'judul' => 'Per ' . ($lbl === 'SKPD' ? 'SKPD' : 'unit / sub unit'), 'model' => $m2];
    }
    return $out;
}

/** Daftar SKPD / unit yang belum menginput pembelian dan/atau pemakaian */
function dash_model_belum(array $f, $semua = false)
{
    $isSkpd = dash_is_skpd($f);
    $status = dash_status_input($f);
    $info = [];
    if ($isSkpd) {
        foreach (rows('SELECT id, pic, telp, email FROM skpd') as $x) $info[(int)$x['id']] = $x;
    } elseif ($f['scope']['unit_ids']) {
        foreach (rows('SELECT id, pic, telp, email FROM unit_kerja WHERE id IN (' . in_ph($f['scope']['unit_ids']) . ')', $f['scope']['unit_ids']) as $x) $info[(int)$x['id']] = $x;
    }
    $lbl = $isSkpd ? 'SKPD' : 'UNIT / SUB UNIT';
    $list = [];
    foreach ($f['scope']['entities'] as $id => $e) {
        $s = $status[$id];
        if ($s['beli'] && $s['pakai']) { $ket = 'Sudah lengkap'; $ord = 3; }
        elseif (!$s['beli'] && !$s['pakai']) { $ket = 'Belum input pembelian & pemakaian'; $ord = 0; }
        elseif (!$s['beli']) { $ket = 'Belum input pembelian'; $ord = 1; }
        else { $ket = 'Belum input pemakaian'; $ord = 2; }
        if ($ord === 3 && !$semua) continue;
        $list[] = ['id' => $id, 'nama' => $e['nama'], 'level' => $e['level'], 'beli' => $s['beli'], 'pakai' => $s['pakai'], 'ket' => $ket, 'ord' => $ord];
    }
    if ($isSkpd) usort($list, function ($a, $b) { return [$a['ord'], $a['nama']] <=> [$b['ord'], $b['nama']]; });

    $meta = dash_ent_meta($f, 0);
    $m = [
        'titles' => ['DAFTAR ' . $lbl . ' YANG BELUM MENGINPUT PEMBELIAN / PEMAKAIAN', $f['plabel'] . ' TAHUN ' . $f['tahun']],
        'meta' => $meta,
        'cols' => [5, 50, 26, 18, 14, 14, 32],
        'head' => [[rc('NO', 's', 1, 2), rc($lbl, 's', 1, 2), rc('PIC', 's', 1, 2), rc('TELEPON', 's', 1, 2), rc('JUMLAH TRANSAKSI', 's', 2), rc('KETERANGAN', 's', 1, 2)], ['PEMBELIAN', 'PEMAKAIAN']],
        'body' => [], 'landscape' => true,
        'notes' => ['Dihitung dari transaksi pembelian dan pemakaian (input manual / impor Excel) berstatus menunggu validasi atau disetujui dengan tanggal dalam periode'
            . ($f['sub'] ? ' dan memuat jenis barang ' . dash_sub_label($f) : '') . '. Saldo awal dan penyesuaian stock opname tidak dihitung.'],
    ];
    $no = 0;
    foreach ($list as $x) {
        $no++;
        $in = $info[$x['id']] ?? ['pic' => '', 'telp' => ''];
        $m['body'][] = ['cells' => [rc($no, 'c'), ($x['level'] === 'subunit' ? '   ↳ ' : '') . $x['nama'], (string)($in['pic'] ?? ''), (string)($in['telp'] ?? ''),
            rc($x['beli'] ?: '-', 'c'), rc($x['pakai'] ?: '-', 'c'), $x['ket']]];
    }
    if (!$list) $m['body'][] = ['cells' => [rc('Semua ' . ($isSkpd ? 'SKPD' : 'unit / sub unit') . ' sudah menginput pembelian dan pemakaian pada periode ini.', 'c', 7)]];
    return $m;
}
