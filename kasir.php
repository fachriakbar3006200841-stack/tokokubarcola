<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_staff();

if (!is_admin() && !has_permission('pos.use')) {
    http_response_code(403);
    exit('Anda tidak memiliki izin menggunakan kasir.');
}

$pdo = db();
$user = current_user();

$pageTitle = 'Kasir';
$activePage = 'kasir';
$error = '';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

/**
 * Format angka menjadi nilai rupiah.
 */
function pos_parse_amount(mixed $value): float
{
    if (is_numeric($value)) {
        return max(0, (float) $value);
    }

    $value = preg_replace('/[^0-9]/', '', (string) $value);

    return max(0, (float) $value);
}

/**
 * Ambil produk berdasarkan ID.
 */
function pos_get_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ?
           AND p.is_active = 1
           AND p.deleted_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Masukkan produk ke keranjang.
 * Format session dipertahankan agar komponen keranjang lama di header tetap kompatibel.
 */
function pos_add_to_cart(array $product): void
{
    $id = (int) $product['id'];
    $current = (int) ($_SESSION['cart'][$id]['qty'] ?? 0);

    if ((int) $product['track_stock'] === 1) {
        if ((int) $product['stock_qty'] < 1) {
            throw new RuntimeException('Stok produk sedang habis.');
        }

        if ($current + 1 > (int) $product['stock_qty']) {
            throw new RuntimeException('Stok produk tidak mencukupi.');
        }
    }

    $_SESSION['cart'][$id] = [
        'id' => $id,
        'kode' => $product['sku'],
        'nama' => $product['name'],
        'harga' => (float) $product['sell_price'],
        'qty' => $current + 1,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        $isAjaxRequest &&
        !hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))
    ) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok' => false,
            'error' => 'Token keamanan tidak valid. Muat ulang halaman.',
        ]);

        exit;
    }

    if (!$isAjaxRequest) {
        check_csrf();
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            $id = (int) ($_POST['barang_id'] ?? 0);
            $product = pos_get_product($pdo, $id);

            if (!$product) {
                throw new RuntimeException('Produk tidak ditemukan atau sudah tidak aktif.');
            }

            pos_add_to_cart($product);

            flash('success', $product['name'] . ' berhasil ditambahkan ke keranjang.');

            header('Location: ' . url('kasir.php'));
            exit;
        }

        elseif ($action === 'add_code') {
            $barcode = trim((string) ($_POST['barcode'] ?? ''));

            if ($barcode === '') {
                throw new RuntimeException('Masukkan atau scan barcode terlebih dahulu.');
            }

            $stmt = $pdo->prepare(
                'SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE (p.barcode = ? OR p.sku = ?)
                   AND p.is_active = 1
                   AND p.deleted_at IS NULL
                 LIMIT 1'
            );

            $stmt->execute([$barcode, $barcode]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new RuntimeException(
                    'Barcode ' . $barcode . ' tidak ditemukan. Periksa data produk.'
                );
            }

            pos_add_to_cart($product);

            if (!$isAjaxRequest) {
                flash('success', $product['name'] . ' berhasil ditambahkan.');
                header('Location: ' . url('kasir.php'));
                exit;
            }

            $scannedItem = $product;
        }

        elseif ($action === 'update') {
            foreach (($_POST['qty'] ?? []) as $id => $qty) {
                $id = (int) $id;
                $qty = (int) $qty;

                if (!isset($_SESSION['cart'][$id])) {
                    continue;
                }

                if ($qty <= 0) {
                    unset($_SESSION['cart'][$id]);
                    continue;
                }

                $product = pos_get_product($pdo, $id);

                if (!$product) {
                    unset($_SESSION['cart'][$id]);
                    continue;
                }

                if ((int) $product['track_stock'] === 1) {
                    $qty = min($qty, (int) $product['stock_qty']);
                }

                if ($qty <= 0) {
                    unset($_SESSION['cart'][$id]);
                    continue;
                }

                $_SESSION['cart'][$id]['qty'] = $qty;
                $_SESSION['cart'][$id]['harga'] = (float) $product['sell_price'];
                $_SESSION['cart'][$id]['nama'] = $product['name'];
                $_SESSION['cart'][$id]['kode'] = $product['sku'];
            }

            flash('success', 'Keranjang berhasil diperbarui.');

            header('Location: ' . url('kasir.php'));
            exit;
        }

        elseif ($action === 'clear') {
            $_SESSION['cart'] = [];

            flash('success', 'Keranjang berhasil dikosongkan.');

            header('Location: ' . url('kasir.php'));
            exit;
        }

        elseif ($action === 'checkout') {
            if (empty($_SESSION['cart'])) {
                throw new RuntimeException('Keranjang masih kosong.');
            }

            $customerId = (int) ($_POST['pelanggan_id'] ?? 0);
            $customerId = $customerId > 0 ? $customerId : null;

            $paymentMethod = (string) ($_POST['metode_pembayaran'] ?? 'tunai');

            /*
             * QRIS statis lama belum didukung oleh struktur pembayaran AURELUX.
             * Jangan membuat transaksi lunas sebelum pembayaran benar-benar diterima.
             */
            if ($paymentMethod === 'qris_statis') {
                throw new RuntimeException(
                    'Pembayaran QRIS tertunda belum tersedia pada struktur database AURELUX. Gunakan tunai terlebih dahulu.'
                );
            }

            if (!in_array($paymentMethod, ['tunai', 'cash'], true)) {
                throw new RuntimeException('Metode pembayaran belum didukung.');
            }

            $paid = pos_parse_amount($_POST['bayar'] ?? 0);

            $userId = (int) $user['id'];
            $storeId = (int) ($user['store_id'] ?? 0);

            if ($storeId <= 0) {
                throw new RuntimeException('Akun pengguna belum terhubung dengan toko.');
            }

            $pdo->beginTransaction();

            /*
             * Transaksi wajib memiliki shift aktif.
             */
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM shifts
                 WHERE user_id = ?
                   AND status = 'open'
                 ORDER BY opened_at DESC
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([$userId]);
            $shiftId = (int) $stmt->fetchColumn();

            if ($shiftId <= 0) {
                throw new RuntimeException(
                    'Tidak ada shift aktif. Buka shift kasir terlebih dahulu sebelum checkout.'
                );
            }

            /*
             * Validasi pelanggan.
             */
            if ($customerId !== null) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM customers
                     WHERE id = ?
                       AND is_active = 1
                       AND deleted_at IS NULL
                     LIMIT 1'
                );

                $stmt->execute([$customerId]);

                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('Data pelanggan tidak ditemukan atau tidak aktif.');
                }
            }

            /*
             * Kunci dan validasi ulang seluruh produk.
             */
            $cartItems = [];
            $subtotal = 0;

            foreach ($_SESSION['cart'] as $cartId => $row) {
                $productId = (int) $cartId;
                $qty = (int) ($row['qty'] ?? 0);

                if ($qty <= 0) {
                    throw new RuntimeException('Jumlah produk dalam keranjang tidak valid.');
                }

                $stmt = $pdo->prepare(
                    'SELECT id, sku, name, sell_price, cost_price,
                            stock_qty, track_stock, is_active, deleted_at
                     FROM products
                     WHERE id = ?
                     FOR UPDATE'
                );

                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    !$product ||
                    !(int) $product['is_active'] ||
                    $product['deleted_at'] !== null
                ) {
                    throw new RuntimeException('Ada produk dalam keranjang yang sudah tidak tersedia.');
                }

                if (
                    (int) $product['track_stock'] === 1 &&
                    $qty > (int) $product['stock_qty']
                ) {
                    throw new RuntimeException(
                        'Stok ' . $product['name'] . ' tidak mencukupi. Periksa kembali keranjang.'
                    );
                }

                $price = (float) $product['sell_price'];
                $lineTotal = $price * $qty;

                $subtotal += $lineTotal;

                $cartItems[] = [
                    'id' => (int) $product['id'],
                    'name' => $product['name'],
                    'qty' => $qty,
                    'sell_price' => $price,
                    'cost_price' => (float) $product['cost_price'],
                    'subtotal' => $lineTotal,
                    'track_stock' => (int) $product['track_stock'],
                ];
            }

            $total = $subtotal;

            if ($paid < $total) {
                throw new RuntimeException(
                    'Pembayaran kurang. Total transaksi: ' . money($total)
                );
            }

            $change = $paid - $total;

            /*
             * Nomor invoice.
             */
            $invoiceNo = 'TRX-' . date('ymdHis') . '-' . random_int(100, 999);

            /*
             * Simpan transaksi utama.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (invoice_no, store_id, shift_id, user_id, customer_id,
                     subtotal, discount_amount, total, paid_total,
                     change_amount, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'completed', NOW())"
            );

            $stmt->execute([
                $invoiceNo,
                $storeId,
                $shiftId,
                $userId,
                $customerId,
                $subtotal,
                $total,
                $paid,
                $change,
            ]);

            $transactionId = (int) $pdo->lastInsertId();

            /*
             * Simpan detail dan kurangi stok.
             */
            foreach ($cartItems as $item) {
                $stmt = $pdo->prepare(
                    'INSERT INTO transaction_details
                        (transaction_id, product_id, product_name,
                         qty, sell_price, cost_price, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $transactionId,
                    $item['id'],
                    $item['name'],
                    $item['qty'],
                    $item['sell_price'],
                    $item['cost_price'],
                    $item['subtotal'],
                ]);

                if ($item['track_stock'] === 1) {
                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET stock_qty = stock_qty - ?
                         WHERE id = ?
                           AND is_active = 1
                           AND stock_qty >= ?'
                    );

                    $stmt->execute([
                        $item['qty'],
                        $item['id'],
                        $item['qty'],
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Stok ' . $item['name'] . ' berubah. Silakan ulangi transaksi.'
                        );
                    }
                }
            }

            /*
             * Simpan pembayaran.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO payments
                    (transaction_id, method, amount, tendered, reference, created_at)
                 VALUES (?, 'cash', ?, ?, NULL, NOW())"
            );

            $stmt->execute([
                $transactionId,
                $total,
                $paid,
            ]);

            /*
             * Perbarui statistik pelanggan.
             */
            if ($customerId !== null) {
                $stmt = $pdo->prepare(
                    'UPDATE customers
                     SET total_transactions = total_transactions + 1,
                         total_spent = total_spent + ?
                     WHERE id = ?'
                );

                $stmt->execute([$total, $customerId]);
            }

            log_activity(
                'transaksi',
                'Menyelesaikan transaksi ' . $invoiceNo
            );

            $pdo->commit();

            $_SESSION['cart'] = [];

            flash(
                'success',
                'Transaksi ' . $invoiceNo . ' berhasil dibayar. Kembalian: ' . money($change)
            );

            header('Location: ' . url('kasir.php?selesai=' . $transactionId));
            exit;
        }

        else {
            throw new RuntimeException('Aksi kasir tidak dikenal.');
        }

        /*
         * Respons AJAX untuk scan barcode.
         */
        if ($action === 'add_code' && $isAjaxRequest) {
            $cart = array_values(array_map(
                static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'nama' => $row['nama'],
                        'harga' => (float) $row['harga'],
                        'qty' => (int) $row['qty'],
                    ];
                },
                $_SESSION['cart']
            ));

            $cartCount = array_sum(array_column($cart, 'qty'));

            $cartTotal = array_sum(array_map(
                static fn(array $row): float => $row['harga'] * $row['qty'],
                $cart
            ));

            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'ok' => true,
                'product' => [
                    'name' => $scannedItem['name'],
                    'barcode' => $scannedItem['barcode'] ?: $scannedItem['sku'],
                    'category' => $scannedItem['category_name'] ?: 'Tanpa kategori',
                    'price' => (float) $scannedItem['sell_price'],
                    'stock' => (int) $scannedItem['stock_qty'],
                    'unit' => $scannedItem['unit'],
                    'quantity' => (int) $_SESSION['cart'][(int) $scannedItem['id']]['qty'],
                ],
                'cart' => $cart,
                'cartCount' => $cartCount,
                'cartTotal' => $cartTotal,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();

        if ($isAjaxRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'ok' => false,
                'error' => $error,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            exit;
        }
    }
}

/*
 * Ambil produk aktif.
 */
$stmt = $pdo->query(
    'SELECT p.*, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1
       AND p.deleted_at IS NULL
       AND (p.track_stock = 0 OR p.stock_qty > 0)
     ORDER BY p.name ASC'
);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Ambil kategori.
 */
$categoryOptions = ['' => 'Semua'];

$stmt = $pdo->query(
    "SELECT DISTINCT c.name
     FROM categories c
     INNER JOIN products p ON p.category_id = c.id
     WHERE c.is_active = 1
       AND c.deleted_at IS NULL
       AND p.is_active = 1
       AND p.deleted_at IS NULL
     ORDER BY c.name"
);

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $category) {
    $categoryOptions[$category] = $category;
}

$selectedCategory = trim((string) ($_GET['kategori'] ?? ''));

if (!array_key_exists($selectedCategory, $categoryOptions)) {
    $selectedCategory = '';
}

/*
 * Informasi shift aktif.
 */
$stmt = $pdo->prepare(
    "SELECT id, opened_at, opening_cash
     FROM shifts
     WHERE user_id = ?
       AND status = 'open'
     ORDER BY opened_at DESC
     LIMIT 1"
);

$stmt->execute([(int) $user['id']]);
$activeShift = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

include __DIR__ . '/includes/header.php';
?>

<section class="card">
    <div class="section-head">
        <div>
            <h2>Pilih Produk</h2>
            <p class="muted small">Pilih produk atau gunakan scanner barcode.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$activeShift): ?>
        <div class="alert error">
            Shift kasir belum dibuka. Produk tetap dapat dilihat dan dimasukkan ke keranjang,
            tetapi checkout tidak dapat dilakukan sebelum shift aktif.
        </div>
    <?php else: ?>
        <div class="alert success">
            Shift aktif sejak <?= e($activeShift['opened_at']) ?>.
        </div>
    <?php endif; ?>

    <section class="barcode-panel">
        <div class="barcode-heading">
            <div>
                <strong>Scan Barcode Produk</strong>
                <p class="muted small">
                    Gunakan scanner USB atau kamera perangkat.
                </p>
            </div>
            <span class="barcode-symbol">▥</span>
        </div>

        <form class="barcode-form" method="post" id="barcodeForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_code">

            <input
                class="input"
                id="barcodeInput"
                name="barcode"
                autocomplete="off"
                placeholder="Scan barcode atau ketik SKU..."
                autofocus
            >

            <button class="btn btn-primary" type="submit">Tambah</button>

            <button class="btn btn-light" type="button" id="cameraButton">
                Scan Kamera
            </button>
        </form>

        <div class="scan-result" id="scanResult" role="status" aria-live="polite" hidden></div>

        <div class="scanner-camera-wrap" id="cameraWrap" hidden>
            <video id="barcodeVideo" playsinline muted></video>

            <div class="scanner-camera-actions">
                <span id="cameraStatus" class="muted small">
                    Arahkan barcode ke kamera.
                </span>

                <button type="button" class="btn btn-light btn-sm" id="stopCamera">
                    Tutup Kamera
                </button>
            </div>
        </div>
    </section>

    <form class="search-row" method="get">
        <input
            class="input"
            name="q"
            placeholder="Cari nama produk atau SKU..."
            value="<?= e($_GET['q'] ?? '') ?>"
        >

        <?php if ($selectedCategory !== ''): ?>
            <input type="hidden" name="kategori" value="<?= e($selectedCategory) ?>">
        <?php endif; ?>

        <button class="btn btn-light" type="submit">Cari</button>
    </form>

    <div class="category-tabs">
        <?php foreach ($categoryOptions as $categoryValue => $categoryLabel): ?>
            <a
                class="category-tab <?= $selectedCategory === $categoryValue ? 'active' : '' ?>"
                href="<?= e(url(
                    'kasir.php' .
                    ($categoryValue !== '' ? '?kategori=' . rawurlencode($categoryValue) : '')
                )) ?>"
            >
                <?= e($categoryLabel) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="product-grid">
        <?php
        $q = strtolower(trim((string) ($_GET['q'] ?? '')));
        $shownProducts = 0;

        foreach ($products as $product):
            $productCategory = $product['category_name'] ?? '';

            if (
                $selectedCategory !== '' &&
                strtolower($productCategory) !== strtolower($selectedCategory)
            ) {
                continue;
            }

            $searchText = strtolower(
                $product['name'] . ' ' .
                $product['sku'] . ' ' .
                ($product['barcode'] ?? '')
            );

            if ($q !== '' && strpos($searchText, $q) === false) {
                continue;
            }

            $shownProducts++;
        ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="barang_id" value="<?= (int) $product['id'] ?>">

                <button
                    class="product-card"
                    type="submit"
                    style="width:100%;text-align:left"
                >
                    <?php if (!empty($product['image'])): ?>
                        <img
                            class="product-card-image"
                            src="<?= e(url('assets/uploads/products/' . $product['image'])) ?>"
                            alt="<?= e($product['name']) ?>"
                        >
                    <?php else: ?>
                        <span class="product-card-image placeholder">▤</span>
                    <?php endif; ?>

                    <strong><?= e($product['name']) ?></strong>

                    <div class="price">
                        <?= money($product['sell_price']) ?>
                    </div>

                    <div class="stock">
                        <?= e($productCategory ?: 'Tanpa kategori') ?>
                        · Stok
                        <?= (int) $product['stock_qty'] ?>
                        <?= e($product['unit']) ?>
                    </div>

                    <span class="barcode-code">
                        SKU: <?= e($product['sku']) ?>
                    </span>
                </button>
            </form>
        <?php endforeach; ?>

        <?php if (!$shownProducts): ?>
            <p class="muted">Produk tidak ditemukan.</p>
        <?php endif; ?>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js"></script>

<script>
(function () {
    const input = document.getElementById('barcodeInput');
    const form = document.getElementById('barcodeForm');
    const cameraButton = document.getElementById('cameraButton');
    const cameraWrap = document.getElementById('cameraWrap');
    const video = document.getElementById('barcodeVideo');
    const status = document.getElementById('cameraStatus');
    const stopButton = document.getElementById('stopCamera');
    const result = document.getElementById('scanResult');

    let reader = null;
    let controls = null;
    let submitting = false;

    function stopCamera() {
        if (controls) {
            controls.stop();
        }

        controls = null;
        reader = null;
        cameraWrap.hidden = true;
        cameraButton.disabled = false;
    }

    function formatMoney(value) {
        return 'Rp ' + new Intl.NumberFormat('id-ID', {
            maximumFractionDigits: 0
        }).format(value);
    }

    function updateCart(data) {
        const badge = document.getElementById('cartCountBadge');

        if (badge) {
            badge.textContent = data.cartCount;
            badge.hidden = data.cartCount === 0;
        }

        const count = document.getElementById('cartPopoverCount');

        if (count) {
            count.textContent = data.cartCount + ' item';
        }

        const total = document.getElementById('cartPopoverTotal');

        if (total) {
            total.textContent = formatMoney(data.cartTotal);
        }

        const cartItems = document.getElementById('cartPopoverItems');

        if (cartItems) {
            cartItems.replaceChildren();

            data.cart.forEach(item => {
                const row = document.createElement('div');
                row.className = 'cart-popover-item';

                const description = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = item.nama;

                const quantity = document.createElement('small');
                quantity.textContent = formatMoney(item.harga) + ' × ' + item.qty;

                description.append(name, quantity);

                const subtotal = document.createElement('strong');
                subtotal.textContent = formatMoney(item.harga * item.qty);

                row.append(description, subtotal);
                cartItems.append(row);
            });
        }

        const empty = document.getElementById('cartEmptyMessage');

        if (empty) {
            empty.hidden = data.cartCount > 0;
        }

        const totalRow = document.getElementById('cartPopoverTotalRow');

        if (totalRow) {
            totalRow.hidden = data.cartCount === 0;
        }

        [
            document.getElementById('cartUpdateForm'),
            document.getElementById('cartCheckoutForm'),
            document.getElementById('cartClearForm')
        ].forEach(element => {
            if (element) {
                element.hidden = data.cartCount === 0;
            }
        });

        const quantityInputs = document.getElementById('cartQuantityInputs');

        if (quantityInputs) {
            quantityInputs.replaceChildren();

            data.cart.forEach(item => {
                const label = document.createElement('label');
                const name = document.createElement('span');

                name.textContent = item.nama;

                const quantityInput = document.createElement('input');
                quantityInput.type = 'number';
                quantityInput.min = '0';
                quantityInput.name = 'qty[' + item.id + ']';
                quantityInput.value = item.qty;

                label.append(name, quantityInput);
                quantityInputs.append(label);
            });
        }

        const amount = document.getElementById('checkoutAmount');

        if (amount) {
            amount.min = data.cartTotal;
        }
    }

    async function submitBarcode() {
        if (submitting || !input.value.trim()) {
            return;
        }

        submitting = true;
        result.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Barcode tidak dapat diproses.');
            }

            updateCart(data);

            result.className = 'scan-result success';
            result.textContent =
                data.product.name + ' · ' +
                data.product.barcode + ' · ' +
                data.product.category + ' · ' +
                formatMoney(data.product.price) + ' · Stok ' +
                data.product.stock + ' ' +
                data.product.unit + ' · Dalam keranjang ' +
                data.product.quantity + ' · Total ' +
                formatMoney(data.cartTotal);

            input.value = '';
            input.focus();

        } catch (error) {
            result.className = 'scan-result error';
            result.textContent = error.message || 'Pencarian barcode gagal.';
        } finally {
            result.hidden = false;
            submitButton.disabled = false;
            submitting = false;
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submitBarcode();
    });

    cameraButton.addEventListener('click', async function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            cameraWrap.hidden = false;
            status.textContent = 'Kamera tidak tersedia. Gunakan scanner USB atau input manual.';
            return;
        }

        if (!window.ZXing || !window.ZXing.BrowserMultiFormatReader) {
            cameraWrap.hidden = false;
            status.textContent = 'Library kamera gagal dimuat. Gunakan scanner USB atau input manual.';
            return;
        }

        cameraWrap.hidden = false;
        cameraButton.disabled = true;
        status.textContent = 'Mengaktifkan kamera...';

        try {
            reader = new ZXing.BrowserMultiFormatReader();

            controls = await reader.decodeFromConstraints(
                {
                    video: {
                        facingMode: { ideal: 'environment' }
                    },
                    audio: false
                },
                video,
                function (decoded, scanError) {
                    if (decoded) {
                        input.value = decoded.getText().trim();
                        status.textContent = 'Barcode ditemukan. Menambahkan produk...';

                        stopCamera();
                        submitBarcode();

                    } else if (
                        scanError &&
                        scanError.name !== 'NotFoundException'
                    ) {
                        status.textContent = 'Kamera aktif. Arahkan barcode dengan jelas.';
                    }
                }
            );

            status.textContent = 'Kamera aktif. Arahkan barcode ke kamera.';

        } catch (error) {
            stopCamera();
            cameraWrap.hidden = false;

            status.textContent =
                'Kamera tidak dapat dibuka. Izinkan akses kamera dan gunakan localhost atau HTTPS.';
        }
    });

    stopButton.addEventListener('click', stopCamera);

    window.addEventListener('pagehide', stopCamera);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
