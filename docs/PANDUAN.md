# Panduan Penggunaan SIPERSEDIAAN BMD

Sistem Informasi Persediaan Barang Milik Daerah — pencatatan pembelian, pemakaian, stock opname, dan laporan persediaan per SKPD, unit, dan sub unit.

## 1. Gambaran Umum

Struktur organisasi di aplikasi mengikuti tiga tingkat:

- **SKPD** — dibuat oleh Super Admin. Setiap SKPD otomatis memiliki **2 akun Admin SKPD**.
- **Unit** — dibuat oleh Admin SKPD di bawah SKPD-nya. Setiap unit otomatis memiliki **1 akun Admin Unit** dan **1 akun Operator Unit**.
- **Sub Unit** — dibuat oleh Admin SKPD di bawah sebuah unit. Setiap sub unit otomatis memiliki **1 akun Admin Sub Unit** dan **1 akun Operator Sub Unit**.

Akun yang dibuat otomatis belum bisa dipakai login sampai admin di atasnya mengisi **email dan password** lalu mencentang **Akun aktif**.

Persediaan (stok) dicatat di tingkat **unit** dan **sub unit**. Admin SKPD dan Super Admin melihat hasilnya melalui dashboard dan laporan.

## 2. Peran dan Hak Akses

| Menu | Super Admin | Admin SKPD | Admin Unit / Sub Unit | Operator Unit / Sub Unit |
|---|---|---|---|---|
| Dashboard | Seluruh SKPD | SKPD-nya | Unit-nya (+ sub unit untuk Admin Unit) | Unit-nya |
| SKPD & Akun Admin | Ya | - | - | - |
| Unit & Sub Unit, atur akun | Ya (dukungan) | Ya | - | - |
| Master Kode Barang | Ya | - | - | - |
| Stok Barang & Kartu Stok | - | - | Ya | Ya |
| Pembelian Barang (+ impor Excel) | - | - | Ya, stok langsung bertambah | Ya, menunggu validasi admin |
| Pengeluaran Barang | - | - | Ya, stok langsung berkurang | Ya, menunggu validasi admin |
| Validasi | - | - | Ya | - |
| Stock Opname | Pantau / batalkan | Mulai serentak, pantau, batalkan | Mulai, isi hitung fisik, selesaikan | Lihat |
| Laporan | Seluruh SKPD | SKPD-nya | Unit-nya (+ sub unit) | - |
| Pengaturan & Log Aktivitas | Ya | - | - | - |

**Aturan validasi:** transaksi yang diinput **Operator** harus disetujui **Admin** sebelum mengubah stok. Transaksi Operator Sub Unit dapat divalidasi oleh **Admin Sub Unit** yang bersangkutan **maupun Admin Unit** induknya. Transaksi yang diinput **Admin** langsung mengubah stok tanpa validasi.

## 3. Instalasi (XAMPP / Laragon)

**Kebutuhan:** PHP 7.4 atau lebih baru (disarankan 8.x) dengan ekstensi `mysqli`, `zip`, `mbstring`; MySQL 5.7+ atau MariaDB 10.3+.

1. Salin folder `persediaan-bmd` ke `C:\xampp\htdocs\` (XAMPP) atau `C:\laragon\www\` (Laragon).
2. Buka **phpMyAdmin**, buat database baru bernama `persediaan_bmd` dengan collation `utf8mb4_unicode_ci`.
3. Pilih database tersebut → tab **Import** → pilih file:
   - `database/persediaan_bmd_lengkap.sql` — berisi struktur, master kode barang, dan **data contoh** (disarankan untuk uji coba), atau
   - `database/persediaan_bmd_kosong.sql` — struktur, master kode barang, dan akun Super Admin saja (untuk mulai dari nol).
4. Buka `config.php`, sesuaikan `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` (bawaan XAMPP: user `root`, password kosong).
5. Buka browser ke `http://localhost/persediaan-bmd/`.
6. Masuk dengan `superadmin@bmd.test` / `password`, lalu **segera ganti password** di menu **Profil & Password**.

Jika ekstensi `zip` belum aktif (dibutuhkan untuk ekspor/impor Excel), buka `php.ini`, hapus tanda `;` pada baris `extension=zip`, lalu restart Apache. Untuk dokumen lebih besar dari 2 MB, naikkan `upload_max_filesize` dan `post_max_size` di `php.ini` (aplikasi membatasi 5 MB per file).

Untuk server produksi: ubah `APP_DEBUG` menjadi `false` di `config.php`, gunakan HTTPS, dan pastikan folder `uploads/` dapat ditulis oleh web server.

## 4. Super Admin

### 4.1 Membuat SKPD

1. Menu **SKPD & Akun Admin** → **Tambah SKPD**.
2. Isi kode (opsional), nama SKPD, lokasi, PIC, email, telepon → **Simpan & Buat Akun Admin**.
3. Di panel **Akun Admin SKPD** muncul 2 akun. Klik **Atur Akun** pada masing-masing, isi nama pengguna, NIP, email, password (minimal 8 karakter), centang **Akun aktif**, lalu **Simpan Akun**.

Kolom **Admin SKPD** pada daftar SKPD menunjukkan berapa akun yang sudah aktif (mis. `1/2 aktif`). SKPD hanya dapat dihapus bila belum memiliki data persediaan.

### 4.2 Master Kode Barang

Menu **Master Kode Barang** menyimpan penggolongan dan kodefikasi 7 level dalam satu tabel bertingkat:

| Level | Nama | Contoh kode | Contoh uraian |
|---|---|---|---|
| 1 | Akun | 1 | Aset |
| 2 | Kelompok | 1.1 | Aset Lancar |
| 3 | Jenis | 1.1.7 | Persediaan |
| 4 | Objek | 1.1.7.01 | Barang Pakai Habis |
| 5 | Rincian Objek | 1.1.7.01.03 | Alat/Bahan untuk Kegiatan Kantor |
| 6 | Sub Rincian Objek | 1.1.7.01.03.01 | Alat Tulis Kantor |
| 7 | Sub Sub Rincian Objek | 1.1.7.01.03.01.001 | Alat Tulis |

- **Menelusuri:** klik uraian atau tombol **Buka** untuk masuk ke level di bawahnya. Jejak (breadcrumb) di atas tabel untuk kembali.
- **Menambah:** di level yang sedang dibuka, isi kode segmen (mis. `017`) dan uraian → **Tambah**. Kode lengkap terbentuk otomatis.
- **Mengubah:** **Edit** untuk mengganti uraian atau menonaktifkan kode. Kode nonaktif tidak muncul lagi di formulir pembelian.
- **Menghapus:** hanya untuk kode tanpa turunan dan belum dipakai barang.
- **Impor/Ekspor:** **Ekspor Excel** mengunduh seluruh kode. **Impor Excel/CSV** menerima format yang sama dengan file *Penggolongan dan Kodefikasi Barang Milik Daerah* (kolom Akun, Kelompok, Jenis, Objek, Rincian, Sub Rincian, Sub-Sub Rincian, Uraian). Kode baru ditambahkan, kode yang sudah ada diperbarui uraiannya.

### 4.3 Pengaturan, Unit, Stock Opname, Log

- **Pengaturan:** nama pemerintah daerah (kop laporan), nama daerah, kota tempat tanda tangan, dan penandatangan default laporan tingkat pemda.
- **Unit & Sub Unit:** Super Admin dapat membantu mengelola unit di SKPD mana pun (pilih SKPD di kanan atas).
- **Stock Opname:** memantau progres per SKPD dan membatalkan stock opname yang tersendat.
- **Log Aktivitas:** jejak login, perubahan data master, transaksi, validasi, stock opname, dan ekspor laporan.

## 5. Admin SKPD

### 5.1 Membuat Unit dan Sub Unit

1. Menu **Unit & Sub Unit** → **Tambah Unit**. Isi nama, lokasi, PIC, email, telepon → **Simpan & Buat Akun**.
2. Untuk sub unit, klik **+ Sub Unit** pada baris unit (atau **Tambah Sub Unit** di halaman unit).
3. Setiap unit dan sub unit otomatis memiliki akun **Admin** dan **Operator**. Klik **Edit & Akun** → **Atur** untuk mengisi email & password, lalu centang **Akun aktif**.

Badge `2/2 aktif` menandakan kedua akun sudah dapat digunakan.

### 5.2 Stock Opname Serentak

Menu **Stock Opname** → isi tanggal posisi, nomor Berita Acara, keterangan → **Mulai Serentak**. Stock opname dibuka di **seluruh unit dan sub unit** SKPD, dan transaksi di semuanya dikunci. Unit yang masih memiliki transaksi *menunggu validasi* dilewati dan ditampilkan di pesan peringatan. Selesaikan validasinya dulu, lalu klik **Mulai Serentak** lagi.

Tabel **Status per unit & sub unit** menampilkan progres isian hitung fisik. Masing-masing Admin Unit/Sub Unit yang mengisi dan menyelesaikan stock opname unitnya. Tombol **Batalkan semua yang berlangsung** membuka kunci tanpa membukukan selisih.

### 5.3 Laporan

Lihat bagian **8. Laporan**. Admin SKPD dapat memilih seluruh unit atau satu unit/sub unit.

## 6. Admin Unit dan Admin Sub Unit

### 6.1 Dashboard

Menampilkan jumlah jenis barang, nilai persediaan, transaksi menunggu validasi, dan pembelian bulan berjalan. **Admin Unit** juga melihat tabel **Kondisi stok sub unit** dengan tombol **Lihat stok** untuk tiap sub unit.

### 6.2 Stok Barang dan Kartu Stok

- Menu **Stok Barang** menampilkan setiap barang: kode, nama, satuan, stok, jumlah yang sedang menunggu pengeluaran, harga rata-rata, dan nilai.
- **Admin Unit** dapat memilih **Lokasi** untuk melihat stok sub unit di bawahnya.
- Saring berdasarkan sub rincian objek atau kata kunci. Centang **Tampilkan stok habis** untuk melihat barang bersaldo nol.
- **Kartu Stok** menampilkan klasifikasi lengkap barang, lot FIFO yang tersisa, dan seluruh mutasi masuk/keluar dengan saldo berjalan.
- **Ekspor Excel** mengunduh daftar stok sesuai filter.

### 6.3 Pembelian Barang (input manual)

1. **Dokumen Pembelian:** isi tanggal pembelian/penerimaan, nomor dokumen (faktur/BAST/SPK), keterangan, dan unggah dokumen pembelian. Unggahan **tidak wajib**.
2. **Daftarkan Barang:**
   - Ketik pada kolom **Sub Sub Rincian Objek** (kode atau nama, mis. `alat tulis` atau `1.1.7.01.03`), lalu pilih dari daftar saran.
   - Setelah dipilih, muncul informasi **Akun, Kelompok, Jenis, Objek, Rincian Objek, Sub Rincian Objek** sebagai referensi.
   - Isi **Nama Barang** sebagai deskripsi detail (teks bebas). Bila barang serupa pernah dicatat, namanya muncul sebagai saran. Pilih saran tersebut agar stok tergabung pada barang yang sama.
   - Isi **Jumlah**, **Satuan**, **Harga Satuan**. **Total** dihitung otomatis.
   - Unggah **Dokumen per Barang** bila ada (tidak wajib), lalu klik **Daftarkan**.
3. Barang masuk ke **Daftar Barang** dan **belum** menjadi stok. Ulangi langkah 2 sampai semua barang dalam satu dokumen terdaftar. Barang yang salah dapat dihapus dari daftar.
4. Klik **Simpan Data Barang**. Seluruh daftar tersimpan sebagai satu transaksi:
   - **Admin:** stok langsung bertambah.
   - **Operator:** status *Menunggu Validasi*. Stok bertambah setelah admin menyetujui.

Daftar barang tersimpan di server. Jika halaman tertutup sebelum disimpan, daftar tetap ada saat halaman dibuka kembali.

### 6.4 Pembelian Barang (impor dari Excel)

1. Di halaman **Pembelian Barang** klik **Impor dari Excel** → **Unduh Template**.
2. Template berisi tiga sheet:
   - **Import Pembelian** — tempat mengisi data mulai baris 5. Kolom B (kode) memiliki dropdown, kolom C menampilkan uraian kode otomatis.
   - **Daftar Kode** — seluruh kode Sub Sub Rincian Objek yang aktif.
   - **Petunjuk** — cara pengisian dan contoh.
3. Isi: kode Sub Sub Rincian Objek, nama barang, jumlah, satuan, harga satuan, keterangan (opsional).
4. Unggah file → **Unggah & Tambahkan ke Daftar**. Bila ada baris yang salah (kode tidak dikenal, jumlah kosong, dsb.), impor dibatalkan dan nomor baris yang perlu diperbaiki ditampilkan.
5. Barang hasil impor masuk ke **Daftar Barang**. Lengkapi tanggal & dokumen pembelian, periksa daftar, lalu klik **Simpan Data Barang**. Aturan admin/operator sama dengan input manual.

### 6.5 Pengeluaran / Pemakaian Barang

1. **Dokumen Pemakaian:** isi tanggal, nomor dokumen (nota permintaan/SPPB), keperluan, dan unggah dokumen (tidak wajib).
2. **Pilih Barang:** ketik nama barang. Muncul saran barang yang **tersedia** di unit Anda beserta stok tersedianya. Pilih barang, isi **Jumlah Digunakan**, unggah **Dokumen Dasar Pemakaian** bila ada (tidak wajib), lalu klik **Daftarkan**.
3. Ulangi sampai semua barang terdaftar, lalu klik **Simpan Data Barang**.

Sistem menolak jumlah yang melebihi stok tersedia. Stok tersedia sudah dikurangi pengeluaran lain yang masih menunggu validasi. Nilai pengeluaran dihitung otomatis dengan metode **FIFO**: barang yang masuk lebih dulu dikeluarkan lebih dulu, dengan harga lot masing-masing.

### 6.6 Validasi

Menu **Validasi** (angka di samping menu = jumlah yang menunggu) menampilkan transaksi dari Operator unit Anda. Admin Unit juga melihat transaksi Operator sub unit di bawahnya.

- Klik **Periksa** untuk melihat detail barang dan dokumen. Lalu **Setujui**, atau **Tolak** dengan mengisi alasan pada kolom catatan.
- Centang beberapa transaksi lalu klik **Setujui yang dicentang** untuk persetujuan massal.

### 6.7 Riwayat Transaksi

Semua pembelian dan pengeluaran beserta statusnya. Saring berdasarkan jenis, status, unit, rentang tanggal, atau kata kunci (nomor, dokumen, nama barang). Klik nomor transaksi untuk melihat detail, mengunduh dokumen, atau mencetak.

### 6.8 Stock Opname Unit

Stock opname mencocokkan **saldo administrasi** dengan **hasil hitung fisik**. **Selama stock opname berlangsung, seluruh transaksi di unit tersebut dikunci:** input pembelian, pengeluaran, impor Excel, dan validasi tidak dapat dilakukan. Banner kuning tampil di setiap halaman.

1. Menu **Stock Opname** → isi **Tanggal posisi**, **No. Berita Acara**, keterangan → **Mulai & Kunci Transaksi**.
   - Syarat: tidak ada transaksi *menunggu validasi*, dan tanggal tidak lebih awal dari transaksi terakhir.
2. Klik **Lembar Kerja** untuk mengunduh daftar barang (Excel) sebagai lembar hitung fisik di gudang.
3. Isi kolom **Saldo Fisik** (dan keterangan, mis. *rusak*, *kedaluwarsa*). Selisih unit dan rupiah dihitung langsung.
   - **Isi kosong = saldo adm** mengisi semua baris kosong dengan saldo administrasi. Setelah itu cukup ubah baris yang berbeda.
   - **Simpan Sementara** bila penghitungan belum selesai.
4. Klik **Selesaikan Stock Opname**. Sistem otomatis membuat transaksi **penyesuaian**:
   - fisik **lebih kecil** → pengeluaran penyesuaian (nilai FIFO);
   - fisik **lebih besar** → penerimaan penyesuaian dengan harga rata-rata barang.
   
   Setelah itu kunci transaksi dibuka kembali.
5. Unduh **Berita Acara (Excel)** atau **Cetak** sebagai dokumen hasil stock opname.

Admin Unit hanya menjalankan stock opname untuk unitnya sendiri. Masing-masing sub unit dijalankan oleh Admin Sub Unit-nya. Admin Unit dapat melihat riwayat stock opname sub unitnya.

## 7. Operator Unit dan Operator Sub Unit

Operator memiliki menu **Dashboard, Stok Barang, Pembelian Barang, Pengeluaran Barang, Riwayat Transaksi, Stock Opname** (lihat saja). Cara input sama dengan bagian 6.3–6.5. Bedanya, setiap transaksi yang disimpan berstatus **Menunggu Validasi** sampai disetujui Admin. Bila ditolak, alasan penolakan terlihat di detail transaksi. Perbaiki lalu input ulang.

## 8. Laporan

Menu **Laporan** tersedia untuk Super Admin, Admin SKPD, dan Admin Unit/Sub Unit. Setiap laporan dapat dilihat di layar, **diekspor ke Excel (.xlsx)**, atau **dicetak / disimpan sebagai PDF** melalui dialog cetak browser.

**Filter umum:**

- **SKPD** (khusus Super Admin; pilih *Seluruh SKPD* untuk tingkat kabupaten).
- **Unit / Sub Unit.** Memilih unit berarti termasuk sub unit di bawahnya.
- **Tahun** dan **Periode:** Triwulan I–IV, Semester I/II, Tahunan, atau rentang tanggal bebas.
- **Pengaturan tanda tangan:** tempat, tanggal, jabatan, nama, dan NIP penandatangan kiri & kanan.

| Laporan | Isi | Sesuai contoh |
|---|---|---|
| **Mutasi & Stock Opname** | Per unit → per sub rincian objek → per barang: saldo awal, penerimaan, jumlah persediaan, pengeluaran, saldo adm, kolom saldo fisik & selisih (untuk diisi saat stock opname), harga satuan, nilai persediaan. Sheet kedua **Rekap** = nilai per jenis barang (sub rincian objek). Dapat disaring per jenis barang, mis. hanya Alat Tulis Kantor. | Stock opname BPKAD (sheet ATK, Kertas, Tinta, dst. + sheet REKAP) |
| **Rekap Rincian (18.1)** | Matriks: baris = SKPD (bila *Seluruh SKPD*) atau unit/sub unit; kolom = seluruh sub rincian objek yang dikelompokkan per objek dan rincian objek, plus total. Centang/hapus **Tampilkan semua kolom** untuk menyembunyikan kolom bernilai nol. | REKAP KOMPONEN PERSEDIAAN TW II (RinciPersediaan 18.1) |
| **Menurut Objek (IV.L.1.4)** | Rekapitulasi Aset Lancar → Persediaan → Objek → Rincian Objek dengan kolom saldo awal, mutasi tambah, mutasi kurang, saldo akhir. Opsi **Hanya saldo akhir** dan **Rinci s.d. sub rincian objek**. | LAP BMD PERSEDIAAN Semester I (Format IV.L.1.4) |

**Cara nilai dihitung:** saldo awal = seluruh transaksi disetujui sebelum tanggal awal periode (ditambah saldo awal hasil migrasi). Penerimaan dan pengeluaran = transaksi disetujui di dalam periode. Saldo akhir = saldo awal + penerimaan − pengeluaran. Transaksi yang masih *menunggu validasi* atau *ditolak* tidak dihitung.

## 9. Data Contoh

Database lengkap (`persediaan_bmd_lengkap.sql`) berisi:

- **Master kode barang**: 484 kode (400 Sub Sub Rincian Objek) dari file kodefikasi.
- **78 SKPD/Kecamatan** dari *Rekap Komponen Persediaan TW II*, masing-masing dengan unit **Sekretariat** dan saldo persediaan per 30 Juni 2026 sebagai **saldo awal migrasi** (satu baris agregat per sub rincian objek). Laporan Semester I 2026 menghasilkan angka yang sama dengan rekap aslinya.
- **BPKAD lengkap** dari file stock opname BPKAD: 5 unit, 15 sub unit, ±690 baris barang dengan saldo awal, penerimaan TW I & II, dan pengeluaran Semester I. Ditambah contoh transaksi Juli–Oktober 2026, contoh validasi, satu transaksi ditolak, tiga transaksi menunggu validasi, dan satu stock opname selesai (Subbagian Keuangan, 30 September 2026).

**Semua akun contoh memakai password `password`.** Akun SKPD lain sudah terbentuk tetapi belum aktif. Aktifkan melalui Super Admin.

| Peran | Unit / Sub Unit | Email |
|---|---|---|
| Super Admin | Seluruh SKPD | superadmin@bmd.test |
| Admin SKPD | BPKAD | admin1@bpkad.test |
| Admin SKPD | BPKAD | admin2@bpkad.test |
| Admin Unit | Sekretariat | admin.sekretariat@bpkad.test |
| Operator Unit | Sekretariat | operator.sekretariat@bpkad.test |
| Admin Sub Unit | Subbagian Umum dan Kepegawaian | admin.umpeg@bpkad.test |
| Operator Sub Unit | Subbagian Umum dan Kepegawaian | operator.umpeg@bpkad.test |
| Admin Sub Unit | Subbagian Keuangan | admin.keuangan@bpkad.test |
| Operator Sub Unit | Subbagian Keuangan | operator.keuangan@bpkad.test |
| Admin Sub Unit | Subbagian Program dan Pelaporan | admin.program@bpkad.test |
| Operator Sub Unit | Subbagian Program dan Pelaporan | operator.program@bpkad.test |
| Admin Unit | Bidang Anggaran | admin.anggaran@bpkad.test |
| Operator Unit | Bidang Anggaran | operator.anggaran@bpkad.test |
| Admin Sub Unit | Subbidang Anggaran Belanja Daerah | admin.belanja@bpkad.test |
| Operator Sub Unit | Subbidang Anggaran Belanja Daerah | operator.belanja@bpkad.test |
| Admin Sub Unit | Subbidang Anggaran PPKD | admin.ppkd@bpkad.test |
| Operator Sub Unit | Subbidang Anggaran PPKD | operator.ppkd@bpkad.test |
| Admin Sub Unit | Subbidang Analisa Kebijakan Anggaran | admin.aka@bpkad.test |
| Operator Sub Unit | Subbidang Analisa Kebijakan Anggaran | operator.aka@bpkad.test |
| Admin Unit | Bidang Perbendaharaan | admin.perbendaharaan@bpkad.test |
| Operator Unit | Bidang Perbendaharaan | operator.perbendaharaan@bpkad.test |
| Admin Sub Unit | Subbidang Kas Daerah | admin.kasda@bpkad.test |
| Operator Sub Unit | Subbidang Kas Daerah | operator.kasda@bpkad.test |
| Admin Sub Unit | Subbidang Bantuan Keuangan | admin.bankeu@bpkad.test |
| Operator Sub Unit | Subbidang Bantuan Keuangan | operator.bankeu@bpkad.test |
| Admin Sub Unit | Subbidang Gaji dan Belanja Tidak Langsung | admin.gaji@bpkad.test |
| Operator Sub Unit | Subbidang Gaji dan Belanja Tidak Langsung | operator.gaji@bpkad.test |
| Admin Unit | Bidang Akuntansi dan Teknologi Informasi | admin.akti@bpkad.test |
| Operator Unit | Bidang Akuntansi dan Teknologi Informasi | operator.akti@bpkad.test |
| Admin Sub Unit | Subbidang Akuntansi | admin.akuntansi@bpkad.test |
| Operator Sub Unit | Subbidang Akuntansi | operator.akuntansi@bpkad.test |
| Admin Sub Unit | Subbidang Pelaporan | admin.pelaporan@bpkad.test |
| Operator Sub Unit | Subbidang Pelaporan | operator.pelaporan@bpkad.test |
| Admin Sub Unit | Subbidang Teknologi Informasi | admin.ti@bpkad.test |
| Operator Sub Unit | Subbidang Teknologi Informasi | operator.ti@bpkad.test |
| Admin Unit | Bidang Aset Daerah | admin.aset@bpkad.test |
| Operator Unit | Bidang Aset Daerah | operator.aset@bpkad.test |
| Admin Sub Unit | Subbidang Perencanaan | admin.perencanaan@bpkad.test |
| Operator Sub Unit | Subbidang Perencanaan | operator.perencanaan@bpkad.test |
| Admin Sub Unit | Subbidang Pemanfaatan dan Pengamanan | admin.pemanfaatan@bpkad.test |
| Operator Sub Unit | Subbidang Pemanfaatan dan Pengamanan | operator.pemanfaatan@bpkad.test |
| Admin Sub Unit | Subbidang Penatausahaan dan Administrasi | admin.penatausahaan@bpkad.test |
| Operator Sub Unit | Subbidang Penatausahaan dan Administrasi | operator.penatausahaan@bpkad.test |

**Skenario uji coba singkat:**

1. Masuk sebagai `operator.umpeg@bpkad.test` → **Pembelian Barang** → daftarkan 2 barang → **Simpan**. Status: menunggu validasi.
2. Masuk sebagai `admin.umpeg@bpkad.test` (atau `admin.sekretariat@bpkad.test`) → **Validasi** → **Periksa** → **Setujui**. Cek **Stok Barang**.
3. Masuk sebagai `admin1@bpkad.test` → **Laporan** → pilih Semester I 2026 → **Ekspor Excel**.

## 10. Catatan Migrasi Data dari File Excel

- Baris induk kodefikasi yang tidak ada di file (Aset, Aset Lancar, Persediaan, Barang Pakai Habis, Bahan, Bahan Bangunan dan Konstruksi) ditambahkan sesuai Permendagri 108/2016.
- Uraian **"Dst..."** pada kodefikasi diubah menjadi **"<Sub Rincian Objek> Lainnya"**, misalnya *Alat Tulis Kantor Lainnya*, sebagai kode penampung barang yang tidak tercantum. Satu kode *Kaporlap dan Perlengkapan Satwa Lainnya* (1.1.7.01.03.10.003) ditambahkan karena sub rincian itu belum memiliki kode penampung.
- Kedua file *REKAP KOMPONEN PERSEDIAAN* yang diunggah identik, sehingga dipakai sebagai satu sumber.
- File BPKAD memuat beberapa ketidaksesuaian rumus. Data dimasukkan berdasarkan **jumlah barang** (saldo awal, penerimaan, pengeluaran), sehingga nilai di aplikasi (Rp 446.785.577,07) lebih tinggi Rp 19.605.975,88 dari jumlah nilai di file (Rp 427.179.601,19, dibulatkan Rp 427.179.602 di sheet REKAP):
  - sheet Tinta baris 128 (*Toner 87A HP Black*): penerimaan TW II 4 buah (Rp 19.485.976) tidak ikut dijumlah karena rumus kolom JUMLAH hanya `=D+E`;
  - sheet Kertas baris 235 (*Kertas HVS A3*): saldo 1 rim (Rp 119.999,88) tidak memiliki rumus nilai;
  - sheet ATK baris 65–70: kolom JUMLAH diisi angka langsung tanpa saldo awal/penerimaan. Angka tersebut diperlakukan sebagai saldo awal.
- Pada Rekap TW II, BPKAD belum mengisi nilai, sedangkan total Alat/Bahan untuk Kegiatan Kantor dari rincian SKPD (Rp 22.002.937.709) berbeda Rp 3.675.175 dari LAP BMD Semester I (Rp 21.999.262.533,92). Rincian lain (Bahan, Suku Cadang, Obat-obatan, dst.) sama persis.

## 11. Tanya Jawab

**Akun tidak bisa login?** Pastikan email & password sudah diisi dan **Akun aktif** dicentang oleh admin di atasnya. Setelah 5 kali gagal, login dikunci 5 menit untuk sesi tersebut.

**Barang tidak muncul di saran pengeluaran?** Hanya barang dengan stok tersedia di unit Anda yang muncul. Bila pembelian dilakukan Operator, pastikan sudah divalidasi.

**Mengapa pembelian tidak bisa disimpan?** Kemungkinan unit sedang **stock opname** (banner kuning di atas halaman). Tunggu sampai admin menyelesaikannya.

**Salah input transaksi yang sudah disetujui?** Transaksi yang sudah mengubah stok tidak dihapus agar jejak audit terjaga. Koreksi dengan transaksi kebalikannya (pengeluaran untuk kelebihan input, pembelian untuk kekurangan), atau melalui **stock opname**.

**Bagaimana menyimpan laporan sebagai PDF?** Klik **Cetak / PDF**, lalu pilih *Save as PDF* di dialog cetak. Atur orientasi *Landscape* untuk laporan matriks.

**Bagaimana mencadangkan data?** Ekspor database `persediaan_bmd` secara berkala lewat phpMyAdmin (tab **Export**) dan salin folder `uploads/`.
