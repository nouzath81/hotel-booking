<?php
require_once __DIR__ . '/../includes/functions.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['expense_date'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? 'Other');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    if ($category !== '' && $amount > 0) {
        $st = $pdo->prepare("INSERT INTO expenses (expense_date,category,description,amount) VALUES (?,?,?,?)");
        $st->execute([$date,$category,$description,$amount]);
    }
    header('Location: expenses.php');
    exit;
}
$expenses = $pdo->query("SELECT * FROM expenses ORDER BY expense_date DESC,id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Expenses</title>
<style>body{font-family:Arial,sans-serif;margin:25px}.form{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}input,select,button{padding:8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:7px}th{background:#f3f3f3}</style>
</head><body>
<h2>Hotel Expenses</h2>
<form method="post" class="form">
<input type="date" name="expense_date" value="<?=date('Y-m-d')?>" required>
<select name="category">
<option>Salary</option><option>Utilities</option><option>Maintenance</option><option>Supplies</option>
<option>Cleaning</option><option>Food & Beverage</option><option>Marketing</option><option>Rent</option>
<option>Taxes</option><option>Other</option>
</select>
<input name="description" placeholder="Description" maxlength="255">
<input type="number" name="amount" min="0.01" step="0.01" placeholder="Amount" required>
<button type="submit">Add Expense</button>
</form>
<table><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr>
<?php foreach($expenses as $e): ?><tr>
<td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td>
<td><?=htmlspecialchars($e['description'])?></td><td><?=number_format((float)$e['amount'],2)?></td>
</tr><?php endforeach; ?></table>
</body></html>
