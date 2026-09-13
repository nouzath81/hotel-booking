<?php
require __DIR__.'/staff_common.php'; require_once __DIR__.'/includes/simple_pdf.php';
$month=$_GET['month']??date('Y-m'); $staffId=(int)($_GET['staff_id']??0); if(!$staffId) die('Staff ID required');
try{$r=staffMonthReport($pdo,$staffId,$month);}catch(Throwable $e){http_response_code(400);die('Report error: '.h($e->getMessage()));}
$lines=[]; $lines[]='STAFF MONTHLY ATTENDANCE & SALARY REPORT'; $lines[]='Employee: '.$r['staff']['name'].' | Code: '.$r['staff']['employee_code'].' | Position: '.$r['staff']['position']; $lines[]='Month: '.date('F Y',strtotime($month.'-01')); $lines[]='';
$lines[]='Present: '.$r['counts']['Present'].'    Absent: '.$r['counts']['Absent'].'    Off Day: '.$r['counts']['Off Day']; $lines[]='Leave: '.$r['counts']['Leave'].'    Holiday: '.$r['counts']['Holiday'].'    Working Days: '.$r['working'].'    Paid Days: '.$r['paidDays']; $lines[]='Basic Salary: '.moneyLkr((float)$r['staff']['basic_salary']).' | Allowance: '.moneyLkr((float)$r['staff']['allowance']).' | Deduction: '.moneyLkr((float)$r['staff']['deduction']); $lines[]='Daily Rate: '.moneyLkr($r['dailyRate']).' | Salary Payable: '.moneyLkr($r['salary']); $lines[]=''; $lines[]='DATE        DAY   STATUS       NOTE';
foreach($r['rows'] as $row) $lines[]=$row['date'].'  '.$row['day'].'   '.$row['status'].'   '.$row['label'];
spdf_output(spdf_build($lines,'Staff Monthly Report'),'staff-'.$r['staff']['employee_code'].'-'.$month.'.pdf');
