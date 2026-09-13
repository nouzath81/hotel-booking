<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$sale = posGetSale($pdo, $id);

if (!$sale) {
    die('Sale not found.');
}

$pageTitle = 'Receipt ' . $sale['sale_no'];
$items = posGetSaleItems($pdo, $id);
$linkedBooking = null;
if (!empty($sale['booking_id'])) {
    $bst = $pdo->prepare('SELECT id, booking_no, customer_name, room_no FROM bookings WHERE id = ?');
    $bst->execute([(int)$sale['booking_id']]);
    $linkedBooking = $bst->fetch(PDO::FETCH_ASSOC) ?: null;
}

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <a href="pos_sales.php" class="btn btn-secondary">&larr; Back to Sales History</a>
    <div>
        <a href="pos.php" class="btn btn-accent">+ New Sale</a>
        <button onclick="window.print()" class="btn btn-accent">🖨 Print Receipt</button>
    </div>
</div>

<?php if ($sale['voided']): ?>
    <div class="alert alert-error no-print">This sale has been voided.</div>
<?php endif; ?>

<div class="invoice receipt">
    <?php require __DIR__ . '/includes/company_header.php'; ?>

    <div style="text-align:center;margin-bottom:16px;">
        <div style="font-weight:700;font-size:1.1rem;">SALES RECEIPT</div>
        <div style="color:var(--muted);font-size:0.85rem;">
            <?= h($sale['sale_no']) ?> &middot; <?= h(date('d M Y, h:i A', strtotime($sale['created_at']))) ?>
        </div>
        <?php if ($sale['voided']): ?><div class="badge badge-cancelled" style="margin-top:6px;display:inline-block;">VOIDED</div><?php endif; ?>
        <?php if ($linkedBooking): ?><div style="margin-top:8px;"><strong>Hotel Invoice:</strong> <?= h($linkedBooking['booking_no']) ?><?php if ($linkedBooking['room_no']): ?> · Room <?= h($linkedBooking['room_no']) ?><?php endif; ?> · <a href="print_bill.php?id=<?= (int)$linkedBooking['id'] ?>">View Hotel Invoice</a></div><?php endif; ?>
    </div>

    <div class="info-grid" style="margin-bottom:16px;">
        <div><span class="label">Customer:</span> <?= h($sale['customer_name']) ?: 'Walk-in' ?></div>
        <div><span class="label">Cashier:</span> <?= h($sale['cashier']) ?></div>
        <div><span class="label">Payment Method:</span> <?= h($sale['payment_method']) ?></div>
        <?php if ($sale['notes']): ?><div><span class="label">Notes:</span> <?= h($sale['notes']) ?></div><?php endif; ?>
    </div>

    <table class="bill-table">
        <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= h($it['product_name']) ?></td>
                <td><?= (int) $it['qty'] ?></td>
                <td><?= money($it['unit_price']) ?></td>
                <td><?= money($it['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="bill-table" style="margin-top:10px;">
        <tr><td>Subtotal</td><td><?= money($sale['subtotal']) ?></td></tr>
        <tr><td>Discount</td><td>-<?= money($sale['discount']) ?></td></tr>
        <tr><td>Tax (<?= money($sale['tax_percent']) ?>%)</td><td><?= money($sale['tax_amount']) ?></td></tr>
        <tr class="bill-total-row"><td>Total</td><td><?= money($sale['total']) ?></td></tr>
        <tr><td>Amount Tendered</td><td><?= money($sale['amount_tendered']) ?></td></tr>
        <tr><td>Change Due</td><td><?= money($sale['change_due']) ?></td></tr>
    </table>

    <p style="text-align:center;color:var(--muted);font-size:0.85rem;margin-top:20px;">
        <?= h(getSetting($pdo, 'footer_note', 'Thank you for your business!')) ?>
    </p>
</div>

<style>
.receipt { max-width: 380px; margin: 0 auto; }
@media print {
    .receipt { max-width: 100%; }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
