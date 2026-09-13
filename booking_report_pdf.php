<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$mode = $_GET['mode'] ?? 'range';
$status = $_GET['status'] ?? '';
if (!in_array($mode, ['range','daily','monthly'], true)) $mode = 'range';

if ($mode === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $from = $to = $date;
    $label = 'Daily Booking Report - ' . $date;
} elseif ($mode === 'monthly') {
    $ym = $_GET['ym'] ?? date('Y-m');
    $from = $ym . '-01';
    $to = date('Y-m-t', strtotime($from));
    $label = 'Monthly Booking Report - ' . date('F Y', strtotime($from));
} else {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    if ($to < $from) $to = $from;
    $label = 'Booking Report - ' . $from . ' to ' . $to;
}

$rows = bookingsInDateRange($pdo, $from, $to, $status);
$summary = summarizeBookings($rows);

function rptText($s): string {
    $s = str_replace(["\r","\n","\t"], [' ',' ',' '], (string)$s);
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($x !== false) $s = $x;
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
}
function rptEsc($s): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], rptText($s)); }
function rptWrap($s, $max=92): array {
    $s = rptText($s); $out=[]; $line='';
    foreach (preg_split('/\s+/', trim($s)) as $w) {
        if ($w==='') continue;
        if ($line !== '' && strlen($line)+1+strlen($w) > $max) { $out[]=$line; $line=$w; }
        else $line = $line === '' ? $w : $line.' '.$w;
    }
    if ($line !== '') $out[]=$line;
    return $out ?: [''];
}

$lines=[];
$lines[]='HOTEL - BOOKING REPORT';
$lines[]='==============================================================';
$lines[]=$label;
$lines[]='Generated: '.date('d M Y H:i');
if ($status) $lines[]='Status Filter: '.$status;
$lines[]='';
$lines[]='SUMMARY';
$lines[]='Bookings: '.(int)$summary['count'];
$lines[]='Invoice Total: '.money($summary['total_invoice']);
$lines[]='Collected: '.money($summary['total_advance']);
$lines[]='Balance Due: '.money($summary['total_balance_due']);
$lines[]='';
$lines[]='BOOKING DETAILS';
$lines[]='No | Customer | Room | Check-in | Check-out | Status | Total | Balance';
$lines[]='--------------------------------------------------------------------------------';

foreach ($rows as $b) {
    $bill=calcBill($b);
    $customer = preg_replace('/\s+/', ' ', (string)$b['customer_name']);
    $room = trim(($b['room_type'] ?? '').' '.(($b['room_no'] ?? '') ? '#'.$b['room_no'] : ''));
    $lines[] = ($b['booking_no'] ?: '-') . ' | ' . $customer . ' | ' . ($room ?: '-') . ' | ' .
        ($b['checkin_date'] ?: '-') . ' | ' . ($b['checkout_date'] ?: '-') . ' | ' .
        ($b['status'] ?: '-') . ' | ' . money($bill['grand_total']) . ' | ' . money($bill['balance_due']);
}
if (!$rows) $lines[]='No bookings found for the selected period.';

// Simple multi-page PDF generator.
$pages=[]; $current=[]; $maxLines=47;
foreach ($lines as $line) {
    foreach (rptWrap($line, 88) as $part) {
        if (count($current) >= $maxLines) { $pages[]=$current; $current=[]; }
        $current[]=$part;
    }
}
if ($current) $pages[]=$current;

$objects=[];
$fontId=1; $pageIds=[]; $contentIds=[];
$objects[]='<< /Type /Catalog /Pages 2 0 R >>';
$objects[]=''; // Pages object filled after page count is known.
foreach ($pages as $pi=>$pageLines) {
    $stream="BT\n/F1 10 Tf\n40 800 Td\n"; $first=true;
    foreach ($pageLines as $line) {
        if (!$first) $stream.="0 -15 Td\n";
        $stream.='('.rptEsc($line).") Tj\n"; $first=false;
    }
    $stream.="ET\n";
    $contentId=count($objects)+1;
    $objects[]="<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
    $contentIds[]=$contentId;
    $pageIds[] = count($objects)+1;
    $objects[]='';
}
$fontId=count($objects)+1;
$objects[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pagesObj='<< /Type /Pages /Kids [';
foreach ($pageIds as $id) $pagesObj.=$id.' 0 R ';
$pagesObj.='] /Count '.count($pageIds).' >>';
$objects[1]=$pagesObj;
foreach ($pageIds as $i=>$pid) {
    $objects[$pid-1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentIds[$i].' 0 R >>';
}
$pdf="%PDF-1.4\n"; $offsets=[0];
foreach ($objects as $i=>$obj) { $offsets[$i+1]=strlen($pdf); $pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n"; }
$xref=strlen($pdf); $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
for($i=1;$i<=count($objects);$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Booking-Report-'.date('Ymd-His').'.pdf"');
echo $pdf;
