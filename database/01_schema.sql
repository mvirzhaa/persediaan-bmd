-- =====================================================================
--  SIPERSEDIAAN BMD - Skema Database
--  MySQL 5.7+ / MariaDB 10.2+  (utf8mb4, InnoDB)
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS stock_opname_detail;
DROP TABLE IF EXISTS stock_opname;
DROP TABLE IF EXISTS stok_keluar;
DROP TABLE IF EXISTS stok_lot;
DROP TABLE IF EXISTS transaksi_detail;
DROP TABLE IF EXISTS transaksi;
DROP TABLE IF EXISTS barang;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS unit_kerja;
DROP TABLE IF EXISTS skpd;
DROP TABLE IF EXISTS kode_barang;
DROP TABLE IF EXISTS pengaturan;
DROP TABLE IF EXISTS log_aktivitas;

-- ---------------------------------------------------------------------
-- Pengaturan umum aplikasi (nama pemda, kota tanda tangan, dsb.)
-- ---------------------------------------------------------------------
CREATE TABLE pengaturan (
  kunci  VARCHAR(60)  NOT NULL PRIMARY KEY,
  nilai  TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Master kode barang (Penggolongan & Kodefikasi BMD)
-- Satu tabel hierarki (adjacency list) untuk 7 level:
--   1 Akun, 2 Kelompok, 3 Jenis, 4 Objek, 5 Rincian Objek,
--   6 Sub Rincian Objek, 7 Sub Sub Rincian Objek
-- kode menyimpan kode lengkap, mis. 1.1.7.01.03.01.001
-- ---------------------------------------------------------------------
CREATE TABLE kode_barang (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id  INT UNSIGNED NULL,
  level      TINYINT UNSIGNED NOT NULL,
  kode       VARCHAR(30)  NOT NULL,
  uraian     VARCHAR(255) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uk_kode (kode),
  KEY idx_parent (parent_id),
  KEY idx_level (level),
  CONSTRAINT fk_kode_parent FOREIGN KEY (parent_id) REFERENCES kode_barang(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- SKPD
-- ---------------------------------------------------------------------
CREATE TABLE skpd (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kode        VARCHAR(30)  NULL,
  nama        VARCHAR(200) NOT NULL,
  lokasi      VARCHAR(255) NULL,
  pic         VARCHAR(150) NULL,
  email       VARCHAR(150) NULL,
  telp        VARCHAR(50)  NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Unit & Sub Unit kerja (satu tabel, sub unit memakai parent_id)
-- ---------------------------------------------------------------------
CREATE TABLE unit_kerja (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  skpd_id     INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED NULL,
  level       ENUM('unit','subunit') NOT NULL,
  kode        VARCHAR(30)  NULL,
  nama        VARCHAR(200) NOT NULL,
  lokasi      VARCHAR(255) NULL,
  pic         VARCHAR(150) NULL,
  email       VARCHAR(150) NULL,
  telp        VARCHAR(50)  NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_skpd (skpd_id),
  KEY idx_parent (parent_id),
  CONSTRAINT fk_unit_skpd   FOREIGN KEY (skpd_id)   REFERENCES skpd(id)       ON DELETE CASCADE,
  CONSTRAINT fk_unit_parent FOREIGN KEY (parent_id) REFERENCES unit_kerja(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Pengguna
--  role: superadmin | admin_skpd | admin | operator
--  admin/operator berlaku di level unit atau sub unit (lihat unit_kerja.level)
--  Akun otomatis dibuat tanpa email/password (is_active=0) sampai diaktifkan.
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nama        VARCHAR(150) NOT NULL,
  nip         VARCHAR(30)  NULL,
  email       VARCHAR(150) NULL,
  password    VARCHAR(255) NULL,
  role        ENUM('superadmin','admin_skpd','admin','operator') NOT NULL,
  skpd_id     INT UNSIGNED NULL,
  unit_id     INT UNSIGNED NULL,
  slot        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active   TINYINT(1) NOT NULL DEFAULT 0,
  last_login  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_email (email),
  KEY idx_skpd (skpd_id),
  KEY idx_unit (unit_id),
  CONSTRAINT fk_user_skpd FOREIGN KEY (skpd_id) REFERENCES skpd(id)       ON DELETE CASCADE,
  CONSTRAINT fk_user_unit FOREIGN KEY (unit_id) REFERENCES unit_kerja(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Barang per unit/sub unit (kartu persediaan)
--   stok & nilai adalah cache saldo terkini (dihitung dari stok_lot)
-- ---------------------------------------------------------------------
CREATE TABLE barang (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  unit_id         INT UNSIGNED NOT NULL,
  kode_barang_id  INT UNSIGNED NOT NULL,
  nama_barang     VARCHAR(255) NOT NULL,
  satuan          VARCHAR(50)  NOT NULL,
  stok            DECIMAL(15,2) NOT NULL DEFAULT 0,
  nilai           DECIMAL(20,2) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_unit_kode (unit_id, kode_barang_id),
  KEY idx_nama (nama_barang(100)),
  CONSTRAINT fk_barang_unit FOREIGN KEY (unit_id)        REFERENCES unit_kerja(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_barang_kode FOREIGN KEY (kode_barang_id) REFERENCES kode_barang(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Transaksi (header) : pembelian/penerimaan (masuk) & pemakaian (keluar)
--   status draft     : daftar sementara (belum disimpan)
--          menunggu  : diinput operator, menunggu validasi admin
--          disetujui : sudah mempengaruhi stok
--          ditolak   : ditolak admin
-- ---------------------------------------------------------------------
CREATE TABLE transaksi (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jenis             ENUM('masuk','keluar') NOT NULL,
  sumber            ENUM('input','import','saldo_awal','stock_opname') NOT NULL DEFAULT 'input',
  unit_id           INT UNSIGNED NOT NULL,
  nomor             VARCHAR(40)  NULL,
  tanggal           DATE NOT NULL,
  no_dokumen        VARCHAR(150) NULL,
  keterangan        TEXT NULL,
  dokumen_file      VARCHAR(255) NULL,
  dokumen_nama      VARCHAR(255) NULL,
  status            ENUM('draft','menunggu','disetujui','ditolak') NOT NULL DEFAULT 'draft',
  created_by        INT UNSIGNED NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at      DATETIME NULL,
  validated_by      INT UNSIGNED NULL,
  validated_at      DATETIME NULL,
  catatan_validasi  TEXT NULL,
  KEY idx_unit_status (unit_id, status, tanggal),
  KEY idx_creator (created_by, status),
  CONSTRAINT fk_trx_unit FOREIGN KEY (unit_id) REFERENCES unit_kerja(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transaksi_detail (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaksi_id    INT UNSIGNED NOT NULL,
  barang_id       INT UNSIGNED NULL,
  kode_barang_id  INT UNSIGNED NOT NULL,
  nama_barang     VARCHAR(255) NOT NULL,
  satuan          VARCHAR(50)  NOT NULL,
  jumlah          DECIMAL(15,2) NOT NULL,
  harga_satuan    DECIMAL(18,2) NOT NULL DEFAULT 0,
  nilai           DECIMAL(20,2) NOT NULL DEFAULT 0,
  dokumen_file    VARCHAR(255) NULL,
  dokumen_nama    VARCHAR(255) NULL,
  keterangan      VARCHAR(255) NULL,
  KEY idx_trx (transaksi_id),
  KEY idx_barang (barang_id),
  CONSTRAINT fk_det_trx    FOREIGN KEY (transaksi_id)   REFERENCES transaksi(id)   ON DELETE CASCADE,
  CONSTRAINT fk_det_barang FOREIGN KEY (barang_id)      REFERENCES barang(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_det_kode   FOREIGN KEY (kode_barang_id) REFERENCES kode_barang(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Lot persediaan (metode FIFO). Setiap baris pembelian yang disetujui
-- menjadi satu lot dengan harga satuannya sendiri.
-- ---------------------------------------------------------------------
CREATE TABLE stok_lot (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  barang_id     INT UNSIGNED NOT NULL,
  detail_id     INT UNSIGNED NOT NULL,
  tanggal       DATE NOT NULL,
  qty_masuk     DECIMAL(15,2) NOT NULL,
  qty_sisa      DECIMAL(15,2) NOT NULL,
  harga_satuan  DECIMAL(18,2) NOT NULL,
  KEY idx_fifo (barang_id, qty_sisa, tanggal),
  CONSTRAINT fk_lot_barang FOREIGN KEY (barang_id) REFERENCES barang(id)           ON DELETE RESTRICT,
  CONSTRAINT fk_lot_detail FOREIGN KEY (detail_id) REFERENCES transaksi_detail(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alokasi pengeluaran ke lot (jejak FIFO)
CREATE TABLE stok_keluar (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  detail_id     INT UNSIGNED NOT NULL,
  lot_id        INT UNSIGNED NOT NULL,
  qty           DECIMAL(15,2) NOT NULL,
  harga_satuan  DECIMAL(18,2) NOT NULL,
  KEY idx_detail (detail_id),
  CONSTRAINT fk_sk_detail FOREIGN KEY (detail_id) REFERENCES transaksi_detail(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sk_lot    FOREIGN KEY (lot_id)    REFERENCES stok_lot(id)         ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Stock opname per unit/sub unit. Selama status 'berlangsung' seluruh
-- transaksi pada unit tersebut dikunci.
--   batch : kode bersama bila dimulai serentak oleh Admin SKPD
-- ---------------------------------------------------------------------
CREATE TABLE stock_opname (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  unit_id         INT UNSIGNED NOT NULL,
  batch           VARCHAR(40)  NULL,
  nomor           VARCHAR(40)  NULL,
  tanggal         DATE NOT NULL,
  no_ba           VARCHAR(150) NULL,
  keterangan      TEXT NULL,
  status          ENUM('berlangsung','selesai','batal') NOT NULL DEFAULT 'berlangsung',
  dibuat_oleh     INT UNSIGNED NOT NULL,
  dibuat_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  selesai_oleh    INT UNSIGNED NULL,
  selesai_at      DATETIME NULL,
  trx_masuk_id    INT UNSIGNED NULL,
  trx_keluar_id   INT UNSIGNED NULL,
  KEY idx_unit_status (unit_id, status),
  KEY idx_batch (batch),
  CONSTRAINT fk_so_unit FOREIGN KEY (unit_id) REFERENCES unit_kerja(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_opname_detail (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  so_id         INT UNSIGNED NOT NULL,
  barang_id     INT UNSIGNED NOT NULL,
  qty_adm       DECIMAL(15,2) NOT NULL,
  nilai_adm     DECIMAL(20,2) NOT NULL,
  qty_fisik     DECIMAL(15,2) NULL,
  keterangan    VARCHAR(255) NULL,
  UNIQUE KEY uk_so_barang (so_id, barang_id),
  CONSTRAINT fk_sod_so     FOREIGN KEY (so_id)     REFERENCES stock_opname(id) ON DELETE CASCADE,
  CONSTRAINT fk_sod_barang FOREIGN KEY (barang_id) REFERENCES barang(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log aktivitas sederhana
CREATE TABLE log_aktivitas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  aksi        VARCHAR(60)  NOT NULL,
  keterangan  VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
