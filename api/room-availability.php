<?php
require __DIR__ . '/../includes/auth.php';
requireLogin();
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $today = date('Y-m-d');
    $defaultCheckout = date('Y-m-d', strtotime('+1 day'));
    $checkin = trim($_GET['checkin'] ?? $today);
    $checkout = trim($_GET['checkout'] ?? $defaultCheckout);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout) || $checkout <= $checkin) throw new InvalidArgumentException('Invalid availability date range.');
    $rooms=allRoomAvailability($pdo,$checkin,$checkout);
    echo json_encode(['ok'=>true,'updated_at'=>date('Y-m-d H:i:s'),'date'=>$checkin,'next_date'=>$checkout,'rooms'=>$rooms]);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Unable to load room availability']);
}
