<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Due Settlements';

$dueBookings = bookingsWithDue($pdo);
$totalDue = array_reduce($dueBookings, fn($sum, $r) => $sum + $r['bill']['balance_due'], 0.0);

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <h1 style="margin:0;">Due Settlements</h1>
    <button onclick="window.print()" class="btn btn-accent">🖨 Print</button>
</div>

<div class="card">
    <div style="color:var(--muted);font-size:0.85rem;">Total Outstanding Across All Bookings</div>
    <div style="font-size:1.8rem;font-weight:700;color:var(--danger);"><?= money($totalDue) ?></div>
    <div style="color:var(--muted);font-size:0.85rem;"><?= count($dueBookings) ?> booking(s) with a balance due</div>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
<table>
    <thead>
        <tr>
            <th>Booking #</th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Room</th>
            <th>Check-in</th>
            <th>Status</th>
            <th>Grand Total</th>
            <th>Paid</th>
            <th>Balance Due</th>
            <th class="no-print">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$dueBookings): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:30px;">No outstanding dues — everything is settled. 🎉</td></tr>
    <?php endif; ?>
    <?php foreach ($dueBookings as $r): $bill = $r['bill']; ?>
        <tr>
            <td><?= h($r['booking_no']) ?></td>
            <td><?= h($r['customer_name']) ?></td>
            <td><?= h($r['phone']) ?: '—' ?></td>
            <td><?= h($r['room_type']) ?></td>
            <td><?= h($r['checkin_date']) ?></td>
            <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= h($r['status']) ?></span></td>
            <td><?= money($bill['grand_total']) ?></td>
            <td><?= money($bill['advance']) ?></td>
            <td class="balance-due"><?= money($bill['balance_due']) ?></td>
            <td class="no-print"><a class="btn btn-sm btn-accent" href="settle_due.php?id=<?= $r['id'] ?>">Settle</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
