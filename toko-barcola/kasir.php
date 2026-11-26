<?php
require_once __DIR__ . '/includes/auth.php';
require_staff();
$pdo = db();
$pageTitle = 'Kasir';
$activePage = 'kasir';
$error = '';
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAjaxRequest && !hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
        exit;
    }
    if (!$isAjaxRequest) check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $id = (int) $_POST['barang_id'];
            $stmt = $pdo->prepare('SELECT * FROM barang WHERE id = ? AND aktif = 1'); $stmt->execute([$id]); $item = $stmt->fetch();
            if (!$item) throw new RuntimeException('Barang tidak ditemukan.');
            $current = $_SESSION['cart'][$id]['qty'] ?? 0;
            if ($current + 1 > (int) $item['stok']) throw new RuntimeException('Stok barang tidak mencukupi.');
            $_SESSION['cart'][$id] = ['id'=>$item['id'], 'kode'=>$item['kode_barang'], 'nama'=>$item['nama_barang'], 'harga'=>$item['harga'], 'qty'=>$current+1];
        } elseif ($action === 'add_code') {
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            if ($barcode === '') throw new RuntimeException('Masukkan atau scan kode barcode terlebih dahulu.');
            $stmt = $pdo->prepare('SELECT * FROM barang WHERE barcode = ? AND aktif = 1 LIMIT 1');
            $stmt->execute([$barcode]);
            $item = $stmt->fetch();
            if (!$item) {
                $stmt = $pdo->prepare('SELECT * FROM barang WHERE kode_barang = ? AND aktif = 1 LIMIT 1');
                $stmt->execute([$barcode]);
                $item = $stmt->fetch();
            }
            if (!$item) throw new RuntimeException('Barcode ' . $barcode . ' tidak ditemukan. Periksa kode barang di Data Barang.');
            if ((int) $item['stok'] < 1) throw new RuntimeException('Stok barang tersebut sedang habis.');
            $id = (int) $item['id'];
            $current = (int) ($_SESSION['cart'][$id]['qty'] ?? 0);
            if ($current + 1 > (int) $item['stok']) throw new RuntimeException('Stok barang tidak mencukupi.');
            $_SESSION['cart'][$id] = ['id'=>$item['id'], 'kode'=>$item['kode_barang'], 'nama'=>$item['nama_barang'], 'harga'=>$item['harga'], 'qty'=>$current+1];
            if (!$isAjaxRequest) flash('success', $item['nama_barang'] . ' berhasil ditambahkan ke keranjang.');
            $scannedItem = $item;
        } elseif ($action === 'update') {
            foreach (($_POST['qty'] ?? []) as $id => $qty) {
                $id = (int) $id; $qty = (int) $qty;
                if (!isset($_SESSION['cart'][$id])) continue;
                $stmt = $pdo->prepare('SELECT stok FROM barang WHERE id = ? AND aktif = 1'); $stmt->execute([$id]); $stock = (int) $stmt->fetchColumn();
                if ($qty <= 0) unset($_SESSION['cart'][$id]); else $_SESSION['cart'][$id]['qty'] = min($qty, $stock);
            }
        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
        } elseif ($action === 'checkout') {
            if (!$_SESSION['cart']) throw new RuntimeException('Keranjang masih kosong.');
            $customerId = (int) ($_POST['pelanggan_id'] ?? 0) ?: null;
            $paymentMethod = (string) ($_POST['metode_pembayaran'] ?? 'tunai');
            if (!in_array($paymentMethod, ['tunai', 'qris_statis'], true)) {
                throw new RuntimeException('Metode pembayaran tidak valid.');
            }
            $isStaticQris = $paymentMethod === 'qris_statis';
            $paid = $isStaticQris ? 0 : (float) ($_POST['bayar'] ?? 0);
            $total = 0;
            $pdo->beginTransaction();
            foreach ($_SESSION['cart'] as $cartId => &$row) {
                $stmt = $pdo->prepare('SELECT id,kode_barang,nama_barang,harga,stok,aktif FROM barang WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $cartId]);
                $item = $stmt->fetch();
                if (!$item || !(int) $item['aktif']) throw new RuntimeException('Barang di keranjang sudah tidak tersedia.');
                if ((int) $row['qty'] > (int) $item['stok']) throw new RuntimeException('Stok ' . $item['nama_barang'] . ' berubah. Silakan periksa keranjang.');
                $row['id'] = $item['id'];
                $row['kode'] = $item['kode_barang'];
                $row['nama'] = $item['nama_barang'];
                $row['harga'] = $item['harga'];
                $total += (float) $item['harga'] * (int) $row['qty'];
            }
            unset($row);
            if (!$isStaticQris && $paid < $total) throw new RuntimeException('Nominal pembayaran kurang dari total transaksi terbaru (' . money($total) . ').');
            $number = 'TRX-' . date('ymdHis') . '-' . random_int(10, 99);
            $status = $isStaticQris ? 'unpaid' : 'paid';
            $paidAt = $isStaticQris ? null : date('Y-m-d H:i:s');
            $stmt = $pdo->prepare('INSERT INTO app_transaksi (nomor_transaksi,pelanggan_id,user_id,subtotal,diskon,total,bayar,kembalian,status,paid_at,metode_pembayaran) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$number, $customerId, current_user()['id'], $total, 0, $total, $paid, $paid - $total, $status, $paidAt, $paymentMethod]);
            $transactionId = (int) $pdo->lastInsertId();
            foreach ($_SESSION['cart'] as $row) {
                $stmt = $pdo->prepare('INSERT INTO app_detail_transaksi (transaksi_id,barang_id,nama_barang,harga,qty,subtotal) VALUES (?,?,?,?,?,?)'); $stmt->execute([$transactionId, $row['id'], $row['nama'], $row['harga'], $row['qty'], $row['harga'] * $row['qty']]);
                $stmt = $pdo->prepare('UPDATE barang SET stok=stok-? WHERE id=? AND aktif=1 AND stok>=?'); $stmt->execute([$row['qty'], $row['id'], $row['qty']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Stok berubah. Silakan ulangi transaksi.');
            }
            if (!$isStaticQris) {
                $stmt = $pdo->prepare('INSERT INTO app_laporan_transaksi (transaksi_id,tanggal,total,status) VALUES (?,CURDATE(),?,?)'); $stmt->execute([$transactionId, $total, 'paid']);
            }
            if (!$isStaticQris && $customerId) {
                $stmt = $pdo->prepare('INSERT INTO app_log_pelanggan (pelanggan_id,transaksi_id,aksi) VALUES (?,?,?)'); $stmt->execute([$customerId, $transactionId, 'Pembelian']);
                $stmt = $pdo->prepare('INSERT INTO app_total_pelanggan (pelanggan_id,total_transaksi,total_belanja) VALUES (?,?,?) ON DUPLICATE KEY UPDATE total_transaksi=total_transaksi+1,total_belanja=total_belanja+VALUES(total_belanja)'); $stmt->execute([$customerId, 1, $total]);
            }
            log_activity($isStaticQris ? 'qris_pending' : 'transaksi', ($isStaticQris ? 'Menunggu pembayaran QRIS ' : 'Menyelesaikan transaksi ') . $number);
            $pdo->commit();
            $_SESSION['cart'] = [];
            if ($isStaticQris) {
                flash('success', 'Transaksi QRIS ' . $number . ' dibuat sebagai belum lunas. Stok telah dicadangkan; kasir perlu memeriksa pembayaran secara manual.');
            } else {
                flash('success', 'Transaksi ' . $number . ' berhasil dibayar. Kembalian: ' . money($paid - $total));
            }
            header('Location: ' . url('transaksi.php?view=' . $transactionId)); exit;
        }
        if ($action === 'add_code' && $isAjaxRequest) {
            $cart = array_values(array_map(static function (array $row): array {
                return ['id' => (int) $row['id'], 'nama' => $row['nama'], 'harga' => (float) $row['harga'], 'qty' => (int) $row['qty']];
            }, $_SESSION['cart']));
            $cartCount = array_sum(array_column($cart, 'qty'));
            $cartTotal = array_sum(array_map(static fn(array $row): float => $row['harga'] * $row['qty'], $cart));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'product' => [
                    'name' => $scannedItem['nama_barang'],
                    'barcode' => ($scannedItem['barcode'] ?? '') ?: $scannedItem['kode_barang'],
                    'category' => $scannedItem['kategori'] ?: 'Tanpa kategori',
                    'price' => (float) $scannedItem['harga'],
                    'stock' => (int) $scannedItem['stok'],
                    'unit' => $scannedItem['satuan'],
                    'quantity' => (int) $_SESSION['cart'][(int) $scannedItem['id']]['qty'],
                ],
                'cart' => $cart,
                'cartCount' => $cartCount,
                'cartTotal' => $cartTotal,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        if ($isAjaxRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

$products = $pdo->query('SELECT * FROM barang WHERE aktif = 1 AND stok > 0 ORDER BY nama_barang')->fetchAll();
$categoryOptions = ['' => 'Semua'];
foreach ($pdo->query("SELECT DISTINCT kategori FROM barang WHERE aktif=1 AND kategori IS NOT NULL AND kategori<>'' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN) as $category) {
    $categoryOptions[$category] = $category;
}
$selectedCategory = trim($_GET['kategori'] ?? '');
if (!array_key_exists($selectedCategory, $categoryOptions)) $selectedCategory = '';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="section-head"><div><h2>Pilih barang</h2></div></div>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <section class="barcode-panel">
        <div class="barcode-heading"><div><strong>Scan barcode produk</strong><p class="muted small">Gunakan scanner USB atau kamera perangkat. Kode yang dipindai harus sama dengan kode barang.</p></div><span class="barcode-symbol">▥</span></div>
        <form class="barcode-form" method="post" id="barcodeForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_code">
            <input class="input" id="barcodeInput" name="barcode" autocomplete="off" placeholder="Scan barcode atau ketik kode barang..." autofocus>
            <button class="btn btn-primary" type="submit">Tambah</button>
            <button class="btn btn-light" type="button" id="cameraButton">Scan Kamera</button>
        </form>
        <div class="scan-result" id="scanResult" role="status" aria-live="polite" hidden></div>
        <div class="scanner-camera-wrap" id="cameraWrap" hidden>
            <video id="barcodeVideo" playsinline muted></video>
            <div class="scanner-camera-actions"><span id="cameraStatus" class="muted small">Arahkan barcode ke kamera.</span><button type="button" class="btn btn-light btn-sm" id="stopCamera">Tutup kamera</button></div>
        </div>
    </section>
    <form class="search-row" method="get"><input class="input" name="q" placeholder="Cari nama barang..." value="<?= e($_GET['q'] ?? '') ?>"><button class="btn btn-light">Cari</button></form>
    <div class="category-tabs"><?php foreach ($categoryOptions as $categoryValue => $categoryLabel): ?><a class="category-tab <?= $selectedCategory === $categoryValue ? 'active' : '' ?>" href="<?= e(url('kasir.php' . ($categoryValue !== '' ? '?kategori=' . rawurlencode($categoryValue) : ''))) ?>"><?= e($categoryLabel) ?></a><?php endforeach; ?></div>
    <div class="product-grid">
    <?php $q = strtolower(trim($_GET['q'] ?? '')); $shownProducts = 0; foreach ($products as $product): if ($selectedCategory !== '' && strtolower($product['kategori'] ?? '') !== strtolower($selectedCategory)) continue; if ($q && strpos(strtolower($product['nama_barang'] . ' ' . $product['kode_barang'] . ' ' . ($product['barcode'] ?? '')), $q) === false) continue; $shownProducts++; ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="barang_id" value="<?= e($product['id']) ?>"><button class="product-card" type="submit" style="width:100%;text-align:left"><?php if (!empty($product['foto'])): ?><img class="product-card-image" src="<?= e(url('assets/uploads/barang/' . $product['foto'])) ?>" alt="<?= e($product['nama_barang']) ?>"><?php else: ?><span class="product-card-image placeholder">▤</span><?php endif; ?><strong><?= e($product['nama_barang']) ?></strong><div class="price"><?= money($product['harga']) ?></div><div class="stock"><?= e($product['kategori'] ?: 'Tanpa kategori') ?> · Stok <?= e($product['stok']) ?></div><span class="barcode-code">BARCODE: <?= e(($product['barcode'] ?? '') ?: $product['kode_barang']) ?></span></button></form><?php endforeach; if (!$shownProducts): ?><p class="muted">Belum ada barang pada kategori ini.</p><?php endif; ?></div>
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
    let reader = null, controls = null, submitting = false;

    function stopCamera() {
        if (controls) controls.stop();
        controls = null;
        reader = null;
        cameraWrap.hidden = true;
        cameraButton.disabled = false;
    }

    function formatMoney(value) {
        return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
    }

    function updateCart(data) {
        const badge = document.getElementById('cartCountBadge');
        if (badge) {
            badge.textContent = data.cartCount;
            badge.hidden = data.cartCount === 0;
        }
        const count = document.getElementById('cartPopoverCount');
        if (count) count.textContent = data.cartCount + ' item';
        const total = document.getElementById('cartPopoverTotal');
        if (total) total.textContent = formatMoney(data.cartTotal);
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
        if (empty) empty.hidden = data.cartCount > 0;
        const totalRow = document.getElementById('cartPopoverTotalRow');
        if (totalRow) totalRow.hidden = data.cartCount === 0;
        [document.getElementById('cartUpdateForm'), document.getElementById('cartCheckoutForm'), document.getElementById('cartClearForm')].forEach(element => {
            if (element) element.hidden = data.cartCount === 0;
        });
        const quantityInputs = document.getElementById('cartQuantityInputs');
        if (quantityInputs) {
            quantityInputs.replaceChildren();
            data.cart.forEach(item => {
                const label = document.createElement('label');
                const name = document.createElement('span');
                name.textContent = item.nama;
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0';
                input.name = 'qty[' + item.id + ']';
                input.value = item.qty;
                label.append(name, input);
                quantityInputs.append(label);
            });
        }
        const amount = document.getElementById('checkoutAmount');
        if (amount) amount.min = data.cartTotal;
    }

    async function submitBarcode() {
        if (submitting || !input.value.trim()) return;
        submitting = true;
        result.hidden = true;
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Barcode tidak dapat diproses.');
            updateCart(data);
            result.className = 'scan-result success';
            result.textContent = data.product.name + ' · ' + data.product.barcode + ' · ' + data.product.category +
                ' · ' + formatMoney(data.product.price) + ' · Stok ' + data.product.stock + ' ' + data.product.unit +
                ' · Dalam keranjang ' + data.product.quantity + ' · Total ' + formatMoney(data.cartTotal);
            input.value = '';
            input.focus();
        } catch (error) {
            result.className = 'scan-result error';
            result.textContent = error.message || 'Pencarian barcode gagal. Silakan coba lagi.';
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
            controls = await reader.decodeFromConstraints({ video: { facingMode: { ideal: 'environment' } }, audio: false }, video, function (decoded, scanError) {
                if (decoded) {
                    input.value = decoded.getText().trim();
                    status.textContent = 'Barcode ditemukan. Menambahkan barang...';
                    stopCamera();
                    submitBarcode();
                } else if (scanError && scanError.name !== 'NotFoundException') {
                    status.textContent = 'Kamera aktif. Arahkan barcode dengan jelas ke kamera.';
                }
            });
            status.textContent = 'Kamera aktif. Arahkan barcode ke kamera.';
        } catch (error) {
            stopCamera();
            cameraWrap.hidden = false;
            status.textContent = 'Kamera tidak dapat dibuka. Izinkan akses kamera dan gunakan localhost/HTTPS. Scanner USB atau input manual tetap tersedia.';
        }
    });
    stopButton.addEventListener('click', stopCamera);
    window.addEventListener('pagehide', stopCamera);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
