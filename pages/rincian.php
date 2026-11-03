<?php
/**
 * Rincian dari dashboard (Super Admin & Admin SKPD)
 *   tipe=barang : rincian per barang (dari kolom Jenis Barang)
 *   tipe=nilai  : rincian nilai per jenis barang (+ per SKPD/unit) (dari kolom Nilai)
 *   tipe=belum  : daftar SKPD/unit yang belum menginput pembelian/pemakaian
 * partial=1 -> potongan HTML untuk jendela pop-up dashboard
 * export=xlsx -> unduh Excel
 */
defined('BASE_PATH') or exit;
$u = user();
$f = dash_filter($u);
$tipe = in_array(input('tipe'), ['barang', 'nilai', 'belum'], true) ? input('tipe') : 'nilai';
$ent = $tipe === 'belum' ? 0 : input_int('ent');
$semua = $tipe === 'belum' && input('semua') ? 1 : 0;
$partial = (bool)input('partial');

$unitIds = dash_ent_units($f, $ent);
if ($unitIds === null) {
    if ($partial) { http_response_code(404); echo '<div class="modal-head"><h3>Rincian</h3><div class="actions"><button class="icon-btn modal-x" type="button" data-modal-close aria-label="Tutup">&#10005;</button></div></div>'
        . '<div class="modal-body"><div class="alert alert-error">Data tidak ditemukan atau di luar hak akses Anda.</div></div>'; exit; }
    flash('error', 'Data tidak ditemukan atau di luar hak akses Anda.');
    redirect(url('dashboard', dash_qs($f)));
}

$entNama = dash_ent_nama($f, $ent);
$isSkpd = dash_is_skpd($f);
if ($tipe === 'barang') {
    $judul = 'Rincian jenis barang — ' . $entNama;
    $models = [['sheet' => 'Rincian Barang', 'judul' => '', 'model' => dash_model_barang($f, $ent, $unitIds)]];
} elseif ($tipe === 'nilai') {
    $judul = 'Rincian nilai persediaan — ' . $entNama;
    $models = dash_model_nilai($f, $ent, $unitIds);
} else {
    $judul = ($isSkpd ? 'SKPD' : 'Unit / sub unit') . ' yang belum menginput pembelian / pemakaian' . ($semua ? ' (semua ditampilkan)' : '');
    $models = [['sheet' => 'Belum Input', 'judul' => '', 'model' => dash_model_belum($f, (bool)$semua)]];
}

$baseQs = array_merge(dash_qs($f), ['tipe' => $tipe, 'ent' => $ent]);
if ($semua) $baseQs['semua'] = 1;
$xlsUrl = url('rincian', array_merge($baseQs, ['export' => 'xlsx']));
$fullUrl = url('rincian', $baseQs);
$backUrl = url('dashboard', dash_qs($f));

// ------------------------------------------------------------------ ekspor
if (input('export') === 'xlsx') {
    $x = new XlsxWriter();
    foreach ($models as $m) { $x->addSheet($m['sheet']); report_xlsx($x, $m['model']); }
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($entNama)), '-');
    log_aktivitas('dashboard_ekspor', $tipe . ' ' . $entNama . ' ' . $f['plabel'] . ' ' . $f['tahun']);
    $x->download('rincian-' . $tipe . '-' . ($slug ?: 'data') . '-' . $f['tahun'] . '-' . $f['periode'] . '.xlsx');
}

$toggleBelum = '';
if ($tipe === 'belum') {
    $tq = dash_qs($f) + ['tipe' => 'belum'];
    if (!$semua) $tq['semua'] = 1;
    $toggleBelum = url('rincian', $tq);
}

// ------------------------------------------------------------------ potongan untuk pop-up
if ($partial) {
    ?>
    <div class="modal-head">
      <h3><?= e($judul) ?></h3>
      <div class="actions">
        <?php if ($toggleBelum): ?><a class="btn btn-light btn-sm" href="<?= e($toggleBelum) ?>" data-rinci><?= $semua ? 'Hanya yang belum' : 'Tampilkan semua' ?></a><?php endif; ?>
        <a class="btn btn-sm" href="<?= e($xlsUrl) ?>">Unduh Excel</a>
        <a class="btn btn-light btn-sm" href="<?= e($fullUrl) ?>" target="_blank" rel="noopener">Buka di tab baru</a>
        <button class="icon-btn modal-x" type="button" data-modal-close aria-label="Tutup">&#10005;</button>
      </div>
    </div>
    <div class="modal-body">
      <?php foreach ($models as $i => $m): ?>
        <?php if ($m['judul'] && count($models) > 1): ?><h4 class="modal-sub"><?= e($m['judul']) ?></h4><?php endif; ?>
        <div class="report-scroll"><?= report_html($m['model']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php
    exit;
}

// ------------------------------------------------------------------ halaman penuh
$pageTitle = 'Rincian';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head no-print">
  <div>
    <div class="breadcrumb"><a href="<?= e($backUrl) ?>">Dashboard</a> / Rincian</div>
    <h1><?= e($judul) ?></h1>
  </div>
  <div class="actions">
    <?php if ($toggleBelum): ?><a class="btn btn-light" href="<?= e($toggleBelum) ?>"><?= $semua ? 'Hanya yang belum' : 'Tampilkan semua' ?></a><?php endif; ?>
    <a class="btn" href="<?= e($xlsUrl) ?>"><?= icon('excel') ?> Unduh Excel</a>
    <button class="btn btn-light" type="button" onclick="window.print()"><?= icon('print') ?> Cetak / PDF</button>
    <a class="btn btn-light" href="<?= e($backUrl) ?>">Kembali ke Dashboard</a>
  </div>
</div>
<div class="card">
  <div class="card-body">
    <?php foreach ($models as $i => $m): ?>
      <?php if ($i > 0): ?><div class="page-break"></div><?php endif; ?>
      <?php if ($m['judul'] && count($models) > 1): ?><h3 class="no-print" style="margin:<?= $i ? '22px' : '0' ?> 0 10px"><?= e($m['judul']) ?></h3><?php endif; ?>
      <div class="report-scroll"><?= report_html($m['model']) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
