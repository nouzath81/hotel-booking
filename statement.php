<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Account Statement';

$name  = trim($_GET['customer'] ?? '');
$phone = trim($_GET['phone'] ?? '');
$q     = trim($_GET['q'] ?? '');

$selectedRows = [];
$paymentHistory = [];
if ($name !== '') {
    $selectedRows = customerBookings($pdo, $name, $phone);
    $paymentHistory = customerPayments($pdo, $name, $phone);
}

$customerList = $name === '' ? searchCustomers($pdo, $q) : [];

require __DIR__ . '/includes/header.php';
?>

<?php if ($name === ''): ?>

    <h1>Account Statement</h1>
    <div class="card no-print">
        <form method="get" class="search-box" style="margin:0;">
            <input type="text" name="q" placeholder="Search customer by name or phone..." value="<?= h($q) ?>" style="max-width:360px;display:inline-block;">
            <button class="btn btn-sm" type="submit">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-sm btn-secondary" href="statement.php">Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="card" style="padding:0;overflow-x:auto;">
    <table>
        <thead>
            <tr><th>Customer</th><th>Phone</th><th>Email</th><th>Bookings</th><th>Statement</th></tr>
        </thead>
        <tbody>
        <?php if (!$customerList): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:30px;">No customers found.</td></tr>
        <?php endif; ?>
        <?php foreach ($customerList as $c): ?>
            <tr>
                <td><?= h($c['customer_name']) ?></td>
                <td><?= h($c['phone']) ?: '—' ?></td>
                <td><?= h($c['email']) ?: '—' ?></td>
                <td><?= (int) $c['booking_count'] ?></td>
                <td>
                    <a class="btn btn-sm" href="statement.php?customer=<?= urlencode($c['customer_name']) ?>&phone=<?= urlencode($c['phone']) ?>">View Statement</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

<?php else: ?>

    <?php
    $summary = summarizeBookings($selectedRows);
    $latest = end($selectedRows) ?: [];
    ?>

    <div class="print-bar no-print flex-between">
        <a href="statement.php" class="btn btn-secondary">&larr; Back to Customer Search</a>
        <button onclick="window.print()" class="btn btn-accent">🖨 Print Statement</button>
    </div>

    <div class="invoice">
        <?php require __DIR__ . '/includes/company_header.php'; ?>
        <div class="invoice-header">
            <div>
                <h1>Account Statement</h1>
                <div>Customer: <strong><?= h($name) ?></strong></div>
            </div>
            <div class="meta">
                <div>Generated: <?= h(date('d M Y')) ?></div>
                <div>Bookings: <?= (int) $summary['count'] ?></div>
            </div>
        </div>

        <div class="invoice-section">
            <h3>Customer Details</h3>
            <div class="info-grid">
                <div><span class="label">Name:</span> <?= h($name) ?></div>
                <div><span class="label">Phone:</span> <?= h($latest['phone'] ?? '') ?: '—' ?></div>
                <div><span class="label">Email:</span> <?= h($latest['email'] ?? '') ?: '—' ?></div>
                <div><span class="label">Address:</span> <?= nl2br(h($latest['address'] ?? '')) ?: '—' ?></div>
            </div>
        </div>

        <div class="invoice-section">
            <h3>Booking History</h3>
            <table class="bill-table">
                <thead>
                    <tr><th>Booking #</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Invoice</th><th>Paid</th><th>Balance</th></tr>
                </thead>
                <tbody>
                <?php $runningBalance = 0.0; ?>
                <?php foreach ($selectedRows as $r): $bill = calcBill($r); $runningBalance += $bill['balance_due']; ?>
                    <tr>
                        <td><?= h($r['booking_no']) ?></td>
                        <td><?= h($r['room_type']) ?> <?= $r['room_no'] ? '(' . h($r['room_no']) . ')' : '' ?></td>
                        <td><?= h($r['checkin_date']) ?></td>
                        <td><?= h($r['checkout_date']) ?></td>
                        <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= h($r['status']) ?></span></td>
                        <td><?= money($bill['grand_total']) ?></td>
                        <td><?= money($bill['advance']) ?></td>
                        <td><?= money($bill['balance_due']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$selectedRows): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);">No bookings found for this customer.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="invoice-section">
            <h3>Statement Summary</h3>
            <table class="bill-table">
                <tr><td>Total Invoiced (all bookings)</td><td><?= money($summary['total_invoice']) ?></td></tr>
                <tr><td>Total Paid (advances)</td><td><?= money($summary['total_advance']) ?></td></tr>
                <tr class="bill-total-row"><td class="balance-due">Total Outstanding Balance</td><td class="balance-due"><?= money($summary['total_balance_due']) ?></td></tr>
            </table>
        </div>

        <div class="invoice-section">
            <h3>Settlement / Payment History</h3>
            <table class="bill-table">
                <thead><tr><th>Date</th><th>Booking #</th><th>Method</th><th>Notes</th><th>Amount</th></tr></thead>
                <tbody>
                <?php if (!$paymentHistory): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);">No payments recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($paymentHistory as $p): ?>
                    <tr>
                        <td><?= h($p['payment_date']) ?></td>
                        <td><?= h($p['booking_no']) ?></td>
                        <td><?= h($p['method']) ?: '—' ?></td>
                        <td><?= h($p['notes']) ?: '—' ?></td>
                        <td><?= money($p['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="color:var(--muted);font-size:0.85rem;">This statement reflects all bookings on record for this customer as of the generation date above.</p>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
