<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$checkin = $_GET['checkin'] ?? date('Y-m-d');
$checkout = $_GET['checkout'] ?? date('Y-m-d', strtotime($checkin.' +1 day'));
if ($checkout <= $checkin) $checkout = date('Y-m-d', strtotime($checkin.' +1 day'));

$rows = allRoomAvailability($pdo, $checkin, $checkout);

function rrText($s): string {
    $s = str_replace(["\r","\n","\t"], [' ',' ',' '], (string)$s);
    if (function_exists('iconv')) {
        $x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
        if ($x!==false) $s=$x;
    }
    return preg_replace('/[^\x20-\x7E]/','?',$s) ?? '';
}
function rrEsc($s): string { return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],rrText($s)); }
function rrWrap($s,$max=92): array {
    $s=rrText($s); $out=[]; $line='';
    foreach(preg_split('/\s+/',trim($s)) as $w){
        if($w==='')continue;
        if($line!=='' && strlen($line)+1+strlen($w)>$max){$out[]=$line;$line=$w;}else{$line=$line===''?$w:$line.' '.$w;}
    }
    if($line!=='')$out[]=$line;
    return $out?:[''];
}

$lines=[];
$lines[]='HOTEL - ROOMS REMAINING REPORT';
$lines[]='==============================================================';
$lines[]='Stay Period: '.$checkin.' to '.$checkout;
$lines[]='Generated: '.date('d M Y H:i');
$lines[]='';
$lines[]='ROOM AVAILABILITY';
$lines[]='Room Type | Total | Booked | Remaining | Available Room Numbers';
$lines[]='--------------------------------------------------------------------------------';
$total=0;$booked=0;$remaining=0;
foreach($rows as $r){
    $total+=(int)$r['total']; $booked+=(int)$r['booked']; $remaining+=(int)$r['remaining'];
    $available = !empty($r['detail']['tracked']) && !empty($r['detail']['available']) ? implode(', ',$r['detail']['available']) : ($r['detail']['tracked'] ? 'None' : 'Not individually tracked');
    $lines[] = ($r['type_name'] ?: $r['room_type'] ?? '-') . ' | '.(int)$r['total'].' | '.(int)$r['booked'].' | '.(int)$r['remaining'].' | '.$available;
    if (!empty($r['detail']['tracked']) && !empty($r['detail']['booked'])) $lines[]='Booked room numbers: '.implode(', ',$r['detail']['booked']);
}
$lines[]='';
$lines[]='TOTAL | '.$total.' | '.$booked.' | '.$remaining;
if(!$rows)$lines[]='No room types found.';

$pages=[];$current=[];$maxLines=47;
foreach($lines as $line){foreach(rrWrap($line,88) as $part){if(count($current)>=$maxLines){$pages[]=$current;$current=[];}$current[]=$part;}}
if($current)$pages[]=$current;

$objects=[];$pageIds=[];$contentIds=[];
$objects[]='<< /Type /Catalog /Pages 2 0 R >>';
$objects[]='';
foreach($pages as $pageLines){
    $stream="BT\n/F1 10 Tf\n40 800 Td\n";$first=true;
    foreach($pageLines as $line){if(!$first)$stream.="0 -15 Td\n";$stream.='('.rrEsc($line).") Tj\n";$first=false;}
    $stream.="ET\n";
    $cid=count($objects)+1;$objects[]="<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";$contentIds[]=$cid;
    $pid=count($objects)+1;$objects[]='';$pageIds[]=$pid;
}
$fontId=count($objects)+1;$objects[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pagesObj='<< /Type /Pages /Kids [';foreach($pageIds as $id)$pagesObj.=$id.' 0 R ';$pagesObj.='] /Count '.count($pageIds).' >>';$objects[1]=$pagesObj;
foreach($pageIds as $i=>$pid)$objects[$pid-1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentIds[$i].' 0 R >>';
$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$obj){$offsets[$i+1]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Rooms-Remaining-'.date('Ymd-His').'.pdf"');
echo $pdf;
