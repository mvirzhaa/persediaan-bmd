# SIPERSEDIAAN BMD

Sistem Informasi Persediaan Barang Milik Daerah — PHP native + MySQLi (tanpa framework, tanpa Composer).

## Instalasi cepat (XAMPP / Laragon)

1. Salin folder ini ke `htdocs/` (XAMPP) atau `www/` (Laragon).
2. Buat database `persediaan_bmd` (collation `utf8mb4_unicode_ci`) lalu impor:
   - `database/persediaan_bmd_lengkap.sql` — 78 SKPD + unit/sub unit BPKAD beserta akunnya, **tanpa data transaksi**, **atau**
   - `database/persediaan_bmd_kosong.sql` — hanya struktur, kode barang, dan super admin.
   - Database sudah terpasang dan ingin memulai input dari nol? Jalankan `database/03_kosongkan_transaksi.sql` (hanya mengosongkan barang, transaksi, dan stock opname).
3. Sesuaikan koneksi di `config.php` (atau buat `config.local.php` berisi `define('DB_PASS', '...');`).
4. Buka `http://localhost/persediaan-bmd/` → login `superadmin@bmd.test` / `password`.

Kebutuhan: PHP 7.4+ (ekstensi `mysqli`, `zip`, `mbstring`), MySQL 5.7+ / MariaDB 10.3+.

Panduan lengkap: `docs/PANDUAN.md` (juga tersedia di menu **Panduan** dalam aplikasi).

## Struktur folder

```
index.php            router halaman (index.php?page=...)
login.php, logout.php
ajax.php             endpoint JSON (pencarian kode/barang, daftar sementara)
unduh.php            unduh dokumen dengan cek hak akses
config.php           konfigurasi database & upload
core/                db, auth, helper, mesin stok FIFO, stock opname, laporan, pembaca/penulis XLSX
pages/               halaman per menu
views/               header/footer layout
assets/              CSS & JS (tanpa library eksternal)
uploads/             dokumen yang diunggah (akses langsung diblokir)
database/            file SQL
docs/                panduan pengguna
```

## Catatan teknis

- Semua query memakai prepared statement MySQLi; form dilindungi token CSRF; password memakai `password_hash`.
- Nilai persediaan dihitung FIFO (tabel `stok_lot` & `stok_keluar`); cache saldo di tabel `barang`.
- Master kode barang 7 level disimpan dalam satu tabel hierarki `kode_barang` (kode lengkap + `parent_id`).
- Selama stock opname berlangsung (`stock_opname.status = 'berlangsung'`), seluruh transaksi unit dikunci di sisi server.
- Ekspor Excel memakai penulis XLSX bawaan (`core/xlsx.php`) — tidak perlu PhpSpreadsheet.
