<?php
/**
 * Stock opname: penguncian transaksi, pencatatan hitung fisik, posting penyesuaian.
 */

/** Stock opname yang sedang berlangsung pada unit (atau null) */
function so_aktif($unitId)
{
    return row("SELECT * FROM stock_opname WHERE unit_id=? AND status='berlangsung' ORDER BY id DESC LIMIT 1", [(int)$unitId]);
}

/** Lempar error bila unit sedang stock opname */
function so_cek_kunci($unitId)
{
    $so = so_aktif($unitId);
    if ($so) {
        $nama = val('SELECT nama FROM unit_kerja WHERE id=?', [(int)$unitId]);
        throw new RuntimeException('Transaksi dikunci: ' . $nama . ' sedang melaksanakan stock opname (' . $so['nomor'] . ', ' . tgl_indo($so['tanggal']) . '). Transaksi dapat dilakukan kembali setelah stock opname diselesaikan.');
    }
}

function so_nomor($id, $tanggal)
{
    return 'SO-' . date('Ymd', strtotime($tanggal)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** Syarat memulai stock opname di sebuah unit. Mengembalikan pesan error atau null. */
function so_syarat($unitId, $tanggal)
{
    if (so_aktif($unitId)) return 'Stock opname sebelumnya masih berlangsung.';
    $pending = (int)val("SELECT COUNT(*) FROM transaksi WHERE unit_id=? AND status='menunggu'", [(int)$unitId]);
    if ($pending) return "Masih ada $pending transaksi menunggu validasi. Validasi/tolak terlebih dahulu.";
    $last = val("SELECT MAX(tanggal) FROM transaksi WHERE unit_id=? AND status='disetujui'", [(int)$unitId]);
    if ($last && $tanggal < $last) return 'Tanggal stock opname tidak boleh sebelum transaksi terakhir (' . tgl_indo($last) . ').';
    return null;
}

/** Mulai stock opname: kunci transaksi & ambil snapshot saldo administrasi */
function so_mulai($unitId, array $d, $u, $batch = null)
{
    return db_tx(function () use ($unitId, $d, $u, $batch) {
        // kunci baris unit agar tidak dobel
        row('SELECT id FROM unit_kerja WHERE id=? FOR UPDATE', [(int)$unitId]);
        $err = so_syarat($unitId, $d['tanggal']);
        if ($err) throw new RuntimeException($err);
        exec_sql("INSERT INTO stock_opname (unit_id, batch, tanggal, no_ba, keterangan, status, dibuat_oleh) VALUES (?,?,?,?,?,'berlangsung',?)",
            [(int)$unitId, $batch, $d['tanggal'], $d['no_ba'] ?: null, $d['keterangan'] ?: null, (int)$u['id']]);
        $id = last_id();
        exec_sql('UPDATE stock_opname SET nomor=? WHERE id=?', [so_nomor($id, $d['tanggal']), $id]);
        exec_sql('INSERT INTO stock_opname_detail (so_id, barang_id, qty_adm, nilai_adm) SELECT ?, id, stok, nilai FROM barang WHERE unit_id=? AND stok>0', [$id, (int)$unitId]);
        log_aktivitas('so_mulai', 'SO #' . $id . ' unit ' . $unitId);
        return $id;
    });
}

/** Simpan hasil hitung fisik (sementara) */
function so_simpan_fisik($soId, array $fisik, array $ket)
{
    foreach ($fisik as $did => $v) {
        $v = trim((string)$v);
        $q = $v === '' ? null : round(parse_num($v), 2);
        if ($q !== null && $q < 0) throw new RuntimeException('Jumlah fisik tidak boleh negatif.');
        exec_sql('UPDATE stock_opname_detail SET qty_fisik=?, keterangan=? WHERE id=? AND so_id=?',
            [$q, mb_substr(trim((string)($ket[$did] ?? '')), 0, 255) ?: null, (int)$did, (int)$soId]);
    }
}

/** Selesaikan stock opname: posting penyesuaian selisih dan buka kunci */
function so_selesai($soId, $u)
{
    return db_tx(function () use ($soId, $u) {
        $so = row("SELECT * FROM stock_opname WHERE id=? AND status='berlangsung' FOR UPDATE", [(int)$soId]);
        if (!$so) throw new RuntimeException('Stock opname tidak ditemukan atau sudah selesai.');
        // fisik kosong dianggap sama dengan administrasi
        exec_sql('UPDATE stock_opname_detail SET qty_fisik=qty_adm WHERE so_id=? AND qty_fisik IS NULL', [(int)$soId]);
        $det = rows('SELECT sd.*, b.nama_barang, b.satuan, b.kode_barang_id, b.stok AS stok_kini FROM stock_opname_detail sd JOIN barang b ON b.id=sd.barang_id WHERE sd.so_id=?', [(int)$soId]);
        $kurang = $lebih = [];
        foreach ($det as $d) {
            $sel = round((float)$d['qty_fisik'] - (float)$d['stok_kini'], 2);
            if ($sel < 0) $kurang[] = [$d, -$sel];
            elseif ($sel > 0) $lebih[] = [$d, $sel];
        }
        $ket = 'Penyesuaian hasil stock opname ' . $so['nomor'] . ($so['no_ba'] ? ' (BA ' . $so['no_ba'] . ')' : '');
        $trxIn = $trxOut = null;
        if ($kurang) {
            exec_sql("INSERT INTO transaksi (jenis, sumber, unit_id, tanggal, no_dokumen, keterangan, status, created_by, submitted_at, validated_by, validated_at)
                      VALUES ('keluar','stock_opname',?,?,?,?,'disetujui',?,NOW(),?,NOW())", [(int)$so['unit_id'], $so['tanggal'], $so['no_ba'], $ket, (int)$u['id'], (int)$u['id']]);
            $trxOut = last_id();
            exec_sql('UPDATE transaksi SET nomor=? WHERE id=?', [nomor_transaksi('keluar', $trxOut, $so['tanggal']), $trxOut]);
            foreach ($kurang as $k) {
                exec_sql('INSERT INTO transaksi_detail (transaksi_id, barang_id, kode_barang_id, nama_barang, satuan, jumlah, keterangan) VALUES (?,?,?,?,?,?,?)',
                    [$trxOut, (int)$k[0]['barang_id'], (int)$k[0]['kode_barang_id'], $k[0]['nama_barang'], $k[0]['satuan'], $k[1], $k[0]['keterangan'] ?: 'Selisih kurang stock opname']);
            }
            posting_keluar($trxOut);
        }
        if ($lebih) {
            exec_sql("INSERT INTO transaksi (jenis, sumber, unit_id, tanggal, no_dokumen, keterangan, status, created_by, submitted_at, validated_by, validated_at)
                      VALUES ('masuk','stock_opname',?,?,?,?,'disetujui',?,NOW(),?,NOW())", [(int)$so['unit_id'], $so['tanggal'], $so['no_ba'], $ket, (int)$u['id'], (int)$u['id']]);
            $trxIn = last_id();
            exec_sql('UPDATE transaksi SET nomor=? WHERE id=?', [nomor_transaksi('masuk', $trxIn, $so['tanggal']), $trxIn]);
            foreach ($lebih as $l) {
                $d = $l[0];
                $harga = (float)$d['qty_adm'] > 0 ? round((float)$d['nilai_adm'] / (float)$d['qty_adm'], 2)
                    : (float)val('SELECT harga_satuan FROM stok_lot WHERE barang_id=? ORDER BY tanggal DESC, id DESC LIMIT 1', [(int)$d['barang_id']]);
                exec_sql('INSERT INTO transaksi_detail (transaksi_id, kode_barang_id, nama_barang, satuan, jumlah, harga_satuan, nilai, keterangan) VALUES (?,?,?,?,?,?,?,?)',
                    [$trxIn, (int)$d['kode_barang_id'], $d['nama_barang'], $d['satuan'], $l[1], $harga, round($l[1] * $harga, 2), $d['keterangan'] ?: 'Selisih lebih stock opname']);
            }
            posting_masuk($trxIn);
        }
        exec_sql("UPDATE stock_opname SET status='selesai', selesai_oleh=?, selesai_at=NOW(), trx_masuk_id=?, trx_keluar_id=? WHERE id=?",
            [(int)$u['id'], $trxIn, $trxOut, (int)$soId]);
        log_aktivitas('so_selesai', 'SO #' . $soId . ' kurang ' . count($kurang) . ', lebih ' . count($lebih));
        return [count($kurang), count($lebih)];
    });
}

function so_batal($soId, $u)
{
    $n = exec_sql("UPDATE stock_opname SET status='batal', selesai_oleh=?, selesai_at=NOW() WHERE id=? AND status='berlangsung'", [(int)$u['id'], (int)$soId]);
    if ($n) log_aktivitas('so_batal', 'SO #' . $soId);
    return $n > 0;
}

/** Hak: 'kelola' (admin unit itu sendiri), 'batal' (admin unit itu / admin SKPD / superadmin), 'lihat' */
function so_hak($so, $u)
{
    $unit = row('SELECT * FROM unit_kerja WHERE id=?', [(int)$so['unit_id']]);
    $h = ['lihat' => false, 'kelola' => false, 'batal' => false];
    if (!$unit) return $h;
    if ($u['role'] === 'superadmin') $h = ['lihat' => true, 'kelola' => false, 'batal' => true];
    elseif ($u['role'] === 'admin_skpd' && (int)$unit['skpd_id'] === (int)$u['skpd_id']) $h = ['lihat' => true, 'kelola' => false, 'batal' => true];
    elseif (in_array((int)$so['unit_id'], unit_scope_ids($u), true)) {
        $own = (int)$so['unit_id'] === (int)$u['unit_id'] && $u['role'] === 'admin';
        $h = ['lihat' => true, 'kelola' => $own, 'batal' => $own];
    }
    if ($so['status'] !== 'berlangsung') { $h['kelola'] = false; $h['batal'] = false; }
    return $h;
}
