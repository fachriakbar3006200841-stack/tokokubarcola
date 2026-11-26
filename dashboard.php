<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_login();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$pdo = db();
$today = date('Y-m-d');

// Statistik penjualan hari ini
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(total), 0) AS sales,
        COUNT(*) AS total_transactions
    FROM transactions
    WHERE status = 'completed'
      AND DATE(created_at) = ?
");
$stmt->execute([$today]);
$todayStats = $stmt->fetch(PDO::FETCH_ASSOC);

$todaySales = $todayStats['sales'] ?? 0;
$todayCount = $todayStats['total_transactions'] ?? 0;

// Total produk aktif
$productCount = $pdo->query("
    SELECT COUNT(*)
    FROM products
    WHERE is_active = 1
      AND deleted_at IS NULL
")->fetchColumn();

// Produk dengan stok menipis
$lowStock = $pdo->query("
    SELECT COUNT(*)
    FROM products
    WHERE is_active = 1
      AND deleted_at IS NULL
      AND track_stock = 1
      AND stock_qty <= min_stock
")->fetchColumn();

// Transaksi terbaru
$recent = $pdo->query("
    SELECT
        t.id,
        t.invoice_no,
        t.total,
        t.status,
        t.created_at,
        u.name AS kasir
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    ORDER BY t.created_at DESC
    LIMIT 7
")->fetchAll(PDO::FETCH_ASSOC);

// Produk terlaris
$topProducts = $pdo->query("
    SELECT
        td.product_name,
        SUM(td.qty) AS qty
    FROM transaction_details td
    INNER JOIN transactions t
        ON t.id = td.transaction_id
    WHERE t.status = 'completed'
    GROUP BY td.product_id, td.product_name
    ORDER BY qty DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<div class="grid stats-grid">

    <div class="card stat-card">
        <div class="stat-icon">Rp</div>
        <div class="label">Penjualan hari ini</div>
        <div class="number"><?= money($todaySales) ?></div>
    </div>

    <div class="card stat-card green">
        <div class="stat-icon">✓</div>
        <div class="label">Transaksi hari ini</div>
        <div class="number"><?= e($todayCount) ?></div>
    </div>

    <div class="card stat-card orange">
        <div class="stat-icon">▤</div>
        <div class="label">Total produk</div>
        <div class="number"><?= e($productCount) ?></div>
    </div>

    <div class="card stat-card red">
        <div class="stat-icon">!</div>
        <div class="label">Stok menipis</div>
        <div class="number"><?= e($lowStock) ?></div>
    </div>

</div>

<div class="grid two-col">

    <section class="card">

        <div class="section-head">
            <h2>Transaksi terbaru</h2>

            <a class="btn btn-light btn-sm"
               href="<?= e(url('transaksi.php')) ?>">
                Lihat semua
            </a>
        </div>

        <div class="table-wrap">
            <table class="table">

                <thead>
                    <tr>
                        <th>Nomor transaksi</th>
                        <th>Kasir</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($recent as $row): ?>
                        <tr>

                            <td>
                                <strong>
                                    <?= e($row['invoice_no']) ?>
                                </strong>

                                <div class="muted small">
                                    <?= e(date(
                                        'd/m/Y H:i',
                                        strtotime($row['created_at'])
                                    )) ?>
                                </div>
                            </td>

                            <td>
                                <?= e($row['kasir'] ?? 'Tidak diketahui') ?>
                            </td>

                            <td class="amount">
                                <?= money($row['total']) ?>
                            </td>

                            <td>
                                <span class="badge <?= e($row['status']) ?>">
                                    <?= e(
                                        $row['status'] === 'completed'
                                            ? 'Selesai'
                                            : 'Dibatalkan'
                                    ) ?>
                                </span>
                            </td>

                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$recent): ?>
                        <tr>
                            <td colspan="4" class="empty">
                                Belum ada transaksi.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

    <section class="card">

        <div class="section-head">
            <h2>Produk terlaris</h2>
            <span class="muted small">Semua waktu</span>
        </div>

        <div class="bar-list">

            <?php
            $max = max(
                1,
                (int) ($topProducts[0]['qty'] ?? 1)
            );
            ?>

            <?php foreach ($topProducts as $item): ?>

                <div class="bar-row">

                    <span>
                        <?= e($item['product_name']) ?>
                    </span>

                    <div class="bar">
                        <i style="width: <?= min(
                            100,
                            round((int) $item['qty'] / $max * 100)
                        ) ?>%"></i>
                    </div>

                    <strong>
                        <?= e($item['qty']) ?> terjual
                    </strong>

                </div>

            <?php endforeach; ?>

            <?php if (!$topProducts): ?>
                <p class="muted">
                    Belum ada data penjualan.
                </p>
            <?php endif; ?>

        </div>

    </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
