-- Jalankan satu kali pada database TokoKu POS yang sedang digunakan.
-- Kode berikut adalah barcode internal format EAN-13 untuk demo/internal toko,
-- bukan nomor GTIN resmi yang terdaftar di GS1.

UPDATE barang SET kode_barang='8991234500003' WHERE kode_barang='BRG-001';
UPDATE barang SET kode_barang='8991234500010' WHERE kode_barang='BRG-002';
UPDATE barang SET kode_barang='8991234500027' WHERE kode_barang='BRG-003';

INSERT INTO barang (kode_barang,nama_barang,kategori,harga,stok,satuan,aktif)
VALUES
('8991234500034','Teh Botol 350ml','Minuman',5000,30,'botol',1),
('8991234500041','Susu UHT 250ml','Minuman',6500,25,'kotak',1),
('8991234500058','Roti Cokelat','Makanan',4000,20,'pcs',1),
('8991234500065','Air Mineral 1500ml','Minuman',6000,24,'botol',1),
('8991234500072','Biskuit Cokelat','Makanan',7500,18,'bungkus',1)
ON DUPLICATE KEY UPDATE
nama_barang=VALUES(nama_barang),kategori=VALUES(kategori),harga=VALUES(harga),satuan=VALUES(satuan),aktif=1;
