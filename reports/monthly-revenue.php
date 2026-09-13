<?php
require_once __DIR__ . '/../includes/functions.php';

$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
    ? $_GET['month'] : date('Y-m');

$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));


try {
    $sql = "SELECT * FROM bookings
            WHERE status NOT IN ('Cancelled')
              AND checkin_date < ?
              AND checkout_date >= ?
            ORDER BY checkin_date, id";
    $st = $pdo->prepare($sql);
    $st->execute([$end, $start]);
    $bookings = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalRevenue = 0.0;
    $totalCollected = 0.0;
    $totalBalance = 0.0;
    $rows = [];

    foreach ($bookings as $b) {
        $amount = isset($b['total_amount']) ? (float)$b['total_amount']
                : (isset($b['grand_total']) ? (float)$b['grand_total'] : 0.0);
        $paid = isset($b['paid_amount']) ? (float)$b['paid_amount']
              : (isset($b['advance']) ? (float)$b['advance'] : 0.0);

        $totalRevenue += $amount;
        $totalCollected += $paid;
        $totalBalance += max(0, $amount - $paid);

        $rows[] = [
            'booking_no' => $b['booking_no'] ?? ($b['id'] ?? ''),
            'customer' => $b['customer_name'] ?? ($b['name'] ?? ''),
            'room_type' => $b['room_type'] ?? '',
            'room_no' => $b['room_no'] ?? '',
            'checkin' => $b['checkin_date'] ?? '',
            'checkout' => $b['checkout_date'] ?? '',
            'status' => $b['status'] ?? '',
            'amount' => $amount,
            'paid' => $paid,
            'balance' => max(0, $amount - $paid)
        ];
    }

    // Use existing FPDF if installed in the project; otherwise provide printable HTML.
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
        $pdf = new FPDF('L','mm','A4');
        $pdf->AddPage();
        $pdf->SetFont('Arial','B',16);
        $pdf->Cell(0,10,'Monthly Revenue Report - ' . date('F Y', strtotime($start)),0,1,'C');
        $pdf->SetFont('Arial','',9);
        $pdf->Cell(0,7,'Period: ' . $start . ' to ' . date('Y-m-d', strtotime($end . ' -1 day')),0,1,'C');
        $pdf->Ln(3);

        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(45,8,'Total Revenue',1);
        $pdf->Cell(45,8,'Collected',1);
        $pdf->Cell(45,8,'Balance Due',1);
        $pdf->Cell(35,8,'Bookings',1);
        $pdf->Ln();
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(45,8,money($totalRevenue),1);
        $pdf->Cell(45,8,money($totalCollected),1);
        $pdf->Cell(45,8,money($totalBalance),1);
        $pdf->Cell(35,8,count($rows),1);
        $pdf->Ln(12);

        $headers = ['Booking','Customer','Room','Check-in','Check-out','Status','Revenue','Collected','Balance'];
        $widths = [25,55,35,28,28,30,30,30,30];
        $pdf->SetFont('Arial','B',8);
        foreach ($headers as $i=>$h) $pdf->Cell($widths[$i],7,$h,1,0,'C');
        $pdf->Ln();
        $pdf->SetFont('Arial','',8);
        foreach ($rows as $r) {
            $pdf->Cell($widths[0],7,substr($r['booking_no'],0,16),1);
            $pdf->Cell($widths[1],7,substr($r['customer'],0,30),1);
            $pdf->Cell($widths[2],7,substr(trim($r['room_type'].' '.$r['room_no']),0,20),1);
            $pdf->Cell($widths[3],7,$r['checkin'],1);
            $pdf->Cell($widths[4],7,$r['checkout'],1);
            $pdf->Cell($widths[5],7,substr($r['status'],0,15),1);
            $pdf->Cell($widths[6],7,money($r['amount']),1,0,'R');
            $pdf->Cell($widths[7],7,money($r['paid']),1,0,'R');
            $pdf->Cell($widths[8],7,money($r['balance']),1,0,'R');
            $pdf->Ln();
        }
        $pdf->Output('I','monthly-revenue-' . $month . '.pdf');
        exit;
    }

    // Fallback: browser print-to-PDF if no PDF library is installed.
    ?>
    <!doctype html><html><head><meta charset="utf-8">
    <title>Monthly Revenue Report <?=htmlspecialchars($month)?></title>
    <style>
      body{font-family:Arial,sans-serif;margin:28px;color:#222}
      h1{text-align:center;font-size:22px}.muted{text-align:center;color:#666}
      .summary{display:flex;gap:12px;margin:20px 0}.box{border:1px solid #ccc;padding:12px;flex:1}
      table{width:100%;border-collapse:collapse;font-size:11px}
      th,td{border:1px solid #ccc;padding:6px}th{background:#f3f3f3}
      .num{text-align:right}@media print{button{display:none}}
    </style></head><body>
    <button onclick="window.print()">Print / Save as PDF</button>
    <h1>Monthly Revenue Report - <?=htmlspecialchars(date('F Y', strtotime($start)))?></h1>
    <div class="muted"><?=htmlspecialchars($start)?> to <?=htmlspecialchars(date('Y-m-d', strtotime($end.' -1 day')))?></div>
    <div class="summary">
      <div class="box"><b>Total Revenue</b><br><?=money($totalRevenue)?></div>
      <div class="box"><b>Collected</b><br><?=money($totalCollected)?></div>
      <div class="box"><b>Balance Due</b><br><?=money($totalBalance)?></div>
      <div class="box"><b>Bookings</b><br><?=count($rows)?></div>
    </div>
    <table><tr><th>Booking</th><th>Customer</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Revenue</th><th>Collected</th><th>Balance</th></tr>
    <?php foreach($rows as $r): ?><tr>
      <td><?=htmlspecialchars($r['booking_no'])?></td><td><?=htmlspecialchars($r['customer'])?></td>
      <td><?=htmlspecialchars(trim($r['room_type'].' '.$r['room_no']))?></td>
      <td><?=htmlspecialchars($r['checkin'])?></td><td><?=htmlspecialchars($r['checkout'])?></td>
      <td><?=htmlspecialchars($r['status'])?></td><td class="num"><?=money($r['amount'])?></td>
      <td class="num"><?=money($r['paid'])?></td><td class="num"><?=money($r['balance'])?></td>
    </tr><?php endforeach; ?></table>
    </body></html>
    <?php
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Unable to generate monthly revenue report.';
}
