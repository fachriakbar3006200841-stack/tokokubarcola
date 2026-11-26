<?php
require_once __DIR__ . '/includes/auth.php';
require_staff();

$pdo = db();
$pageTitle = 'Transaksi';
$activePage = 'transaksi';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'delete') {
            if (!is_admin()) {
                throw new RuntimeException('Kasir tidak memiliki akses menghapus transaksi.');
            }
            $stmt = $pdo->prepare('SELECT status,metode_pembayaran,nomor_transaksi FROM app_transaksi WHERE id=?');
            $stmt->execute([$id]);
            $transaction = $stmt->fetch();
            if (!$transaction) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($transaction['status'] === 'paid') {
                throw new RuntimeException('Transaksi yang sudah dibayar tidak dapat dihapus, termasuk oleh admin.');
            }
            if ($transaction['metode_pembayaran'] === 'qris_statis' && $transaction['status'] === 'unpaid') {
                throw new RuntimeException('Transaksi QRIS belum lunas harus dibatalkan agar stok yang dicadangkan dikembalikan.');
            }
            $pdo->prepare('DELETE FROM app_transaksi WHERE id=?')->execute([$id]);
            log_activity('hapus_transaksi', 'Menghapus transaksi ' . $transaction['nomor_transaksi']);
            flash('success', 'Transaksi berhasil dihapus.');
            header('Location: ' . url('transaksi.php'));
            exit;
        }

        if ($action === 'confirm_qris' || $action === 'cancel_qris') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id,status,metode_pembayaran,total,pelanggan_id,nomor_transaksi FROM app_transaksi WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $transaction = $stmt->fetch();
            if (!$transaction || $transaction['status'] !== 'unpaid' || $transaction['metode_pembayaran'] !== 'qris_statis') {
                throw new RuntimeException('Transaksi QRIS ini tidak lagi menunggu pembayaran.');
            }

            if ($action === 'confirm_qris') {
                $receivedAmount = trim((string) ($_POST['nominal_diterima'] ?? ''));
                $amountInMinorUnits = static function (string $amount): ?int {
                    if (!preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $amount)) {
                        return null;
                    }
                    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
                };
                $receivedMinorUnits = $amountInMinorUnits($receivedAmount);
                $expectedMinorUnits = $amountInMinorUnits((string) $transaction['total']);
                if ($receivedMinorUnits === null || $expectedMinorUnits === null || $receivedMinorUnits !== $expectedMinorUnits) {
                    throw new RuntimeException('Nominal yang dikonfirmasi harus sama persis dengan total transaksi: ' . money($transaction['total']) . '.');
                }
                $stmt = $pdo->prepare("UPDATE app_transaksi SET status='paid',bayar=total,kembalian=0,paid_at=NOW() WHERE id=? AND status='unpaid' AND metode_pembayaran='qris_statis'");
                $stmt->execute([$id]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Status transaksi berubah. Muat ulang halaman.');
                }
                $stmt = $pdo->prepare("INSERT INTO app_laporan_transaksi (transaksi_id,tanggal,total,status) VALUES (?,CURDATE(),?,'paid')");
                $stmt->execute([$id, $transaction['total']]);
                if ($transaction['pelanggan_id']) {
                    $stmt = $pdo->prepare("INSERT INTO app_log_pelanggan (pelanggan_id,transaksi_id,aksi) VALUES (?,?,'Pembelian')");
                    $stmt->execute([$transaction['pelanggan_id'], $id]);
                    $stmt = $pdo->prepare('INSERT INTO app_total_pelanggan (pelanggan_id,total_transaksi,total_belanja) VALUES (?,?,?) ON DUPLICATE KEY UPDATE total_transaksi=total_transaksi+1,total_belanja=total_belanja+VALUES(total_belanja)');
                    $stmt->execute([$transaction['pelanggan_id'], 1, $transaction['total']]);
                }
            } else {
                $stmt = $pdo->prepare('SELECT barang_id,qty FROM app_detail_transaksi WHERE transaksi_id=?');
                $stmt->execute([$id]);
                $details = $stmt->fetchAll();
                $stmt = $pdo->prepare("UPDATE app_transaksi SET status='cancelled' WHERE id=? AND status='unpaid' AND metode_pembayaran='qris_statis'");
                $stmt->execute([$id]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Status transaksi berubah. Muat ulang halaman.');
                }
                $restoreStock = $pdo->prepare('UPDATE barang SET stok=stok+? WHERE id=?');
                foreach ($details as $detail) {
                    $restoreStock->execute([(int) $detail['qty'], (int) $detail['barang_id']]);
                    if ($restoreStock->rowCount() !== 1) {
                        throw new RuntimeException('Stok barang untuk transaksi ini tidak dapat dikembalikan.');
                    }
                }
            }

            log_activity(
                $action === 'confirm_qris' ? 'qris_manual_paid' : 'qris_cancelled',
                ($action === 'confirm_qris' ? 'Kasir mengonfirmasi pembayaran QRIS ' : 'Membatalkan transaksi QRIS ') . $transaction['nomor_transaksi']
            );
            $pdo->commit();
            $message = $action === 'confirm_qris'
                ? 'Pembayaran QRIS sebesar ' . money($transaction['total']) . ' berhasil dikonfirmasi. Transaksi sudah lunas.'
                : 'Transaksi QRIS dibatalkan dan stok yang dicadangkan telah dikembalikan.';
            flash('success', $message);
            header('Location: ' . url('transaksi.php?view=' . $id));
            exit;
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$view = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare('SELECT t.*,u.nama kasir,p.nama_pelanggan pelanggan FROM app_transaksi t JOIN app_users u ON u.id=t.user_id LEFT JOIN pelanggan p ON p.id_pelanggan=t.pelanggan_id WHERE t.id=?');
    $stmt->execute([(int) $_GET['view']]);
    $view = $stmt->fetch();
    if ($view) {
        $stmt = $pdo->prepare('SELECT * FROM app_detail_transaksi WHERE transaksi_id=?');
        $stmt->execute([$view['id']]);
        $view['details'] = $stmt->fetchAll();
    }
}

$query = trim((string) ($_GET['q'] ?? ''));
$stmt = $pdo->prepare('SELECT t.*,u.nama kasir,p.nama_pelanggan pelanggan FROM app_transaksi t JOIN app_users u ON u.id=t.user_id LEFT JOIN pelanggan p ON p.id_pelanggan=t.pelanggan_id WHERE t.nomor_transaksi LIKE ? OR COALESCE(p.nama_pelanggan,\'Umum\') LIKE ? ORDER BY t.created_at DESC');
$like = '%' . $query . '%';
$stmt->execute([$like, $like]);
$rows = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<?php if ($view): ?>
    <div class="no-print" style="margin-bottom:15px">
        <a class="btn btn-primary" target="_blank" href="<?= e(url('nota.php?view=' . $view['id'] . '&print=1')) ?>">Cetak nota 58 mm</a>
    </div>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($view): ?>
    <section class="card" style="max-width:760px">
        <div class="section-head">
            <div>
                <h2>Detail <?= e($view['nomor_transaksi']) ?></h2>
                <p class="muted small"><?= e(date('d F Y, H:i', strtotime($view['created_at']))) ?> · Kasir <?= e($view['kasir']) ?> · <?= e(ucfirst($view['status'])) ?></p>
            </div>
            <div class="toolbar no-print">
                <a class="btn btn-light btn-sm" target="_blank" href="<?= e(url('nota.php?view=' . $view['id'] . '&print=1')) ?>">Cetak nota</a>
                <a class="btn btn-light btn-sm" href="<?= e(url('transaksi.php')) ?>">Kembali</a>
            </div>
        </div>
        <?php if ($view['metode_pembayaran'] === 'qris_statis' && $view['status'] === 'unpaid'): ?>
            <div class="card" style="text-align:center;margin:15px 0;padding:18px">
                <h3>Menunggu pembayaran QRIS</h3>
                <p>Merchant: <strong>Tokofachri, Service</strong></p>
                <img src="<?= e(url('assets/images/qris-toko-fachri.png')) ?>" alt="QRIS statis merchant Tokofachri Service" style="display:block;width:min(100%,280px);height:auto;margin:12px auto">
                <p>Pelanggan harus memasukkan nominal <strong><?= e(money($view['total'])) ?></strong> secara manual. QR ini statis dan tidak memverifikasi pembayaran otomatis.</p>
                <p class="muted small">Periksa dana masuk pada aplikasi/dashboard merchant DANA. Konfirmasikan lunas hanya setelah pembayaran dengan nominal yang tepat terlihat. Stok barang saat ini dicadangkan. Membatalkan transaksi tidak menonaktifkan QR merchant; batalkan hanya jika pelanggan belum membayar.</p>
                <div class="toolbar no-print" style="justify-content:center">
                    <form method="post" style="display:grid;gap:8px;max-width:320px" onsubmit="return confirm('Pastikan dana dengan nominal yang sama sudah masuk ke merchant. Konfirmasi lunas?')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="confirm_qris">
                        <input type="hidden" name="id" value="<?= e($view['id']) ?>">
                        <label for="qrisReceivedAmount">Nominal diterima (cocokkan dengan aplikasi DANA)</label>
                        <input class="input" id="qrisReceivedAmount" type="number" name="nominal_diterima" min="<?= e(number_format((float) $view['total'], 2, '.', '')) ?>" max="<?= e(number_format((float) $view['total'], 2, '.', '')) ?>" step="0.01" required>
                        <button class="btn btn-primary" type="submit">Dana sudah diterima — konfirmasi lunas</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Pastikan pelanggan belum membayar. QRIS statis tetap aktif meskipun transaksi dibatalkan. Batalkan transaksi dan kembalikan stok?')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cancel_qris">
                        <input type="hidden" name="id" value="<?= e($view['id']) ?>">
                        <button class="btn btn-light" type="submit">Batalkan QRIS</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Barang</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
                <tbody>
                <?php foreach ($view['details'] as $detail): ?>
                    <tr><td><?= e($detail['nama_barang']) ?></td><td><?= money($detail['harga']) ?></td><td><?= e($detail['qty']) ?></td><td class="amount"><?= money($detail['subtotal']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="total-box" style="max-width:340px;margin-left:auto">
            <div class="total-line"><span>Metode pembayaran</span><strong><?= $view['metode_pembayaran'] === 'qris_statis' ? 'QRIS statis' : 'Tunai' ?></strong></div>
            <div class="total-line"><span>Subtotal</span><strong><?= money($view['subtotal']) ?></strong></div>
            <div class="total-line grand"><span>Total</span><span><?= money($view['total']) ?></span></div>
            <?php if ($view['status'] === 'paid'): ?>
                <div class="total-line"><span>Bayar</span><span><?= money($view['bayar']) ?></span></div>
                <div class="total-line"><span>Kembalian</span><strong><?= money($view['kembalian']) ?></strong></div>
            <?php elseif ($view['status'] === 'unpaid'): ?>
                <div class="total-line"><span>Status pembayaran</span><strong>Belum lunas</strong></div>
            <?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <section class="card">
        <div class="section-head">
            <div><h2>Riwayat transaksi</h2><p class="muted small">Transaksi berstatus paid tersimpan sebagai riwayat dan tidak dapat dihapus.</p></div>
            <a class="btn btn-primary" href="<?= e(url('kasir.php')) ?>">+ Transaksi baru</a>
        </div>
        <form class="search-row no-print" method="get">
            <input class="input" name="q" value="<?= e($query) ?>" placeholder="Cari nomor transaksi atau pelanggan...">
            <button class="btn btn-light">Cari</button>
        </form>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Transaksi</th><th>Pelanggan</th><th>Kasir</th><th>Total</th><th>Metode</th><th>Status</th><th class="no-print">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e($row['nomor_transaksi']) ?></strong><div class="muted small"><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></div></td>
                        <td><?= e($row['pelanggan'] ?: 'Umum') ?></td>
                        <td><?= e($row['kasir']) ?></td>
                        <td class="amount"><?= money($row['total']) ?></td>
                        <td><?= $row['metode_pembayaran'] === 'qris_statis' ? 'QRIS statis' : 'Tunai' ?></td>
                        <td><span class="badge <?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td>
                        <td class="no-print">
                            <div class="actions">
                                <a class="btn btn-light btn-sm" href="<?= e(url('transaksi.php?view=' . $row['id'])) ?>">Detail</a>
                                <?php if (is_admin() && $row['status'] !== 'paid' && !($row['metode_pembayaran'] === 'qris_statis' && $row['status'] === 'unpaid')): ?>
                                    <form method="post" onsubmit="return confirm('Hapus transaksi ini?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                        <button class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
