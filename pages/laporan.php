<?php
defined('BASE_PATH') or exit;
$u = user();

// ------------------------------------------------------------------ parameter
$jenis = in_array(input('jenis'), ['mutasi', 'rekap', 'objek'], true) ? input('jenis') : 'mutasi';
$defP = ['ytd', (int)date('Y')]; // bawaan: awal tahun berjalan s.d. hari ini
$tahun = input_int('tahun', $defP[1]);
$periode = (string)input('periode', $defP[0]);
list($dari, $sampai, $plabel, $tahun) = lap_periode($tahun, $periode, (string)input('dari', ''), (string)input('sampai', ''));
$skpdF = $u['role'] === 'superadmin' ? input_int('skpd') : 0;
$unitF = input_int('unit');
$subF = input_int('sub');
$semuaKolom = input('f') ? (input('semua') ? 1 : 0) : 1;
$ringkas = input('ringkas') ? 1 : 0;
$detail6 = input('detail6') ? 1 : 0;

$scope = lap_scope($u, $skpdF, $unitF);
$pemerintah = setting('nama_pemerintah', 'PEMERINTAH DAERAH');
$pemda = setting('nama_pemda', '');

// ------------------------------------------------------------------ tanda tangan
$td = lap_ttd_default($u, $scope);
$ttdIn = [];
foreach (['kota', 'tgl', 'kiri_jabatan', 'kiri_nama', 'kiri_nip', 'kanan_jabatan', 'kanan_nama', 'kanan_nip'] as $k) {
    $ttdIn[$k] = input('t_' . $k) !== null ? (string)input('t_' . $k) : ($k === 'tgl' ? date('Y-m-d') : ($td[$k] ?? ''));
}
$ttd = [
    'tempat' => trim($ttdIn['kota'] . ', ' . (valid_date($ttdIn['tgl']) ? tgl_indo($ttdIn['tgl']) : ''), ', '),
    'kiri' => array_values(array_filter(array_map('trim', explode("\n", str_replace('\n', "\n", $ttdIn['kiri_jabatan']))))),
    'kiri_nama' => $ttdIn['kiri_nama'], 'kiri_nip' => $ttdIn['kiri_nip'],
    'kanan' => array_values(array_filter(array_map('trim', explode("\n", str_replace('\n', "\n", $ttdIn['kanan_jabatan']))))),
    'kanan_nama' => $ttdIn['kanan_nama'], 'kanan_nip' => $ttdIn['kanan_nip'],
];

$kodeAll = [];
foreach (lap_kode_tree() as $k) $kodeAll[$k['kode']] = $k;
$scopeMeta = [];
if ($scope['skpd']) $scopeMeta[] = ['SKPD', $scope['skpd']['nama']];
if ($scope['unit']) $scopeMeta[] = [$scope['unit']['level'] === 'subunit' ? 'SUB UNIT' : 'UNIT', $scope['unit']['nama'] . ($scope['unit']['level'] === 'unit' && count($scope['unit_ids']) > 1 ? ' (termasuk sub unit)' : '')];
$scopeMeta[] = ['PERIODE', $plabel . ' TAHUN ' . $tahun . ' (' . tgl_indo($dari) . ' s.d. ' . tgl_indo($sampai) . ')'];

// ==================================================================
// 1. LAPORAN MUTASI / STOCK OPNAME PER BARANG
// ==================================================================
function model_mutasi($scope, $dari, $sampai, $subF, $scopeMeta, $ttd, $tahun, $periode)
{
    $data = lap_mutasi($scope['unit_ids'], $dari, $sampai, $subF, $tahun);
    $byUnit = [];
    foreach ($data as $r) $byUnit[(int)$r['unit_id']][] = $r;

    // Rincian kolom PENERIMAAN per triwulan, menyesuaikan periode yang dipilih:
    //  - Semester I          -> TW I, TW II
    //  - Semester II / Tahun -> TW I s.d. TW IV (dikelompokkan per semester)
    //  - periode lain (per triwulan, s.d. hari ini, rentang bebas) -> kolom PENERIMAAN tunggal (seperti semula)
    if ($periode === 'smt1') $twList = [1, 2];
    elseif (in_array($periode, ['smt2', 'tahun'], true)) $twList = [1, 2, 3, 4];
    else $twList = [];
    $twCount = count($twList);
    $fullRs = $twCount === 4 ? 3 : 2;   // rowspan kolom yang utuh (tanpa sub kolom)
    $saldoRs = $twCount === 4 ? 2 : 1;  // rowspan SALDO & SELISIH (anaknya baru muncul 1 baris di bawahnya)

    $cols = array_merge([5, 44, 9, 10], array_fill(0, max(1, $twCount), 11), [11, 11, 10, 10, 15, 17, 17, 8, 13]);
    $nc = count($cols);

    $row1 = [
        rc('NO', 's', 1, $fullRs), rc('NAMA / JENIS BARANG', 's', 1, $fullRs), rc('SATUAN', 's', 1, $fullRs), rc('SALDO AWAL', 's', 1, $fullRs),
        rc('PENERIMAAN', 's', $twCount ?: 1, $twCount ? 1 : $fullRs),
        rc("JUMLAH\nPERSEDIAAN", 's', 1, $fullRs), rc("JUMLAH\nPENGELUARAN", 's', 1, $fullRs),
        rc('SALDO', 's', 2, $saldoRs),
        rc("HARGA SATUAN\n(Rp)", 's', 1, $fullRs), rc("JUMLAH\nPENERIMAAN\n(Rp)", 's', 1, $fullRs), rc("NILAI PERSEDIAAN\n(Rp)", 's', 1, $fullRs),
        rc('SELISIH ADM & FISIK', 's', 2, $saldoRs),
    ];
    $head = [$row1];
    if ($twCount === 4) {
        $head[] = [rc('Semester 1', 's', 2, 1), rc('Semester 2', 's', 2, 1)];
        $head[] = ['TW I', 'TW II', 'TW III', 'TW IV', 'ADM', 'FISIK', 'Unit', 'Rp'];
    } elseif ($twCount === 2) {
        $head[] = ['TW I', 'TW II', 'ADM', 'FISIK', 'Unit', 'Rp'];
    } else {
        $head[] = ['ADM', 'FISIK', 'Unit', 'Rp'];
    }
    // baris nomor referensi kolom (mis. "9=4+5+6+7+8"), dihitung otomatis mengikuti jumlah kolom TW
    $col = 0;
    $nextCol = function () use (&$col) { return (string)(++$col); };
    $refs = [$nextCol(), $nextCol(), $nextCol(), $nextCol()];
    $penStart = $col + 1;
    if ($twCount) { for ($i = 0; $i < $twCount; $i++) $refs[] = $nextCol(); } else $refs[] = $nextCol();
    $penEnd = $col;
    $penTerms = implode('+', range($penStart, $penEnd));
    $jpCol = $nextCol(); $refs[] = $jpCol . '=4+' . $penTerms;
    $jkCol = $nextCol(); $refs[] = $jkCol;
    $sadmCol = $nextCol(); $refs[] = $sadmCol . '=' . $jpCol . '-' . $jkCol;
    $refs[] = $nextCol(); // saldo fisik
    $hsCol = $nextCol(); $refs[] = $hsCol;
    $jprCol = $nextCol(); $refs[] = $jprCol . '=' . ($penEnd > $penStart ? '(' . $penTerms . ')' : $penTerms) . 'x' . $hsCol;
    $nvCol = $nextCol(); $refs[] = $nvCol . '=' . $sadmCol . 'x' . $hsCol;
    $refs[] = $nextCol(); // selisih unit
    $refs[] = $nextCol(); // selisih rp
    $head[] = $refs;

    $m = [
        'titles' => ['LAPORAN MUTASI DAN STOCK OPNAME BARANG PERSEDIAAN', 'POSISI ' . mb_strtoupper(tgl_indo($sampai))],
        'meta' => $scopeMeta,
        'cols' => $cols,
        'head' => $head,
        'body' => [], 'landscape' => true, 'ttd' => $ttd,
        'notes' => [
            'Kolom Saldo Fisik dan Selisih diisi saat pelaksanaan stock opname (lihat menu Stock Opname untuk Berita Acara hasil hitung fisik).',
            'Kolom "Jumlah Penerimaan (Rp)" = jumlah seluruh transaksi penerimaan yang disetujui pada periode terpilih × harga satuan masing-masing (dihitung presisi per transaksi, bukan rata-rata), untuk pencocokan dengan LRA.',
        ],
    ];
    if ($twCount) $m['notes'][] = 'Rincian TW I' . ($twCount === 4 ? ' s.d. TW IV' : '–II') . ' pada kolom PENERIMAAN hanya ditampilkan untuk periode Semester/Tahunan.';
    $rekap = [];
    $grand = 0;
    // kelompok selalu per unit/sub unit (bila cakupan = seluruh SKPD, nama SKPD ditampilkan di depan)
    $units = $scope['entities'];
    if ($scope['entity'] === 'skpd') {
        $units = [];
        foreach (rows('SELECT uk.id, uk.nama, uk.level, uk.parent_id, s.nama AS skpd_nama FROM unit_kerja uk JOIN skpd s ON s.id=uk.skpd_id
                       ORDER BY (s.kode IS NULL), s.kode+0, s.kode, s.nama, COALESCE(uk.parent_id, uk.id), uk.level, uk.nama') as $x) {
            $units[(int)$x['id']] = ['nama' => $x['skpd_nama'] . ' — ' . $x['nama'], 'level' => $x['level'], 'parent_id' => $x['parent_id']];
        }
    }
    foreach ($units as $uid => $ent) {
        if (empty($byUnit[$uid])) continue;
        $m['body'][] = ['cls' => 'g', 'cells' => [rc(mb_strtoupper(($ent['level'] === 'subunit' ? '› ' : '') . $ent['nama']), 's', $nc)]];
        $curSub = null; $no = 0; $tot = 0;
        foreach ($byUnit[$uid] as $r) {
            if ($curSub !== $r['sub_kode']) {
                $curSub = $r['sub_kode']; $no = 0;
                $m['body'][] = ['cls' => '', 'cells' => [rc(''), rc($r['sub_kode'] . '  ' . judul($r['sub_uraian']), 's', $nc - 1)]];
            }
            $no++;
            $cells = [rc($no, 'c'), $r['nama_barang'], rc($r['satuan'], 'c'), rc($r['awal_q'], 'q')];
            if ($twCount) { foreach ($twList as $tn) $cells[] = rc($r['tw' . $tn . '_q'], 'q'); } else $cells[] = rc($r['masuk_q'], 'q');
            $cells[] = rc($r['awal_q'] + $r['masuk_q'], 'q');
            $cells[] = rc($r['keluar_q'], 'q');
            $cells[] = rc($r['akhir_q'], 'q');
            $cells[] = rc('', 'q'); // saldo fisik (diisi saat stock opname)
            $cells[] = rc($r['harga'], 'n');
            $cells[] = rc($r['masuk_v'], 'n'); // jumlah penerimaan (Rp)
            $cells[] = rc($r['akhir_v'], 'n');
            $cells[] = rc('', 'q'); // selisih unit
            $cells[] = rc('', 'n'); // selisih rp
            $m['body'][] = ['cells' => $cells];
            $tot += $r['akhir_v'];
            $k = $r['sub_kode'];
            if (!isset($rekap[$k])) $rekap[$k] = ['uraian' => $r['sub_uraian'], 'awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0, 'n' => 0];
            $rekap[$k]['awal'] += $r['awal_v']; $rekap[$k]['masuk'] += $r['masuk_v']; $rekap[$k]['keluar'] += $r['keluar_v']; $rekap[$k]['akhir'] += $r['akhir_v']; $rekap[$k]['n']++;
        }
        $m['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH ' . mb_strtoupper($ent['nama']), 's', $nc - 3), rc($tot, 'n'), rc('', 's', 2)]];
        $grand += $tot;
    }
    if ($m['body']) $m['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH TOTAL', 's', $nc - 3), rc($grand, 'n'), rc('', 's', 2)]];
    else $m['body'][] = ['cells' => [rc('Tidak ada data persediaan pada periode ini.', 'c', $nc)]];
    ksort($rekap);
    $r2 = [
        'titles' => ['REKAP NILAI PERSEDIAAN PER JENIS BARANG', 'POSISI ' . mb_strtoupper(tgl_indo($sampai))],
        'meta' => $scopeMeta,
        'cols' => [5, 18, 44, 10, 18, 18, 18, 18],
        'head' => [['NO', 'KODE', 'JENIS BARANG (SUB RINCIAN OBJEK)', 'JML BARANG', 'SALDO AWAL (Rp)', 'PENERIMAAN (Rp)', 'PENGELUARAN (Rp)', 'SALDO AKHIR (Rp)']],
        'body' => [], 'ttd' => $ttd,
    ];
    $i = 0; $t = ['awal' => 0, 'masuk' => 0, 'keluar' => 0, 'akhir' => 0];
    foreach ($rekap as $k => $v) {
        $i++;
        $r2['body'][] = ['cells' => [rc($i, 'c'), rc($k, 'c'), judul($v['uraian']), rc($v['n'], 'q'), rc($v['awal'], 'n'), rc($v['masuk'], 'n'), rc($v['keluar'], 'n'), rc($v['akhir'], 'n')]];
        foreach ($t as $kk => $_) $t[$kk] += $v[$kk];
    }
    $r2['body'][] = ['cls' => 't', 'cells' => [rc('JUMLAH', 's', 4), rc($t['awal'], 'n'), rc($t['masuk'], 'n'), rc($t['keluar'], 'n'), rc($t['akhir'], 'n')]];
    return [$m, $r2];
}

// ==================================================================
// 2. REKAP RINCIAN PERSEDIAAN (FORMAT 18.1) — matriks
// ==================================================================
function model_rekap($scope, $dari, $sampai, $kodeAll, $semuaKolom, $pemerintah, $ttd)
{
    $vals = lap_nilai_sub($scope['unit_ids'], $dari, $sampai, $scope['entity']);
    // kolom = sub rincian objek (level 6)
    $subs = array_filter($kodeAll, function ($k) { return (int)$k['level'] === 6; });
    if (!$semuaKolom) {
        $used = [];
        foreach ($vals as $e) foreach ($e as $k => $v) if (abs($v['akhir']) > 0.004) $used[$k] = true;
        $subs = array_filter($subs, function ($k) use ($used) { return isset($used[$k['kode']]); });
    }
    $subs = array_values($subs);
    $g4 = $g5 = [];
    foreach ($subs as $s) {
        $k4 = implode('.', array_slice(explode('.', $s['kode']), 0, 4));
        $k5 = implode('.', array_slice(explode('.', $s['kode']), 0, 5));
        $g4[$k4] = ($g4[$k4] ?? 0) + 1;
        $g5[$k5] = ($g5[$k5] ?? 0) + 1;
    }
    $nc = count($subs);
    $entLabel = $scope['entity'] === 'skpd' ? 'SKPD' : 'UNIT / SUB UNIT';
    $h1 = [rc('NO.', 's', 1, 3), rc($entLabel, 's', 1, 3)];
    foreach ($g4 as $k => $n) $h1[] = rc(mb_strtoupper(judul($kodeAll[$k]['uraian'] ?? $k)), 's', $n);
    $h1[] = rc('TOTAL', 's', 1, 3);
    $h2 = []; foreach ($g5 as $k => $n) $h2[] = rc(judul($kodeAll[$k]['uraian'] ?? $k), 's', $n);
    $h3 = []; foreach ($subs as $s) $h3[] = judul($s['uraian']);
    $h4 = []; for ($i = 1; $i <= $nc + 3; $i++) $h4[] = (string)$i;
    $m = [
        'titles' => [$pemerintah, 'REKAP NILAI PERSEDIAAN', 'PER ' . mb_strtoupper(tgl_indo($sampai))],
        'meta' => $scope['skpd'] ? [['SKPD', $scope['skpd']['nama']]] : [],
        'cols' => array_merge([5, 40], array_fill(0, $nc, 15), [18]),
        'head' => [$h1, $h2, $h3, $h4],
        'body' => [], 'landscape' => true, 'matrix' => true, 'ttd' => $ttd,
    ];
    $colTot = array_fill(0, $nc, 0); $grand = 0; $no = 0;
    foreach ($scope['entities'] as $eid => $ent) {
        $no++;
        $cells = [rc($no, 'c'), ($ent['level'] === 'subunit' ? '   ↳ ' : '') . $ent['nama']];
        $rt = 0;
        foreach ($subs as $i => $s) {
            $v = $vals[$eid][$s['kode']]['akhir'] ?? 0;
            $cells[] = rc($v ?: '', 'n');
            $colTot[$i] += $v; $rt += $v;
        }
        $cells[] = rc($rt, 'n');
        $grand += $rt;
        $m['body'][] = ['cells' => $cells];
    }
    $cells = [rc('JUMLAH', 's', 2)];
    foreach ($colTot as $v) $cells[] = rc($v, 'n');
    $cells[] = rc($grand, 'n');
    $m['body'][] = ['cls' => 't', 'cells' => $cells];
    return $m;
}

// ==================================================================
// 3. REKAPITULASI LAPORAN PERSEDIAAN MENURUT OBJEK (FORMAT IV.L.1.4)
// ==================================================================
function model_objek($scope, $dari, $sampai, $plabel, $tahun, $kodeAll, $ringkas, $detail6, $pemda, $ttd)
{
    $vals = lap_nilai_sub($scope['unit_ids'], $dari, $sampai, $scope['entity']);
    $agg = [];
    foreach ($vals as $e) foreach ($e as $k6 => $v) {
        $seg = explode('.', $k6);
        for ($lv = 2; $lv <= 6; $lv++) {
            $k = implode('.', array_slice($seg, 0, $lv));
            foreach (['awal', 'masuk', 'keluar', 'akhir'] as $f) $agg[$k][$f] = ($agg[$k][$f] ?? 0) + $v[$f];
        }
    }
    $maxLv = $detail6 ? 6 : 5;
    $kc = $maxLv; // jumlah kolom kode (akun..rincian/sub rincian)
    $valCols = $ringkas ? ['akhir' => 'Saldo Akhir'] : ['awal' => 'Saldo Awal', 'masuk' => 'Mutasi Tambah', 'keluar' => 'Mutasi Kurang', 'akhir' => 'Saldo Akhir'];
    $kodeHead = ['Akun', 'Kel.', 'Jenis', 'Objek', 'Rincian', 'Sub Rinc.'];
    $h1 = [rc('Penggolongan dan Kodefikasi Barang', 's', $kc + 1)];
    foreach ($valCols as $lbl) $h1[] = rc($lbl, 's', 1, 2);
    $h2 = array_slice($kodeHead, 0, $kc); $h2[] = 'Nama Barang';
    $h3 = [rc('(1)', 'c', $kc), '(2)'];
    $num = $ringkas ? ['(6)'] : ['(3)', '(4)', '(5)', '(6)'];
    foreach ($num as $n) $h3[] = $n;
    $judulScope = $scope['skpd'] ? mb_strtoupper($scope['skpd']['nama']) . ($scope['unit'] ? ' - ' . mb_strtoupper($scope['unit']['nama']) : '') : $pemda;
    $m = [
        'titles' => ['REKAPITULASI LAPORAN PERSEDIAAN', 'MENURUT OBJEK', $judulScope, $plabel, 'TAHUN ' . $tahun],
        'meta' => [],
        'cols' => array_merge(array_fill(0, $kc, 6), [46], array_fill(0, count($valCols), 21)),
        'head' => [$h1, [rc('Kode Barang', 's', $kc), rc('Nama Barang', 's', 1, 1)], $h3],
        'body' => [], 'landscape' => false, 'ttd' => $ttd,
    ];
    // sesuaikan baris header ke-2: kode per level
    $m['head'][1] = array_merge(array_map(function ($l) { return rc($l, 'c'); }, array_slice($kodeHead, 0, $kc)), [rc('Nama Barang')]);
    $rowFor = function ($k) use ($kodeAll, $agg, $valCols, $kc) {
        $seg = explode('.', $k);
        $cells = [];
        for ($i = 0; $i < $kc; $i++) $cells[] = rc(isset($seg[$i]) ? (string)(int)$seg[$i] : '', 'c');
        $cells[] = judul($kodeAll[$k]['uraian'] ?? $k);
        foreach ($valCols as $f => $_) $cells[] = rc(round($agg[$k][$f] ?? 0, 2), 'n');
        return $cells;
    };
    $blank = function () use ($kc, $valCols) { $c = []; for ($i = 0; $i < $kc + 1 + count($valCols); $i++) $c[] = ''; return $c; };
    foreach ($kodeAll as $k => $kd) {
        $lv = (int)$kd['level'];
        if ($lv > $maxLv) continue;
        if ($lv === 4 && $m['body']) $m['body'][] = ['cells' => $blank()];
        $m['body'][] = ['cls' => $lv <= 4 ? 'g' : '', 'cells' => $rowFor($k)];
    }
    return $m;
}

// ------------------------------------------------------------------ bangun model
if ($jenis === 'mutasi') {
    list($model, $model2) = model_mutasi($scope, $dari, $sampai, $subF, $scopeMeta, $ttd, $tahun, $periode);
} elseif ($jenis === 'rekap') {
    $model = model_rekap($scope, $dari, $sampai, $kodeAll, $semuaKolom, $pemerintah, $ttd);
    $model2 = null;
} else {
    $model = model_objek($scope, $dari, $sampai, $plabel, $tahun, $kodeAll, $ringkas, $detail6, $pemda, $ttd);
    $model2 = null;
}

// ------------------------------------------------------------------ ekspor
if (input('export') === 'xlsx') {
    $x = new XlsxWriter();
    $names = ['mutasi' => 'Mutasi Persediaan', 'rekap' => 'Rekap Rincian 18.1', 'objek' => 'Menurut Objek IV.L.1.4'];
    $x->addSheet($names[$jenis]);
    report_xlsx($x, $model);
    if ($model2) { $x->addSheet('Rekap'); report_xlsx($x, $model2); }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($scope['label'] ?: 'laporan'));
    log_aktivitas('laporan_ekspor', $jenis . ' ' . $scope['label'] . ' ' . $plabel . ' ' . $tahun);
    $x->download('laporan-' . $jenis . '-' . trim($slug, '-') . '-' . $tahun . '-' . $periode . '.xlsx');
}

// ------------------------------------------------------------------ tampilan
$skpdList = $u['role'] === 'superadmin' ? rows('SELECT id, nama FROM skpd ORDER BY nama') : [];
$unitOpts = $scope['allowed'] ?? [];
$subOpts = $jenis === 'mutasi' ? array_filter($kodeAll, function ($k) { return (int)$k['level'] === 6; }) : [];
$qs = $_GET;
$pageTitle = 'Laporan';
include BASE_PATH . '/views/header.php';
$tabs = [
    'mutasi' => ['Mutasi & Stock Opname', 'Per barang: saldo awal, penerimaan, pengeluaran, saldo akhir & nilai (format stock opname BPKAD) + rekap per jenis barang.'],
    'rekap' => ['Rekap Rincian (18.1)', 'Matriks nilai saldo per ' . ($scope['entity'] === 'skpd' ? 'SKPD' : 'unit') . ' × sub rincian objek (format Rekap Nilai Persediaan 18.1).'],
    'objek' => ['Menurut Objek (IV.L.1.4)', 'Rekapitulasi laporan persediaan menurut objek & rincian objek (format IV.L.1.4).'],
];
?>
<div class="page-head no-print">
  <div><h1>Laporan Persediaan</h1><p><?= e($tabs[$jenis][1]) ?></p></div>
  <div class="actions">
    <a class="btn" href="<?= e('?' . http_build_query(array_merge($qs, ['export' => 'xlsx']))) ?>"><?= icon('excel') ?> Ekspor Excel</a>
    <button class="btn btn-light" type="button" onclick="window.print()"><?= icon('print') ?> Cetak / PDF</button>
  </div>
</div>
<div class="tabs no-print">
  <?php foreach ($tabs as $k => $t): ?><a class="<?= $jenis === $k ? 'active' : '' ?>" href="<?= e('?' . http_build_query(array_merge($qs, ['jenis' => $k, 'export' => null]))) ?>"><?= e($t[0]) ?></a><?php endforeach; ?>
</div>

<form method="get" class="card no-print" id="fLap">
  <input type="hidden" name="page" value="laporan"><input type="hidden" name="jenis" value="<?= e($jenis) ?>"><input type="hidden" name="f" value="1">
  <div class="card-body">
    <div class="filters">
      <?php if ($u['role'] === 'superadmin'): ?>
        <div class="field" style="min-width:260px"><label>SKPD</label><select name="skpd" onchange="this.form.unit && (this.form.unit.value='');this.form.submit()">
          <option value="0">— Seluruh SKPD —</option>
          <?php foreach ($skpdList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $skpdF ? 'selected' : '' ?>><?= e($s['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <?php if ($unitOpts && count($unitOpts) > 1): ?>
        <div class="field" style="min-width:240px"><label>Unit / Sub Unit</label><select name="unit">
          <option value="0"><?= $u['role'] === 'admin' ? '— ' . e($u['unit_nama']) . ' + sub unit —' : '— Semua unit —' ?></option>
          <?php foreach ($unitOpts as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $unitF ? 'selected' : '' ?>><?= $x['level'] === 'subunit' ? '↳ ' : '' ?><?= e($x['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="field" style="min-width:110px"><label>Tahun</label><select name="tahun"><?php for ($y = (int)date('Y') + 1; $y >= 2020; $y--): ?><option <?= $y === $tahun ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
      <div class="field" style="min-width:150px"><label>Periode</label><select name="periode" id="periode">
        <?php foreach (['ytd' => 'Awal tahun s.d. hari ini', 'tw1' => 'Triwulan I', 'tw2' => 'Triwulan II', 'tw3' => 'Triwulan III', 'tw4' => 'Triwulan IV', 'smt1' => 'Semester I', 'smt2' => 'Semester II', 'tahun' => 'Tahunan', 'custom' => 'Rentang tanggal…'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $periode === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="field custom-range" style="min-width:140px"><label>Dari</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
      <div class="field custom-range" style="min-width:140px"><label>Sampai</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
      <?php if ($jenis === 'mutasi'): ?>
        <div class="field" style="min-width:240px"><label>Jenis Barang</label><select name="sub"><option value="0">Semua sub rincian objek</option>
          <?php foreach ($subOpts as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $subF ? 'selected' : '' ?>><?= e($s['kode'] . ' ' . judul($s['uraian'])) ?></option><?php endforeach; ?></select></div>
      <?php elseif ($jenis === 'rekap'): ?>
        <label class="check" style="margin-bottom:9px"><input type="checkbox" name="semua" value="1" <?= $semuaKolom ? 'checked' : '' ?>> Tampilkan semua kolom (termasuk bernilai nol)</label>
      <?php else: ?>
        <label class="check" style="margin-bottom:9px"><input type="checkbox" name="ringkas" value="1" <?= $ringkas ? 'checked' : '' ?>> Hanya saldo akhir</label>
        <label class="check" style="margin-bottom:9px"><input type="checkbox" name="detail6" value="1" <?= $detail6 ? 'checked' : '' ?>> Rinci s.d. sub rincian objek</label>
      <?php endif; ?>
      <button class="btn" type="submit">Tampilkan</button>
    </div>
    <details style="margin-top:12px">
      <summary class="small" style="cursor:pointer;color:var(--accent);font-weight:600">Pengaturan tanda tangan</summary>
      <div class="form-grid" style="margin-top:10px">
        <div class="field col-3"><label>Tempat</label><input type="text" name="t_kota" value="<?= e($ttdIn['kota']) ?>"></div>
        <div class="field col-3"><label>Tanggal</label><input type="date" name="t_tgl" value="<?= e($ttdIn['tgl']) ?>"></div>
        <div class="field col-6"></div>
        <div class="field col-6"><label>Penandatangan kiri — jabatan (baris baru = Enter)</label><textarea name="t_kiri_jabatan" rows="2"><?= e($ttdIn['kiri_jabatan']) ?></textarea></div>
        <div class="field col-6"><label>Penandatangan kanan — jabatan</label><textarea name="t_kanan_jabatan" rows="2"><?= e($ttdIn['kanan_jabatan']) ?></textarea></div>
        <div class="field col-3"><label>Nama kiri</label><input type="text" name="t_kiri_nama" value="<?= e($ttdIn['kiri_nama']) ?>"></div>
        <div class="field col-3"><label>NIP kiri</label><input type="text" name="t_kiri_nip" value="<?= e($ttdIn['kiri_nip']) ?>"></div>
        <div class="field col-3"><label>Nama kanan</label><input type="text" name="t_kanan_nama" value="<?= e($ttdIn['kanan_nama']) ?>"></div>
        <div class="field col-3"><label>NIP kanan</label><input type="text" name="t_kanan_nip" value="<?= e($ttdIn['kanan_nip']) ?>"></div>
      </div>
    </details>
  </div>
</form>

<?php $luar = lap_info_luar($scope['unit_ids'], $sampai); ?>
<div class="alert alert-info no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <span>Periode laporan: <b><?= tgl_indo($dari) ?> s.d. <?= tgl_indo($sampai) ?></b>.</span>
  <?php if ($luar['setelah']): ?>
    <span><?= (int)$luar['setelah'] ?> transaksi bertanggal setelah <?= tgl_indo($sampai) ?> (terakhir <?= tgl_indo($luar['tgl_terakhir']) ?>) tidak termasuk —
      <a href="<?= e('?' . http_build_query(array_merge($qs, ['periode' => 'ytd', 'tahun' => substr($luar['tgl_terakhir'], 0, 4), 'export' => null]))) ?>">tampilkan s.d. hari ini</a>.</span>
  <?php endif; ?>
  <?php if ($luar['menunggu']): ?><span><?= (int)$luar['menunggu'] ?> transaksi masih <b>menunggu validasi</b> dan belum dihitung.</span><?php endif; ?>
</div>
<div class="card">
  <div class="card-body">
    <div class="report-scroll"><?= report_html($model) ?></div>
    <?php if ($model2): ?><div class="page-break"></div><h3 class="no-print" style="margin:22px 0 10px">Rekap per jenis barang</h3><div class="report-scroll"><?= report_html($model2) ?></div><?php endif; ?>
  </div>
</div>
<?php
$pageScript = <<<'JS'
<script>
(function(){
  var p = document.getElementById('periode');
  function t(){ document.querySelectorAll('.custom-range').forEach(function(el){ el.style.display = p.value === 'custom' ? '' : 'none'; }); }
  p.addEventListener('change', t); t();
})();
</script>
JS;
include BASE_PATH . '/views/footer.php';
