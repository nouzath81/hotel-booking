<?php
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');
if ($id < 1 || !hash_equals(bookingPdfToken($id), $token)) {
    http_response_code(403);
    exit('Invalid or expired booking PDF link.');
}

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b) { http_response_code(404); exit('Booking not found.'); }
$bill = calcBill($b);

function pdfText($s): string {
    $s = (string)$s;
    $s = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $s);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($converted !== false) $s = $converted;
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
}
function pdfEsc($s): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], pdfText($s)); }
function wrapPdf($text, $max=88): array {
    $text = pdfText($text);
    $out=[];
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        if ($word==='') continue;
        if (!$out || strlen(end($out))+1+strlen($word)>$max) $out[]=$word;
        else $out[count($out)-1] .= ' '.$word;
    }
    return $out ?: [''];
}

$lines = [];
$lines[] = 'HOTEL BOOKING CONFIRMATION';
$lines[] = '==============================================';
$lines[] = 'Booking No: ' . $b['booking_no'];
$lines[] = 'Status: ' . $b['status'];
$lines[] = 'Date Booked: ' . date('d M Y', strtotime($b['created_at']));
$lines[] = '';
$lines[] = 'CUSTOMER DETAILS';
$lines[] = 'Name: ' . $b['customer_name'];
$lines[] = 'Phone: ' . ($b['phone'] ?: '-');
$lines[] = 'WhatsApp: ' . (($b['whatsapp_no'] ?? '') ?: '-');
$lines[] = 'Email: ' . ($b['email'] ?: '-');
$lines[] = 'Address: ' . ($b['address'] ?: '-');
$lines[] = '';
$lines[] = 'RESERVATION DETAILS';
$lines[] = 'Room Type: ' . ($b['room_type'] ?: '-');
$lines[] = 'Rooms: ' . (int)($b['room_count'] ?? 1) . ' | Room No: ' . ($b['room_no'] ?: 'Unassigned');
$lines[] = 'Check-in: ' . $b['checkin_date'];
$lines[] = 'Check-out: ' . $b['checkout_date'];
$lines[] = 'Nights: ' . (int)$b['nights'];
$lines[] = '';
$lines[] = 'BILL SUMMARY';
$lines[] = 'Room Charges: ' . money($bill['room_total']);
$lines[] = 'Extra Charges: ' . money($bill['extra']);
$lines[] = 'Discount: -' . money($bill['discount']);
$lines[] = 'Subtotal: ' . money($bill['subtotal']);
$lines[] = 'Tax (' . money($bill['tax_percent']) . '%): ' . money($bill['tax_amount']);
$lines[] = 'Grand Total: ' . money($bill['grand_total']);
$lines[] = 'Advance Paid: ' . money($bill['advance']);
$lines[] = 'Balance Due: ' . money($bill['balance_due']);
$lines[] = '';
$lines[] = 'Thank you for choosing us.';

// Minimal dependency-free PDF generator using Helvetica. Suitable for simple booking confirmations.
$stream = "BT\n/F1 16 Tf\n50 760 Td\n";
$first=true;
foreach ($lines as $line) {
    $wrapped = wrapPdf($line, 86);
    foreach ($wrapped as $part) {
        if (!$first) $stream .= "0 -16 Td\n";
        $stream .= '(' . pdfEsc($part) . ") Tj\n";
        $first=false;
    }
}
$stream .= "ET\n";

$objects=[];
$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
$objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pdf="%PDF-1.4\n";
$offsets=[0];
foreach($objects as $i=>$obj){$offsets[$i+1]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}
$xref=strlen($pdf);
$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
for($i=1;$i<=count($objects);$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Booking-' . preg_replace('/[^A-Za-z0-9_-]/','-', $b['booking_no']) . '.pdf"');
echo $pdf;
