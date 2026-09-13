<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Booking List';

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dueOnly = isset($_GET['due_only']) && $_GET['due_only'] === '1';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(customer_name LIKE :q OR phone LIKE :q OR booking_no LIKE :q OR room_type LIKE :q OR room_no LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}

$sql = 'SELECT * FROM bookings';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Attach computed bill figures to each row up front, so both the due-only
// filter and the totals row below use exactly the same numbers as the table.
$rows = [];
$sumTotal = 0.0;
$sumAdvance = 0.0;
$sumDue = 0.0;
foreach ($bookings as $b) {
    $bill = calcBill($b);
    if ($dueOnly && $bill['balance_due'] <= 0.005) {
        continue;
    }
    $rows[] = ['b' => $b, 'bill' => $bill];
    $sumTotal += $bill['grand_total'];
    $sumAdvance += $bill['advance'];
    $sumDue += $bill['balance_due'];
}

require __DIR__ . '/includes/header.php';
?>

<div class="flex-between">
    <h1>Booking List</h1>
    <a href="add_booking.php" class="btn btn-accent">+ New Booking</a>
</div>
<p style="color:var(--muted);margin-top:-8px;">Every booking with full details — room, dates, amount, advance paid, and balance due. For an outstanding-balance-only dashboard, see <a href="due_list.php">Due Settlements</a>.</p>

<form method="get" class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
    <div class="form-group" style="min-width:220px;">
        <label>Search</label>
        <input type="text" name="q" placeholder="Customer, phone, booking #, room..." value="<?= h($search) ?>">
    </div>
    <div class="form-group" style="min-width:160px;">
        <label>Status</label>
        <select name="status">
            <option value="">All</option>
            <?php foreach (['Confirmed', 'Checked-In', 'Checked-Out', 'Cancelled'] as $s): ?>
                <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="min-width:160px;">
        <label style="visibility:hidden;">Due</label>
        <label style="font-weight:400;display:flex;align-items:center;gap:6px;padding:9px 0;">
            <input type="checkbox" name="due_only" value="1" style="width:auto;" <?= $dueOnly ? 'checked' : '' ?>> Only with balance due
        </label>
    </div>
    <div class="form-group" style="flex:0;">
        <button class="btn" type="submit">Apply</button>
        <?php if ($search !== '' || $statusFilter !== '' || $dueOnly): ?>
            <a class="btn btn-secondary" href="booking_list.php">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="form-row">
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Showing</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= count($rows) ?> booking(s)</div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Total Amount</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= money($sumTotal) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Advance Collected</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($sumAdvance) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:180px;">
        <div style="color:var(--muted);font-size:0.85rem;">Balance Due</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--danger);"><?= money($sumDue) ?></div>
    </div>
</div>

<div class="card" style="padding:0; overflow-x:auto;">
<table>
    <thead>
        <tr>
            <th>Booking #</th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Room</th>
            <th>Check-in</th>
            <th>Check-out</th>
            <th>Nights</th>
            <th>Status</th>
            <th>Total</th>
            <th>Advance</th>
            <th>Balance Due</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:30px;">No bookings found.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row):
        $b = $row['b'];
        $bill = $row['bill'];
    ?>
        <tr>
            <td><?= h($b['booking_no']) ?></td>
            <td><?= h($b['customer_name']) ?></td>
            <td><?= h($b['phone']) ?></td>
            <td><?= h($b['room_type']) ?> <?= $b['room_no'] ? '(' . h($b['room_no']) . ')' : '' ?></td>
            <td><?= h($b['checkin_date']) ?></td>
            <td><?= h($b['checkout_date']) ?></td>
            <td><?= (int) $b['nights'] ?></td>
            <td><span class="badge <?= statusBadgeClass($b['status']) ?>"><?= h($b['status']) ?></span></td>
            <td><?= money($bill['grand_total']) ?></td>
            <td><?= money($bill['advance']) ?></td>
            <td<?= $bill['balance_due'] > 0.005 ? ' class="balance-due"' : '' ?>><?= money($bill['balance_due']) ?></td>
            <td class="actions">
                <a class="btn btn-sm" href="view_booking.php?id=<?= $b['id'] ?>">View</a>
                <a class="btn btn-sm" href="edit_booking.php?id=<?= $b['id'] ?>">Edit</a>
                <a class="btn btn-sm btn-secondary" href="print_bill.php?id=<?= $b['id'] ?>">Bill</a>
                <?php if ($bill['balance_due'] > 0.005): ?>
                    <a class="btn btn-sm btn-accent" href="settle_due.php?id=<?= $b['id'] ?>">Settle</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
