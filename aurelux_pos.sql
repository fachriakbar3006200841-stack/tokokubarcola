-- =====================================================================
-- AURELUX POS — skema final (MySQL 5.7+/MariaDB 10.3+, InnoDB, utf8mb4)
-- Aman diimpor berulang: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
-- Database lama `toko` TIDAK disentuh sama sekali.
-- Tidak ada password di file ini; akun dibuat lewat setup.php.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `aurelux_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `aurelux_pos`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  module VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  tagline VARCHAR(160) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(30) NULL,
  receipt_footer VARCHAR(255) NULL,
  qris_image VARCHAR(255) NULL,
  bank_info VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL DEFAULT 1,
  role_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
  INDEX idx_users_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  status ENUM('success','failed','locked','logout') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_lookup (username, ip, status, created_at),
  INDEX idx_login_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  description VARCHAR(255) NULL,
  old_values TEXT NULL,
  new_values TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_action (action),
  INDEX idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(60) NOT NULL UNIQUE,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  sku VARCHAR(40) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  sell_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  stock_qty INT NOT NULL DEFAULT 0,
  min_stock INT NOT NULL DEFAULT 5,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  product_type ENUM('regular','preorder') NOT NULL DEFAULT 'regular',
  variant_options VARCHAR(255) NULL,
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_products_name (name),
  INDEX idx_products_cat (category_id),
  INDEX idx_products_type (product_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(100) NULL,
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(30) NOT NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  order_date DATE NOT NULL,
  status ENUM('ordered','partial','received','cancelled') NOT NULL DEFAULT 'ordered',
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pur_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_pur_date (order_date), INDEX idx_pur_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_details (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  received_qty INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(15,2) NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_pd_pur FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  type ENUM('opening','purchase','sale','sale_void','return_in','opname','adjustment') NOT NULL,
  qty_change INT NOT NULL,
  qty_before INT NOT NULL,
  qty_after INT NOT NULL,
  ref_type VARCHAR(30) NULL,
  ref_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sm_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_sm_prod (product_id, created_at), INDEX idx_sm_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_opnames (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_so_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_opname_details (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opname_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  system_qty INT NOT NULL,
  counted_qty INT NOT NULL,
  diff_qty INT NOT NULL,
  note VARCHAR(255) NULL,
  CONSTRAINT fk_sod_op FOREIGN KEY (opname_id) REFERENCES stock_opnames(id) ON DELETE CASCADE,
  CONSTRAINT fk_sod_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  total_transactions INT NOT NULL DEFAULT 0,
  total_spent DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_name (name), INDEX idx_cust_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promotions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type ENUM('percent','fixed') NOT NULL,
  value DECIMAL(15,2) NOT NULL,
  min_purchase DECIMAL(15,2) NOT NULL DEFAULT 0,
  scope ENUM('all','products') NOT NULL DEFAULT 'all',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promotion_products (
  promotion_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (promotion_id, product_id),
  CONSTRAINT fk_pp_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL,
  opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  expected_cash DECIMAL(15,2) NULL,
  counted_cash DECIMAL(15,2) NULL,
  difference DECIMAL(15,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_shift_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_shift_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('in','out') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cm_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_counters (
  counter_date DATE NOT NULL PRIMARY KEY,
  last_no INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  store_id INT UNSIGNED NOT NULL DEFAULT 1,
  shift_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  promotion_id INT UNSIGNED NULL,
  subtotal DECIMAL(15,2) NOT NULL,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL,
  paid_total DECIMAL(15,2) NOT NULL,
  change_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('completed','void') NOT NULL DEFAULT 'completed',
  void_reason VARCHAR(255) NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trx_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_trx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_trx_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_trx_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
  INDEX idx_trx_created (created_at), INDEX idx_trx_user (user_id, created_at), INDEX idx_trx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_details (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  qty INT NOT NULL,
  sell_price DECIMAL(15,2) NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL,
  note VARCHAR(150) NULL,
  returned_qty INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_td_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_td_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_td_trx (transaction_id), INDEX idx_td_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  method ENUM('cash','transfer','qris') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  tendered DECIMAL(15,2) NOT NULL,
  reference VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  INDEX idx_pay_trx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(30) NOT NULL UNIQUE,
  transaction_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reason VARCHAR(255) NOT NULL,
  refund_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ret_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ret_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_ret_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS return_details (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id INT UNSIGNED NOT NULL,
  transaction_detail_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  refund_price DECIMAL(15,2) NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL,
  item_condition ENUM('good','damaged') NOT NULL DEFAULT 'good',
  CONSTRAINT fk_rd_ret FOREIGN KEY (return_id) REFERENCES transaction_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_rd_td FOREIGN KEY (transaction_detail_id) REFERENCES transaction_details(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rd_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  description VARCHAR(255) NOT NULL,
  expense_date DATE NOT NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preorder_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  open_date DATE NOT NULL,
  close_date DATE NOT NULL,
  estimated_arrival DATE NULL,
  min_dp_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
  status ENUM('open','closed','arrived','completed','cancelled') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preorder_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  price DECIMAL(15,2) NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  quota INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_po_item (batch_id, product_id),
  CONSTRAINT fk_poi_batch FOREIGN KEY (batch_id) REFERENCES preorder_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preorder_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  batch_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(100) NOT NULL,
  customer_phone VARCHAR(30) NULL,
  status ENUM('pending','dp_paid','paid','completed','cancelled') NOT NULL DEFAULT 'pending',
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_poo_batch FOREIGN KEY (batch_id) REFERENCES preorder_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_poo_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_poo_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_poo_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preorder_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  variant VARCHAR(40) NULL,
  qty INT NOT NULL,
  price DECIMAL(15,2) NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_pooi_order FOREIGN KEY (order_id) REFERENCES preorder_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pooi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preorder_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('dp','pelunasan') NOT NULL,
  method ENUM('cash','transfer','qris') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  shift_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pop_order FOREIGN KEY (order_id) REFERENCES preorder_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pop_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(120) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  action ENUM('backup','restore') NOT NULL DEFAULT 'backup',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== DATA AWAL =====================
INSERT IGNORE INTO stores (id, name, tagline, address, phone, receipt_footer) VALUES
(1, 'AURELUX Seblak & Snack Bar', 'Pedasnya Premium, Ngemilnya Puas', 'Alamat toko belum diisi', '', 'Terima kasih sudah jajan! Simpan struk ini sebagai bukti.');

INSERT IGNORE INTO roles (id, code, name, description, is_system) VALUES
(1, 'super_admin', 'Super Admin', 'Akses tertinggi ke seluruh sistem', 1),
(2, 'admin', 'Admin', 'Operasional toko, stok, pembelian, laporan', 1),
(3, 'kasir', 'Kasir', 'Transaksi penjualan dan shift pribadi', 1);

INSERT IGNORE INTO permissions (code, name, module) VALUES
('dashboard.view','Melihat dashboard','umum'),
('users.manage','Kelola Admin & Kasir','pengguna'),
('roles.manage','Kelola role & permission','pengguna'),
('store.manage','Profil toko','pengaturan'),
('settings.manage','Pengaturan sistem & tampilan','pengaturan'),
('security.manage','Pengaturan keamanan','pengaturan'),
('audit.view','Melihat audit log','keamanan'),
('logins.view','Monitoring aktivitas login','keamanan'),
('backup.manage','Backup database','sistem'),
('restore.manage','Restore database','sistem'),
('reports.global','Laporan global','laporan'),
('products.view','Melihat produk','produk'),
('products.manage','CRUD produk & barcode','produk'),
('products.cost','Melihat/ubah harga modal','produk'),
('categories.manage','Kelola kategori','produk'),
('suppliers.manage','Kelola supplier','pembelian'),
('purchases.manage','Pembelian & penerimaan barang','pembelian'),
('stock.view','Monitoring stok & stock movement','stok'),
('stock.opname','Stock opname','stok'),
('customers.view','Melihat pelanggan','pelanggan'),
('customers.manage','Kelola pelanggan','pelanggan'),
('transactions.view_all','Monitoring semua transaksi','transaksi'),
('transactions.view_own','Riwayat transaksi pribadi','transaksi'),
('transactions.void','Membatalkan transaksi','transaksi'),
('returns.approve','Menyetujui retur','retur'),
('returns.request','Mengajukan retur','retur'),
('promotions.manage','Promosi & diskon','promosi'),
('expenses.manage','Pengeluaran operasional','keuangan'),
('shifts.monitor','Monitoring shift kasir','shift'),
('shift.own','Buka/tutup shift pribadi','shift'),
('reports.sales','Laporan penjualan','laporan'),
('reports.profit','Laporan keuntungan','laporan'),
('reports.export','Export PDF & Excel','laporan'),
('report.own','Laporan kas pribadi','laporan'),
('pos.use','Menggunakan POS','pos'),
('pos.reprint','Cetak ulang struk','pos'),
('preorder.manage','Kelola batch & pesanan pre-order','preorder'),
('preorder.create','Input pesanan & pembayaran pre-order','preorder');

INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code IN (
 'dashboard.view','products.view','products.manage','products.cost','categories.manage','suppliers.manage',
 'purchases.manage','stock.view','stock.opname','customers.view','customers.manage','transactions.view_all',
 'transactions.void','returns.approve','promotions.manage','expenses.manage','shifts.monitor',
 'reports.sales','reports.profit','reports.export','pos.reprint','preorder.manage','preorder.create');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE code IN (
 'dashboard.view','products.view','customers.view','customers.manage','transactions.view_own',
 'returns.request','shift.own','report.own','pos.use','pos.reprint','preorder.create');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('theme_mode','dark'),('session_timeout_min','30'),('login_max_attempts','5'),
('login_lock_minutes','15'),('receipt_width_mm','58'),('currency','Rp');

INSERT IGNORE INTO expense_categories (name) VALUES
('Bahan Baku'),('Gas & Listrik'),('Gaji & Upah'),('Sewa Tempat'),('Kemasan & Plastik'),('Promosi'),('Lain-lain');

INSERT IGNORE INTO categories (name) VALUES
('Seblak'),('Topping Seblak'),('Snack Kering'),('Cemilan Frozen'),('Minuman'),('Baju (Pre-Order)');

-- Produk contoh seblak & snack bar. track_stock=0 untuk menu yang dimasak per pesanan.
INSERT IGNORE INTO products (category_id, sku, barcode, name, unit, cost_price, sell_price, stock_qty, min_stock, track_stock) VALUES
((SELECT id FROM categories WHERE name='Seblak'),'SBL-001','8990001000011','Seblak Original','porsi',8000,15000,0,0,0),
((SELECT id FROM categories WHERE name='Seblak'),'SBL-002','8990001000028','Seblak Ceker','porsi',11000,20000,0,0,0),
((SELECT id FROM categories WHERE name='Seblak'),'SBL-003','8990001000035','Seblak Seafood','porsi',14000,25000,0,0,0),
((SELECT id FROM categories WHERE name='Seblak'),'SBL-004','8990001000042','Seblak Kerupuk Basah','porsi',7000,13000,0,0,0),
((SELECT id FROM categories WHERE name='Seblak'),'SBL-005','8990001000059','Seblak Mie Jebew','porsi',9000,16000,0,0,0),
((SELECT id FROM categories WHERE name='Topping Seblak'),'TOP-001','8990001000103','Ceker Tambahan','porsi',3000,5000,60,10,1),
((SELECT id FROM categories WHERE name='Topping Seblak'),'TOP-002','8990001000110','Telur Tambahan','pcs',2000,4000,60,10,1),
((SELECT id FROM categories WHERE name='Topping Seblak'),'TOP-003','8990001000127','Sosis Tambahan','pcs',1500,3000,80,10,1),
((SELECT id FROM categories WHERE name='Topping Seblak'),'TOP-004','8990001000134','Bakso Tambahan','pcs',1500,3000,80,10,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-001','8990001000202','Basreng Pedas 100g','bungkus',9000,15000,50,8,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-002','8990001000219','Makaroni Bantat 100g','bungkus',6000,10000,50,8,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-003','8990001000226','Keripik Singkong Balado','bungkus',7000,12000,40,8,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-004','8990001000233','Cimol Crispy','bungkus',5000,10000,40,8,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-005','8990001000240','Keripik Pisang Cokelat','bungkus',7000,12000,35,8,1),
((SELECT id FROM categories WHERE name='Snack Kering'),'SNK-006','8990001000257','Seblak Kering Instan','bungkus',8000,14000,45,8,1),
((SELECT id FROM categories WHERE name='Cemilan Frozen'),'FRZ-001','8990001000301','Cireng Isi Ayam (isi 10)','pack',12000,20000,30,5,1),
((SELECT id FROM categories WHERE name='Cemilan Frozen'),'FRZ-002','8990001000318','Otak-otak Ikan (isi 10)','pack',13000,22000,25,5,1),
((SELECT id FROM categories WHERE name='Minuman'),'MIN-001','8990001000400','Es Teh Manis','gelas',2000,5000,0,0,0),
((SELECT id FROM categories WHERE name='Minuman'),'MIN-002','8990001000417','Es Jeruk','gelas',3500,7000,0,0,0),
((SELECT id FROM categories WHERE name='Minuman'),'MIN-003','8990001000424','Air Mineral 600ml','botol',2500,5000,48,12,1);

INSERT IGNORE INTO products (category_id, sku, barcode, name, unit, cost_price, sell_price, stock_qty, min_stock, track_stock, product_type, variant_options) VALUES
((SELECT id FROM categories WHERE name='Baju (Pre-Order)'),'PO-001',NULL,'Kaos Oversize Seblak Squad','pcs',55000,99000,0,0,0,'preorder','S,M,L,XL'),
((SELECT id FROM categories WHERE name='Baju (Pre-Order)'),'PO-002',NULL,'Hoodie Snack Bar Edition','pcs',120000,199000,0,0,0,'preorder','M,L,XL,XXL');

INSERT INTO stock_movements (product_id, type, qty_change, qty_before, qty_after, ref_type, note)
SELECT p.id, 'opening', p.stock_qty, 0, p.stock_qty, 'seed', 'Saldo awal data contoh'
FROM products p
WHERE p.track_stock = 1 AND p.stock_qty > 0
  AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.product_id = p.id);
