<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Sales History';

if (isset($_GET['void'])) {
    posVoidSale($pdo, (int) $_GET['void']);
    header('Location: pos_sales.php?voided=1');
    exit;
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
if ($to < $from) { $to = $from; }

$sales = posSalesInRange($pdo, $from, $to);
foreach ($sales as &$saleRow) {
    $saleRow['booking_no'] = '';
    if (!empty($saleRow['booking_id'])) {
        $bst = $pdo->prepare('SELECT booking_no FROM bookings WHERE id = ?');
        $bst->execute([(int)$saleRow['booking_id']]);
        $saleRow['booking_no'] = (string)$bst->fetchColumn();
    }
}
unset($saleRow);
$totals = posSalesTotals($sales);

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <h1 style="margin:0;">Sales History</h1>
    <div>
        <a href="pos.php" class="btn btn-accent">+ New Sale</a>
        <button onclick="window.print()" class="btn btn-secondary">🖨 Print</button>
    </div>
</div>

<?php if (isset($_GET['voided'])): ?><div class="alert alert-success no-print">Sale voided and stock restored.</div><?php endif; ?>

<div class="card no-print">
    <form method="get" class="form-row" style="align-items:flex-end;">
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

<div class="form-row">
    <div class="card" style="flex:1;min-width:160px;">
        <div style="color:var(--muted);font-size:0.85rem;">Sales</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--primary-dark);"><?= (int) $totals['count'] ?></div>
    </div>
    <div class="card" style="flex:1;min-width:160px;">
        <div style="color:var(--muted);font-size:0.85rem;">Subtotal</div>
        <div style="font-size:1.4rem;font-weight:700;"><?= money($totals['subtotal']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:160px;">
        <div style="color:var(--muted);font-size:0.85rem;">Discounts</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--danger);"><?= money($totals['discount']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:160px;">
        <div style="color:var(--muted);font-size:0.85rem;">Tax</div>
        <div style="font-size:1.4rem;font-weight:700;"><?= money($totals['tax']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:160px;">
        <div style="color:var(--muted);font-size:0.85rem;">Total Revenue</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= money($totals['total']) ?></div>
    </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
<table>
    <thead>
        <tr><th>Sale #</th><th>Date</th><th>Hotel Invoice</th><th>Customer</th><th>Cashier</th><th>Payment</th><th>Total</th><th class="no-print">Action</th></tr>
    </thead>
    <tbody>
    <?php if (!$sales): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px;">No sales for this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($sales as $s): ?>
        <tr>
            <td><?= h($s['sale_no']) ?></td>
            <td><?= h(date('d M Y H:i', strtotime($s['created_at']))) ?></td>
            <td><?= $s['booking_id'] ? '<a href="print_bill.php?id='.(int)$s['booking_id'].'">'.h($s['booking_no'] ?? ('Booking #'.$s['booking_id'])).'</a>' : '<span style="color:var(--muted)">Separate POS</span>' ?></td>
            <td><?= h($s['customer_name']) ?: 'Walk-in' ?></td>
            <td><?= h($s['cashier']) ?></td>
            <td><?= h($s['payment_method']) ?></td>
            <td><?= money($s['total']) ?></td>
            <td class="no-print">
                <a class="btn btn-sm" href="pos_receipt.php?id=<?= $s['id'] ?>">Receipt</a>
                <a class="btn btn-sm btn-danger" href="pos_sales.php?void=<?= $s['id'] ?>&from=<?= h($from) ?>&to=<?= h($to) ?>"
                   onclick="return confirm('Void this sale and restock its items?');">Void</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
