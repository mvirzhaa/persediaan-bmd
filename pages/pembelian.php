<?php
defined('BASE_PATH') or exit;
$u = user();

// ------------------------------------------------------------------ unduh template impor
if (input('aksi') === 'template') {
    $kode = rows("SELECT k.kode, k.uraian, p.uraian AS sub, g.uraian AS rinc
                  FROM kode_barang k JOIN kode_barang p ON p.id=k.parent_id JOIN kode_barang g ON g.id=p.parent_id
                  WHERE k.level=7 AND k.is_active=1 ORDER BY k.kode");
    $x = new XlsxWriter();
    $x->addSheet('Import Pembelian');
    $x->set(1, 1, 'TEMPLATE IMPOR PEMBELIAN BARANG PERSEDIAAN', 1);
    $x->set(2, 1, 'Isi mulai baris 5. Kolom B wajib berisi Kode Sub Sub Rincian Objek (pilih dari dropdown atau lihat sheet "Daftar Kode"). Kolom C terisi otomatis. Jangan mengubah susunan kolom.', 11);
    $x->row(4, ['No', 'Kode Sub Sub Rincian Objek *', 'Uraian Kode (otomatis)', 'Nama Barang (deskripsi detail) *', 'Jumlah *', 'Satuan *', 'Harga Satuan (Rp) *', 'Keterangan'], 2);
    for ($r = 5; $r <= 504; $r++) {
        $x->set($r, 1, $r - 4, 14);
        $x->set($r, 2, '', 3);
        $x->set($r, 3, '=IFERROR(VLOOKUP(B' . $r . ",'Daftar Kode'!\$A:\$B,2,FALSE),\"\")", 3);
        foreach ([4, 6, 8] as $c) $x->set($r, $c, '', 3);
        $x->set($r, 5, '', 8);
        $x->set($r, 7, '', 4);
    }
    $x->listValidation('B5:B504', "'Daftar Kode'!\$A\$2:\$A\$" . (count($kode) + 1));
    foreach ([1 => 5, 2 => 24, 3 => 30, 4 => 44, 5 => 10, 6 => 11, 7 => 18, 8 => 28] as $c => $w) $x->width($c, $w);
    $x->freeze(4);

    $x->addSheet('Daftar Kode');
    $x->row(1, ['Kode Sub Sub Rincian Objek', 'Uraian', 'Sub Rincian Objek', 'Rincian Objek'], 2);
    $r = 2;
    foreach ($kode as $k) $x->row($r++, [$k['kode'], $k['uraian'], judul($k['sub']), judul($k['rinc'])], 3);
    foreach ([1 => 24, 2 => 44, 3 => 40, 4 => 36] as $c => $w) $x->width($c, $w);
    $x->freeze(1);

    $x->addSheet('Petunjuk');
    $tips = [
        ['PETUNJUK PENGISIAN', 1],
        ['1. Isi data pada sheet "Import Pembelian" mulai baris 5, satu baris untuk satu jenis barang.', 0],
        ['2. Kolom B: kode Sub Sub Rincian Objek, contoh 1.1.7.01.03.01.001 (Alat Tulis). Pilih dari dropdown atau salin dari sheet "Daftar Kode".', 0],
        ['3. Kolom D: nama/deskripsi barang secara detail, contoh "Ballpoint Gel 0,5 mm warna hitam".', 0],
        ['4. Kolom E: jumlah barang (angka, boleh desimal), kolom F: satuan (Buah, Rim, Dus, Pack, dst).', 0],
        ['5. Kolom G: harga satuan dalam rupiah (angka tanpa titik ribuan atau format angka Excel).', 0],
        ['6. Simpan file (.xlsx), lalu pada menu Pembelian Barang klik "Impor dari Excel" dan unggah file ini.', 0],
        ['7. Barang hasil impor masuk ke Daftar Barang terlebih dahulu. Periksa kembali, lengkapi tanggal & dokumen pembelian, lalu klik "Simpan Data Barang".', 0],
        ['', 0],
        ['CONTOH PENGISIAN', 1],
    ];
    foreach ($tips as $i => $t) $x->set($i + 1, 1, $t[0], $t[1]);
    $x->row(12, ['No', 'Kode Sub Sub Rincian Objek', 'Uraian Kode', 'Nama Barang', 'Jumlah', 'Satuan', 'Harga Satuan', 'Keterangan'], 2);
    $x->row(13, [[1, 14], ['1.1.7.01.03.01.001', 3], ['Alat Tulis', 3], ['Ballpoint Gel 0,5 mm hitam', 3], [10, 8], ['Dus', 3], [64500, 4], ['', 3]]);
    $x->row(14, [[2, 14], ['1.1.7.01.03.02.001', 3], ['Kertas HVS', 3], ['Kertas HVS A4 80 gram', 3], [25, 8], ['Rim', 3], [81000, 4], ['', 3]]);
    $x->width(1, 6)->width(2, 24)->width(3, 20)->width(4, 34)->width(5, 9)->width(6, 9)->width(7, 14)->width(8, 14);
    $x->download('template-impor-pembelian.xlsx');
}

// ------------------------------------------------------------------ impor excel -> daftar (draft)
if (is_post() && input('aksi') === 'import') {
    csrf_check();
    try {
        so_cek_kunci($u['unit_id']);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file Excel (.xlsx) hasil template.');
        if (strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('File harus berformat .xlsx');
        $sheet = xlsx_read($_FILES['file']['tmp_name']);
        // cari baris judul (kolom B berisi "Kode")
        $hdr = 0;
        foreach ($sheet as $ri => $cols) { if ($ri > 15) break; if (isset($cols[2]) && stripos((string)$cols[2], 'kode') !== false) { $hdr = $ri; break; } }
        if (!$hdr) throw new RuntimeException('Format tidak dikenali. Gunakan template impor dari aplikasi.');
        $kodeMap = [];
        foreach (rows('SELECT id, kode FROM kode_barang WHERE level=7 AND is_active=1') as $k) $kodeMap[$k['kode']] = (int)$k['id'];
        $valid = [];
        $errs = [];
        foreach ($sheet as $ri => $cols) {
            if ($ri <= $hdr) continue;
            $kode = preg_replace('/\s+/', '', (string)($cols[2] ?? ''));
            $nama = trim(preg_replace('/\s+/', ' ', (string)($cols[4] ?? '')));
            $jRaw = $cols[5] ?? '';
            $satuan = trim((string)($cols[6] ?? ''));
            $hRaw = $cols[7] ?? '';
            $ket = trim((string)($cols[8] ?? ''));
            if ($kode === '' && $nama === '' && $jRaw === '' && $satuan === '' && $hRaw === '') continue;
            $e = [];
            if (!isset($kodeMap[$kode])) $e[] = 'kode "' . $kode . '" tidak ditemukan';
            if ($nama === '') $e[] = 'nama barang kosong';
            $jumlah = round(parse_num($jRaw), 2);
            if ($jumlah <= 0) $e[] = 'jumlah harus > 0';
            if ($satuan === '') $e[] = 'satuan kosong';
            $harga = round(parse_num($hRaw), 2);
            if ($hRaw === '' || $harga < 0) $e[] = 'harga tidak valid';
            if ($e) { $errs[] = 'Baris ' . $ri . ': ' . implode(', ', $e); continue; }
            $valid[] = [$kodeMap[$kode], mb_substr($nama, 0, 255), $jumlah, mb_substr($satuan, 0, 50), $harga, mb_substr($ket, 0, 255)];
        }
        if ($errs) {
            flash('error', 'Impor dibatalkan, perbaiki data berikut lalu unggah ulang:<ul><li>' . implode('</li><li>', array_map('e', array_slice($errs, 0, 25))) . '</li></ul>'
                . (count($errs) > 25 ? '… dan ' . (count($errs) - 25) . ' baris lain.' : ''), true);
        } elseif (!$valid) {
            flash('warning', 'Tidak ada baris data yang diisi pada file.');
        } else {
            db_tx(function () use ($valid, $u) {
                $t = draft_get('masuk', $u, true);
                foreach ($valid as $v) {
                    exec_sql('INSERT INTO transaksi_detail (transaksi_id, kode_barang_id, nama_barang, satuan, jumlah, harga_satuan, nilai, keterangan) VALUES (?,?,?,?,?,?,?,?)',
                        [(int)$t['id'], $v[0], $v[1], $v[3], $v[2], $v[4], round($v[2] * $v[4], 2), $v[5] ?: null]);
                }
                exec_sql("UPDATE transaksi SET sumber='import' WHERE id=?", [(int)$t['id']]);
            });
            flash('success', count($valid) . ' barang dari Excel ditambahkan ke Daftar Barang. Periksa, lengkapi tanggal & dokumen, lalu klik "Simpan Data Barang".');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('pembelian'));
}

if (is_post() && input('aksi') === 'simpan') handle_simpan_draft('masuk', $u, 'pembelian');

$draft = draft_get('masuk', $u, false);
$satuanList = ['Buah', 'Unit', 'Pcs', 'Rim', 'Dus', 'Box', 'Lusin', 'Pack', 'Set', 'Lembar', 'Botol', 'Tube', 'Roll', 'Liter', 'Kg', 'Meter', 'Paket', 'Batang', 'Bungkus', 'Kaleng', 'Sachet', 'Galon'];
foreach (rows('SELECT DISTINCT satuan FROM barang WHERE unit_id=? ORDER BY satuan', [$u['unit_id']]) as $s) if (!in_array($s['satuan'], $satuanList, true)) $satuanList[] = $s['satuan'];

$pageTitle = 'Pembelian Barang';
include BASE_PATH . '/views/header.php';
if (so_aktif($u['unit_id'])) {
    echo '<div class="card empty-state"><h2>🔒 Input pembelian sementara dikunci</h2><p class="muted">Unit Anda sedang melaksanakan stock opname. Formulir akan tersedia kembali setelah Admin menyelesaikan stock opname.</p>'
        . '<a class="btn btn-light" href="' . e(url('stock_opname')) . '">Lihat Stock Opname</a></div>';
    include BASE_PATH . '/views/footer.php';
    return;
}
?>
<div class="page-head">
  <div>
    <h1>Pembelian Barang</h1>
    <p><?= e($u['unit_nama']) ?> — <?= $u['role'] === 'admin'
        ? 'Sebagai <b>Admin</b>, data yang disimpan langsung menambah stok.'
        : 'Sebagai <b>Operator</b>, data yang disimpan menunggu validasi Admin sebelum menambah stok.' ?></p>
  </div>
  <div class="actions">
    <button class="btn btn-light" type="button" onclick="document.getElementById('imp').classList.toggle('hidden')"><?= icon('excel') ?> Impor dari Excel</button>
    <a class="btn btn-light" href="<?= e(url('transaksi', ['jenis' => 'masuk'])) ?>"><?= icon('list') ?> Riwayat</a>
  </div>
</div>

<div class="card hidden" id="imp">
  <div class="card-head"><h2>Impor daftar pembelian dari Excel</h2></div>
  <div class="card-body">
    <ol class="steps small">
      <li>Unduh template, lalu isi satu baris per barang (kode Sub Sub Rincian Objek, nama barang, jumlah, satuan, harga satuan).</li>
      <li>Unggah file. Barang akan masuk ke <b>Daftar Barang</b> di bawah (belum menjadi stok).</li>
      <li>Periksa daftar, isi tanggal &amp; dokumen pembelian, lalu klik <b>Simpan Data Barang</b>.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="filters" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="import">
      <a class="btn btn-light" href="<?= e(url('pembelian', ['aksi' => 'template'])) ?>"><?= icon('excel') ?> Unduh Template</a>
      <div class="field grow"><input type="file" name="file" accept=".xlsx" required></div>
      <button class="btn" type="submit"><?= icon('upload') ?> Unggah &amp; Tambahkan ke Daftar</button>
    </form>
  </div>
</div>

<form id="fSimpan" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan">
  <div class="card">
    <div class="card-head"><h2>1. Dokumen Pembelian</h2><span class="muted small">Upload dokumen tidak wajib</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field col-3"><label>Tanggal Pembelian/Penerimaan <span class="req">*</span></label><input type="date" name="tanggal" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="field col-4"><label>No. Dokumen (Faktur/BAST/SPK)</label><input type="text" name="no_dokumen" maxlength="150"></div>
        <div class="field col-5"><label>Upload Dokumen Pembelian</label><input type="file" name="dokumen" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"><div class="help">PDF/JPG/PNG/DOC/XLS, maks <?= UPLOAD_MAX / 1048576 ?> MB</div></div>
        <div class="field col-12"><label>Keterangan</label><input type="text" name="keterangan" placeholder="mis. Belanja ATK kegiatan …"></div>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-head"><h2>2. Daftarkan Barang</h2></div>
  <div class="card-body">
    <form id="fItem" autocomplete="off" onsubmit="return false">
      <div class="form-grid">
        <div class="field col-12">
          <label>Sub Sub Rincian Objek <span class="req">*</span></label>
          <input type="text" id="kodeSearch" placeholder="Ketik kode atau nama, mis. 'alat tulis' atau '1.1.7.01.03'…">
          <input type="hidden" name="kode_id" id="kodeId">
        </div>
        <div class="field col-12 hidden" id="hierBox"><div class="hier" id="hier"></div></div>
        <div class="field col-12">
          <label>Nama Barang (deskripsi detail) <span class="req">*</span></label>
          <input type="text" name="nama" id="nama" maxlength="255" placeholder="mis. Ballpoint Gel 0,5 mm warna hitam">
          <div class="help">Ketik bebas. Nama barang yang pernah dicatat pada kode ini akan muncul sebagai saran agar stok tergabung.</div>
        </div>
        <div class="field col-2"><label>Jumlah <span class="req">*</span></label><input type="number" name="jumlah" id="jumlah" min="0.01" step="any"></div>
        <div class="field col-2"><label>Satuan <span class="req">*</span></label><input type="text" name="satuan" id="satuan" list="satuanList" maxlength="50"></div>
        <div class="field col-3"><label>Harga Satuan (Rp) <span class="req">*</span></label><input type="number" name="harga" id="harga" min="0" step="any"></div>
        <div class="field col-2"><label>Total (Rp)</label><input type="text" id="total" readonly></div>
        <div class="field col-3"><label>Dokumen per Barang</label><input type="file" name="dokumen" id="dokItem" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>
        <div class="field col-12"><label>Keterangan</label><input type="text" name="keterangan" id="ketItem" maxlength="255" placeholder="opsional"></div>
      </div>
      <div class="divider"></div>
      <button class="btn" type="button" id="btnAdd"><?= icon('plus') ?> Daftarkan</button>
      <span id="addMsg" class="small" style="margin-left:10px"></span>
    </form>
    <datalist id="satuanList"><?php foreach ($satuanList as $s) echo '<option value="' . e($s) . '">'; ?></datalist>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>3. Daftar Barang <span class="badge badge-gray" id="cnt">0</span></h2>
    <button class="btn btn-danger btn-sm" type="button" id="btnClear">Kosongkan daftar</button></div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="num">Jumlah</th><th>Satuan</th><th class="num">Harga Satuan</th><th class="num">Total (Rp)</th><th>Dokumen</th><th></th></tr></thead>
      <tbody id="list"><tr><td colspan="9" class="empty">Memuat…</td></tr></tbody>
      <tfoot><tr><td colspan="6">Total pembelian</td><td class="num" id="grand">0</td><td colspan="2"></td></tr></tfoot>
    </table>
  </div>
  <div class="card-body" style="border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="small muted">Barang di daftar ini <b>belum</b> menjadi stok sampai tombol <b>Simpan Data Barang</b> ditekan.</span>
    <button class="btn btn-lg btn-green" type="submit" form="fSimpan" id="btnSave" data-confirm-save="1">Simpan Data Barang</button>
  </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
(function(){
  var B = window.BMD, items = [];
  var $ = function(id){ return document.getElementById(id); };
  var kodeId = $('kodeId'), hier = $('hier'), hierBox = $('hierBox');

  new B.Combo($('kodeSearch'), {
    minChars: 1,
    source: function(q){ return B.getJSON('ajax.php?act=kode_cari&q=' + encodeURIComponent(q)).then(function(r){ return r.items; }); },
    render: function(it){ return '<div class="k">' + B.esc(it.kode) + '</div><div>' + B.esc(it.uraian) + '</div><div class="s">' + B.esc(it.induk) + '</div>'; },
    onSelect: function(it){
      $('kodeSearch').value = it.kode + ' — ' + it.uraian; kodeId.value = it.id;
      B.getJSON('ajax.php?act=kode_info&id=' + it.id).then(function(r){
        hier.innerHTML = r.items.map(function(h){ return '<div class="hl">' + B.esc(h.nama) + '</div><div class="hv"><b>' + B.esc(h.kode) + '</b>' + B.esc(h.uraian) + '</div>'; }).join('');
        hierBox.classList.remove('hidden');
      });
      $('nama').focus();
    },
    onClear: function(){ kodeId.value = ''; hierBox.classList.add('hidden'); }
  });

  new B.Combo($('nama'), {
    minChars: 1,
    source: function(q){ if(!kodeId.value) return Promise.resolve([]); return B.getJSON('ajax.php?act=nama_saran&kode_id=' + kodeId.value + '&q=' + encodeURIComponent(q)).then(function(r){ return r.items; }); },
    render: function(it){ return '<div>' + B.esc(it.nama_barang) + '</div><div class="s">Satuan ' + B.esc(it.satuan) + ' · stok ' + B.fmtQty(it.stok) + '</div>' + (it.keterangan ? '<div class="s" style="font-style:italic">' + B.esc(it.keterangan) + '</div>' : ''); },
    onSelect: function(it){ $('nama').value = it.nama_barang; $('satuan').value = it.satuan; $('jumlah').focus(); }
  });

  function calc(){ $('total').value = B.fmt((parseFloat($('jumlah').value)||0) * (parseFloat($('harga').value)||0)); }
  $('jumlah').addEventListener('input', calc); $('harga').addEventListener('input', calc);

  function draw(list){
    items = list; $('cnt').textContent = list.length;
    var tb = $('list'), g = 0;
    if(!list.length){ tb.innerHTML = '<tr><td colspan="9" class="empty">Belum ada barang. Isi formulir di atas lalu klik <b>Daftarkan</b>, atau impor dari Excel.</td></tr>'; }
    else tb.innerHTML = list.map(function(it, i){
      g += it.nilai;
      return '<tr><td>' + (i+1) + '</td><td><span class="mono">' + B.esc(it.kode) + '</span><div class="small muted">' + B.esc(it.kode_uraian) + '</div></td>'
        + '<td>' + B.esc(it.nama) + (it.ket ? '<div class="small muted">' + B.esc(it.ket) + '</div>' : '') + '</td><td class="num">' + B.fmtQty(it.jumlah) + '</td><td>' + B.esc(it.satuan) + '</td>'
        + '<td class="num">' + B.fmt(it.harga) + '</td><td class="num">' + B.fmt(it.nilai) + '</td><td class="small">' + (it.dok ? '📎 ' + B.esc(it.dok) : '<span class="muted">-</span>') + '</td>'
        + '<td class="act"><button class="link-btn danger" data-del="' + it.id + '">Hapus</button></td></tr>';
    }).join('');
    $('grand').textContent = B.fmt(g);
    $('btnSave').disabled = !list.length;
  }
  B.getJSON('ajax.php?act=draft_list&jenis=masuk').then(function(r){ draw(r.items || []); });

  $('btnAdd').addEventListener('click', function(){
    var msg = $('addMsg'); msg.textContent = '';
    if(!kodeId.value){ msg.innerHTML = '<span style="color:var(--red)">Pilih Sub Sub Rincian Objek dari daftar.</span>'; $('kodeSearch').focus(); return; }
    var fd = new FormData($('fItem')); fd.append('jenis', 'masuk');
    this.disabled = true; var btn = this;
    B.api('ajax.php?act=draft_add', fd).then(function(r){
      btn.disabled = false;
      if(!r.ok){ msg.innerHTML = '<span style="color:var(--red)">' + B.esc(r.msg) + '</span>'; return; }
      draw(r.items);
      ['nama','jumlah','harga','ketItem','dokItem'].forEach(function(id){ $(id).value=''; }); $('total').value='';
      msg.innerHTML = '<span style="color:var(--green)">✓ Ditambahkan ke daftar</span>';
      $('nama').focus();
    });
  });
  $('list').addEventListener('click', function(e){
    var id = e.target.getAttribute('data-del'); if(!id) return;
    B.api('ajax.php?act=draft_del', {id: id}).then(function(r){ if(r.ok) draw(r.items); else alert(r.msg); });
  });
  $('btnClear').addEventListener('click', function(){
    if(!items.length || !confirm('Kosongkan seluruh daftar barang?')) return;
    B.api('ajax.php?act=draft_clear', {jenis:'masuk'}).then(function(r){ if(r.ok) draw([]); });
  });
  $('fSimpan').addEventListener('submit', function(e){
    if(!items.length){ e.preventDefault(); alert('Daftar barang masih kosong.'); return; }
    if(!confirm('Simpan ' + items.length + ' barang sebagai satu transaksi pembelian?')) e.preventDefault();
  });
})();
</script>
JS;
include BASE_PATH . '/views/footer.php';
