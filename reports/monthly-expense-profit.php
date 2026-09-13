<?php
require_once __DIR__ . '/../includes/functions.php';

$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
    ? $_GET['month'] : date('Y-m');

$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));


try {
    // Create the expense table automatically if it does not exist.
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT '',
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_expense_date (expense_date),
        INDEX idx_expense_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $st = $pdo->prepare("SELECT * FROM expenses
                         WHERE expense_date >= ? AND expense_date < ?
                         ORDER BY expense_date, category, id");
    $st->execute([$start, $end]);
    $expenses = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalExpense = 0.0;
    $byCategory = [];
    foreach ($expenses as $e) {
        $amount = (float)$e['amount'];
        $totalExpense += $amount;
        $cat = $e['category'] ?: 'Other';
        if (!isset($byCategory[$cat])) $byCategory[$cat] = 0.0;
        $byCategory[$cat] += $amount;
    }
    arsort($byCategory);

    // Revenue is based on bookings overlapping the selected month.
    $st = $pdo->prepare("SELECT * FROM bookings
                         WHERE status NOT IN ('Cancelled')
                           AND checkin_date < ?
                           AND checkout_date >= ?");
    $st->execute([$end, $start]);
    $bookings = $st->fetchAll(PDO::FETCH_ASSOC);

    $revenue = 0.0;
    foreach ($bookings as $b) {
        $revenue += isset($b['total_amount']) ? (float)$b['total_amount']
                  : (isset($b['grand_total']) ? (float)$b['grand_total'] : 0.0);
    }

    $profit = $revenue - $totalExpense;
    $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

    $fpdfCandidates = [
        __DIR__ . '/../vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/../fpdf/fpdf.php',
        __DIR__ . '/../includes/fpdf/fpdf.php'
    ];
    $fpdf = null;
    foreach ($fpdfCandidates as $candidate) {
        if (file_exists($candidate)) { $fpdf = $candidate; break; }
    }

    if ($fpdf) {
        require_once $fpdf;
        $pdf = new FPDF('P','mm','A4');
        $pdf->AddPage();
        $pdf->SetFont('Arial','B',16);
        $pdf->Cell(0,10,'Monthly Expense & Profit Report',0,1,'C');
        $pdf->SetFont('Arial','',9);
        $pdf->Cell(0,7,date('F Y', strtotime($start)),0,1,'C');
        $pdf->Ln(5);

        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(45,8,'Revenue',1);
        $pdf->Cell(45,8,'Expenses',1);
        $pdf->Cell(45,8,'Net Profit',1);
        $pdf->Cell(45,8,'Profit Margin',1);
        $pdf->Ln();
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(45,8,money($revenue),1);
        $pdf->Cell(45,8,money($totalExpense),1);
        $pdf->Cell(45,8,money($profit),1);
        $pdf->Cell(45,8,number_format($margin,2).'% ',1);
        $pdf->Ln(12);

        $pdf->SetFont('Arial','B',11);
        $pdf->Cell(0,8,'Expenses by Category',0,1);
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(85,7,'Category',1);
        $pdf->Cell(50,7,'Amount',1);
        $pdf->Ln();
        $pdf->SetFont('Arial','',9);
        foreach ($byCategory as $cat=>$amount) {
            $pdf->Cell(85,7,substr($cat,0,45),1);
            $pdf->Cell(50,7,money($amount),1,0,'R');
            $pdf->Ln();
        }

        $pdf->Ln(8);
        $pdf->SetFont('Arial','B',11);
        $pdf->Cell(0,8,'Expense Details',0,1);
        $pdf->SetFont('Arial','B',8);
        $headers=['Date','Category','Description','Amount'];
        $widths=[28,42,90,30];
        foreach($headers as $i=>$h) $pdf->Cell($widths[$i],7,$h,1,0,'C');
        $pdf->Ln();
        $pdf->SetFont('Arial','',8);
        foreach($expenses as $e) {
            $pdf->Cell(28,7,$e['expense_date'],1);
            $pdf->Cell(42,7,substr($e['category'],0,23),1);
            $pdf->Cell(90,7,substr($e['description'],0,50),1);
            $pdf->Cell(30,7,money($e['amount']),1,0,'R');
            $pdf->Ln();
        }
        $pdf->Ln(6);
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0,8,'Net Profit = Revenue - Expenses',0,1);
        $pdf->Output('I','monthly-expense-profit-'.$month.'.pdf');
        exit;
    }

    // Printable fallback when FPDF is not installed.
    ?>
    <!doctype html><html><head><meta charset="utf-8">
    <title>Monthly Expense & Profit Report</title>
    <style>
      body{font-family:Arial,sans-serif;margin:28px;color:#222}
      h1{text-align:center;font-size:22px}.muted{text-align:center;color:#666}
      .summary{display:flex;gap:10px;margin:20px 0}.box{border:1px solid #ccc;padding:12px;flex:1}
      table{width:100%;border-collapse:collapse;font-size:11px;margin-top:12px}
      th,td{border:1px solid #ccc;padding:6px}th{background:#f3f3f3}.num{text-align:right}
      button{padding:8px 12px}@media print{button{display:none}}
    </style></head><body>
    <button onclick="window.print()">Print / Save as PDF</button>
    <h1>Monthly Expense &amp; Profit Report</h1>
    <div class="muted"><?=htmlspecialchars(date('F Y', strtotime($start)))?></div>
    <div class="summary">
      <div class="box"><b>Revenue</b><br><?=money($revenue)?></div>
      <div class="box"><b>Expenses</b><br><?=money($totalExpense)?></div>
      <div class="box"><b>Net Profit</b><br><?=money($profit)?></div>
      <div class="box"><b>Margin</b><br><?=number_format($margin,2)?>%</div>
    </div>
    <h3>Expenses by Category</h3>
    <table><tr><th>Category</th><th>Amount</th></tr>
    <?php foreach($byCategory as $cat=>$amount): ?><tr><td><?=htmlspecialchars($cat)?></td><td class="num"><?=money($amount)?></td></tr><?php endforeach; ?>
    </table>
    <h3>Expense Details</h3>
    <table><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr>
    <?php foreach($expenses as $e): ?><tr>
      <td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td>
      <td><?=htmlspecialchars($e['description'])?></td><td class="num"><?=money($e['amount'])?></td>
    </tr><?php endforeach; ?></table>
    </body></html>
    <?php
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Unable to generate expense/profit report.';
}
