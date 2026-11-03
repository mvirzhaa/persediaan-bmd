-- =====================================================================
--  SIPERSEDIAAN BMD - KOSONGKAN SELURUH DATA TRANSAKSI
--
--  Jalankan pada database yang SUDAH terpasang (phpMyAdmin > pilih
--  database persediaan_bmd > tab SQL / Import) bila ingin memulai input
--  persediaan dari nol.
--
--  YANG DIHAPUS (dan nomor urut id kembali ke 1):
--    - barang               (kartu persediaan per unit/sub unit)
--    - transaksi            (pembelian, pemakaian/pengeluaran, saldo awal, draft)
--    - transaksi_detail
--    - stok_lot, stok_keluar (jejak FIFO)
--    - stock_opname, stock_opname_detail
--
--  YANG TIDAK DIUBAH:
--    SKPD, unit & sub unit, akun pengguna, master kode barang,
--    pengaturan, dan log aktivitas.
--
--  CATATAN: file dokumen yang pernah diunggah tetap ada di folder
--  uploads/dokumen/. Hapus isi folder itu secara manual bila perlu
--  (jangan hapus file uploads/.htaccess dan uploads/index.html).
--
--  PERINGATAN: tindakan ini tidak dapat dibatalkan. Backup dulu
--  (phpMyAdmin > Export) sebelum menjalankan.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE stock_opname_detail;
TRUNCATE TABLE stock_opname;
TRUNCATE TABLE stok_keluar;
TRUNCATE TABLE stok_lot;
TRUNCATE TABLE transaksi_detail;
TRUNCATE TABLE transaksi;
TRUNCATE TABLE barang;

SET FOREIGN_KEY_CHECKS = 1;

-- Opsional: hapus juga riwayat log aktivitas (hilangkan tanda -- di depan baris berikut)
-- TRUNCATE TABLE log_aktivitas;
