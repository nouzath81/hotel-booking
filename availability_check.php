<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$roomType = trim($_GET['room_type'] ?? '');
$checkin  = trim($_GET['checkin'] ?? '');
$checkout = trim($_GET['checkout'] ?? '');
$excludeId = isset($_GET['exclude_id']) ? (int) $_GET['exclude_id'] : null;

if ($roomType === '' || $checkin === '' || $checkout === '' || $checkout <= $checkin) {
    echo json_encode(['ok' => false, 'message' => 'Select a room type and valid dates.']);
    exit;
}

$avail = roomAvailability($pdo, $roomType, $checkin, $checkout, $excludeId);
$detail = roomAvailabilityDetailed($pdo, $roomType, $checkin, $checkout, $excludeId);
echo json_encode(['ok' => true] + $avail + ['detail' => $detail]);
