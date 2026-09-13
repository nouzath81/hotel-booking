<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Petty Cash';
$errors = [];

// Add new entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_entry'])) {
    $entryDate = trim($_POST['entry_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $type = trim($_POST['type'] ?? 'Out');
    $amount = (float) ($_POST['amount'] ?? 0);

    if ($entryDate === '') $errors[] = 'Date is required.';
    if ($description === '') $errors[] = 'Description is required.';
    if (!in_array($type, ['In', 'Out'], true)) $errors[] = 'Invalid entry type.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO petty_cash (entry_date, description, category, type, amount) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$entryDate, $description, $category, $type, $amount]);
        header('Location: petty_cash.php?added=1');
        exit;
    }
}

// Delete an entry
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM petty_cash WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: petty_cash.php?deleted=1');
    exit;
}

// Statement date filter (optional — blank shows all-time)
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$allRows = pettyCashEntries($pdo); // all-time, for correct opening balance
$filteredRows = ($from !== '' && $to !== '') ? pettyCashEntries($pdo, $from, $to) : $allRows;

// Opening balance = net of everything before the "from" date, when a range is applied
$openingBalance = 0.0;
if ($from !== '' && $to !== '') {
    foreach ($allRows as $r) {
        if ($r['entry_date'] < $from) {
            $openingBalance += ($r['type'] === 'In' ? 1 : -1) * (float) $r['amount'];
        }
    }
}

$periodTotals = pettyCashTotals($filteredRows);
$overallTotals = pettyCashTotals($allRows);

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <h1 style="margin:0;">Petty Cash</h1>
    <button onclick="window.print()" class="btn btn-accent">🖨 Print Statement</button>
</div>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success no-print">Entry added.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success no-print">Entry deleted.</div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error no-print">
        <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card no-print">
    <h3>Add Entry</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Date *</label>
            <input type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group" style="flex:2;">
            <label>Description *</label>
            <input type="text" name="description" placeholder="e.g. Office supplies, Taxi fare, Cash top-up" required>
        </div>
        <div class="form-group">
            <label>Category</label>
            <input type="text" name="category" placeholder="e.g. Supplies, Maintenance">
        </div>
        <div class="form-group">
            <label>Type *</label>
            <select name="type">
                <option value="Out">Cash Out (expense)</option>
                <option value="In">Cash In (top-up / deposit)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Amount *</label>
            <input type="number" step="0.01" min="0.01" name="amount" required>
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" name="add_entry" value="1" class="btn btn-accent">Add</button>
        </div>
    </form>
</div>

<div class="card no-print">
    <h3>Filter Statement by Date</h3>
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
            <button class="btn btn-sm" type="submit">Apply</button>
        </div>
        <?php if ($from !== '' || $to !== ''): ?>
            <div class="form-group" style="flex:0;"><a class="btn btn-sm btn-secondary" href="petty_cash.php">Show All-Time</a></div>
        <?php endif; ?>
    </form>
</div>

<div class="invoice">
    <?php require __DIR__ . '/includes/company_header.php'; ?>
    <div class="invoice-header">
        <div>
            <h1>Petty Cash Statement</h1>
            <div>
                <?php if ($from !== '' && $to !== ''): ?>
                    Period: <strong><?= h($from) ?></strong> to <strong><?= h($to) ?></strong>
                <?php else: ?>
                    Period: <strong>All-Time</strong>
                <?php endif; ?>
            </div>
        </div>
        <div class="meta">
            <div>Generated: <?= h(date('d M Y')) ?></div>
        </div>
    </div>

    <div class="invoice-section">
        <div class="form-row">
            <div class="card" style="flex:1;min-width:180px;">
                <div style="color:var(--muted);font-size:0.85rem;">Cash In</div>
                <div style="font-size:1.5rem;font-weight:700;color:var(--accent);"><?= money($periodTotals['total_in']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px;">
                <div style="color:var(--muted);font-size:0.85rem;">Cash Out</div>
                <div style="font-size:1.5rem;font-weight:700;color:var(--danger);"><?= money($periodTotals['total_out']) ?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px;">
                <div style="color:var(--muted);font-size:0.85rem;">Current Balance (all-time)</div>
                <div style="font-size:1.5rem;font-weight:700;color:var(--primary-dark);"><?= money($overallTotals['balance']) ?></div>
            </div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Entries</h3>
        <table class="bill-table">
            <thead>
                <tr><th>Date</th><th>Description</th><th>Category</th><th>Type</th><th>Amount</th><th>Balance</th><th class="no-print">Manage</th></tr>
            </thead>
            <tbody>
            <?php if ($from !== '' && $to !== ''): ?>
                <tr>
                    <td colspan="5">Opening Balance</td>
                    <td><?= money($openingBalance) ?></td>
                    <td class="no-print"></td>
                </tr>
            <?php endif; ?>
            <?php
            $running = $openingBalance;
            foreach ($filteredRows as $r):
                $running += ($r['type'] === 'In' ? 1 : -1) * (float) $r['amount'];
            ?>
                <tr>
                    <td><?= h($r['entry_date']) ?></td>
                    <td><?= h($r['description']) ?></td>
                    <td><?= h($r['category']) ?: '—' ?></td>
                    <td><?= $r['type'] === 'In' ? '<span class="badge badge-confirmed">In</span>' : '<span class="badge badge-cancelled">Out</span>' ?></td>
                    <td><?= $r['type'] === 'In' ? '+' : '-' ?><?= money($r['amount']) ?></td>
                    <td><?= money($running) ?></td>
                    <td class="no-print">
                        <a class="btn btn-sm btn-danger" href="petty_cash.php?delete=<?= $r['id'] ?>"
                           onclick="return confirm('Delete this entry?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$filteredRows): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);">No entries for this period.</td></tr>
            <?php endif; ?>
            <tr class="bill-total-row">
                <td colspan="5">Closing Balance</td>
                <td><?= money($running) ?></td>
                <td class="no-print"></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
