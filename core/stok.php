<?php
/**
 * Mesin persediaan: posting pembelian (lot FIFO), pemakaian (konsumsi FIFO), validasi.
 */

/** Cari barang yang sama di unit (kode + nama + satuan, tidak peka huruf besar/kecil) atau buat baru */
function barang_find_or_create($unitId, $kodeId, $nama, $satuan)
{
    $nama = trim(preg_replace('/\s+/', ' ', $nama));
    $satuan = trim($satuan);
    $b = row('SELECT id FROM barang WHERE unit_id=? AND kode_barang_id=? AND LOWER(nama_barang)=LOWER(?) AND LOWER(satuan)=LOWER(?) LIMIT 1',
        [(int)$unitId, (int)$kodeId, $nama, $satuan]);
    if ($b) return (int)$b['id'];
    exec_sql('INSERT INTO barang (unit_id, kode_barang_id, nama_barang, satuan) VALUES (?,?,?,?)', [(int)$unitId, (int)$kodeId, $nama, $satuan]);
    return last_id();
}

/** Hitung ulang cache stok & nilai barang dari lot */
function barang_recalc($barangId)
{
    exec_sql('UPDATE barang b SET
                stok  = (SELECT COALESCE(SUM(qty_sisa),0) FROM stok_lot WHERE barang_id=b.id),
                nilai = (SELECT COALESCE(SUM(ROUND(qty_sisa*harga_satuan,2)),0) FROM stok_lot WHERE barang_id=b.id)
              WHERE b.id=?', [(int)$barangId]);
}

/** Jumlah stok yang sudah "dipesan" oleh pengeluaran yang menunggu validasi */
function stok_dipesan($barangId, $excludeTrxId = 0)
{
    return (float)val("SELECT COALESCE(SUM(d.jumlah),0) FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id
                       WHERE d.barang_id=? AND t.jenis='keluar' AND t.status='menunggu' AND t.id<>?", [(int)$barangId, (int)$excludeTrxId]);
}

/** Stok tersedia = stok fisik - pengeluaran yang masih menunggu validasi */
function stok_tersedia($barangId, $excludeTrxId = 0)
{
    $stok = (float)val('SELECT stok FROM barang WHERE id=?', [(int)$barangId]);
    return round($stok - stok_dipesan($barangId, $excludeTrxId), 2);
}

function nomor_transaksi($jenis, $id, $tanggal)
{
    return ($jenis === 'masuk' ? 'BM' : 'BK') . '-' . date('Ymd', strtotime($tanggal)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** Posting transaksi masuk: membuat/menautkan barang dan lot FIFO */
function posting_masuk($trxId)
{
    $t = row('SELECT * FROM transaksi WHERE id=?', [(int)$trxId]);
    foreach (rows('SELECT * FROM transaksi_detail WHERE transaksi_id=? ORDER BY id', [(int)$trxId]) as $d) {
        $bid = barang_find_or_create($t['unit_id'], $d['kode_barang_id'], $d['nama_barang'], $d['satuan']);
        $nilai = round((float)$d['jumlah'] * (float)$d['harga_satuan'], 2);
        exec_sql('UPDATE transaksi_detail SET barang_id=?, nilai=? WHERE id=?', [$bid, $nilai, (int)$d['id']]);
        exec_sql('INSERT INTO stok_lot (barang_id, detail_id, tanggal, qty_masuk, qty_sisa, harga_satuan) VALUES (?,?,?,?,?,?)',
            [$bid, (int)$d['id'], $t['tanggal'], (float)$d['jumlah'], (float)$d['jumlah'], (float)$d['harga_satuan']]);
        barang_recalc($bid);
    }
}

/** Posting transaksi keluar: konsumsi lot FIFO (lot dengan tanggal <= tanggal transaksi) */
function posting_keluar($trxId)
{
    $t = row('SELECT * FROM transaksi WHERE id=?', [(int)$trxId]);
    foreach (rows('SELECT * FROM transaksi_detail WHERE transaksi_id=? ORDER BY id', [(int)$trxId]) as $d) {
        $bid = (int)$d['barang_id'];
        $b = row('SELECT * FROM barang WHERE id=? FOR UPDATE', [$bid]);
        if (!$b || (int)$b['unit_id'] !== (int)$t['unit_id']) throw new RuntimeException('Barang "' . $d['nama_barang'] . '" tidak ditemukan di unit ini.');
        $need = round((float)$d['jumlah'], 2);
        $lots = rows('SELECT * FROM stok_lot WHERE barang_id=? AND qty_sisa>0 AND tanggal<=? ORDER BY tanggal, id FOR UPDATE', [$bid, $t['tanggal']]);
        $avail = 0;
        foreach ($lots as $l) $avail += (float)$l['qty_sisa'];
        if ($avail + 0.00001 < $need) {
            throw new RuntimeException('Stok "' . $b['nama_barang'] . '" tidak mencukupi per tanggal ' . tgl_indo($t['tanggal'])
                . ' (tersedia ' . angka($avail) . ' ' . $b['satuan'] . ', diminta ' . angka($need) . ').');
        }
        $nilai = 0.0;
        foreach ($lots as $l) {
            if ($need <= 0) break;
            $take = min($need, (float)$l['qty_sisa']);
            exec_sql('UPDATE stok_lot SET qty_sisa = qty_sisa - ? WHERE id=?', [$take, (int)$l['id']]);
            exec_sql('INSERT INTO stok_keluar (detail_id, lot_id, qty, harga_satuan) VALUES (?,?,?,?)', [(int)$d['id'], (int)$l['id'], $take, (float)$l['harga_satuan']]);
            $nilai += round($take * (float)$l['harga_satuan'], 2);
            $need = round($need - $take, 2);
        }
        $harga = (float)$d['jumlah'] > 0 ? round($nilai / (float)$d['jumlah'], 2) : 0;
        exec_sql('UPDATE transaksi_detail SET nilai=?, harga_satuan=? WHERE id=?', [round($nilai, 2), $harga, (int)$d['id']]);
        barang_recalc($bid);
    }
}

/**
 * Finalisasi draft (dipanggil saat user menekan "Simpan").
 * Admin -> langsung disetujui & stok berubah. Operator -> menunggu validasi.
 */
function finalisasi_transaksi($trxId, array $header, $u)
{
    return db_tx(function () use ($trxId, $header, $u) {
        $t = row('SELECT * FROM transaksi WHERE id=? FOR UPDATE', [(int)$trxId]);
        if (!$t || $t['status'] !== 'draft' || (int)$t['created_by'] !== (int)$u['id']) throw new RuntimeException('Draft tidak ditemukan.');
        so_cek_kunci($t['unit_id']);
        $n = (int)val('SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id=?', [(int)$trxId]);
        if ($n === 0) throw new RuntimeException('Daftar barang masih kosong.');
        $status = $u['role'] === 'admin' ? 'disetujui' : 'menunggu';
        exec_sql('UPDATE transaksi SET tanggal=?, no_dokumen=?, keterangan=?, dokumen_file=COALESCE(?, dokumen_file), dokumen_nama=COALESCE(?, dokumen_nama),
                  status=?, submitted_at=NOW(), nomor=?, sumber=? WHERE id=?', [
            $header['tanggal'], $header['no_dokumen'], $header['keterangan'], $header['dokumen_file'], $header['dokumen_nama'],
            $status, nomor_transaksi($t['jenis'], $trxId, $header['tanggal']), $header['sumber'] ?? $t['sumber'], (int)$trxId,
        ]);
        if ($t['jenis'] === 'keluar') {
            // cek ketersediaan (memperhitungkan pengeluaran lain yang menunggu)
            foreach (rows('SELECT barang_id, SUM(jumlah) j, MAX(nama_barang) nama FROM transaksi_detail WHERE transaksi_id=? GROUP BY barang_id', [(int)$trxId]) as $d) {
                $tersedia = stok_tersedia($d['barang_id'], $trxId);
                if ($tersedia + 0.00001 < (float)$d['j']) throw new RuntimeException('Stok "' . $d['nama'] . '" tidak mencukupi (tersedia ' . angka($tersedia) . ').');
            }
        }
        if ($status === 'disetujui') {
            exec_sql('UPDATE transaksi SET validated_by=?, validated_at=NOW() WHERE id=?', [(int)$u['id'], (int)$trxId]);
            if ($t['jenis'] === 'masuk') posting_masuk($trxId); else posting_keluar($trxId);
        }
        log_aktivitas('simpan_' . $t['jenis'], 'Transaksi #' . $trxId . ' status ' . $status);
        return $status;
    });
}

/** Validasi oleh admin: setujui / tolak */
function validasi_transaksi($trxId, $setuju, $catatan, $u)
{
    return db_tx(function () use ($trxId, $setuju, $catatan, $u) {
        $t = row('SELECT * FROM transaksi WHERE id=? FOR UPDATE', [(int)$trxId]);
        if (!$t || !can_validate($t, $u)) throw new RuntimeException('Transaksi tidak dapat divalidasi.');
        so_cek_kunci($t['unit_id']);
        if ($setuju) {
            if ($t['jenis'] === 'masuk') posting_masuk($trxId); else posting_keluar($trxId);
        }
        exec_sql('UPDATE transaksi SET status=?, validated_by=?, validated_at=NOW(), catatan_validasi=? WHERE id=?',
            [$setuju ? 'disetujui' : 'ditolak', (int)$u['id'], $catatan, (int)$trxId]);
        log_aktivitas($setuju ? 'validasi_setuju' : 'validasi_tolak', 'Transaksi #' . $trxId);
        return true;
    });
}

/** Ambil/buat draft milik user untuk jenis tertentu */
function draft_get($jenis, $u, $create = true)
{
    $t = row("SELECT * FROM transaksi WHERE created_by=? AND jenis=? AND status='draft' AND unit_id=? ORDER BY id DESC LIMIT 1",
        [(int)$u['id'], $jenis, (int)$u['unit_id']]);
    if (!$t && $create) {
        exec_sql("INSERT INTO transaksi (jenis, unit_id, tanggal, status, created_by) VALUES (?,?,CURDATE(),'draft',?)", [$jenis, (int)$u['unit_id'], (int)$u['id']]);
        $t = row('SELECT * FROM transaksi WHERE id=?', [last_id()]);
    }
    return $t;
}

function draft_items($trxId)
{
    return rows('SELECT d.*, k.kode, k.uraian AS kode_uraian, b.stok AS stok_barang
                 FROM transaksi_detail d
                 JOIN kode_barang k ON k.id=d.kode_barang_id
                 LEFT JOIN barang b ON b.id=d.barang_id
                 WHERE d.transaksi_id=? ORDER BY d.id', [(int)$trxId]);
}

/** Tangani tombol "Simpan Data Barang" pada halaman pembelian/pengeluaran */
function handle_simpan_draft($jenis, $u, $backPage)
{
    csrf_check();
    $t = draft_get($jenis, $u, false);
    $dok = null;
    try {
        if (!$t) throw new RuntimeException('Daftar barang masih kosong.');
        $tanggal = (string)input('tanggal', '');
        if (!valid_date($tanggal)) throw new RuntimeException('Tanggal tidak valid.');
        if ($tanggal > date('Y-m-d')) throw new RuntimeException('Tanggal tidak boleh melebihi hari ini.');
        $dok = simpan_upload('dokumen', $jenis === 'masuk' ? 'pembelian' : 'pemakaian');
        $status = finalisasi_transaksi($t['id'], [
            'tanggal' => $tanggal,
            'no_dokumen' => mb_substr(trim((string)input('no_dokumen', '')), 0, 150) ?: null,
            'keterangan' => trim((string)input('keterangan', '')) ?: null,
            'dokumen_file' => $dok['file'] ?? null,
            'dokumen_nama' => $dok['nama'] ?? null,
        ], $u);
        $label = $jenis === 'masuk' ? 'Pembelian' : 'Pengeluaran';
        if ($status === 'disetujui') flash('success', $label . ' disimpan dan stok sudah ' . ($jenis === 'masuk' ? 'bertambah.' : 'berkurang.'));
        else flash('info', $label . ' disimpan dan menunggu validasi Admin. Stok akan berubah setelah divalidasi.');
        redirect(url('transaksi_detail', ['id' => $t['id']]));
    } catch (RuntimeException $e) {
        if ($dok) hapus_file($dok['file']);
        flash('error', $e->getMessage());
        redirect(url($backPage));
    }
}

/** Rantai induk kode (level 1..7) untuk satu kode sub sub rincian */
function kode_hierarki($kodeId)
{
    $out = [];
    $id = (int)$kodeId;
    $guard = 0;
    while ($id && $guard++ < 10) {
        $k = row('SELECT id, parent_id, level, kode, uraian FROM kode_barang WHERE id=?', [$id]);
        if (!$k) break;
        $out[(int)$k['level']] = $k;
        $id = (int)$k['parent_id'];
    }
    ksort($out);
    return $out;
}

function level_nama($lv)
{
    $n = [1 => 'Akun', 2 => 'Kelompok', 3 => 'Jenis', 4 => 'Objek', 5 => 'Rincian Objek', 6 => 'Sub Rincian Objek', 7 => 'Sub Sub Rincian Objek'];
    return $n[(int)$lv] ?? '';
}
