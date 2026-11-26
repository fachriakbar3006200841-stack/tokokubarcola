<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$pdo = db();
$readOnly = !is_staff();
$pageTitle = $readOnly ? 'Katalog Barang' : 'Data Barang';
$activePage = 'barang';
$error = '';
$edit = null;
$barcodeItem = null;
$newPhotoPath = null;
$uploadDir = __DIR__ . '/assets/uploads/barang';
$categoryOptions = ['Sepatu', 'Jaket', 'Perlengkapan'];

if (!$readOnly && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM barang WHERE id = ? AND aktif = 1');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

if (!$readOnly && isset($_GET['barcode'])) {
    $stmt = $pdo->prepare('SELECT * FROM barang WHERE id = ? AND aktif = 1');
    $stmt->execute([(int) $_GET['barcode']]);
    $barcodeItem = $stmt->fetch() ?: null;
}

function generate_product_code(PDO $pdo): string
{
    do {
        $code = 'BRG-' . strtoupper(bin2hex(random_bytes(5)));
        $check = $pdo->prepare('SELECT COUNT(*) FROM barang WHERE kode_barang = ? OR barcode = ?');
        $check->execute([$code, $code]);
    } while ((int) $check->fetchColumn() > 0);

    return $code;
}

// Generated values are checked in both columns to avoid ambiguous legacy-code scans.
function generate_product_barcode(PDO $pdo): string
{
    do {
        $barcode = 'TK' . strtoupper(bin2hex(random_bytes(5)));
        $check = $pdo->prepare('SELECT COUNT(*) FROM barang WHERE barcode = ? OR kode_barang = ?');
        $check->execute([$barcode, $barcode]);
    } while ((int) $check->fetchColumn() > 0);

    return $barcode;
}

$defaultProductCode = $edit ? (string) $edit['kode_barang'] : generate_product_code($pdo);
$defaultBarcode = $edit ? (string) (($edit['barcode'] ?? '') ?: $edit['kode_barang']) : generate_product_barcode($pdo);
$barcodeInputValue = $defaultBarcode;

function upload_product_photo(array $file, string $directory): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto barang gagal diunggah.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 2 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder upload foto tidak dapat dibuat.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Foto barang tidak dapat disimpan.');
    }
    return $filename;
}

if ($readOnly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    include __DIR__ . '/includes/403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $codeValue = $_POST['kode_barang'] ?? '';
            if (!is_string($codeValue)) {
                throw new RuntimeException('Kode barang tidak valid.');
            }
            $kodeBarang = trim($codeValue);
            $barcodeValue = $_POST['barcode'] ?? '';
            if (!is_string($barcodeValue)) {
                throw new RuntimeException('Kode barcode tidak valid.');
            }
            $kode = trim($barcodeValue);
            $barcodeInputValue = $kode;
            $nama = trim($_POST['nama_barang'] ?? '');
            $kategori = trim($_POST['kategori'] ?? '');
            $harga = parse_price($_POST['harga'] ?? '');
            $stokValue = (string) ($_POST['stok'] ?? '');
            if (!preg_match('/^\d+$/D', $stokValue)) {
                throw new RuntimeException('Stok harus berupa bilangan bulat nol atau lebih.');
            }
            $stok = (int) $stokValue;
            $satuan = trim($_POST['satuan'] ?? 'pcs') ?: 'pcs';
            if (!preg_match('/^[\x20-\x7E]{1,40}$/D', $kodeBarang) || !preg_match('/^[\x20-\x7E]{1,64}$/D', $kode) || $nama === '' || strlen($nama) > 150 || strlen($kategori) > 80 || $harga < 0 || $satuan === '' || strlen($satuan) > 20) {
                throw new RuntimeException('Lengkapi data barang dengan benar.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM barang WHERE id <> ? AND (kode_barang = ? OR barcode = ?)');
            $stmt->execute([$id, $kodeBarang, $kodeBarang]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Kode barang sudah digunakan oleh barang lain.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM barang WHERE id <> ? AND (barcode = ? OR kode_barang = ?)');
            $stmt->execute([$id, $kode, $kode]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Barcode sudah digunakan oleh barang lain.');
            }

            $photo = '';
            if (!empty($_FILES['foto']['name'])) {
                $photo = upload_product_photo($_FILES['foto'], $uploadDir);
                $newPhotoPath = $uploadDir . DIRECTORY_SEPARATOR . $photo;
            }

            if ($id) {
                if ($photo !== '') {
                    $stmt = $pdo->prepare('SELECT foto FROM barang WHERE id = ?');
                    $stmt->execute([$id]);
                    $oldPhoto = $stmt->fetchColumn();
                    $stmt = $pdo->prepare('UPDATE barang SET kode_barang=?, barcode=?, nama_barang=?, kategori=?, harga=?, stok=?, satuan=?, foto=? WHERE id=?');
                    $stmt->execute([$kodeBarang, $kode, $nama, $kategori, $harga, $stok, $satuan, $photo, $id]);
                    if ($oldPhoto) {
                        @unlink($uploadDir . DIRECTORY_SEPARATOR . basename((string) $oldPhoto));
                    }
                } else {
                    $stmt = $pdo->prepare('UPDATE barang SET kode_barang=?, barcode=?, nama_barang=?, kategori=?, harga=?, stok=?, satuan=? WHERE id=?');
                    $stmt->execute([$kodeBarang, $kode, $nama, $kategori, $harga, $stok, $satuan, $id]);
                }
                log_activity('ubah_barang', 'Mengubah barang ' . $nama);
                flash('success', 'Data barang berhasil diperbarui.');
                $savedId = $id;
            } else {
                $stmt = $pdo->prepare('INSERT INTO barang (kode_barang,barcode,nama_barang,kategori,harga,stok,satuan,foto) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$kodeBarang, $kode, $nama, $kategori, $harga, $stok, $satuan, $photo ?: null]);
                log_activity('tambah_barang', 'Menambahkan barang ' . $nama);
                flash('success', 'Barang baru berhasil ditambahkan.');
                $savedId = (int) $pdo->lastInsertId();
            }
            header('Location: ' . url('barang.php?barcode=' . $savedId));
            exit;
        }

        if ($action === 'delete') {
            if (!is_admin()) {
                throw new RuntimeException('Kasir tidak memiliki akses menghapus barang.');
            }
            $id = (int) $_POST['id'];
            $stmt = $pdo->prepare('SELECT foto FROM barang WHERE id = ?');
            $stmt->execute([$id]);
            $photo = $stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_detail_transaksi WHERE barang_id = ?');
            $stmt->execute([$id]);
            $usedInTransactions = (int) $stmt->fetchColumn() > 0;
            if ($usedInTransactions) {
                // Riwayat transaksi harus tetap utuh, jadi barang dibuat tidak aktif.
                $pdo->prepare('UPDATE barang SET aktif = 0, stok = 0 WHERE id = ?')->execute([$id]);
                log_activity('nonaktifkan_barang', 'Menonaktifkan barang #' . $id . ' karena sudah ada di transaksi');
                flash('success', 'Barang dinonaktifkan dari daftar. Riwayat transaksi tetap tersimpan.');
            } else {
                $pdo->prepare('DELETE FROM barang WHERE id = ?')->execute([$id]);
                if ($photo) {
                    @unlink($uploadDir . DIRECTORY_SEPARATOR . basename((string) $photo));
                }
                log_activity('hapus_barang', 'Menghapus barang #' . $id);
                flash('success', 'Barang berhasil dihapus.');
            }
            header('Location: ' . url('barang.php'));
            exit;
        }
    } catch (Throwable $e) {
        if ($newPhotoPath) {
            @unlink($newPhotoPath);
        }
        $error = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'Kode barang atau barcode sudah digunakan, atau barang sudah dipakai dalam transaksi.'
            : $e->getMessage();
    }
}

$q = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare('SELECT * FROM barang WHERE aktif = 1 AND (kode_barang LIKE ? OR barcode LIKE ? OR nama_barang LIKE ? OR kategori LIKE ?) ORDER BY nama_barang');
$like = '%' . $q . '%';
$stmt->execute([$like, $like, $like, $like]);
$items = $stmt->fetchAll();
$barcodeLabelValue = $barcodeItem ? (string) (($barcodeItem['barcode'] ?? '') ?: $barcodeItem['kode_barang']) : '';
include __DIR__ . '/includes/header.php';
?>
<div class="grid<?= $readOnly ? '' : ' two-col' ?>">
<?php if (!$readOnly): ?>
    <section class="card">
        <div class="section-head"><div><h2><?= $edit ? 'Ubah barang' : 'Tambah barang' ?></h2></div></div>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="barcode-field">
                    <label>Barcode produk</label>
                    <input class="input" id="barcodeInput" name="barcode" required maxlength="64" autocomplete="off" placeholder="Masukkan barcode atau buat kode otomatis" value="<?= e($barcodeInputValue) ?>">
                    <small class="muted">Kode otomatis memakai format internal Code 128. Untuk barcode manual, ketik atau gunakan scanner USB.</small>
                    <div class="toolbar barcode-tools">
                        <button class="btn btn-light btn-sm" type="button" id="generateBarcode">Buat kode otomatis</button>
                        <button class="btn btn-light btn-sm" type="button" id="downloadBarcode">Download SVG</button>
                    </div>
                    <div class="barcode-preview" id="barcodePreview" hidden>
                        <svg id="barcodeImage" aria-label="Pratinjau barcode"></svg>
                        <div class="muted small">Pratinjau barcode Code 128</div>
                    </div>
                </div>
                <div><label>Kode barang (internal)</label><input class="input" name="kode_barang" required maxlength="40" autocomplete="off" value="<?= e($defaultProductCode) ?>"></div>
                <div><label>Nama barang</label><input class="input" name="nama_barang" required value="<?= e($edit['nama_barang'] ?? '') ?>"></div>
                <div><label>Kategori</label><select class="select" name="kategori" required><?php $currentCategory = trim($edit['kategori'] ?? ''); if ($currentCategory !== '' && !in_array($currentCategory, $categoryOptions, true)): ?><option value="<?= e($currentCategory) ?>" selected><?= e($currentCategory) ?> (kategori lama)</option><?php endif; ?><option value="">Pilih kategori</option><?php foreach ($categoryOptions as $category): ?><option value="<?= e($category) ?>" <?= $currentCategory === $category ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></div>
                <div><label>Satuan</label><input class="input" name="satuan" value="<?= e($edit['satuan'] ?? 'pcs') ?>"></div>
                <div><label>Harga jual</label><input class="input" type="text" inputmode="decimal" name="harga" required placeholder="Contoh: 4.000 atau 4.000,00" value="<?= e(isset($edit['harga']) ? price_input($edit['harga']) : '') ?>"></div>
                <div><label>Stok</label><input class="input" type="number" min="0" name="stok" required value="<?= e($edit['stok'] ?? 0) ?>"></div>
                <div class="full"><label>Foto barang <span class="muted">(rasio 4:5 · JPG, PNG, WEBP · maksimal 2 MB)</span></label><input class="input" type="file" name="foto" accept="image/jpeg,image/png,image/webp"><?php if (!empty($edit['foto'])): ?><div class="current-photo"><img class="product-thumb" src="<?= e(url('assets/uploads/barang/' . $edit['foto'])) ?>" alt="Foto <?= e($edit['nama_barang']) ?>"><span class="muted small">Foto saat ini. Pilih file baru untuk menggantinya.</span></div><?php endif; ?></div>
            </div>
            <div class="toolbar" style="margin-top:18px"><button class="btn btn-primary" type="submit"><?= $edit ? 'Simpan perubahan' : 'Tambah barang' ?></button><?php if ($edit): ?><a class="btn btn-light" href="<?= e(url('barang.php')) ?>">Batal</a><?php endif; ?></div>
        </form>
    </section>
<?php endif; ?>
    <section class="card">
        <div class="section-head"><h2>Daftar barang <span class="muted small">(<?= count($items) ?>)</span></h2></div>
        <form class="search-row" method="get"><input class="input" name="q" placeholder="Cari kode, nama, kategori..." value="<?= e($q) ?>"><button class="btn btn-light" type="submit">Cari</button></form>
        <div class="table-wrap"><table class="table"><thead><tr><th>Barang</th><th>Harga</th><th>Stok</th><th></th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><div class="item-with-photo"><?php if (!empty($item['foto'])): ?><img class="product-thumb" src="<?= e(url('assets/uploads/barang/' . $item['foto'])) ?>" alt="<?= e($item['nama_barang']) ?>"><?php else: ?><span class="product-thumb placeholder">▤</span><?php endif; ?><div><strong><?= e($item['nama_barang']) ?></strong><div class="muted small">Kode: <?= e($item['kode_barang']) ?> · Barcode: <?= e(($item['barcode'] ?? '') ?: $item['kode_barang']) ?> · <?= e($item['kategori'] ?: 'Tanpa kategori') ?></div></div></div></td><td class="amount"><?= money($item['harga']) ?></td><td><span class="badge <?= $item['stok'] <= 5 ? 'unpaid' : 'paid' ?>"><?= e($item['stok']) ?> <?= e($item['satuan']) ?></span></td><td><?php if (!$readOnly): ?><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('barang.php?barcode=' . $item['id'])) ?>">Barcode</a><a class="btn btn-light btn-sm" href="<?= e(url('barang.php?edit=' . $item['id'])) ?>">Ubah</a><?php if (is_admin()): ?><form method="post" onsubmit="return confirm('Hapus barang ini?')"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button class="btn btn-danger btn-sm">Hapus</button></form><?php endif; ?></div><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="4" class="empty">Barang belum tersedia.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</div>
<?php if ($barcodeItem): ?>
<section class="card barcode-label-area">
    <div class="section-head"><div><h2>Label barcode</h2><p class="muted small"><?= e($barcodeItem['nama_barang']) ?> · <?= e($barcodeLabelValue) ?></p></div><div class="toolbar no-print"><button class="btn btn-primary" type="button" onclick="window.print()">Cetak label</button><button class="btn btn-light" type="button" id="downloadSavedBarcode">Download SVG</button><a class="btn btn-light" href="<?= e(url('barang.php')) ?>">Tutup</a></div></div>
    <div class="barcode-label"><svg id="savedBarcode" aria-label="Barcode <?= e($barcodeLabelValue) ?>"></svg><strong><?= e($barcodeItem['nama_barang']) ?></strong><span><?= e($barcodeLabelValue) ?></span></div>
</section>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function () {
    const input = document.getElementById('barcodeInput');
    const preview = document.getElementById('barcodePreview');
    const svg = document.getElementById('barcodeImage');
    if (!input || !preview || !svg) return;

    function updateBarcode() {
        const value = input.value.trim();
        if (/^[\x20-\x7E]{1,64}$/.test(value) && typeof JsBarcode === 'function') {
            try {
                JsBarcode(svg, value, { format: 'CODE128', width: 2, height: 55, displayValue: true, fontSize: 13, margin: 8 });
                preview.hidden = false;
            } catch {
                preview.hidden = true;
            }
        } else {
            preview.hidden = true;
        }
    }

    input.addEventListener('input', updateBarcode);
    document.getElementById('generateBarcode')?.addEventListener('click', function () {
        const bytes = new Uint8Array(5);
        crypto.getRandomValues(bytes);
        input.value = 'TK' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('').toUpperCase();
        updateBarcode();
    });
    document.getElementById('downloadBarcode')?.addEventListener('click', function () {
        if (!svg.firstChild || preview.hidden) return;
        const blob = new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = (input.value.trim() || 'barcode') + '.svg';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    });
    updateBarcode();
})();
</script>
<?php if ($barcodeItem): ?>
<script>
if (typeof JsBarcode === 'function') {
    const savedBarcode = document.getElementById('savedBarcode');
    const savedBarcodeValue = <?= json_encode($barcodeLabelValue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    JsBarcode(savedBarcode, savedBarcodeValue, { format: 'CODE128', width: 2, height: 70, displayValue: true, fontSize: 14, margin: 12 });
    document.getElementById('downloadSavedBarcode')?.addEventListener('click', function () {
        const blob = new Blob([new XMLSerializer().serializeToString(savedBarcode)], { type: 'image/svg+xml;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = savedBarcodeValue + '.svg';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    });
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
