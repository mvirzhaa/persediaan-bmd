<?php
defined('BASE_PATH') or exit;

$SEG_LEN = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 2, 7 => 3];

/** Impor kode dari baris [akun, kelompok, jenis, objek, rincian, sub rincian, sub sub rincian, uraian] */
function kode_import_rows(array $data, $SEG_LEN)
{
    $ins = $upd = $skip = 0;
    $err = [];
    $cache = [];
    foreach (rows('SELECT id, kode, uraian FROM kode_barang') as $r) $cache[$r['kode']] = $r;
    foreach ($data as $ri => $cols) {
        $segs = [];
        for ($c = 1; $c <= 7; $c++) {
            $v = isset($cols[$c]) ? trim((string)$cols[$c]) : '';
            if ($v === '') break;
            if (!ctype_digit($v)) { $segs = null; break; }
            $segs[] = str_pad($v, $SEG_LEN[$c], '0', STR_PAD_LEFT);
        }
        $uraian = trim((string)($cols[8] ?? ''));
        if (!$segs || $uraian === '') { $skip++; continue; }
        $level = count($segs);
        $kode = implode('.', $segs);
        $parentKode = $level > 1 ? implode('.', array_slice($segs, 0, -1)) : null;
        if ($parentKode && !isset($cache[$parentKode])) { $err[] = "Baris $ri: induk $parentKode belum ada."; continue; }
        if (preg_match('/^dst\.*$/i', $uraian)) {
            $pu = judul($cache[$parentKode]['uraian'] ?? '');
            $uraian = preg_match('/lainnya$/i', $pu) ? $pu : trim($pu . ' Lainnya');
        }
        if (isset($cache[$kode])) {
            if ($cache[$kode]['uraian'] !== $uraian) { exec_sql('UPDATE kode_barang SET uraian=? WHERE id=?', [$uraian, (int)$cache[$kode]['id']]); $upd++; }
        } else {
            exec_sql('INSERT INTO kode_barang (parent_id, level, kode, uraian) VALUES (?,?,?,?)', [$parentKode ? (int)$cache[$parentKode]['id'] : null, $level, $kode, $uraian]);
            $cache[$kode] = ['id' => last_id(), 'kode' => $kode, 'uraian' => $uraian];
            $ins++;
        }
    }
    return [$ins, $upd, $skip, $err];
}

$parentId = input_int('parent');
$parent = $parentId ? row('SELECT * FROM kode_barang WHERE id=?', [$parentId]) : null;
if ($parentId && !$parent) redirect(url('kode'));

// ------------------------------------------------------------------ aksi POST
if (is_post()) {
    csrf_check();
    $aksi = input('aksi');
    try {
        if ($aksi === 'tambah') {
            $level = $parent ? (int)$parent['level'] + 1 : 1;
            if ($level > 7) throw new RuntimeException('Level maksimal adalah 7 (Sub Sub Rincian Objek).');
            $seg = preg_replace('/\D/', '', (string)input('segmen'));
            $uraian = trim((string)input('uraian'));
            if ($seg === '' || $uraian === '') throw new RuntimeException('Kode dan uraian wajib diisi.');
            $seg = str_pad($seg, $SEG_LEN[$level], '0', STR_PAD_LEFT);
            if (strlen($seg) > $SEG_LEN[$level]) throw new RuntimeException('Panjang kode level ini maksimal ' . $SEG_LEN[$level] . ' digit.');
            $kode = ($parent ? $parent['kode'] . '.' : '') . $seg;
            if (val('SELECT id FROM kode_barang WHERE kode=?', [$kode])) throw new RuntimeException("Kode $kode sudah ada.");
            exec_sql('INSERT INTO kode_barang (parent_id, level, kode, uraian) VALUES (?,?,?,?)', [$parent ? (int)$parent['id'] : null, $level, $kode, mb_substr($uraian, 0, 255)]);
            log_aktivitas('kode_tambah', $kode);
            flash('success', "Kode $kode ditambahkan.");
        } elseif ($aksi === 'ubah') {
            $id = input_int('id');
            $uraian = trim((string)input('uraian'));
            if ($uraian === '') throw new RuntimeException('Uraian wajib diisi.');
            exec_sql('UPDATE kode_barang SET uraian=?, is_active=? WHERE id=?', [mb_substr($uraian, 0, 255), input('is_active') ? 1 : 0, $id]);
            flash('success', 'Kode diperbarui.');
        } elseif ($aksi === 'hapus') {
            $id = input_int('id');
            $k = row('SELECT * FROM kode_barang WHERE id=?', [$id]);
            if (!$k) throw new RuntimeException('Kode tidak ditemukan.');
            if ((int)val('SELECT COUNT(*) FROM kode_barang WHERE parent_id=?', [$id])) throw new RuntimeException('Kode masih memiliki turunan. Hapus turunannya terlebih dahulu.');
            if ((int)val('SELECT COUNT(*) FROM barang WHERE kode_barang_id=?', [$id]) || (int)val('SELECT COUNT(*) FROM transaksi_detail WHERE kode_barang_id=?', [$id]))
                throw new RuntimeException('Kode sudah dipakai pada data barang. Nonaktifkan saja (edit → hilangkan centang Aktif).');
            exec_sql('DELETE FROM kode_barang WHERE id=?', [$id]);
            log_aktivitas('kode_hapus', $k['kode']);
            flash('success', 'Kode ' . $k['kode'] . ' dihapus.');
        } elseif ($aksi === 'import') {
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file XLSX/CSV.');
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext === 'xlsx') {
                $sheet = xlsx_read($_FILES['file']['tmp_name']);
            } elseif ($ext === 'csv') {
                $sheet = [];
                $fh = fopen($_FILES['file']['tmp_name'], 'r');
                $first = fgets($fh); rewind($fh);
                $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
                $i = 0;
                while (($r = fgetcsv($fh, 0, $delim)) !== false) { $i++; foreach ($r as $c => $v) $sheet[$i][$c + 1] = $v; }
                fclose($fh);
            } else throw new RuntimeException('Format file harus .xlsx atau .csv');
            unset($sheet[1]); // header
            list($ins, $upd, $skip, $err) = db_tx(function () use ($sheet, $SEG_LEN) { return kode_import_rows($sheet, $SEG_LEN); });
            $msg = "Impor selesai: $ins kode baru, $upd uraian diperbarui, $skip baris dilewati.";
            if ($err) $msg .= '<ul><li>' . implode('</li><li>', array_map('e', array_slice($err, 0, 15))) . '</li></ul>';
            flash($err ? 'warning' : 'success', $msg, true);
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('kode', $parentId ? ['parent' => $parentId] : []));
}

// ------------------------------------------------------------------ ekspor
if (input('export') === 'xlsx') {
    $x = new XlsxWriter();
    $x->addSheet('Kodefikasi BMD');
    $x->row(1, ['Akun', 'Kelompok', 'Jenis', 'Objek', 'Rincian', 'Sub Rincian', 'Sub-Sub Rincian', 'Uraian', 'Kode Lengkap', 'Aktif'], 2);
    $r = 2;
    foreach (rows('SELECT * FROM kode_barang ORDER BY kode') as $k) {
        $segs = explode('.', $k['kode']);
        $vals = [];
        for ($i = 0; $i < 7; $i++) $vals[] = $segs[$i] ?? '';
        $vals[] = $k['uraian']; $vals[] = $k['kode']; $vals[] = $k['is_active'] ? 'Ya' : 'Tidak';
        $x->row($r++, $vals, 3);
    }
    foreach ([1 => 7, 2 => 9, 3 => 7, 4 => 7, 5 => 8, 6 => 11, 7 => 14, 8 => 55, 9 => 20, 10 => 7] as $c => $w) $x->width($c, $w);
    $x->freeze(1);
    $x->download('kodefikasi-barang-' . date('Ymd') . '.xlsx');
}

// ------------------------------------------------------------------ data
$q = trim((string)input('q', ''));
if ($q !== '') {
    $list = rows('SELECT k.*, (SELECT COUNT(*) FROM kode_barang c WHERE c.parent_id=k.id) n_anak FROM kode_barang k
                  WHERE k.kode LIKE ? OR k.uraian LIKE ? ORDER BY k.kode LIMIT 200', [$q . '%', '%' . $q . '%']);
} else {
    $list = $parent
        ? rows('SELECT k.*, (SELECT COUNT(*) FROM kode_barang c WHERE c.parent_id=k.id) n_anak FROM kode_barang k WHERE k.parent_id=? ORDER BY k.kode', [$parentId])
        : rows('SELECT k.*, (SELECT COUNT(*) FROM kode_barang c WHERE c.parent_id=k.id) n_anak FROM kode_barang k WHERE k.parent_id IS NULL ORDER BY k.kode');
}
$crumbs = $parent ? kode_hierarki($parentId) : [];
$childLevel = $parent ? (int)$parent['level'] + 1 : 1;
$stat = rows('SELECT level, COUNT(*) n FROM kode_barang GROUP BY level ORDER BY level');
$editId = input_int('edit');

$pageTitle = 'Master Kode Barang';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div>
    <h1>Master Kode Barang</h1>
    <p>Penggolongan &amp; kodefikasi BMD: Akun → Kelompok → Jenis → Objek → Rincian Objek → Sub Rincian Objek → Sub Sub Rincian Objek.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="<?= e(url('kode', ['export' => 'xlsx'])) ?>"><?= icon('excel') ?> Ekspor Excel</a>
    <button class="btn btn-light" type="button" onclick="document.getElementById('imp').classList.toggle('hidden')"><?= icon('upload') ?> Impor Excel/CSV</button>
  </div>
</div>

<div class="card hidden" id="imp">
  <div class="card-head"><h2>Impor kode dari Excel / CSV</h2></div>
  <div class="card-body">
    <p class="small muted">Gunakan format yang sama dengan file <b>Penggolongan dan Kodefikasi Barang Milik Daerah</b>: kolom A–H = Akun, Kelompok, Jenis, Objek, Rincian, Sub Rincian, Sub-Sub Rincian, Uraian (baris 1 judul kolom). Kode yang sudah ada akan diperbarui uraiannya, kode baru ditambahkan. Uraian “Dst” otomatis diubah menjadi “… Lainnya”.</p>
    <form method="post" enctype="multipart/form-data" class="filters">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="import">
      <div class="field grow"><input type="file" name="file" accept=".xlsx,.csv" required></div>
      <button class="btn" type="submit">Impor</button>
    </form>
  </div>
</div>

<div class="stats" style="grid-template-columns:repeat(7,minmax(0,1fr))">
  <?php foreach ($stat as $s): ?>
    <div class="stat"><div class="label" style="font-size:10.5px"><?= e(level_nama($s['level'])) ?></div><div class="value" style="font-size:1.15rem"><?= angka($s['n']) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head">
    <div class="breadcrumb" style="margin:0;font-size:13px">
      <a href="<?= e(url('kode')) ?>">Semua</a>
      <?php foreach ($crumbs as $c): ?> / <a href="<?= e(url('kode', ['parent' => $c['id']])) ?>"><span class="mono"><?= e($c['kode']) ?></span> <?= e(judul($c['uraian'])) ?></a><?php endforeach; ?>
    </div>
    <form method="get" class="filters"><input type="hidden" name="page" value="kode">
      <div class="field"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari kode / uraian…"></div></form>
  </div>

  <?php if ($q === '' && $childLevel <= 7): ?>
  <div class="card-body" style="border-bottom:1px solid var(--line);background:var(--surface-2)">
    <form method="post" class="filters">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
      <div class="field" style="min-width:110px;max-width:150px"><label>Kode <?= e(level_nama($childLevel)) ?></label>
        <input type="text" name="segmen" maxlength="<?= $SEG_LEN[$childLevel] ?>" placeholder="<?= str_repeat('0', $SEG_LEN[$childLevel] - 1) ?>1" required></div>
      <div class="field grow"><label>Uraian</label><input type="text" name="uraian" required placeholder="Uraian <?= e(level_nama($childLevel)) ?>"></div>
      <button class="btn" type="submit"><?= icon('plus') ?> Tambah <?= e(level_nama($childLevel)) ?></button>
    </form>
    <div class="help small muted" style="margin-top:6px">Kode lengkap: <span class="mono"><?= e($parent ? $parent['kode'] . '.' : '') ?><?= str_repeat('x', $SEG_LEN[$childLevel]) ?></span></div>
  </div>
  <?php endif; ?>

  <div class="card-body tight table-wrap">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Uraian</th><th>Level</th><th class="num">Turunan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $k): ?>
        <tr class="tree-row">
          <td><?= e($k['kode']) ?></td>
          <td>
            <?php if ($editId === (int)$k['id']): ?>
              <form method="post" class="filters"><?= csrf_field() ?><input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <div class="field grow"><input type="text" name="uraian" value="<?= e($k['uraian']) ?>" required></div>
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= $k['is_active'] ? 'checked' : '' ?>> Aktif</label>
                <button class="btn btn-sm">Simpan</button> <a class="btn btn-light btn-sm" href="<?= e(url('kode', $parentId ? ['parent' => $parentId] : [])) ?>">Batal</a>
              </form>
            <?php else: ?>
              <?= $k['n_anak'] ? '<a href="' . e(url('kode', ['parent' => $k['id']])) . '"><b>' . e($k['uraian']) . '</b></a>' : e($k['uraian']) ?>
              <?= $k['is_active'] ? '' : ' <span class="badge badge-gray">nonaktif</span>' ?>
            <?php endif; ?>
          </td>
          <td><span class="lv-badge"><?= e(level_nama($k['level'])) ?></span></td>
          <td class="num"><?= (int)$k['n_anak'] ?: '-' ?></td>
          <td class="act">
            <?php if ((int)$k['level'] < 7): ?><a class="btn btn-light btn-sm" href="<?= e(url('kode', ['parent' => $k['id']])) ?>">Buka</a><?php endif; ?>
            <a class="btn btn-light btn-sm" href="<?= e(url('kode', array_filter(['parent' => $parentId ?: null, 'edit' => $k['id'], 'q' => $q ?: null]))) ?>">Edit</a>
            <form method="post" class="inline-form" data-confirm="Hapus kode <?= e($k['kode']) ?>?"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="5" class="empty">Tidak ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
