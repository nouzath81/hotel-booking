<?php
$pageTitle='Monthly Salary Sheet';
require __DIR__.'/staff_common.php';
$month=$_GET['month']??date('Y-m');
$staff=$pdo->query('SELECT * FROM staff WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$tot=0; $totBasic=0; $totAllowance=0; $totDeduction=0; $rows=[];
foreach($staff as $s){
    $r=staffMonthReport($pdo,(int)$s['id'],$month);
    $rows[]=$r;
    $tot += (float)$r['salary'];
    $totBasic += (float)$r['staff']['basic_salary'];
    $totAllowance += (float)$r['staff']['allowance'];
    $totDeduction += (float)$r['staff']['deduction'];
}
$monthLabel=(new DateTimeImmutable($month.'-01'))->format('F Y');
include __DIR__.'/includes/header.php';
?>
<style>
.salary-sheet { background:#fff; border:1px solid #ddd; padding:22px; }
.salary-head { text-align:center; border-bottom:2px solid #222; padding-bottom:12px; margin-bottom:14px; }
.salary-head h1 { margin:0; color:#222; font-size:24px; }
.salary-head .period { margin-top:5px; font-size:14px; color:#555; }
.salary-meta { display:flex; justify-content:space-between; margin-bottom:12px; font-size:13px; }
.salary-table { width:100%; border-collapse:collapse; font-size:11px; }
.salary-table th,.salary-table td { border:1px solid #bbb; padding:6px 5px; white-space:nowrap; }
.salary-table th { background:#f1f1f1; color:#222; text-align:center; }
.salary-table td.num { text-align:center; }
.salary-table td.money { text-align:right; }
.salary-table .total td { font-weight:700; background:#f7f7f7; border-top:2px solid #222; }
.salary-summary { margin-top:14px; display:flex; justify-content:flex-end; }
.salary-total { border:1px solid #222; padding:10px 18px; min-width:260px; }
.salary-total .label { font-size:12px; color:#555; }
.salary-total .amount { font-size:20px; font-weight:700; margin-top:4px; }
.signature-area { margin-top:55px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:45px; }
.signature { text-align:center; font-size:12px; }
.signature .line { border-top:1px solid #222; height:28px; }
.print-only { display:none; }
@media print {
  body { background:#fff !important; }
  .topbar, .container > .card:first-child, footer, .no-print { display:none !important; }
  .container { max-width:none; margin:0; padding:0; }
  .salary-sheet { border:0; padding:8px; }
  .print-only { display:block; }
  @page { size:A4 landscape; margin:10mm; }
  .salary-table { font-size:9px; }
  .salary-table th,.salary-table td { padding:4px 3px; }
}
</style>

<div class="card no-print">
  <div class="flex-between">
    <h1 style="margin:0">Monthly Salary Sheet</h1>
    <div>
      <button class="btn btn-accent" onclick="window.print()">Print / Sign</button>
      <a class="btn" href="staff.php">Staff & Attendance</a>
    </div>
  </div>
  <form method="get" class="form-grid" style="margin-top:15px">
    <input type="month" name="month" value="<?=h($month)?>">
    <button class="btn btn-accent">Calculate</button>
  </form>
</div>

<div class="salary-sheet">
  <div class="salary-head">
    <h1>MONTHLY SALARY SHEET</h1>
    <div class="period"><?=h($monthLabel)?></div>
  </div>
  <div class="salary-meta">
    <span><strong>Prepared:</strong> <?=h(date('Y-m-d'))?></span>
    <span><strong>Currency:</strong> LKR</span>
  </div>

  <table class="salary-table">
    <tr>
      <th>No.</th><th>Code</th><th>Employee</th><th>Working<br>Days</th><th>Present</th><th>Absent</th><th>Off Day</th><th>Leave</th><th>Holiday</th><th>Paid<br>Days</th><th>Basic Salary</th><th>Allowance</th><th>Deduction</th><th>Salary Payable</th>
    </tr>
    <?php foreach($rows as $i=>$r): ?>
    <tr>
      <td class="num"><?=$i+1?></td>
      <td><?=h($r['staff']['employee_code'])?></td>
      <td><?=h($r['staff']['name'])?></td>
      <td class="num"><?=$r['working']?></td>
      <td class="num"><?=$r['counts']['Present']?></td>
      <td class="num"><?=$r['counts']['Absent']?></td>
      <td class="num"><?=$r['counts']['Off Day']?></td>
      <td class="num"><?=$r['counts']['Leave']?></td>
      <td class="num"><?=$r['counts']['Holiday']?></td>
      <td class="num"><?=$r['paidDays']?></td>
      <td class="money"><?=number_format((float)$r['staff']['basic_salary'],2)?></td>
      <td class="money"><?=number_format((float)$r['staff']['allowance'],2)?></td>
      <td class="money"><?=number_format((float)$r['staff']['deduction'],2)?></td>
      <td class="money"><strong><?=number_format((float)$r['salary'],2)?></strong></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
      <td colspan="10" style="text-align:right">TOTAL</td>
      <td class="money"><?=number_format($totBasic,2)?></td>
      <td class="money"><?=number_format($totAllowance,2)?></td>
      <td class="money"><?=number_format($totDeduction,2)?></td>
      <td class="money"><?=number_format($tot,2)?></td>
    </tr>
  </table>

  <div class="salary-summary">
    <div class="salary-total">
      <div class="label">TOTAL SALARY PAYABLE</div>
      <div class="amount">LKR <?=number_format($tot,2)?></div>
    </div>
  </div>

  <div class="signature-area">
    <div class="signature"><div class="line"></div><strong>Prepared By</strong><br>HR / Attendance</div>
    <div class="signature"><div class="line"></div><strong>Accountant Signature</strong><br>Date: __________________</div>
    <div class="signature"><div class="line"></div><strong>Manager / Authorized Signature</strong><br>Date: __________________</div>
  </div>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
