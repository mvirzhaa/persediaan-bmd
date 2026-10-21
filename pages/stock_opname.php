<?php
defined('BASE_PATH') or exit;
$u = user();
$id = input_int('id');

// =====================================================================
//  A. LEMBAR STOCK OPNAME (detail)
// =====================================================================
if ($id) {
    $so = row('SELECT so.*, uk.nama AS unit_nama, uk.level AS unit_level, uk.pic AS unit_pic, s.nama AS skpd_nama, s.pic AS skpd_pic,
                      a.nama AS pembuat, b.nama AS penutup
               FROM stock_opname so JOIN unit_kerja uk ON uk.id=so.unit_id JOIN skpd s ON s.id=uk.skpd_id
               JOIN users a ON a.id=so.dibuat_oleh LEFT JOIN users b ON b.id=so.selesai_oleh WHERE so.id=?', [$id]);
    if (!$so) { flash('error', 'Stock opname tidak ditemukan.'); redirect(url('stock_opname')); }
    $hak = so_hak($so, $u);
    if (!$hak['lihat']) { flash('error', 'Akses ditolak.'); redirect(url('stock_opname')); }

    if (is_post()) {
        csrf_check();
        $aksi = input('aksi');
        try {
            if (in_array($aksi, ['simpan', 'selesai'], true)) {
                if (!$hak['kelola']) throw new RuntimeException('Hanya Admin ' . ($so['unit_level'] === 'subunit' ? 'Sub Unit' : 'Unit') . ' yang bersangkutan yang dapat mengisi stock opname ini.');
                so_simpan_fisik($id, (array)($_POST['fisik'] ?? []), (array)($_POST['ket'] ?? []));
                if ($aksi === 'selesai') {
                    list($k, $l) = so_selesai($id, $u);
                    flash('success', "Stock opname selesai. Penyesuaian: $k barang kurang, $l barang lebih. Transaksi kembali dibuka.");
                } else flash('success', 'Hasil hitung fisik disimpan sementara.');
            } elseif ($aksi === 'batal') {
                if (!$hak['batal']) throw new RuntimeException('Anda tidak berhak membatalkan stock opname ini.');
                so_batal($id, $u);
                flash('success', 'Stock opname dibatalkan. Transaksi kembali dibuka tanpa penyesuaian.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('stock_opname', ['id' => $id]));
    }

    $det = rows('SELECT sd.*, b.nama_barang, b.satuan, k.kode, k.uraian AS kode_uraian, p.kode AS sub_kode, p.uraian AS sub_uraian
                 FROM stock_opname_detail sd JOIN barang b ON b.id=sd.barang_id JOIN kode_barang k ON k.id=b.kode_barang_id JOIN kode_barang p ON p.id=k.parent_id
                 WHERE sd.so_id=? ORDER BY p.kode, k.kode, b.nama_barang', [$id]);
    $lembar = input('cetak') === 'lembar';

    // ---------------------------------------------------- ekspor Excel (BA / lembar kerja)
    if (input('export') === 'xlsx') {
        $x = new XlsxWriter();
        $x->addSheet('Stock Opname')->landscape();
        $judul = $lembar || $so['status'] === 'berlangsung' ? 'LEMBAR KERJA STOCK OPNAME BARANG PERSEDIAAN' : 'BERITA ACARA HASIL STOCK OPNAME BARANG PERSEDIAAN';
        $x->set(1, 1, $judul, 6)->merge(1, 1, 1, 13);
        $x->set(2, 1, 'NOMOR: ' . ($so['no_ba'] ?: $so['nomor']), 9)->merge(2, 1, 2, 13);
        $x->set(4, 1, 'SKPD', 1)->set(4, 3, ': ' . $so['skpd_nama']);
        $x->set(5, 1, 'UNIT', 1)->set(5, 3, ': ' . $so['unit_nama']);
        $x->set(6, 1, 'POSISI', 1)->set(6, 3, ': ' . tgl_indo($so['tanggal']));
        $x->row(8, ['NO', 'KODE BARANG', 'NAMA BARANG', 'SATUAN', 'SALDO ADM', 'SALDO FISIK', 'SELISIH', 'HARGA SATUAN (Rp)', 'NILAI ADM (Rp)', 'NILAI FISIK (Rp)', 'SELISIH (Rp)', 'KETERANGAN', ''], 2);
        $x->row(9, ['1', '2', '3', '4', '5', '6', '7=6-5', '8', '9=5x8', '10=6x8', '11=10-9', '12', ''], 2);
        $r = 10; $no = 0; $curSub = null; $tA = $tF = 0;
        foreach ($det as $d) {
            if ($curSub !== $d['sub_kode']) { $curSub = $d['sub_kode']; $x->set($r, 1, '', 12)->set($r, 2, $d['sub_kode'], 12)->set($r, 3, judul($d['sub_uraian']), 12); for ($c = 4; $c <= 12; $c++) $x->set($r, $c, '', 12); $r++; }
            $no++;
            $h = (float)$d['qty_adm'] > 0 ? round($d['nilai_adm'] / $d['qty_adm'], 2) : 0;
            $f = ($lembar || $d['qty_fisik'] === null) ? '' : (float)$d['qty_fisik'];
            $x->row($r, [[$no, 14], [$d['kode'], 3], [$d['nama_barang'], 10], [$d['satuan'], 3], [(float)$d['qty_adm'], 8], [$f, 8],
                [$f === '' ? '' : round($f - $d['qty_adm'], 2), 8], [$h, 4], [(float)$d['nilai_adm'], 4], [$f === '' ? '' : round($f * $h, 2), 4], [$f === '' ? '' : round($f * $h - $d['nilai_adm'], 2), 4], [$lembar ? '' : (string)$d['keterangan'], 10]]);
            $tA += (float)$d['nilai_adm']; if ($f !== '') $tF += $f * $h;
            $r++;
        }
        $x->row($r, [['JUMLAH', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], ['', 7], [$tA, 5], [$lembar ? '' : round($tF, 2), 5], [$lembar ? '' : round($tF - $tA, 2), 5], ['', 7]]);
        $x->merge($r, 1, $r, 8);
        $r += 3;
        $x->set($r, 2, 'Mengetahui,', 0)->set($r, 10, setting('kota_ttd', '') . ', ' . tgl_indo($so['tanggal']), 0);
        $x->set($r + 1, 2, 'Kepala ' . $so['skpd_nama'], 0)->set($r + 1, 10, 'Tim Stock Opname / Pengurus Barang', 0);
        $x->set($r + 5, 2, $so['skpd_pic'] ?: '(................................)', 1)->set($r + 5, 10, $so['unit_pic'] ?: '(................................)', 1);
        foreach ([1 => 5, 2 => 19, 3 => 44, 4 => 9, 5 => 10, 6 => 10, 7 => 9, 8 => 15, 9 => 16, 10 => 16, 11 => 15, 12 => 24] as $c => $w) $x->width($c, $w);
        $x->freeze(9);
        $x->download(($lembar ? 'lembar-kerja-' : 'ba-') . strtolower($so['nomor']) . '.xlsx');
    }

    $pageTitle = 'Stock Opname ' . $so['nomor'];
    include BASE_PATH . '/views/header.php';
    $open = $so['status'] === 'berlangsung';
    $edit = $open && $hak['kelola'];
    $nIsi = count(array_filter($det, function ($d) { return $d['qty_fisik'] !== null; }));
    ?>
    <div class="page-head">
      <div>
        <div class="breadcrumb"><a href="<?= e(url('stock_opname')) ?>">Stock Opname</a> / <?= e($so['nomor']) ?></div>
        <h1>Stock Opname <?= e($so['unit_nama']) ?></h1>
        <p><?= e($so['skpd_nama']) ?> · Posisi <?= tgl_indo($so['tanggal']) ?><?= $so['no_ba'] ? ' · BA ' . e($so['no_ba']) : '' ?></p>
      </div>
      <div class="actions">
        <?= $open ? '<span class="badge badge-amber">Berlangsung — transaksi dikunci</span>' : ($so['status'] === 'selesai' ? '<span class="badge badge-green">Selesai</span>' : '<span class="badge badge-gray">Dibatalkan</span>') ?>
        <a class="btn btn-light" href="<?= e(url('stock_opname', ['id' => $id, 'export' => 'xlsx', 'cetak' => 'lembar'])) ?>"><?= icon('excel') ?> Lembar Kerja</a>
        <?php if (!$open): ?><a class="btn btn-light" href="<?= e(url('stock_opname', ['id' => $id, 'export' => 'xlsx'])) ?>"><?= icon('excel') ?> Berita Acara (Excel)</a><?php endif; ?>
        <button class="btn btn-light" onclick="window.print()"><?= icon('print') ?> Cetak</button>
      </div>
    </div>

    <?php if ($open): ?>
      <div class="card no-print">
        <div class="card-body">
          <ol class="steps small" style="margin:0">
            <li>Unduh/cetak <b>Lembar Kerja</b>, lalu hitung fisik barang di gudang/ruang penyimpanan.</li>
            <li>Isi kolom <b>Saldo Fisik</b> di bawah (kolom kosong dianggap sama dengan saldo administrasi). Klik <b>Simpan Sementara</b> bila belum selesai.</li>
            <li>Klik <b>Selesaikan Stock Opname</b>. Selisih otomatis dibukukan sebagai penyesuaian dan transaksi dibuka kembali.</li>
          </ol>
          <?php if (!$hak['kelola']): ?><div class="alert alert-info" style="margin:12px 0 0">Hanya Admin <?= e($so['unit_nama']) ?> yang dapat mengisi dan menyelesaikan stock opname ini.</div><?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="grid grid-2">
        <div class="card"><div class="card-body"><dl class="kv">
          <dt>Dimulai</dt><dd><?= e($so['pembuat']) ?> · <?= tgl_indo($so['dibuat_at'], true) ?></dd>
          <dt><?= $so['status'] === 'selesai' ? 'Diselesaikan' : 'Dibatalkan' ?></dt><dd><?= e($so['penutup']) ?> · <?= tgl_indo($so['selesai_at'], true) ?></dd>
        </dl></div></div>
        <div class="card"><div class="card-body"><dl class="kv">
          <dt>Penyesuaian kurang</dt><dd><?= $so['trx_keluar_id'] ? '<a href="' . e(url('transaksi_detail', ['id' => $so['trx_keluar_id']])) . '">lihat transaksi</a>' : '-' ?></dd>
          <dt>Penyesuaian lebih</dt><dd><?= $so['trx_masuk_id'] ? '<a href="' . e(url('transaksi_detail', ['id' => $so['trx_masuk_id']])) . '">lihat transaksi</a>' : '-' ?></dd>
        </dl></div></div>
      </div>
    <?php endif; ?>

    <form method="post" id="fSO">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-head">
          <h2><?= count($det) ?> barang <?php if ($open): ?><span class="badge badge-gray"><?= $nIsi ?> sudah diisi</span><?php endif; ?></h2>
          <?php if ($edit): ?>
            <div class="actions no-print">
              <input type="search" id="cariSO" placeholder="Saring nama barang…" style="width:220px">
              <button type="button" class="btn btn-light btn-sm" id="samakan">Isi kosong = saldo adm</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-body tight table-wrap">
          <table class="tbl compact" id="tblSO">
            <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th>Satuan</th><th class="num">Saldo Adm</th><th class="num" style="width:120px">Saldo Fisik</th><th class="num">Selisih</th><th class="num">Harga Satuan</th><th class="num">Selisih (Rp)</th><th>Keterangan</th></tr></thead>
            <tbody>
            <?php $no = 0; $curSub = null; $tSel = 0; foreach ($det as $d):
                $h = (float)$d['qty_adm'] > 0 ? (float)$d['nilai_adm'] / (float)$d['qty_adm'] : 0;
                $sel = $d['qty_fisik'] === null ? null : (float)$d['qty_fisik'] - (float)$d['qty_adm'];
                if ($sel !== null) $tSel += $sel * $h;
                if ($curSub !== $d['sub_kode']): $curSub = $d['sub_kode']; ?>
                <tr class="row-group"><td></td><td class="mono"><?= e($d['sub_kode']) ?></td><td colspan="8"><?= e(judul($d['sub_uraian'])) ?></td></tr>
              <?php endif; $no++; ?>
              <tr data-nama="<?= e(mb_strtolower($d['nama_barang'])) ?>">
                <td><?= $no ?></td>
                <td class="mono small"><?= e($d['kode']) ?></td>
                <td><?= e($d['nama_barang']) ?></td>
                <td><?= e($d['satuan']) ?></td>
                <td class="num adm" data-v="<?= (float)$d['qty_adm'] ?>"><?= angka($d['qty_adm']) ?></td>
                <td class="num"><?php if ($edit): ?><input type="number" step="any" min="0" class="fisik" name="fisik[<?= (int)$d['id'] ?>]" value="<?= $d['qty_fisik'] === null ? '' : e((float)$d['qty_fisik']) ?>" style="text-align:right;padding:5px 8px"><?php else: ?><?= $d['qty_fisik'] === null ? '-' : angka($d['qty_fisik']) ?><?php endif; ?></td>
                <td class="num sel" data-h="<?= round($h, 2) ?>"><?= $sel === null ? '' : ($sel == 0 ? '<span class="muted">0</span>' : '<b style="color:' . ($sel < 0 ? 'var(--red)' : 'var(--green)') . '">' . ($sel > 0 ? '+' : '') . angka($sel) . '</b>') ?></td>
                <td class="num"><?= rupiah($h) ?></td>
                <td class="num selrp"><?= $sel === null || $sel == 0 ? '' : rupiah($sel * $h) ?></td>
                <td><?php if ($edit): ?><input type="text" name="ket[<?= (int)$d['id'] ?>]" value="<?= e($d['keterangan']) ?>" maxlength="255" style="padding:5px 8px" placeholder="mis. rusak/kedaluwarsa"><?php else: ?><span class="small"><?= e($d['keterangan']) ?></span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$det): ?><tr><td colspan="10" class="empty">Tidak ada barang dengan saldo pada saat stock opname dimulai.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><td colspan="8">Total selisih nilai</td><td class="num" id="totSel"><?= rupiah($tSel) ?></td><td></td></tr></tfoot>
          </table>
        </div>
        <?php if ($edit || ($open && $hak['batal'])): ?>
        <div class="card-body no-print" style="border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between">
          <div>
            <?php if ($hak['batal']): ?><button class="btn btn-danger" name="aksi" value="batal" type="submit" onclick="return confirm('Batalkan stock opname? Hasil hitung tidak dibukukan dan transaksi dibuka kembali.')">Batalkan Stock Opname</button><?php endif; ?>
          </div>
          <?php if ($edit): ?>
          <div class="actions">
            <button class="btn btn-light" name="aksi" value="simpan" type="submit">Simpan Sementara</button>
            <button class="btn btn-green btn-lg" name="aksi" value="selesai" type="submit" onclick="return confirm('Selesaikan stock opname? Selisih akan dibukukan sebagai penyesuaian stok dan tidak dapat dibatalkan.')"><?= icon('check') ?> Selesaikan Stock Opname</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </form>
    <?php
    $pageScript = <<<'JS'
<script>
(function(){
  var B = window.BMD, tbl = document.getElementById('tblSO'); if(!tbl) return;
  function recalc(){
    var tot = 0;
    tbl.querySelectorAll('tbody tr[data-nama]').forEach(function(tr){
      var inp = tr.querySelector('.fisik'); if(!inp) return;
      var adm = parseFloat(tr.querySelector('.adm').dataset.v), selTd = tr.querySelector('.sel'), h = parseFloat(selTd.dataset.h), rp = tr.querySelector('.selrp');
      if(inp.value === ''){ selTd.innerHTML=''; rp.textContent=''; return; }
      var s = Math.round((parseFloat(inp.value) - adm) * 100) / 100;
      selTd.innerHTML = s === 0 ? '<span class="muted">0</span>' : '<b style="color:' + (s<0?'var(--red)':'var(--green)') + '">' + (s>0?'+':'') + B.fmtQty(s) + '</b>';
      rp.textContent = s === 0 ? '' : B.fmt(s*h); tot += s*h;
    });
    document.getElementById('totSel').textContent = B.fmt(tot);
  }
  tbl.addEventListener('input', function(e){ if(e.target.classList.contains('fisik')) recalc(); });
  var sm = document.getElementById('samakan');
  if(sm) sm.addEventListener('click', function(){
    tbl.querySelectorAll('tbody tr[data-nama]').forEach(function(tr){ var i = tr.querySelector('.fisik'); if(i && i.value==='') i.value = tr.querySelector('.adm').dataset.v; });
    recalc();
  });
  var cari = document.getElementById('cariSO');
  if(cari) cari.addEventListener('input', function(){
    var q = this.value.toLowerCase();
    tbl.querySelectorAll('tbody tr[data-nama]').forEach(function(tr){ tr.style.display = !q || tr.dataset.nama.indexOf(q) >= 0 ? '' : 'none'; });
  });
})();
</script>
JS;
    include BASE_PATH . '/views/footer.php';
    return;
}

// =====================================================================
//  B. DAFTAR / MULAI
// =====================================================================
$isUnitUser = is_unit_user();
if ($u['role'] === 'superadmin') {
    $skpdList = rows('SELECT id, nama FROM skpd ORDER BY nama');
    $sid = input_int('skpd', $skpdList ? (int)$skpdList[0]['id'] : 0);
} else {
    $sid = (int)$u['skpd_id'];
}

if (is_post()) {
    csrf_check();
    $d = ['tanggal' => (string)input('tanggal', ''), 'no_ba' => mb_substr(trim((string)input('no_ba', '')), 0, 150), 'keterangan' => trim((string)input('keterangan', ''))];
    $aksi = input('aksi');
    try {
        if (in_array($aksi, ['mulai', 'mulai_serentak'], true)) {
            if (!valid_date($d['tanggal']) || $d['tanggal'] > date('Y-m-d')) throw new RuntimeException('Tanggal stock opname tidak valid (maksimal hari ini).');
        }
        if ($aksi === 'mulai') {
            if ($u['role'] !== 'admin') throw new RuntimeException('Hanya Admin Unit/Sub Unit yang dapat memulai stock opname unitnya.');
            $nid = so_mulai($u['unit_id'], $d, $u);
            flash('success', 'Stock opname dimulai. Seluruh transaksi di ' . $u['unit_nama'] . ' dikunci sampai stock opname diselesaikan.');
            redirect(url('stock_opname', ['id' => $nid]));
        } elseif ($aksi === 'mulai_serentak') {
            if (!in_array($u['role'], ['admin_skpd', 'superadmin'], true)) throw new RuntimeException('Akses ditolak.');
            $batch = 'B' . date('YmdHis') . $sid;
            $ok = 0; $gagal = [];
            foreach (rows('SELECT id, nama FROM unit_kerja WHERE skpd_id=? ORDER BY COALESCE(parent_id,id), level, nama', [$sid]) as $x) {
                try { so_mulai($x['id'], $d, $u, $batch); $ok++; }
                catch (RuntimeException $e) { $gagal[] = $x['nama'] . ': ' . $e->getMessage(); }
            }
            if ($ok) flash('success', "Stock opname serentak dimulai di $ok unit/sub unit. Transaksi di unit tersebut dikunci; masing-masing Admin mengisi dan menyelesaikan stock opname unitnya.");
            if ($gagal) flash('warning', 'Belum dapat dimulai di:<ul><li>' . implode('</li><li>', array_map('e', $gagal)) . '</li></ul>', true);
        } elseif ($aksi === 'batal_batch') {
            if (!in_array($u['role'], ['admin_skpd', 'superadmin'], true)) throw new RuntimeException('Akses ditolak.');
            $n = 0;
            foreach (rows("SELECT so.id FROM stock_opname so JOIN unit_kerja uk ON uk.id=so.unit_id WHERE uk.skpd_id=? AND so.status='berlangsung'", [$sid]) as $r) { if (so_batal($r['id'], $u)) $n++; }
            flash('success', "$n stock opname yang masih berlangsung dibatalkan; transaksi dibuka kembali.");
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('stock_opname', $u['role'] === 'superadmin' ? ['skpd' => $sid] : []));
}

if ($isUnitUser) {
    $scope = unit_scope_ids($u);
    $aktif = so_aktif($u['unit_id']);
    $syarat = $aktif ? null : so_syarat($u['unit_id'], date('Y-m-d'));
    $hist = rows("SELECT so.*, uk.nama AS unit_nama, (SELECT COUNT(*) FROM stock_opname_detail WHERE so_id=so.id) n
                  FROM stock_opname so JOIN unit_kerja uk ON uk.id=so.unit_id WHERE so.unit_id IN (" . in_ph($scope) . ") ORDER BY so.id DESC LIMIT 50", $scope);
} else {
    $units = rows("SELECT uk.*, (SELECT COUNT(*) FROM barang WHERE unit_id=uk.id AND stok>0) item,
                     (SELECT COUNT(*) FROM transaksi WHERE unit_id=uk.id AND status='menunggu') pending
                   FROM unit_kerja uk WHERE uk.skpd_id=? ORDER BY COALESCE(uk.parent_id, uk.id), uk.level, uk.nama", [$sid]);
    foreach ($units as &$x) {
        $x['so'] = row('SELECT so.*, (SELECT COUNT(*) FROM stock_opname_detail WHERE so_id=so.id) n,
                          (SELECT COUNT(*) FROM stock_opname_detail WHERE so_id=so.id AND qty_fisik IS NOT NULL) n_isi
                        FROM stock_opname so WHERE unit_id=? AND status<>\'batal\' ORDER BY id DESC LIMIT 1', [$x['id']]);
    }
    unset($x);
    $nAktif = count(array_filter($units, function ($x) { return $x['so'] && $x['so']['status'] === 'berlangsung'; }));
}

$pageTitle = 'Stock Opname';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Stock Opname</h1>
    <p>Pencocokan saldo administrasi dengan hasil hitung fisik. Selama stock opname berlangsung, seluruh transaksi di unit tersebut <b>dikunci</b>.</p>
  </div>
  <?php if ($u['role'] === 'superadmin'): ?>
    <form method="get" class="filters"><input type="hidden" name="page" value="stock_opname">
      <div class="field" style="min-width:320px"><select name="skpd" data-autosubmit>
        <?php foreach ($skpdList as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $x['id'] == $sid ? 'selected' : '' ?>><?= e($x['nama']) ?></option><?php endforeach; ?>
      </select></div></form>
  <?php endif; ?>
</div>

<?php if ($isUnitUser): ?>
  <?php if ($aktif): ?>
    <div class="card" style="border-color:#f1d9a7"><div class="card-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div><h2 style="margin:0">🔒 Stock opname sedang berlangsung</h2><p class="muted" style="margin:4px 0 0"><?= e($aktif['nomor']) ?> · posisi <?= tgl_indo($aktif['tanggal']) ?>. Pembelian, pengeluaran, dan validasi dikunci.</p></div>
      <a class="btn" href="<?= e(url('stock_opname', ['id' => $aktif['id']])) ?>"><?= $u['role'] === 'admin' ? 'Isi Hasil Hitung Fisik' : 'Lihat' ?></a>
    </div></div>
  <?php elseif ($u['role'] === 'admin'): ?>
    <div class="card">
      <div class="card-head"><h2>Mulai stock opname <?= e($u['unit_nama']) ?></h2></div>
      <div class="card-body">
        <?php if ($syarat): ?><div class="alert alert-warning"><?= e($syarat) ?><?= strpos($syarat, 'menunggu') !== false ? ' <a href="' . e(url('validasi')) . '">Buka Validasi</a>' : '' ?></div><?php endif; ?>
        <form method="post" data-confirm="Mulai stock opname? Seluruh transaksi di unit ini akan dikunci sampai stock opname selesai.">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="mulai">
          <div class="form-grid">
            <div class="field col-3"><label>Tanggal posisi <span class="req">*</span></label><input type="date" name="tanggal" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="field col-4"><label>No. Berita Acara</label><input type="text" name="no_ba" maxlength="150"></div>
            <div class="field col-5"><label>Keterangan</label><input type="text" name="keterangan" placeholder="mis. Stock opname semester I"></div>
          </div>
          <div class="divider"></div>
          <button class="btn" type="submit" <?= $syarat ? 'disabled' : '' ?>>🔒 Mulai &amp; Kunci Transaksi</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Stock opname dimulai dan diselesaikan oleh Admin <?= e($u['unit_level'] === 'subunit' ? 'Sub Unit' : 'Unit') ?>. Operator dapat melihat riwayatnya.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head"><h2>Riwayat stock opname</h2></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>Nomor</th><th>Unit</th><th>Tanggal Posisi</th><th>No. BA</th><th class="num">Barang</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hist as $h): ?>
          <tr><td class="mono"><?= e($h['nomor']) ?></td><td><?= e($h['unit_nama']) ?></td><td><?= tgl_indo($h['tanggal']) ?></td><td><?= e($h['no_ba'] ?: '-') ?></td><td class="num"><?= (int)$h['n'] ?></td>
            <td><?= $h['status'] === 'berlangsung' ? '<span class="badge badge-amber">Berlangsung</span>' : ($h['status'] === 'selesai' ? '<span class="badge badge-green">Selesai</span>' : '<span class="badge badge-gray">Batal</span>') ?></td>
            <td class="act"><a class="btn btn-light btn-sm" href="<?= e(url('stock_opname', ['id' => $h['id']])) ?>">Buka</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$hist): ?><tr><td colspan="7" class="empty">Belum ada stock opname.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <div class="grid grid-2">
    <div class="card">
      <div class="card-head"><h2>Stock opname serentak</h2></div>
      <div class="card-body">
        <p class="small muted">Memulai stock opname di <b>seluruh unit &amp; sub unit</b> SKPD sekaligus. Transaksi di semua unit dikunci; masing-masing Admin Unit/Sub Unit mengisi hasil hitung fisik dan menyelesaikan stock opname unitnya. Unit yang masih punya transaksi menunggu validasi akan dilewati.</p>
        <form method="post" data-confirm="Mulai stock opname serentak di seluruh unit? Semua transaksi akan dikunci.">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="mulai_serentak">
          <div class="form-grid">
            <div class="field col-4"><label>Tanggal posisi <span class="req">*</span></label><input type="date" name="tanggal" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="field col-8"><label>No. Berita Acara</label><input type="text" name="no_ba" maxlength="150"></div>
            <div class="field col-12"><label>Keterangan</label><input type="text" name="keterangan" placeholder="mis. Stock opname akhir semester"></div>
          </div>
          <div class="divider"></div>
          <button class="btn" type="submit">🔒 Mulai Serentak</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Progres</h2></div>
      <div class="card-body">
        <div class="stat" style="box-shadow:none;border:0;padding:0"><div class="label">Sedang berlangsung</div><div class="value"><?= $nAktif ?> / <?= count($units) ?> unit</div></div>
        <?php if ($nAktif): ?>
          <form method="post" style="margin-top:12px" data-confirm="Batalkan semua stock opname yang masih berlangsung di SKPD ini?">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="batal_batch">
            <button class="btn btn-danger" type="submit">Batalkan semua yang berlangsung</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Status per unit &amp; sub unit</h2></div>
    <div class="card-body tight table-wrap">
      <table class="tbl">
        <thead><tr><th>Unit / Sub Unit</th><th class="num">Barang</th><th>Stock opname terakhir</th><th>Status</th><th>Progres isian</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($units as $x): $so = $x['so']; ?>
          <tr class="<?= $x['level'] === 'subunit' ? 'row-sub' : '' ?>">
            <td><?= $x['level'] === 'subunit' ? '↳ ' : '<b>' ?><?= e($x['nama']) ?><?= $x['level'] === 'unit' ? '</b>' : '' ?><?= $x['pending'] ? ' <span class="badge badge-amber">' . (int)$x['pending'] . ' menunggu validasi</span>' : '' ?></td>
            <td class="num"><?= (int)$x['item'] ?></td>
            <td><?= $so ? e($so['nomor']) . ' · ' . tgl_indo($so['tanggal']) : '<span class="muted">belum pernah</span>' ?></td>
            <td><?= !$so ? '-' : ($so['status'] === 'berlangsung' ? '<span class="badge badge-amber">Berlangsung</span>' : '<span class="badge badge-green">Selesai</span>') ?></td>
            <td><?php if ($so && $so['status'] === 'berlangsung'): $pct = $so['n'] ? round($so['n_isi'] / $so['n'] * 100) : 100; ?><div class="bar" style="max-width:160px"><span style="width:<?= $pct ?>%"></span></div><span class="small muted"><?= (int)$so['n_isi'] ?>/<?= (int)$so['n'] ?> barang</span><?php endif; ?></td>
            <td class="act"><?php if ($so): ?><a class="btn btn-light btn-sm" href="<?= e(url('stock_opname', ['id' => $so['id']])) ?>">Lihat</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$units): ?><tr><td colspan="6" class="empty">Belum ada unit.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php include BASE_PATH . '/views/footer.php'; ?>
