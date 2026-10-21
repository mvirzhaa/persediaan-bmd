<?php
defined('BASE_PATH') or exit;
$u = user();
if (is_post() && input('aksi') === 'simpan') handle_simpan_draft('keluar', $u, 'pengeluaran');

$pageTitle = 'Pengeluaran Barang';
include BASE_PATH . '/views/header.php';
if (so_aktif($u['unit_id'])) {
    echo '<div class="card empty-state"><h2>🔒 Input pengeluaran sementara dikunci</h2><p class="muted">Unit Anda sedang melaksanakan stock opname. Formulir akan tersedia kembali setelah Admin menyelesaikan stock opname.</p>'
        . '<a class="btn btn-light" href="' . e(url('stock_opname')) . '">Lihat Stock Opname</a></div>';
    include BASE_PATH . '/views/footer.php';
    return;
}
?>
<div class="page-head">
  <div>
    <h1>Pengeluaran / Pemakaian Barang</h1>
    <p><?= e($u['unit_nama']) ?> — <?= $u['role'] === 'admin'
        ? 'Sebagai <b>Admin</b>, data yang disimpan langsung mengurangi stok.'
        : 'Sebagai <b>Operator</b>, data yang disimpan menunggu validasi Admin sebelum mengurangi stok.' ?></p>
  </div>
  <div class="actions"><a class="btn btn-light" href="<?= e(url('transaksi', ['jenis' => 'keluar'])) ?>"><?= icon('list') ?> Riwayat</a></div>
</div>

<form id="fSimpan" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan">
  <div class="card">
    <div class="card-head"><h2>1. Dokumen Pemakaian</h2><span class="muted small">Upload dokumen tidak wajib</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field col-3"><label>Tanggal Pemakaian <span class="req">*</span></label><input type="date" name="tanggal" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="field col-4"><label>No. Dokumen (Nota Permintaan/SPPB/BAST)</label><input type="text" name="no_dokumen" maxlength="150"></div>
        <div class="field col-5"><label>Upload Dokumen Pemakaian</label><input type="file" name="dokumen" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"><div class="help">PDF/JPG/PNG/DOC/XLS, maks <?= UPLOAD_MAX / 1048576 ?> MB</div></div>
        <div class="field col-12"><label>Keperluan / Keterangan</label><input type="text" name="keterangan" placeholder="mis. Pemakaian rutin Subbagian Umum bulan ini"></div>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-head"><h2>2. Pilih Barang</h2></div>
  <div class="card-body">
    <form id="fItem" autocomplete="off" onsubmit="return false">
      <div class="form-grid">
        <div class="field col-12">
          <label>Nama Barang <span class="req">*</span></label>
          <input type="text" id="barangSearch" placeholder="Ketik nama barang yang akan digunakan…">
          <input type="hidden" name="barang_id" id="barangId">
          <div class="help" id="barangInfo">Hanya barang dengan stok tersedia di <?= e($u['unit_nama']) ?> yang muncul.</div>
        </div>
        <div class="field col-2"><label>Jumlah Digunakan <span class="req">*</span></label><input type="number" name="jumlah" id="jumlah" min="0.01" step="any"></div>
        <div class="field col-2"><label>Satuan</label><input type="text" id="satuan" readonly></div>
        <div class="field col-4"><label>Dokumen Dasar Pemakaian</label><input type="file" name="dokumen" id="dokItem" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>
        <div class="field col-4"><label>Keterangan</label><input type="text" name="keterangan" id="ketItem" maxlength="255" placeholder="opsional, mis. untuk kegiatan …"></div>
      </div>
      <div class="divider"></div>
      <button class="btn" type="button" id="btnAdd"><?= icon('plus') ?> Daftarkan</button>
      <span id="addMsg" class="small" style="margin-left:10px"></span>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>3. Daftar Barang Keluar <span class="badge badge-gray" id="cnt">0</span></h2>
    <button class="btn btn-danger btn-sm" type="button" id="btnClear">Kosongkan daftar</button></div>
  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="num">Jumlah</th><th>Satuan</th><th>Dokumen</th><th></th></tr></thead>
      <tbody id="list"><tr><td colspan="7" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
  <div class="card-body" style="border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="small muted">Nilai pengeluaran dihitung otomatis dengan metode FIFO saat stok berkurang.</span>
    <button class="btn btn-lg btn-green" type="submit" form="fSimpan" id="btnSave">Simpan Data Barang</button>
  </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
(function(){
  var B = window.BMD, items = [];
  var $ = function(id){ return document.getElementById(id); };
  var barangId = $('barangId'), info = $('barangInfo'), defInfo = info.innerHTML, sel = null;

  new B.Combo($('barangSearch'), {
    minChars: 1,
    source: function(q){ return B.getJSON('ajax.php?act=barang_cari&q=' + encodeURIComponent(q)).then(function(r){ return r.items; }); },
    render: function(it){ return '<div>' + B.esc(it.nama) + '</div><div class="s"><span class="k">' + B.esc(it.kode) + '</span> ' + B.esc(it.uraian) + ' · tersedia <b>' + B.fmtQty(it.tersedia) + '</b> ' + B.esc(it.satuan) + '</div>'; },
    onSelect: function(it){
      sel = it; $('barangSearch').value = it.nama; barangId.value = it.id; $('satuan').value = it.satuan;
      $('jumlah').max = it.tersedia;
      info.innerHTML = 'Kode <span class="mono">' + B.esc(it.kode) + '</span> ' + B.esc(it.uraian) + ' · Stok tersedia: <b>' + B.fmtQty(it.tersedia) + ' ' + B.esc(it.satuan) + '</b>' + (it.stok != it.tersedia ? ' <span class="muted">(stok ' + B.fmtQty(it.stok) + ', sebagian menunggu validasi/ada di daftar)</span>' : '');
      $('jumlah').focus();
    },
    onClear: function(){ sel = null; barangId.value = ''; $('satuan').value = ''; info.innerHTML = defInfo; }
  });

  function draw(list){
    items = list; $('cnt').textContent = list.length;
    var tb = $('list');
    if(!list.length) tb.innerHTML = '<tr><td colspan="7" class="empty">Belum ada barang. Pilih barang di atas lalu klik <b>Daftarkan</b>.</td></tr>';
    else tb.innerHTML = list.map(function(it, i){
      return '<tr><td>' + (i+1) + '</td><td><span class="mono">' + B.esc(it.kode) + '</span><div class="small muted">' + B.esc(it.kode_uraian) + '</div></td>'
        + '<td>' + B.esc(it.nama) + (it.ket ? '<div class="small muted">' + B.esc(it.ket) + '</div>' : '') + '</td><td class="num">' + B.fmtQty(it.jumlah) + '</td><td>' + B.esc(it.satuan) + '</td>'
        + '<td class="small">' + (it.dok ? '📎 ' + B.esc(it.dok) : '<span class="muted">-</span>') + '</td>'
        + '<td class="act"><button class="link-btn danger" data-del="' + it.id + '">Hapus</button></td></tr>';
    }).join('');
    $('btnSave').disabled = !list.length;
  }
  B.getJSON('ajax.php?act=draft_list&jenis=keluar').then(function(r){ draw(r.items || []); });

  $('btnAdd').addEventListener('click', function(){
    var msg = $('addMsg'); msg.textContent = '';
    if(!barangId.value){ msg.innerHTML = '<span style="color:var(--red)">Pilih barang dari daftar saran.</span>'; $('barangSearch').focus(); return; }
    var fd = new FormData($('fItem')); fd.append('jenis', 'keluar');
    var btn = this; btn.disabled = true;
    B.api('ajax.php?act=draft_add', fd).then(function(r){
      btn.disabled = false;
      if(!r.ok){ msg.innerHTML = '<span style="color:var(--red)">' + B.esc(r.msg) + '</span>'; return; }
      draw(r.items);
      $('barangSearch').value=''; barangId.value=''; $('satuan').value=''; $('jumlah').value=''; $('ketItem').value=''; $('dokItem').value=''; info.innerHTML = defInfo;
      msg.innerHTML = '<span style="color:var(--green)">✓ Ditambahkan ke daftar</span>';
      $('barangSearch').focus();
    });
  });
  $('list').addEventListener('click', function(e){
    var id = e.target.getAttribute('data-del'); if(!id) return;
    B.api('ajax.php?act=draft_del', {id: id}).then(function(r){ if(r.ok) draw(r.items); else alert(r.msg); });
  });
  $('btnClear').addEventListener('click', function(){
    if(!items.length || !confirm('Kosongkan seluruh daftar?')) return;
    B.api('ajax.php?act=draft_clear', {jenis:'keluar'}).then(function(r){ if(r.ok) draw([]); });
  });
  $('fSimpan').addEventListener('submit', function(e){
    if(!items.length){ e.preventDefault(); alert('Daftar barang masih kosong.'); return; }
    if(!confirm('Simpan ' + items.length + ' barang sebagai satu transaksi pengeluaran?')) e.preventDefault();
  });
})();
</script>
JS;
include BASE_PATH . '/views/footer.php';
