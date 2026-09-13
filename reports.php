<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Reports';

$mode = $_GET['mode'] ?? 'range';
if (!in_array($mode, ['range', 'daily', 'monthly'], true)) {
    $mode = 'range';
}

$statusFilter = $_GET['status'] ?? '';
$reportLabel = '';

if ($mode === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $from = $to = $date;
    $reportLabel = 'Daily Report — ' . $date;
} elseif ($mode === 'monthly') {
    $ym = $_GET['ym'] ?? date('Y-m');
    $from = $ym . '-01';
    $to = date('Y-m-t', strtotime($from));
    $reportLabel = 'Monthly Report — ' . date('F Y', strtotime($from));
} else {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-t');
    if ($to < $from) { $to = $from; }
    $reportLabel = 'Custom Range Report';
}

// --- Business data ---
$rows = bookingsInDateRange($pdo, $from, $to, $statusFilter);
$summary = summarizeBookings($rows);
$byRoomType = groupByRoomType($rows);
$byStatus = groupByStatus($rows);

// --- Petty cash data ---
$cashRows = pettyCashEntries($pdo, $from, $to);
$cashTotals = pettyCashTotals($cashRows);
$openingCash = pettyCashOpeningBalance($pdo, $from);
$closingCash = $openingCash + $cashTotals['balance'];
$posRows = posSalesInRange($pdo, $from, $to);
$posTotals = posSalesTotals($posRows);
$posAccounts = posAccountTotals($pdo, $from, $to);

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <h1 style="margin:0;">Reports</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button onclick="window.print()" class="btn">🖨 Print Report</button>
        <a class="btn btn-accent" target="_blank" href="booking_report_pdf.php?mode=<?= h($mode) ?>&from=<?= h($from) ?>&to=<?= h($to) ?>&date=<?= h($from) ?>&ym=<?= h(date('Y-m', strtotime($from))) ?>&status=<?= h($statusFilter) ?>">📄 Booking Report PDF</a>
        <a class="btn" target="_blank" href="room_remaining_report_pdf.php?checkin=<?= h($from) ?>&checkout=<?= h($to > $from ? $to : date('Y-m-d', strtotime($from.' +1 day'))) ?>">🏨 Rooms Remaining PDF</a>
        <a class="btn" target="_blank" href="daily_room_occupancy_report_pdf.php?date=<?= h($mode === 'daily' ? $from : date('Y-m-d')) ?>">🛏 Daily Occupancy PDF</a>
    </div>
</div>

<div class="card no-print">
    <form method="get" class="form-row" style="align-items:flex-end;" id="report-form">
        <div class="form-group">
            <label>Report Type</label>
            <select name="mode" onchange="this.form.submit()">
                <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Custom Range</option>
                <option value="daily" <?= $mode === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="monthly" <?= $mode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>

        <?php if ($mode === 'daily'): ?>
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="date" value="<?= h($from) ?>">
            </div>
        <?php elseif ($mode === 'monthly'): ?>
            <div class="form-group">
                <label>Month</label>
                <input type="month" name="ym" value="<?= h(date('Y-m', strtotime($from))) ?>">
            </div>
        <?php else: ?>
            <div class="form-group">
                <label>From (check-in date)</label>
                <input type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div class="form-group">
                <label>To (check-in date)</label>
                <input type="date" name="to" value="<?= h($to) ?>">
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Booking Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['Confirmed', 'Checked-In', 'Checked-Out', 'Cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:0;">
            <button class="btn" type="submit">Apply</button>
        </div>
    </form>
</div>

<div class="invoice">
    <?php require __DIR__ . '/includes/company_header.php'; ?>
    <div class="invoice-header">
        <div>
            <h1><?= h($reportLabel) ?></h1>
            <div>Period: <strong><?= h($from) ?></strong> to <strong><?= h($to) ?></strong></div>
            <?php if ($statusFilter): ?><div>Status filter: <?= h($statusFilter) ?></div><?php endif; ?>
        </div>
        <div class="meta">
            <div>Generated: <?= h(date('d M Y')) ?></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Business Summary</h3>
        <div class="form-row">
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Bookings</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= (int) $summary['count'] ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Total Invoice Amount</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= money($summary['total_invoice']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Total Collected</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($summary['total_advance']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Outstanding Balance</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--danger);"><?= money($summary['total_balance_due']) ?></div>
            </div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>POS Sales & Accounts</h3>
        <div class="form-row">
            <div class="card" style="flex:1"><div style="color:var(--muted)">POS Transactions</div><strong><?= (int)$posTotals['count'] ?></strong></div>
            <div class="card" style="flex:1"><div style="color:var(--muted)">POS Revenue</div><strong><?= money($posTotals['total']) ?></strong></div>
        </div>
        <table class="bill-table"><thead><tr><th>Account</th><th>Type</th><th>Transactions</th><th>Total</th></tr></thead><tbody>
        <?php foreach($posAccounts as $pa): ?><tr><td><?=h($pa['account_name'])?></td><td><?=h($pa['account_type'])?></td><td><?= (int)$pa['sale_count'] ?></td><td><?=money($pa['total'])?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div>

<div class="invoice-section">
        <h3>Petty Cash Summary</h3>
        <div class="form-row">
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Opening Cash Balance</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= money($openingCash) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Cash In</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($cashTotals['total_in']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Cash Out</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--danger);"><?= money($cashTotals['total_out']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:170px;">
                <div style="color:var(--muted);font-size:0.85rem;">Closing Cash Balance</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= money($closingCash) ?></div>
            </div>
        </div>
        <table class="bill-table" style="margin-top:12px;">
            <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Type</th><th>Amount</th></tr></thead>
            <tbody>
            <?php if (!$cashRows): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);">No petty cash entries for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($cashRows as $c): ?>
                <tr>
                    <td><?= h($c['entry_date']) ?></td>
                    <td><?= h($c['description']) ?></td>
                    <td><?= h($c['category']) ?: '—' ?></td>
                    <td><?= $c['type'] === 'In' ? '<span class="badge badge-confirmed">In</span>' : '<span class="badge badge-cancelled">Out</span>' ?></td>
                    <td><?= $c['type'] === 'In' ? '+' : '-' ?><?= money($c['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="invoice-section">
        <h3>Bookings by Room Type</h3>
        <table class="bill-table">
            <thead><tr><th>Room Type</th><th>Bookings</th><th>Invoice Total</th></tr></thead>
            <tbody>
            <?php if (!$byRoomType): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--muted);">No data for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($byRoomType as $g): ?>
                <tr><td><?= h($g['room_type']) ?></td><td><?= (int) $g['count'] ?></td><td><?= money($g['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="invoice-section">
        <h3>Bookings by Status</h3>
        <table class="bill-table">
            <thead><tr><th>Status</th><th>Bookings</th><th>Invoice Total</th></tr></thead>
            <tbody>
            <?php if (!$byStatus): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--muted);">No data for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($byStatus as $g): ?>
                <tr><td><span class="badge <?= statusBadgeClass($g['status']) ?>"><?= h($g['status']) ?></span></td><td><?= (int) $g['count'] ?></td><td><?= money($g['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="invoice-section">
        <h3>Booking Detail</h3>
        <table class="bill-table">
            <thead>
                <tr><th>Booking #</th><th>Customer</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Invoice</th><th>Balance Due</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--muted);">No bookings found for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $bill = calcBill($r); ?>
                <tr>
                    <td><?= h($r['booking_no']) ?></td>
                    <td><?= h($r['customer_name']) ?></td>
                    <td><?= h($r['room_type']) ?></td>
                    <td><?= h($r['checkin_date']) ?></td>
                    <td><?= h($r['checkout_date']) ?></td>
                    <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= h($r['status']) ?></span></td>
                    <td><?= money($bill['grand_total']) ?></td>
                    <td><?= money($bill['balance_due']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>


<div class="card" style="margin-top:16px;">
  <h3>Monthly Revenue Report</h3>
  <form action="reports/monthly-revenue.php" method="get" target="_blank"
        style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <label for="revenueMonth">Month</label>
    <input id="revenueMonth" name="month" type="month"
           value="<?=date('Y-m')?>" required>
    <button type="submit">📄 Generate Revenue PDF</button>
  </form>
  <div style="color:var(--muted);font-size:.85rem;margin-top:8px;">
    Shows revenue, collected payments, balance due and booking details for the selected month.
  </div>
</div>


<div class="card" style="margin-top:16px;">
  <h3>Monthly Expense &amp; Profit Report</h3>
  <form action="reports/monthly-expense-profit.php" method="get" target="_blank"
        style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <label for="profitMonth">Month</label>
    <input id="profitMonth" name="month" type="month" value="<?=date('Y-m')?>" required>
    <button type="submit">📄 Generate Expense &amp; Profit PDF</button>
    <a href="reports/expenses.php" target="_blank"
       style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;text-decoration:none;">
       💰 Manage Expenses
    </a>
  </form>
  <div style="color:var(--muted);font-size:.85rem;margin-top:8px;">
    Net profit is calculated as monthly booking revenue minus recorded expenses.
  </div>
</div>
