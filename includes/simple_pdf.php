<?php
/**
 * Dependency-free, text-first PDF helpers.  Uses the built-in PDF Type1 Helvetica font.
 */
function spdf_text($s): string {
    $s = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], (string)$s);
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($x !== false) $s = $x;
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
}
function spdf_esc($s): string {
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], spdf_text($s));
}
function spdf_wrap($text, int $max = 92): array {
    $text = spdf_text($text);
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        if ($word === '') continue;
        if (!$out || strlen(end($out)) + 1 + strlen($word) > $max) $out[] = $word;
        else $out[count($out)-1] .= ' ' . $word;
    }
    return $out ?: [''];
}
function spdf_build(array $lines, string $title = 'Hotel Export'): string {
    $expanded = [];
    foreach ($lines as $line) {
        foreach (spdf_wrap($line, 88) as $part) $expanded[] = $part;
    }
    $maxLines = 48;
    $pages = array_chunk($expanded, $maxLines);
    if (!$pages) $pages = [['']];

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $pageIds = []; $contentIds = [];
    foreach ($pages as $pageLines) {
        $stream = "BT\n/F1 10 Tf\n40 800 Td\n";
        $first = true;
        foreach ($pageLines as $line) {
            if (!$first) $stream .= "0 -15 Td\n";
            $stream .= '(' . spdf_esc($line) . ") Tj\n";
            $first = false;
        }
        $stream .= "ET\n";
        $contentId = count($objects) + 1;
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $contentIds[] = $contentId;
        $pageIds[] = count($objects) + 1;
        $objects[] = '';
    }
    $fontId = count($objects) + 1;
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $pagesObj = '<< /Type /Pages /Kids [';
    foreach ($pageIds as $id) $pagesObj .= $id . ' 0 R ';
    $pagesObj .= '] /Count ' . count($pageIds) . ' >>';
    $objects[1] = $pagesObj;
    foreach ($pageIds as $i => $pid) {
        $objects[$pid-1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentIds[$i] . ' 0 R >>';
    }
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $obj) {
        $offsets[$i+1] = strlen($pdf);
        $pdf .= ($i+1) . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
    for ($i=1; $i<=count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= "trailer\n<< /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
function spdf_output(string $pdf, string $filename): void {
    if (ob_get_length()) @ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
