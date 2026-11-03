<?php
/**
 * Endpoint AJAX (JSON)
 */
require __DIR__ . '/core/bootstrap.php';
require_login();
$u = user();
$act = $_GET['act'] ?? '';

function detail_json($d)
{
    return [
        'id' => (int)$d['id'], 'kode' => $d['kode'], 'kode_uraian' => $d['kode_uraian'], 'nama' => $d['nama_barang'],
        'satuan' => $d['satuan'], 'jumlah' => (float)$d['jumlah'], 'harga' => (float)$d['harga_satuan'],
        'nilai' => round((float)$d['jumlah'] * (float)$d['harga_satuan'], 2), 'dok' => $d['dokumen_nama'],
        'ket' => $d['keterangan'], 'barang_id' => $d['barang_id'] ? (int)$d['barang_id'] : null,
    ];
}

try {
    switch ($act) {

        // ---------------------------------------------------------- cari kode sub sub rincian (level 7)
        case 'kode_cari':
            $q = trim((string)($_GET['q'] ?? ''));
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $res = rows("SELECT k.id, k.kode, k.uraian, p.uraian AS induk
                         FROM kode_barang k LEFT JOIN kode_barang p ON p.id=k.parent_id
                         WHERE k.level=7 AND k.is_active=1 AND (k.kode LIKE ? OR k.uraian LIKE ? OR p.uraian LIKE ?)
                         ORDER BY (k.uraian LIKE ?) DESC, k.kode LIMIT 40", [$q . '%', $like, $like, $q . '%']);
            json_out(['ok' => true, 'items' => array_map(function ($r) {
                return ['id' => (int)$r['id'], 'kode' => $r['kode'], 'uraian' => $r['uraian'], 'induk' => judul($r['induk'])];
            }, $res)]);

        // ---------------------------------------------------------- hierarki kode
        case 'kode_info':
            $h = kode_hierarki((int)($_GET['id'] ?? 0));
            $out = [];
            foreach ($h as $lv => $k) $out[] = ['level' => $lv, 'nama' => level_nama($lv), 'kode' => $k['kode'], 'uraian' => judul($k['uraian'])];
            json_out(['ok' => true, 'items' => $out]);

        // ---------------------------------------------------------- saran nama barang yang sudah ada (pembelian)
        case 'nama_saran':
            if (!is_unit_user()) json_out(['ok' => false], 403);
            $q = trim((string)($_GET['q'] ?? ''));
            $kid = (int)($_GET['kode_id'] ?? 0);
            // Gabungkan dari 3 sumber, supaya barang yang pernah diketik sebelumnya selalu muncul sebagai saran:
            //  1) cache "barang" (sudah pernah disetujui, punya stok akurat)
            //  2) riwayat transaksi_detail (draft/menunggu validasi yang belum punya baris barang)
            //  3) pencarian juga mencocokkan ke kolom "Keterangan" per baris barang (deskripsi/spesifikasi
            //     bebas yang pernah diisi saat input), bukan cuma Nama Barang.
            $res = rows(
                'SELECT nama_barang, satuan, MAX(stok) AS stok, MAX(keterangan) AS keterangan FROM (
                    SELECT nama_barang, satuan, stok, NULL AS keterangan FROM barang
                     WHERE unit_id=? AND kode_barang_id=? AND nama_barang LIKE ?
                    UNION ALL
                    SELECT d.nama_barang, d.satuan, 0 AS stok, d.keterangan
                      FROM transaksi_detail d
                      JOIN transaksi t ON t.id = d.transaksi_id
                     WHERE t.unit_id=? AND d.kode_barang_id=? AND t.jenis=\'masuk\'
                       AND (d.nama_barang LIKE ? OR d.keterangan LIKE ?)
                ) x
                GROUP BY LOWER(nama_barang), LOWER(satuan)
                ORDER BY nama_barang LIMIT 15',
                [(int)$u['unit_id'], $kid, '%' . $q . '%', (int)$u['unit_id'], $kid, '%' . $q . '%', '%' . $q . '%']
            );
            json_out(['ok' => true, 'items' => $res]);

        // ---------------------------------------------------------- cari barang di stok (pengeluaran)
        case 'barang_cari':
            if (!is_unit_user()) json_out(['ok' => false], 403);
            $q = trim((string)($_GET['q'] ?? ''));
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $res = rows('SELECT b.id, b.nama_barang, b.satuan, b.stok, b.nilai, k.kode, k.uraian
                         FROM barang b JOIN kode_barang k ON k.id=b.kode_barang_id
                         WHERE b.unit_id=? AND b.stok>0 AND (b.nama_barang LIKE ? OR k.uraian LIKE ? OR k.kode LIKE ?)
                         ORDER BY (b.nama_barang LIKE ?) DESC, b.nama_barang LIMIT 30', [(int)$u['unit_id'], $like, $like, $q . '%', $q . '%']);
            $draft = draft_get('keluar', $u, false);
            $out = [];
            foreach ($res as $r) {
                $tersedia = stok_tersedia($r['id']);
                if ($draft) $tersedia -= (float)val('SELECT COALESCE(SUM(jumlah),0) FROM transaksi_detail WHERE transaksi_id=? AND barang_id=?', [(int)$draft['id'], (int)$r['id']]);
                $out[] = ['id' => (int)$r['id'], 'nama' => $r['nama_barang'], 'satuan' => $r['satuan'], 'stok' => (float)$r['stok'],
                    'tersedia' => round($tersedia, 2), 'kode' => $r['kode'], 'uraian' => $r['uraian'],
                    'harga_rata' => (float)$r['stok'] > 0 ? round((float)$r['nilai'] / (float)$r['stok'], 2) : 0];
            }
            json_out(['ok' => true, 'items' => $out]);

        // ---------------------------------------------------------- daftar draft
        case 'draft_list':
            if (!is_unit_user()) json_out(['ok' => false], 403);
            $jenis = ($_GET['jenis'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
            $t = draft_get($jenis, $u, false);
            $items = $t ? array_map('detail_json', draft_items($t['id'])) : [];
            json_out(['ok' => true, 'items' => $items]);

        // ---------------------------------------------------------- tambah ke daftar (draft)
        case 'draft_add':
            if (!is_unit_user() || !is_post()) json_out(['ok' => false], 403);
            csrf_check();
            $jenis = ($_POST['jenis'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
            so_cek_kunci($u['unit_id']);
            $t = draft_get($jenis, $u, true);
            $jumlah = round(parse_num($_POST['jumlah'] ?? 0), 2);
            if ($jumlah <= 0) throw new RuntimeException('Jumlah harus lebih dari 0.');
            $ket = mb_substr(trim((string)($_POST['keterangan'] ?? '')), 0, 255);

            if ($jenis === 'masuk') {
                $kid = (int)($_POST['kode_id'] ?? 0);
                $k = row('SELECT id FROM kode_barang WHERE id=? AND level=7 AND is_active=1', [$kid]);
                if (!$k) throw new RuntimeException('Pilih Sub Sub Rincian Objek terlebih dahulu.');
                $nama = trim(preg_replace('/\s+/', ' ', (string)($_POST['nama'] ?? '')));
                $satuan = trim((string)($_POST['satuan'] ?? ''));
                $harga = round(parse_num($_POST['harga'] ?? 0), 2);
                if ($nama === '') throw new RuntimeException('Nama barang wajib diisi.');
                if ($satuan === '') throw new RuntimeException('Satuan wajib diisi.');
                if ($harga < 0) throw new RuntimeException('Harga tidak boleh negatif.');
                $dok = simpan_upload('dokumen', 'barang');
                exec_sql('INSERT INTO transaksi_detail (transaksi_id, kode_barang_id, nama_barang, satuan, jumlah, harga_satuan, nilai, dokumen_file, dokumen_nama, keterangan)
                          VALUES (?,?,?,?,?,?,?,?,?,?)', [(int)$t['id'], $kid, mb_substr($nama, 0, 255), mb_substr($satuan, 0, 50), $jumlah, $harga,
                    round($jumlah * $harga, 2), $dok['file'] ?? null, $dok['nama'] ?? null, $ket ?: null]);
            } else {
                $bid = (int)($_POST['barang_id'] ?? 0);
                $b = row('SELECT * FROM barang WHERE id=? AND unit_id=?', [$bid, (int)$u['unit_id']]);
                if (!$b) throw new RuntimeException('Pilih barang dari daftar saran.');
                $sudah = (float)val('SELECT COALESCE(SUM(jumlah),0) FROM transaksi_detail WHERE transaksi_id=? AND barang_id=?', [(int)$t['id'], $bid]);
                $tersedia = stok_tersedia($bid) - $sudah;
                if ($jumlah > $tersedia + 0.00001) throw new RuntimeException('Jumlah melebihi stok tersedia (' . angka($tersedia) . ' ' . $b['satuan'] . ').');
                $dok = simpan_upload('dokumen', 'pemakaian');
                exec_sql('INSERT INTO transaksi_detail (transaksi_id, barang_id, kode_barang_id, nama_barang, satuan, jumlah, harga_satuan, nilai, dokumen_file, dokumen_nama, keterangan)
                          VALUES (?,?,?,?,?,?,0,0,?,?,?)', [(int)$t['id'], $bid, (int)$b['kode_barang_id'], $b['nama_barang'], $b['satuan'], $jumlah,
                    $dok['file'] ?? null, $dok['nama'] ?? null, $ket ?: null]);
            }
            json_out(['ok' => true, 'items' => array_map('detail_json', draft_items($t['id']))]);

        // ---------------------------------------------------------- hapus dari daftar
        case 'draft_del':
            if (!is_unit_user() || !is_post()) json_out(['ok' => false], 403);
            csrf_check();
            $d = row("SELECT d.*, t.id AS tid FROM transaksi_detail d JOIN transaksi t ON t.id=d.transaksi_id
                      WHERE d.id=? AND t.created_by=? AND t.status='draft'", [(int)($_POST['id'] ?? 0), (int)$u['id']]);
            if (!$d) throw new RuntimeException('Item tidak ditemukan.');
            exec_sql('DELETE FROM transaksi_detail WHERE id=?', [(int)$d['id']]);
            hapus_file($d['dokumen_file']);
            json_out(['ok' => true, 'items' => array_map('detail_json', draft_items($d['tid']))]);

        // ---------------------------------------------------------- kosongkan daftar
        case 'draft_clear':
            if (!is_unit_user() || !is_post()) json_out(['ok' => false], 403);
            csrf_check();
            $jenis = ($_POST['jenis'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
            $t = draft_get($jenis, $u, false);
            if ($t) {
                foreach (rows('SELECT dokumen_file FROM transaksi_detail WHERE transaksi_id=?', [(int)$t['id']]) as $d) hapus_file($d['dokumen_file']);
                exec_sql('DELETE FROM transaksi_detail WHERE transaksi_id=?', [(int)$t['id']]);
                exec_sql("UPDATE transaksi SET sumber='input' WHERE id=?", [(int)$t['id']]);
            }
            json_out(['ok' => true, 'items' => []]);

        default:
            json_out(['ok' => false, 'msg' => 'Aksi tidak dikenal'], 404);
    }
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'msg' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => APP_DEBUG ? $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
}
