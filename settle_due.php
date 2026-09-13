<?php
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    die('Booking not found.');
}

$pageTitle = 'Settle Due — ' . $b['booking_no'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['payment_date'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $bill = calcBill($b);

    if ($date === '') $errors[] = 'Payment date is required.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($amount > $bill['balance_due'] + 0.01) {
        $errors[] = 'Amount exceeds the current balance due (' . money($bill['balance_due']) . '). Adjust the amount or edit the booking if this is a refund/adjustment.';
    }

    if (!$errors) {
        if (recordPayment($pdo, $id, $date, $amount, $method, $notes)) {
            header('Location: settle_due.php?id=' . $id . '&recorded=1');
            exit;
        }
        $errors[] = 'Could not record the payment. Please try again.';
    }
}

// Re-fetch after any update
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
$bill = calcBill($b);
$history = getPaymentsForBooking($pdo, $id);

require __DIR__ . '/includes/header.php';
?>

<div class="flex-between no-print">
    <h1>Settle Due — <?= h($b['booking_no']) ?></h1>
    <div>
        <a href="view_booking.php?id=<?= $id ?>" class="btn btn-secondary">&larr; Back to Reservation</a>
        <button onclick="window.print()" class="btn btn-accent">🖨 Print</button>
    </div>
</div>

<?php if (isset($_GET['recorded'])): ?>
    <div class="alert alert-success no-print">Payment recorded.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error no-print">
        <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="form-row">
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Customer</div>
        <div style="font-size:1.1rem;font-weight:700;"><?= h($b['customer_name']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Grand Total</div>
        <div style="font-size:1.3rem;font-weight:700;color:var(--primary-dark);"><?= money($bill['grand_total']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Paid to Date</div>
        <div style="font-size:1.3rem;font-weight:700;color:var(--accent);"><?= money($bill['advance']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Balance Due</div>
        <div style="font-size:1.3rem;font-weight:700;color:<?= $bill['balance_due'] > 0.005 ? 'var(--danger)' : 'var(--accent)' ?>;"><?= money($bill['balance_due']) ?></div>
    </div>
</div>

<?php if ($bill['balance_due'] > 0.005): ?>
<div class="card no-print">
    <h3>Record a Payment</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Payment Date *</label>
            <input type="date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
            <label>Amount *</label>
            <input type="number" step="0.01" min="0.01" max="<?= $bill['balance_due'] ?>" name="amount" value="<?= $bill['balance_due'] ?>" required>
        </div>
        <div class="form-group">
            <label>Method</label>
            <select name="method">
                <option value="Cash">Cash</option>
                <option value="Card">Card</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Online">Online</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <input type="text" name="notes" placeholder="Optional reference / note">
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" class="btn btn-accent">Record Payment</button>
        </div>
    </form>
</div>
<?php else: ?>
    <div class="alert alert-success no-print">This booking is fully settled — no balance due.</div>
<?php endif; ?>

<div class="invoice">
    <?php require __DIR__ . '/includes/company_header.php'; ?>
    <div class="invoice-section" style="margin-bottom:0;">
        <h3>Settlement History</h3>
        <table class="bill-table">
            <thead><tr><th>Date</th><th>Method</th><th>Notes</th><th>Amount</th></tr></thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--muted);">No payments recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $p): ?>
                <tr>
                    <td><?= h($p['payment_date']) ?></td>
                    <td><?= h($p['method']) ?: '—' ?></td>
                    <td><?= h($p['notes']) ?: '—' ?></td>
                    <td><?= money($p['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
