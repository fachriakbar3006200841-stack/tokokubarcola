-- TokoKu POS - skema tambahan yang aman untuk database toko Anda.
-- Database lama tetap dipertahankan; tabel aplikasi baru memakai prefix app_.
CREATE DATABASE IF NOT EXISTS `toko` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `toko`;

-- Tabel pelanggan lama pada database Anda memakai id_pelanggan/nama_pelanggan/no_hp.
CREATE TABLE IF NOT EXISTS pelanggan (
  id_pelanggan INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nama_pelanggan VARCHAR(100) NOT NULL,
  alamat VARCHAR(150) NULL,
  no_hp VARCHAR(15) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir',
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS barang (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kode_barang VARCHAR(40) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL,
  nama_barang VARCHAR(150) NOT NULL,
  kategori VARCHAR(80) NULL,
  harga DECIMAL(14,2) NOT NULL DEFAULT 0,
  stok INT NOT NULL DEFAULT 0,
  satuan VARCHAR(20) NOT NULL DEFAULT 'pcs',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrasi role: tambah role 'user' (aman dijalankan berulang kali).
ALTER TABLE app_users MODIFY role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir';

ALTER TABLE barang ADD COLUMN IF NOT EXISTS barcode VARCHAR(64) NULL AFTER kode_barang;
SET @barcode_unique_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'barang'
    AND column_name = 'barcode' AND non_unique = 0
);
SET @barcode_index_sql = IF(
  @barcode_unique_exists = 0,
  'ALTER TABLE barang ADD UNIQUE KEY uq_barang_barcode (barcode)',
  'SELECT 1'
);
PREPARE add_barcode_index FROM @barcode_index_sql;
EXECUTE add_barcode_index;
DEALLOCATE PREPARE add_barcode_index;

ALTER TABLE barang ADD COLUMN IF NOT EXISTS foto VARCHAR(255) NULL AFTER satuan;
ALTER TABLE barang ADD COLUMN IF NOT EXISTS aktif TINYINT(1) NOT NULL DEFAULT 1 AFTER foto;

CREATE TABLE IF NOT EXISTS app_transaksi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nomor_transaksi VARCHAR(60) NOT NULL UNIQUE,
  pelanggan_id INT NULL,
  user_id INT UNSIGNED NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  diskon DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  bayar DECIMAL(14,2) NOT NULL DEFAULT 0,
  kembalian DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_trx_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggan(id_pelanggan) ON DELETE SET NULL,
  CONSTRAINT fk_app_trx_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  INDEX idx_app_trx_tanggal (created_at), INDEX idx_app_trx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE app_transaksi
  ADD COLUMN IF NOT EXISTS metode_pembayaran ENUM('tunai','qris_statis') NOT NULL DEFAULT 'tunai' AFTER kembalian;

CREATE TABLE IF NOT EXISTS app_detail_transaksi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaksi_id INT UNSIGNED NOT NULL,
  barang_id INT UNSIGNED NOT NULL,
  nama_barang VARCHAR(150) NOT NULL,
  harga DECIMAL(14,2) NOT NULL DEFAULT 0,
  qty INT NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_app_detail_trx FOREIGN KEY (transaksi_id) REFERENCES app_transaksi(id) ON DELETE RESTRICT,
  CONSTRAINT fk_app_detail_barang FOREIGN KEY (barang_id) REFERENCES barang(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_log_kasir (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  aksi VARCHAR(80) NOT NULL,
  keterangan VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_log_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  INDEX idx_app_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_log_pelanggan (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pelanggan_id INT NOT NULL,
  transaksi_id INT UNSIGNED NULL,
  aksi VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_log_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggan(id_pelanggan) ON DELETE RESTRICT,
  CONSTRAINT fk_app_log_pelanggan_trx FOREIGN KEY (transaksi_id) REFERENCES app_transaksi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_total_pelanggan (
  pelanggan_id INT PRIMARY KEY,
  total_transaksi INT NOT NULL DEFAULT 0,
  total_belanja DECIMAL(14,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_total_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggan(id_pelanggan) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_laporan_transaksi (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaksi_id INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_laporan_trx FOREIGN KEY (transaksi_id) REFERENCES app_transaksi(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_users (nama, username, password_hash, role) VALUES
('Administrator', 'admin', '$2b$10$RqN5QfR2/6rMT0ZqGY5OEuAcDXmVeGAx8k10dBICD1v3tTSbVeFZC', 'admin'),
('Kasir Utama', 'kasir', '$2b$10$qha/MeGrWCLFYu9riRE.rutY9XlHYWwABc9da1AdpIZ.nn.XVDShe', 'kasir'),
('User', 'user', '$2b$10$JxUP9b/1HCpGP9otZlZQVuf9KMj2wzAI3jPQHA4WeO/KclScFhp3i', 'user')
ON DUPLICATE KEY UPDATE username=username; -- akun yang sudah ada tidak diubah

INSERT INTO pelanggan (nama_pelanggan, alamat, no_hp)
SELECT 'Pelanggan Umum', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM pelanggan WHERE nama_pelanggan='Pelanggan Umum');

-- Data barang diisi lewat menu Data Barang (kategori: Sepatu, Jaket, Perlengkapan).
