
<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

$pdo = db();
$isAdmin = is_admin();
$readOnly = !$isAdmin;

if (!$isAdmin && !has_permission('products.view')) {
    http_response_code(403);
    include __DIR__ . '/includes/403.php';
    exit;
}

$pageTitle = $readOnly ? 'Katalog Produk' : 'Manajemen Produk';
$activePage = 'barang';

$error = '';
$edit = null;
$barcodeItem = null;
$newPhotoPath = null;

$uploadDir = __DIR__ . '/assets/uploads/products';
$uploadUrl = 'assets/uploads/products/';

function aurelux_generate_sku(PDO $pdo): string
{
    do {
        $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(5)));

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM products WHERE sku = ?'
        );
        $stmt->execute([$sku]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $sku;
}

function aurelux_generate_barcode(PDO $pdo): string
{
    do {
        $barcode = 'TK' . strtoupper(bin2hex(random_bytes(5)));

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM products WHERE barcode = ?'
        );
        $stmt->execute([$barcode]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $barcode;
}

function aurelux_upload_photo(array $file, string $directory): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto gagal diunggah.');
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 2 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Foto harus berformat JPG, PNG, atau WEBP.');
    }

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException('Folder penyimpanan foto gagal dibuat.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];

    if (!move_uploaded_file(
        $file['tmp_name'],
        $directory . DIRECTORY_SEPARATOR . $filename
    )) {
        throw new RuntimeException('Foto tidak dapat disimpan.');
    }

    return $filename;
}

function aurelux_parse_price(string $value): float
{
    $value = trim($value);
    $value = preg_replace('/[^0-9,.]/', '', $value);

    if ($value === '') {
        return 0;
    }

    // Format Indonesia: 4.000 atau 4.000,50
    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        // Titik dianggap pemisah ribuan.
        $value = str_replace('.', '', $value);
    }

    return is_numeric($value) ? (float) $value : 0;
}

$categories = $pdo->query(
    'SELECT id, name FROM categories
     WHERE is_active = 1 AND deleted_at IS NULL
     ORDER BY name ASC'
)->fetchAll();

if (!$readOnly && isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM products
         WHERE id = ? AND is_active = 1 AND deleted_at IS NULL'
    );
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

if (isset($_GET['barcode'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM products
         WHERE id = ? AND is_active = 1 AND deleted_at IS NULL'
    );
    $stmt->execute([(int) $_GET['barcode']]);
    $barcodeItem = $stmt->fetch() ?: null;
}

$defaultSku = $edit
    ? (string) $edit['sku']
    : aurelux_generate_sku($pdo);

$defaultBarcode = $edit
    ? (string) ($edit['barcode'] ?? '')
    : aurelux_generate_barcode($pdo);

$barcodeInputValue = $defaultBarcode;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($readOnly) {
        http_response_code(403);
        include __DIR__ . '/includes/403.php';
        exit;
    }

    check_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);

            $sku = trim((string) ($_POST['sku'] ?? ''));
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $unit = trim((string) ($_POST['unit'] ?? 'pcs'));
            $sellPrice = aurelux_parse_price((string) ($_POST['sell_price'] ?? ''));
            $stock = (int) ($_POST['stock_qty'] ?? 0);
            $minStock = (int) ($_POST['min_stock'] ?? 5);

            $barcodeInputValue = $barcode;

            if (
                $sku === '' ||
                strlen($sku) > 40 ||
                $barcode === '' ||
                strlen($barcode) > 64 ||
                $name === '' ||
                strlen($name) > 150 ||
                $unit === '' ||
                strlen($unit) > 20 ||
                $sellPrice < 0 ||
                $stock < 0 ||
                $minStock < 0
            ) {
                throw new RuntimeException('Periksa kembali data produk.');
            }

            if ($categoryId > 0) {
                $checkCategory = $pdo->prepare(
                    'SELECT COUNT(*) FROM categories
                     WHERE id = ? AND is_active = 1 AND deleted_at IS NULL'
                );
                $checkCategory->execute([$categoryId]);

                if ((int) $checkCategory->fetchColumn() === 0) {
                    throw new RuntimeException('Kategori tidak ditemukan.');
                }
            } else {
                $categoryId = null;
            }

            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM products
                 WHERE id <> ?
                 AND (sku = ? OR barcode = ?)'
            );
            $check->execute([$id, $sku, $barcode]);

            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('SKU atau barcode sudah digunakan produk lain.');
            }

            $photo = '';

            if (!empty($_FILES['image']['name'])) {
                $photo = aurelux_upload_photo($_FILES['image'], $uploadDir);
                $newPhotoPath = $uploadDir . DIRECTORY_SEPARATOR . $photo;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'SELECT image FROM products
                     WHERE id = ? AND deleted_at IS NULL'
                );
                $stmt->execute([$id]);
                $oldPhoto = $stmt->fetchColumn();

                if ($oldPhoto === false) {
                    throw new RuntimeException('Produk yang akan diubah tidak ditemukan.');
                }

                if ($photo !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE products SET
                            category_id = ?,
                            sku = ?,
                            barcode = ?,
                            name = ?,
                            unit = ?,
                            sell_price = ?,
                            stock_qty = ?,
                            min_stock = ?,
                            image = ?,
                            updated_at = NOW()
                         WHERE id = ? AND deleted_at IS NULL'
                    );

                    $stmt->execute([
                        $categoryId,
                        $sku,
                        $barcode,
                        $name,
                        $unit,
                        $sellPrice,
                        $stock,
                        $minStock,
                        $photo,
                        $id
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE products SET
                            category_id = ?,
                            sku = ?,
                            barcode = ?,
                            name = ?,
                            unit = ?,
                            sell_price = ?,
                            stock_qty = ?,
                            min_stock = ?,
                            updated_at = NOW()
                         WHERE id = ? AND deleted_at IS NULL'
                    );

                    $stmt->execute([
                        $categoryId,
                        $sku,
                        $barcode,
                        $name,
                        $unit,
                        $sellPrice,
                        $stock,
                        $minStock,
                        $id
                    ]);
                }

                if ($photo !== '' && $oldPhoto) {
                    @unlink(
                        $uploadDir . DIRECTORY_SEPARATOR . basename((string) $oldPhoto)
                    );
                }

                log_activity('ubah_produk', 'Mengubah produk ' . $name);

                $savedId = $id;
                flash('success', 'Produk berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO products (
                        category_id,
                        sku,
                        barcode,
                        name,
                        unit,
                        cost_price,
                        sell_price,
                        stock_qty,
                        min_stock,
                        track_stock,
                        product_type,
                        image,
                        is_active
                    ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1, "regular", ?, 1)'
                );

                $stmt->execute([
                    $categoryId,
                    $sku,
                    $barcode,
                    $name,
                    $unit,
                    $sellPrice,
                    $stock,
                    $minStock,
                    $photo ?: null
                ]);

                $savedId = (int) $pdo->lastInsertId();

                log_activity('tambah_produk', 'Menambahkan produk ' . $name);

                flash('success', 'Produk baru berhasil ditambahkan.');
            }

            $newPhotoPath = null;

            header('Location: ' . url('barang.php?barcode=' . $savedId));
            exit;
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare(
                'SELECT name FROM products
                 WHERE id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$id]);
            $productName = $stmt->fetchColumn();

            if (!$productName) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            // Soft delete: data dan riwayat transaksi tetap dipertahankan.
            $stmt = $pdo->prepare(
                'UPDATE products
                 SET is_active = 0, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$id]);

            log_activity(
                'nonaktifkan_produk',
                'Menonaktifkan produk ' . $productName
            );

            flash('success', 'Produk berhasil dinonaktifkan.');

            header('Location: ' . url('barang.php'));
            exit;
        }

    } catch (Throwable $e) {
        if ($newPhotoPath) {
            @unlink($newPhotoPath);
        }

        $error = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'SKU atau barcode sudah digunakan.'
            : $e->getMessage();
    }
}

$q = trim((string) ($_GET['q'] ?? ''));

$stmt = $pdo->prepare(
    'SELECT
        p.*,
        c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1
       AND p.deleted_at IS NULL
       AND (
            p.sku LIKE ?
            OR p.barcode LIKE ?
            OR p.name LIKE ?
            OR c.name LIKE ?
       )
     ORDER BY p.name ASC'
);

$like = '%' . $q . '%';
$stmt->execute([$like, $like, $like, $like]);
$items = $stmt->fetchAll();

$barcodeLabelValue = $barcodeItem
    ? (string) ($barcodeItem['barcode'] ?: $barcodeItem['sku'])
    : '';

include __DIR__ . '/includes/header.php';
?>

<div class="grid<?= $readOnly ? '' : ' two-col' ?>">

<?php if (!$readOnly): ?>
<section class="card">
    <div class="section-head">
        <div>
            <h2><?= $edit ? 'Ubah Produk' : 'Tambah Produk' ?></h2>
            <p class="muted small">Kelola informasi dan stok produk toko.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? 0) ?>">

        <div class="form-grid">

            <div class="barcode-field">
                <label>Barcode Produk</label>
                <input
                    class="input"
                    id="barcodeInput"
                    name="barcode"
                    maxlength="64"
                    required
                    value="<?= e($barcodeInputValue) ?>"
                    placeholder="Masukkan barcode"
                >

                <small class="muted">
                    Bisa menggunakan scanner barcode USB atau kode otomatis.
                </small>

                <div class="toolbar barcode-tools">
                    <button class="btn btn-light btn-sm" type="button" id="generateBarcode">
                        Buat Barcode
                    </button>

                    <button class="btn btn-light btn-sm" type="button" id="downloadBarcode">
                        Download SVG
                    </button>
                </div>

                <div class="barcode-preview" id="barcodePreview" hidden>
                    <svg id="barcodeImage"></svg>
                </div>
            </div>

            <div>
                <label>SKU Produk</label>
                <input
                    class="input"
                    name="sku"
                    maxlength="40"
                    required
                    value="<?= e($defaultSku) ?>"
                >
            </div>

            <div>
                <label>Nama Produk</label>
                <input
                    class="input"
                    name="name"
                    maxlength="150"
                    required
                    value="<?= e($edit['name'] ?? '') ?>"
                >
            </div>

            <div>
                <label>Kategori</label>
                <select class="select" name="category_id">
                    <option value="0">Tanpa kategori</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= e($category['id']) ?>"
                            <?= (int) ($edit['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Satuan</label>
                <input
                    class="input"
                    name="unit"
                    maxlength="20"
                    value="<?= e($edit['unit'] ?? 'pcs') ?>"
                    required
                >
            </div>

            <div>
                <label>Harga Jual</label>
                <input
                    class="input"
                    type="text"
                    inputmode="decimal"
                    name="sell_price"
                    required
                    placeholder="Contoh: 5.000"
                    value="<?= e(isset($edit['sell_price']) ? number_format((float) $edit['sell_price'], 0, ',', '.') : '') ?>"
                >
            </div>

            <div>
                <label>Stok Produk</label>
                <input
                    class="input"
                    type="number"
                    name="stock_qty"
                    min="0"
                    required
                    value="<?= e($edit['stock_qty'] ?? 0) ?>"
                >
            </div>

            <div>
                <label>Batas Minimum Stok</label>
                <input
                    class="input"
                    type="number"
                    name="min_stock"
                    min="0"
                    required
                    value="<?= e($edit['min_stock'] ?? 5) ?>"
                >
            </div>

            <div class="full">
                <label>Foto Produk</label>
                <input
                    class="input"
                    type="file"
                    name="image"
                    accept="image/jpeg,image/png,image/webp"
                >

                <small class="muted">JPG, PNG, WEBP. Maksimal 2 MB.</small>

                <?php if (!empty($edit['image'])): ?>
                    <div class="current-photo">
                        <img
                            class="product-thumb"
                            src="<?= e(url($uploadUrl . basename($edit['image']))) ?>"
                            alt="<?= e($edit['name']) ?>"
                        >
                        <span class="muted small">Foto saat ini.</span>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="toolbar" style="margin-top:18px">
            <button class="btn btn-primary" type="submit">
                <?= $edit ? 'Simpan Perubahan' : 'Tambah Produk' ?>
            </button>

            <?php if ($edit): ?>
                <a class="btn btn-light" href="<?= e(url('barang.php')) ?>">
                    Batal
                </a>
            <?php endif; ?>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <div class="section-head">
        <div>
            <h2>Daftar Produk</h2>
            <p class="muted small"><?= count($items) ?> produk tersedia</p>
        </div>
    </div>

    <form class="search-row" method="get">
        <input
            class="input"
            name="q"
            placeholder="Cari nama, SKU, barcode, kategori..."
            value="<?= e($q) ?>"
        >
        <button class="btn btn-light" type="submit">Cari</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Harga</th>
                    <th>Stok</th>
                    <?php if (!$readOnly): ?>
                        <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div class="item-with-photo">
                            <?php if (!empty($item['image'])): ?>
                                <img
                                    class="product-thumb"
                                    src="<?= e(url($uploadUrl . basename($item['image']))) ?>"
                                    alt="<?= e($item['name']) ?>"
                                >
                            <?php else: ?>
                                <span class="product-thumb placeholder">▤</span>
                            <?php endif; ?>

                            <div>
                                <strong><?= e($item['name']) ?></strong>
                                <div class="muted small">
                                    SKU: <?= e($item['sku']) ?>
                                    · Barcode: <?= e($item['barcode'] ?: '-') ?>
                                    · <?= e($item['category_name'] ?? 'Tanpa kategori') ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <td class="amount">
                        <?= money($item['sell_price']) ?>
                    </td>

                    <td>
                        <span class="badge <?= (int) $item['stock_qty'] <= (int) $item['min_stock'] ? 'unpaid' : 'paid' ?>">
                            <?= e($item['stock_qty']) ?> <?= e($item['unit']) ?>
                        </span>
                    </td>

                    <?php if (!$readOnly): ?>
                    <td>
                        <div class="actions">
                            <a
                                class="btn btn-light btn-sm"
                                href="<?= e(url('barang.php?barcode=' . $item['id'])) ?>"
                            >
                                Barcode
                            </a>

                            <a
                                class="btn btn-light btn-sm"
                                href="<?= e(url('barang.php?edit=' . $item['id'])) ?>"
                            >
                                Ubah
                            </a>

                            <form
                                method="post"
                                onsubmit="return confirm('Nonaktifkan produk ini? Riwayat transaksi tetap disimpan.')"
                            >
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e($item['id']) ?>">

                                <button class="btn btn-danger btn-sm" type="submit">
                                    Nonaktifkan
                                </button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>

            <?php if (!$items): ?>
                <tr>
                    <td colspan="<?= $readOnly ? 3 : 4 ?>" class="empty">
                        Produk belum tersedia.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</div>

<?php if ($barcodeItem): ?>
<section class="card barcode-label-area">
    <div class="section-head">
        <div>
            <h2>Label Barcode</h2>
            <p class="muted small">
                <?= e($barcodeItem['name']) ?> · <?= e($barcodeLabelValue) ?>
            </p>
        </div>

        <div class="toolbar no-print">
            <button class="btn btn-primary" type="button" onclick="window.print()">
                Cetak Label
            </button>

            <button class="btn btn-light" type="button" id="downloadSavedBarcode">
                Download SVG
            </button>

            <a class="btn btn-light" href="<?= e(url('barang.php')) ?>">
                Tutup
            </a>
        </div>
    </div>

    <div class="barcode-label">
        <svg id="savedBarcode"></svg>
        <strong><?= e($barcodeItem['name']) ?></strong>
        <span><?= e($barcodeLabelValue) ?></span>
    </div>
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

        if (value && value.length <= 64 && typeof JsBarcode === 'function') {
            try {
                JsBarcode(svg, value, {
                    format: 'CODE128',
                    width: 2,
                    height: 55,
                    displayValue: true,
                    fontSize: 13,
                    margin: 8
                });

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

        input.value = 'TK' + Array.from(
            bytes,
            byte => byte.toString(16).padStart(2, '0')
        ).join('').toUpperCase();

        updateBarcode();
    });

    document.getElementById('downloadBarcode')?.addEventListener('click', function () {
        if (!svg.firstChild || preview.hidden) return;

        const blob = new Blob(
            [new XMLSerializer().serializeToString(svg)],
            { type: 'image/svg+xml;charset=utf-8' }
        );

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

    const savedBarcodeValue = <?= json_encode(
        $barcodeLabelValue,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    JsBarcode(savedBarcode, savedBarcodeValue, {
        format: 'CODE128',
        width: 2,
        height: 70,
        displayValue: true,
        fontSize: 14,
        margin: 12
    });

    document.getElementById('downloadSavedBarcode')?.addEventListener('click', function () {
        const blob = new Blob(
            [new XMLSerializer().serializeToString(savedBarcode)],
            { type: 'image/svg+xml;charset=utf-8' }
        );

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
