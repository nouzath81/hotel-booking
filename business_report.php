<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Trend Report';

$view = ($_GET['view'] ?? 'daily') === 'monthly' ? 'monthly' : 'daily';

// Sensible defaults: daily = current month, monthly = last 6 months
if ($view === 'monthly') {
    $from = $_GET['from'] ?? date('Y-m-01', strtotime('-5 months'));
    $to   = $_GET['to']   ?? date('Y-m-t');
} else {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-t');
}
if ($to < $from) { $to = $from; }

$groupBy = $view === 'monthly' ? 'month' : 'day';
$rows = combinedBusinessReport($pdo, $from, $to, $groupBy);

// Grand totals across the whole period
$grand = [
    'bookings_count' => 0, 'invoice_total' => 0.0, 'collected_total' => 0.0,
    'petty_cash_in' => 0.0, 'petty_cash_out' => 0.0, 'petty_cash_net' => 0.0, 'pos_sales_count' => 0, 'pos_sales_total' => 0.0,
];
foreach ($rows as $r) {
    $grand['bookings_count'] += $r['bookings_count'];
    $grand['invoice_total'] += $r['invoice_total'];
    $grand['collected_total'] += $r['collected_total'];
    $grand['petty_cash_in'] += $r['petty_cash_in'];
    $grand['petty_cash_out'] += $r['petty_cash_out'];
    $grand['petty_cash_net'] += $r['petty_cash_net'];
    $grand['pos_sales_count'] += $r['pos_sales_count'];
    $grand['pos_sales_total'] += $r['pos_sales_total'];
}

function formatPeriod(string $key, string $view): string
{
    if ($view === 'monthly') {
        return date('M Y', strtotime($key . '-01'));
    }
    return date('d M Y (D)', strtotime($key));
}

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <h1 style="margin:0;">Trend Report (Bookings + POS + Cash)</h1>
    <button onclick="window.print()" class="btn btn-accent">🖨 Print Report</button>
</div>
<p class="no-print" style="color:var(--muted);margin-top:-10px;">
    Shows a day-by-day or month-by-month breakdown across a range. For a full single-period
    report with booking and petty cash line items, use <a href="reports.php">Reports</a>.
</p>

<div class="card no-print">
    <div class="form-row" style="margin-bottom:10px;">
        <a href="?view=daily" class="btn <?= $view === 'daily' ? 'btn-accent' : 'btn-secondary' ?> btn-sm">Daily</a>
        <a href="?view=monthly" class="btn <?= $view === 'monthly' ? 'btn-accent' : 'btn-secondary' ?> btn-sm">Monthly</a>
    </div>
    <form method="get" class="form-row" style="align-items:flex-end;">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <div class="form-group">
            <label>From</label>
            <input type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="form-group">
            <label>To</label>
            <input type="date" name="to" value="<?= h($to) ?>">
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
            <h1><?= $view === 'monthly' ? 'Monthly' : 'Daily' ?> Business Report</h1>
            <div>Period: <strong><?= h($from) ?></strong> to <strong><?= h($to) ?></strong></div>
            <div style="color:var(--muted);font-size:0.85rem;">Booking figures based on check-in date; petty cash figures based on entry date.</div>
        </div>
        <div class="meta">
            <div>Generated: <?= h(date('d M Y')) ?></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Overall Totals</h3>
        <div class="form-row">
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Bookings</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= (int) $grand['bookings_count'] ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Invoice Total</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= money($grand['invoice_total']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Collected (Advances)</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($grand['collected_total']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Petty Cash In</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($grand['petty_cash_in']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Petty Cash Out</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--danger);"><?= money($grand['petty_cash_out']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">POS Sales</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"> <?= money($grand['pos_sales_total']) ?></div>
                <div style="font-size:.8rem;color:var(--muted);"> <?= (int)$grand['pos_sales_count'] ?> transactions</div>
            </div>
            <div class="card" style="flex:1;min-width:160px;">
                <div style="color:var(--muted);font-size:0.85rem;">Petty Cash Net</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $grand['petty_cash_net'] < 0 ? 'var(--danger)' : 'var(--primary-dark)' ?>;"><?= money($grand['petty_cash_net']) ?></div>
            </div>
        </div>
    </div>

    <div class="invoice-section">
        <h3><?= $view === 'monthly' ? 'Month-by-Month' : 'Day-by-Day' ?> Breakdown</h3>
        <table class="bill-table">
            <thead>
                <tr>
                    <th><?= $view === 'monthly' ? 'Month' : 'Date' ?></th>
                    <th>Bookings</th>
                    <th>Invoice Total</th>
                    <th>Collected</th>
                    <th>Petty Cash In</th>
                    <th>Petty Cash Out</th>
                    <th>POS Sales</th><th>Petty Cash Net</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--muted);">No activity for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h(formatPeriod($r['period'], $view)) ?></td>
                    <td><?= (int) $r['bookings_count'] ?></td>
                    <td><?= money($r['invoice_total']) ?></td>
                    <td><?= money($r['collected_total']) ?></td>
                    <td><?= money($r['petty_cash_in']) ?></td>
                    <td><?= money($r['petty_cash_out']) ?></td>
                    <td><?= money($r['pos_sales_total']) ?></td>
                    <td style="<?= $r['petty_cash_net'] < 0 ? 'color:var(--danger);' : '' ?>"><?= money($r['petty_cash_net']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows): ?>
                <tr class="bill-total-row">
                    <td>Total</td>
                    <td><?= (int) $grand['bookings_count'] ?></td>
                    <td><?= money($grand['invoice_total']) ?></td>
                    <td><?= money($grand['collected_total']) ?></td>
                    <td><?= money($grand['petty_cash_in']) ?></td>
                    <td><?= money($grand['petty_cash_out']) ?></td>
                    <td><?= money($grand['pos_sales_total']) ?></td>
                    <td><?= money($grand['petty_cash_net']) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
