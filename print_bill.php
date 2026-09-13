<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    die('Booking not found.');
}

$pageTitle = 'Bill ' . $b['booking_no'];
$invoice = hotelInvoiceWithPos($pdo, $b);
$bill = $invoice['bill'];
$posSales = $invoice['pos_sales'];

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <a href="view_booking.php?id=<?= $b['id'] ?>" class="btn btn-secondary">&larr; Back to Reservation</a>
    <button onclick="window.print()" class="btn btn-accent">🖨 Print Bill</button>
</div>

<div class="invoice">
    <?php require __DIR__ . '/includes/company_header.php'; ?>
    <div class="invoice-header">
        <div>
            <h1>Hotel Invoice</h1>
            <div>Invoice / Booking No: <strong><?= h($b['booking_no']) ?></strong></div>
        </div>
        <div class="meta">
            <div>Date: <?= h(date('d M Y', strtotime($b['created_at']))) ?></div>
            <div>Status: <span class="badge <?= statusBadgeClass($b['status']) ?>"><?= h($b['status']) ?></span></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Billed To</h3>
        <div class="info-grid">
            <div><span class="label">Name:</span> <?= h($b['customer_name']) ?></div>
            <div><span class="label">Phone:</span> <?= h($b['phone']) ?: '—' ?></div>
            <div><span class="label">Email:</span> <?= h($b['email']) ?: '—' ?></div>
            <div><span class="label">Address:</span> <?= nl2br(h($b['address'])) ?: '—' ?></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Stay Details</h3>
        <table class="bill-table">
            <thead>
                <tr><th>Description</th><th>Qty / Nights</th><th>Rate</th><th>Amount</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= h($b['room_type']) ?: 'Room' ?> — <?= (int)($b['room_count'] ?? 1) ?> room(s) <?= $b['room_no'] ? '(' . h($b['room_no']) . ')' : '' ?><br>
                        <small style="color:var(--muted);"><?= h($b['checkin_date']) ?> to <?= h($b['checkout_date']) ?></small>
                    </td>
                    <td><?= (int) $b['nights'] ?> night(s)</td>
                    <td><?= money($b['rate_per_night']) ?></td>
                    <td><?= money($bill['room_total']) ?></td>
                </tr>
                <?php if ($bill['extra'] > 0): ?>
                <tr>
                    <td colspan="3">Extra Charges</td>
                    <td><?= money($bill['extra']) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($posSales): ?>
    <div class="invoice-section">
        <h3>POS / Additional Charges</h3>
        <table class="bill-table">
            <thead><tr><th>POS Bill No.</th><th>Date</th><th>Payment</th><th>Amount</th></tr></thead>
            <tbody>
            <?php foreach ($posSales as $ps): ?>
                <tr>
                    <td><?= h($ps['sale_no']) ?></td>
                    <td><?= h(date('d M Y H:i', strtotime($ps['created_at']))) ?></td>
                    <td><?= h($ps['payment_method']) ?></td>
                    <td><?= money((float)$ps['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="invoice-section">
        <h3>Bill Summary</h3>
        <table class="bill-table">
            <tr><td>Subtotal</td><td><?= money($bill['subtotal'] + $bill['discount']) ?></td></tr>
            <tr><td>Discount</td><td>-<?= money($bill['discount']) ?></td></tr>
            <tr><td>Taxable Subtotal</td><td><?= money($bill['subtotal']) ?></td></tr>
            <tr><td>Tax (<?= money($bill['tax_percent']) ?>%)</td><td><?= money($bill['tax_amount']) ?></td></tr>
            <tr><td>Hotel Stay Total</td><td><?= money($bill['grand_total']) ?></td></tr>
            <tr><td>POS / Additional Charges</td><td><?= money($invoice['pos_total']) ?></td></tr>
            <tr class="bill-total-row"><td>Grand Total</td><td><?= money($invoice['grand_total']) ?></td></tr>
            <tr><td>Hotel Advance Paid</td><td>-<?= money($bill['advance']) ?></td></tr>
            <tr><td>POS Paid Separately</td><td>-<?= money($invoice['pos_paid']) ?></td></tr>
            <tr class="bill-total-row"><td class="balance-due">Balance Due</td><td class="balance-due"><?= money($invoice['balance_due']) ?></td></tr>
        </table>
    </div>

    <p style="color:var(--muted);font-size:0.85rem;margin-top:24px;">
        Thank you for your stay. Please retain this invoice for your records.
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
