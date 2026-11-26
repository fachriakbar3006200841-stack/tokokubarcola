<?php
require_once __DIR__ . '/auth.php';
require_login();
$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
$flashMessages = take_flash();
$cartItems = $_SESSION['cart'] ?? [];
$cartCount = 0;
$cartTotal = 0;
foreach ($cartItems as $cartItem) {
    $cartCount += (int) ($cartItem['qty'] ?? 0);
    $cartTotal += (float) ($cartItem['harga'] ?? 0) * (int) ($cartItem['qty'] ?? 0);
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/theme.css')) ?>">
</head>
<body class="<?= !empty($barcodeItem) ? 'print-barcode' : '' ?>">
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e(url(home_page())) ?>"><span class="brand-mark">TK</span><span><strong>TokoKu</strong></span></a>
        <div class="user-chip"><div class="avatar"><?= e(strtoupper(substr($user['nama'], 0, 1))) ?></div><div><strong><?= e($user['nama']) ?></strong><small><?= e(ucfirst($user['role'])) ?></small></div></div>
        <nav>
            <?php if (is_staff()): ?>
            <small class="nav-label">MENU UTAMA</small>
            <a class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>"><span>▦</span> Dashboard</a>
            <a class="nav-link <?= $activePage === 'kasir' ? 'active' : '' ?>" href="<?= e(url('kasir.php')) ?>"><span>▣</span> Kasir</a>
            <a class="nav-link <?= $activePage === 'barang' ? 'active' : '' ?>" href="<?= e(url('barang.php')) ?>"><span>▤</span> Data Barang</a>
            <a class="nav-link <?= $activePage === 'transaksi' ? 'active' : '' ?>" href="<?= e(url('transaksi.php')) ?>"><span>◷</span> Transaksi</a>
            <?php if (is_admin()): ?>
                <small class="nav-label">ADMINISTRASI</small>
                <a class="nav-link <?= $activePage === 'laporan' ? 'active' : '' ?>" href="<?= e(url('laporan.php')) ?>"><span>▥</span> Laporan</a>
                <a class="nav-link <?= $activePage === 'log' ? 'active' : '' ?>" href="<?= e(url('log.php')) ?>"><span>◌</span> Log Aktivitas</a>
            <?php endif; ?>
            <?php else: ?>
                <small class="nav-label">MENU</small>
                <a class="nav-link active" href="<?= e(url('barang.php')) ?>"><span>▤</span> Katalog Barang</a>
            <?php endif; ?>
        </nav>
        <a class="logout-link" href="<?= e(url('logout.php')) ?>"><span>↪</span> Keluar</a>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div><p class="eyebrow"><?= e(date('l, d F Y')) ?></p><h1><?= e($pageTitle) ?></h1></div>
            <div class="topbar-actions">
                <?php if (is_staff()): ?><details class="cart-menu">
                    <summary class="cart-trigger" title="Lihat keranjang">
                        <span class="cart-icon">🛒</span>
                        <span class="cart-count" id="cartCountBadge" <?= $cartCount ? '' : 'hidden' ?>><?= e($cartCount) ?></span>
                    </summary>
                    <div class="cart-popover">
                        <div class="cart-popover-head"><strong>Keranjang</strong><span class="muted small" id="cartPopoverCount"><?= e($cartCount) ?> item</span></div>
                        <div class="cart-popover-items" id="cartPopoverItems"><?php foreach ($cartItems as $cartItem): ?><div class="cart-popover-item"><div><strong><?= e($cartItem['nama']) ?></strong><small><?= e(money($cartItem['harga'])) ?> × <?= e($cartItem['qty']) ?></small></div><strong><?= e(money((float) $cartItem['harga'] * (int) $cartItem['qty'])) ?></strong></div><?php endforeach; ?></div>
                        <div class="cart-empty" id="cartEmptyMessage" <?= $cartItems ? 'hidden' : '' ?>>Keranjang masih kosong.</div>
                        <div class="cart-popover-total" id="cartPopoverTotalRow" <?= $cartItems ? '' : 'hidden' ?>><span>Total</span><strong id="cartPopoverTotal"><?= e(money($cartTotal)) ?></strong></div>
                        <form class="cart-quick-form" id="cartUpdateForm" method="post" action="<?= e(url('kasir.php')) ?>" <?= $cartItems ? '' : 'hidden' ?>><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><div id="cartQuantityInputs"><?php foreach ($cartItems as $cartItem): ?><label><span><?= e($cartItem['nama']) ?></span><input type="number" min="0" name="qty[<?= e($cartItem['id']) ?>]" value="<?= e($cartItem['qty']) ?>"></label><?php endforeach; ?></div><button class="btn btn-light btn-sm" type="submit">Perbarui jumlah</button></form>
                        <form class="cart-quick-form" id="cartCheckoutForm" method="post" action="<?= e(url('kasir.php')) ?>" <?= $cartItems ? '' : 'hidden' ?>>
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="checkout">
                            <label for="checkoutPaymentMethod">Metode pembayaran</label>
                            <select class="input" id="checkoutPaymentMethod" name="metode_pembayaran">
                                <option value="tunai">Tunai</option>
                                <option value="qris_statis">QRIS statis</option>
                            </select>
                            <div id="checkoutCashFields">
                                <label for="checkoutAmount">Bayar tunai</label>
                                <input class="input" id="checkoutAmount" type="number" min="<?= e($cartTotal) ?>" name="bayar" required placeholder="Nominal pembayaran">
                            </div>
                            <p id="checkoutQrisNote" class="muted small" hidden>QRIS ini statis: masukkan nominal yang tepat di aplikasi, lalu kasir wajib memeriksa pembayaran sebelum mengonfirmasi lunas.</p>
                            <button class="btn btn-primary cart-open-btn" type="submit">Buat transaksi</button>
                        </form>
                        <form id="cartClearForm" method="post" action="<?= e(url('kasir.php')) ?>" <?= $cartItems ? '' : 'hidden' ?>><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button class="btn btn-danger btn-sm" type="submit">Kosongkan keranjang</button></form>
                        <a class="btn btn-light cart-open-btn" href="<?= e(url('kasir.php')) ?>">Buka halaman kasir</a>
                    </div>
                </details><?php endif; ?>
                <div class="topbar-user"><?= e($user['nama']) ?><span class="role-pill <?= e($user['role']) ?>"><?= e(ucfirst($user['role'])) ?></span></div>
            </div>
        </header>
        <?php foreach ($flashMessages as $flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
        <script>
        (function () {
            const method = document.getElementById('checkoutPaymentMethod');
            const cashFields = document.getElementById('checkoutCashFields');
            const amount = document.getElementById('checkoutAmount');
            const qrisNote = document.getElementById('checkoutQrisNote');
            if (!method || !cashFields || !amount || !qrisNote) return;
            function updatePaymentFields() {
                const isQris = method.value === 'qris_statis';
                cashFields.hidden = isQris;
                amount.required = !isQris;
                amount.disabled = isQris;
                qrisNote.hidden = !isQris;
            }
            method.addEventListener('change', updatePaymentFields);
            updatePaymentFields();
        })();
        </script>
