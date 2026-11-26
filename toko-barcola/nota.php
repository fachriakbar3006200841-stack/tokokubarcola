<?php
require_once __DIR__ . '/includes/auth.php';
require_staff();

$id = (int) ($_GET['view'] ?? 0);
$stmt = db()->prepare('SELECT t.*,u.nama kasir,u.username kasir_username,p.nama_pelanggan pelanggan FROM app_transaksi t JOIN app_users u ON u.id=t.user_id LEFT JOIN pelanggan p ON p.id_pelanggan=t.pelanggan_id WHERE t.id=?');
$stmt->execute([$id]);
$transaction = $stmt->fetch();
if (!$transaction) { http_response_code(404); exit('Transaksi tidak ditemukan.'); }
$stmt = db()->prepare('SELECT * FROM app_detail_transaksi WHERE transaksi_id=? ORDER BY id');
$stmt->execute([$id]);
$details = $stmt->fetchAll();

function receipt_amount(mixed $amount): string
{
    return number_format((float) $amount, 0, ',', '.');
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nota <?= e($transaction['nomor_transaksi']) ?></title>
    <style>
        *{box-sizing:border-box}
        html,body{margin:0;padding:0;color:#111;font-family:"Courier New",monospace;font-size:10px}
        body{background:#eef1f5}
        .receipt{width:58mm;margin:12px auto;background:#fff;padding:3mm 2mm 4mm;overflow-wrap:break-word}
        .center{text-align:center}.store-logo{display:block;width:27mm;height:19mm;margin:0 auto 1mm}.brand{font-size:15px;font-weight:700;letter-spacing:.2px;margin:0}.store-subtitle{display:flex;align-items:center;gap:4px;margin:1mm 0;font-size:9px;font-weight:700;letter-spacing:1.2px}.store-subtitle:before,.store-subtitle:after{content:"";height:1px;background:#111;flex:1}.address{font-size:10px;margin:1mm 0 2mm}
        .line{border-top:1px dashed #111;margin:2mm 0}
        .meta{display:flex;justify-content:space-between;gap:3px;font-size:9px;white-space:nowrap;line-height:1.35}.meta span{min-width:0;overflow:hidden;text-overflow:ellipsis}.meta span:last-child{text-align:right}
        .items-head,.item{display:grid;grid-template-columns:minmax(0,1fr) 4ch 8ch 8ch;column-gap:1px;align-items:start;line-height:1.3}.items-head{font-weight:700;margin-bottom:1mm}.item{margin:1mm 0}.item-name{min-width:0;overflow-wrap:anywhere}.qty{text-align:center}.price,.item-total{text-align:right;white-space:nowrap}
        .summary{width:100%;margin-left:auto}.summary-row{display:flex;justify-content:space-between;gap:4px;margin:1mm 0;white-space:nowrap;line-height:1.3}.summary-row span:last-child{min-width:17mm;text-align:right}.grand-total{font-size:12px;font-weight:700;margin-top:1mm}.thanks{text-align:center;margin:2mm 0;font-size:9px;font-weight:700}.footer{text-align:center;font-size:9px;line-height:1.35;margin:1mm 0 0}
        .receipt-qris{text-align:center;margin:2mm 0;padding-top:2mm;border-top:1px dashed #111}.receipt-qris img{display:block;width:44mm;height:44mm;object-fit:contain;margin:1mm auto}.receipt-qris strong{font-size:10px}.receipt-qris p{font-size:8px;line-height:1.3;margin:1mm 0}
        .receipt-barcode{text-align:center;margin:2mm 0 1mm}.receipt-barcode svg{display:block;width:52mm;height:auto;max-height:15mm;margin:0 auto}.receipt-barcode small{font-size:9px}
        .print-actions{text-align:center;margin:12px auto}.print-actions button,.print-actions a{border:0;border-radius:5px;background:#2764e7;color:#fff;padding:8px 12px;text-decoration:none;font:12px Segoe UI,Arial,sans-serif;cursor:pointer}.print-actions a{background:#758297;margin-left:5px}
        @page{size:80mm auto;margin:0}
        @media print{html,body{width:80mm;margin:0;padding:0;background:#fff}.receipt{width:58mm;margin:0 auto;padding:3mm 2mm 4mm;background:#fff;overflow:visible}.print-actions{display:none}}
    </style>
</head>
<body>
<main class="receipt">
    <header class="center">
        <svg class="store-logo" viewBox="0 0 120 86" role="img" aria-label="Logo keranjang FACHRI AKBAR 13">
            <path d="M13 15h13l9 43h49l12-31H32" fill="none" stroke="#111" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M41 39h48M45 51h39M53 28l5 31M75 28l-2 31" fill="none" stroke="#111" stroke-width="5" stroke-linecap="round"/>
            <circle cx="47" cy="72" r="6" fill="#111"/><circle cx="79" cy="72" r="6" fill="#111"/>
            <path d="M12 47C2 31 14 12 35 6M105 7c14 13 10 32-6 44" fill="none" stroke="#111" stroke-width="5" stroke-linecap="round"/>
        </svg>
        <p class="brand">FACHRI AKBAR 13</p>
        <p class="store-subtitle"><span>POINT OF SALE</span></p>
        <p class="address">NOTA PENJUALAN</p>
    </header>
    <div class="line"></div>
    <div class="meta"><span><?= e(date('d/m/Y H:i', strtotime($transaction['created_at']))) ?></span><span><?= e($transaction['nomor_transaksi']) ?></span></div>
    <div class="meta"><span>KASIR: <?= e(strtoupper($transaction['kasir_username'] ?: $transaction['kasir'])) ?></span><span><?= e(strtoupper($transaction['pelanggan'] ?: 'UMUM')) ?></span></div>
    <div class="line"></div>
    <div class="items-head"><span>BARANG</span><span class="qty">QTY</span><span class="price">HARGA</span><span class="item-total">TOTAL</span></div>
    <?php foreach ($details as $detail): ?><div class="item"><span class="item-name"><?= e($detail['nama_barang']) ?></span><span class="qty"><?= e($detail['qty']) ?></span><span class="price"><?= receipt_amount($detail['harga']) ?></span><span class="item-total"><?= receipt_amount($detail['subtotal']) ?></span></div><?php endforeach; ?>
    <div class="line"></div>
    <section class="summary">
        <div class="summary-row"><span>HARGA JUAL :</span><span><?= receipt_amount($transaction['subtotal']) ?></span></div>
        <div class="summary-row"><span>DISKON :</span><span><?= receipt_amount($transaction['diskon']) ?></span></div>
        <div class="line"></div>
        <div class="summary-row grand-total"><span>TOTAL :</span><span>Rp<?= receipt_amount($transaction['total']) ?></span></div>
        <?php if (($transaction['metode_pembayaran'] ?? 'tunai') === 'qris_statis' && $transaction['status'] === 'unpaid'): ?>
            <div class="summary-row"><span>STATUS :</span><span>MENUNGGU QRIS</span></div>
        <?php else: ?>
            <div class="summary-row"><span>METODE :</span><span><?= ($transaction['metode_pembayaran'] ?? 'tunai') === 'qris_statis' ? 'QRIS' : 'TUNAI' ?></span></div>
            <div class="summary-row"><span>BAYAR :</span><span><?= receipt_amount($transaction['bayar']) ?></span></div>
            <div class="summary-row"><span>KEMBALI :</span><span><?= receipt_amount($transaction['kembalian']) ?></span></div>
        <?php endif; ?>
    </section>
    <div class="line"></div><p class="thanks">TERIMA KASIH. SELAMAT BELANJA KEMBALI</p>
    <?php if (($transaction['metode_pembayaran'] ?? 'tunai') === 'qris_statis' && $transaction['status'] === 'unpaid'): ?>
        <section class="receipt-qris">
            <strong>PEMBAYARAN QRIS — BELUM LUNAS</strong>
            <img src="<?= e(url('assets/images/qris-toko-fachri.png')) ?>" alt="QRIS statis merchant Tokofachri Service">
            <p>Merchant: Tokofachri, Service</p>
            <p>Masukkan nominal tepat Rp<?= receipt_amount($transaction['total']) ?> pada aplikasi pembayaran.</p>
            <p>QRIS statis tetap aktif walaupun transaksi dibatalkan. Pembayaran harus diperiksa dan dikonfirmasi kasir. Jangan membayar lagi jika sudah membayar.</p>
        </section>
    <?php endif; ?>
    <div class="receipt-barcode"><svg id="transactionBarcode" aria-label="Barcode nomor transaksi"></svg><small><?= e($transaction['nomor_transaksi']) ?></small></div>
    <p class="footer">Simpan nota ini sebagai bukti pembayaran.<br>FACHRI AKBAR 13</p>
</main>
<div class="print-actions"><button type="button" onclick="window.print()">Cetak nota</button><a href="<?= e(url('transaksi.php?view=' . $transaction['id'])) ?>">Kembali</a></div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
if (typeof JsBarcode === 'function') {
    JsBarcode(document.getElementById('transactionBarcode'), <?= json_encode($transaction['nomor_transaksi'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, {
        format: 'CODE128', width: 1.5, height: 36, displayValue: false, margin: 0
    });
}
</script>
<?php if (($_GET['print'] ?? '') === '1'): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},350)});</script><?php endif; ?>
</body>
</html>
