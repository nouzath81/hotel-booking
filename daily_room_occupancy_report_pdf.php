<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

function occText($s): string {
    $s = str_replace(["\r","\n","\t"], [' ',' ',' '], (string)$s);
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($x !== false) $s = $x;
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
}
function occEsc($s): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], occText($s)); }
function occWrap($s, $max=92): array {
    $s = occText($s); $out=[]; $line='';
    foreach (preg_split('/\s+/', trim($s)) as $w) {
        if ($w==='') continue;
        if ($line!=='' && strlen($line)+1+strlen($w)>$max) { $out[]=$line; $line=$w; }
        else { $line=$line==='' ? $w : $line.' '.$w; }
    }
    if ($line!=='') $out[]=$line;
    return $out ?: [''];
}

// A room is occupied on a given day when the stay includes that date:
// check-in <= selected date < check-out. Cancelled bookings never occupy rooms.
$types = $pdo->query('SELECT type_name, total_rooms FROM room_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);

$rows=[]; $grandTotal=0; $grandOccupied=0; $grandVacant=0;
foreach ($types as $t) {
    $type = $t['type_name'];

    $stmt = $pdo->prepare("SELECT room_no, customer_name, booking_no, checkin_date, checkout_date, status
                           FROM bookings
                           WHERE room_type = ?
                             AND status <> 'Cancelled'
                             AND checkin_date <= ?
                             AND checkout_date > ?
                           ORDER BY room_no, customer_name");
    $stmt->execute([$type, $date, $date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Use individually tracked active rooms when available; otherwise use room_types capacity.
    $rstmt = $pdo->prepare("SELECT room_no FROM hotel_rooms WHERE room_type = ? AND status = 'Active' ORDER BY room_no");
    $rstmt->execute([$type]);
    $roomNos = $rstmt->fetchAll(PDO::FETCH_COLUMN);
    $tracked = count($roomNos) > 0;
    $total = $tracked ? count($roomNos) : (int)$t['total_rooms'];

    $occupiedRoomNos=[];
    foreach ($bookings as $b) {
        if (!empty($b['room_no'])) $occupiedRoomNos[] = (string)$b['room_no'];
    }
    $occupiedRoomNos = array_values(array_unique($occupiedRoomNos));
    $occupied = $tracked ? count(array_intersect($roomNos, $occupiedRoomNos)) : count($bookings);
    $occupied = min($occupied, $total);
    $vacant = max(0, $total - $occupied);
    $pct = $total > 0 ? round(($occupied/$total)*100, 1) : 0;

    $rows[] = [
        'type'=>$type, 'total'=>$total, 'occupied'=>$occupied, 'vacant'=>$vacant,
        'pct'=>$pct, 'tracked'=>$tracked, 'roomNos'=>$roomNos, 'bookings'=>$bookings,
        'occupiedRoomNos'=>$occupiedRoomNos
    ];
    $grandTotal += $total; $grandOccupied += $occupied; $grandVacant += $vacant;
}

$grandPct = $grandTotal > 0 ? round(($grandOccupied/$grandTotal)*100, 1) : 0;

$lines=[];
$lines[]='HOTEL - DAILY ROOM OCCUPANCY REPORT';
$lines[]='==============================================================';
$lines[]='Occupancy Date: '.$date.' ('.date('l', strtotime($date)).')';
$lines[]='Generated: '.date('d M Y H:i');
$lines[]='';
$lines[]='ROOM OCCUPANCY SUMMARY';
$lines[]='Room Type | Total | Occupied | Vacant | Occupancy %';
$lines[]='----------------------------------------------------------------';
foreach ($rows as $r) {
    $lines[] = $r['type'].' | '.$r['total'].' | '.$r['occupied'].' | '.$r['vacant'].' | '.$r['pct'].'%';
    if ($r['tracked']) {
        $lines[] = 'Occupied room numbers: '.($r['occupiedRoomNos'] ? implode(', ', $r['occupiedRoomNos']) : 'None');
    }
}
$lines[]='----------------------------------------------------------------';
$lines[]='TOTAL | '.$grandTotal.' | '.$grandOccupied.' | '.$grandVacant.' | '.$grandPct.'%';
$lines[]='';
$lines[]='OCCUPIED ROOM DETAILS';
$lines[]='Room | Room Type | Customer | Booking No | Check-in | Check-out | Status';
$lines[]='--------------------------------------------------------------------------------';
$hasDetails=false;
foreach ($rows as $r) {
    foreach ($r['bookings'] as $b) {
        $hasDetails=true;
        $lines[] = ($b['room_no'] ?: 'Unassigned').' | '.$r['type'].' | '.($b['customer_name'] ?: '-').' | '.($b['booking_no'] ?: '-').' | '.$b['checkin_date'].' | '.$b['checkout_date'].' | '.$b['status'];
    }
}
if (!$hasDetails) $lines[]='No occupied rooms for this date.';

$pages=[]; $current=[]; $maxLines=47;
foreach ($lines as $line) {
    foreach (occWrap($line, 88) as $part) {
        if (count($current) >= $maxLines) { $pages[]=$current; $current=[]; }
        $current[]=$part;
    }
}
if ($current) $pages[]=$current;

$objects=[]; $pageIds=[]; $contentIds=[];
$objects[]='<< /Type /Catalog /Pages 2 0 R >>';
$objects[]='';
foreach ($pages as $pageLines) {
    $stream="BT\n/F1 10 Tf\n40 800 Td\n"; $first=true;
    foreach ($pageLines as $line) {
        if (!$first) $stream.="0 -15 Td\n";
        $stream.='('.occEsc($line).") Tj\n"; $first=false;
    }
    $stream.="ET\n";
    $cid=count($objects)+1; $objects[]="<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream"; $contentIds[]=$cid;
    $pid=count($objects)+1; $objects[]=''; $pageIds[]=$pid;
}
$fontId=count($objects)+1; $objects[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pagesObj='<< /Type /Pages /Kids ['; foreach($pageIds as $id)$pagesObj.=$id.' 0 R '; $pagesObj.='] /Count '.count($pageIds).' >>'; $objects[1]=$pagesObj;
foreach($pageIds as $i=>$pid) $objects[$pid-1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentIds[$i].' 0 R >>';
$pdf="%PDF-1.4\n"; $offsets=[0];
foreach($objects as $i=>$obj){$offsets[$i+1]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}
$xref=strlen($pdf); $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Daily-Room-Occupancy-'.str_replace('-','',$date).'.pdf"');
echo $pdf;
