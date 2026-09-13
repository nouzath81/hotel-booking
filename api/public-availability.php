<?php
/**
 * Public availability endpoint — intentionally NOT behind requireLogin().
 * Meant to be called from a public website/widget to show live room
 * availability.
 *
 * NOTE: at the site owner's explicit request, this endpoint also returns
 * guest booking details (name, phone, whatsapp, stay dates, room, status)
 * for bookings overlapping the requested date range. Because this endpoint
 * has no authentication, that guest data is readable by anyone who has the
 * URL. Confirm that's really intended before relying on this in production.
 *
 * GET params:
 *   checkin   (YYYY-MM-DD, optional, default: today)
 *   checkout  (YYYY-MM-DD, optional, default: tomorrow)
 *   type      (room type name, optional, e.g. "Deluxe")
 *   type_id   (room type id, optional, e.g. 3)
 *
 * If neither type nor type_id is given, all room types are returned.
 * If both are given, a room type must match both to be included.
 */

require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $today = date('Y-m-d');
    $defaultCheckout = date('Y-m-d', strtotime('+1 day'));
    $checkin = trim($_GET['checkin'] ?? $today);
    $checkout = trim($_GET['checkout'] ?? $defaultCheckout);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout) || $checkout <= $checkin) {
        throw new InvalidArgumentException('Invalid availability date range.');
    }

    $typeName = isset($_GET['type']) ? trim((string) $_GET['type']) : null;
    $typeId = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int) $_GET['type_id'] : null;

    $rooms = allRoomAvailability($pdo, $checkin, $checkout);

    if ($typeName !== null && $typeName !== '') {
        $rooms = array_values(array_filter($rooms, fn($r) => strcasecmp((string)$r['type_name'], $typeName) === 0));
    }
    if ($typeId !== null) {
        $rooms = array_values(array_filter($rooms, fn($r) => (int)$r['id'] === $typeId));
    }

    $bookingStmt = $pdo->prepare(
        "SELECT booking_no, customer_name, phone, whatsapp_no, room_no, room_count,
                checkin_date, checkout_date, nights, status
         FROM bookings
         WHERE room_type = :room_type
           AND status NOT IN ('Cancelled', 'Checked-Out')
           AND checkin_date < :checkout AND checkout_date > :checkin
         ORDER BY checkin_date"
    );

    $out = array_map(function ($r) use ($bookingStmt, $checkin, $checkout) {
        $bookingStmt->execute([
            'room_type' => $r['type_name'],
            'checkin' => $checkin,
            'checkout' => $checkout,
        ]);
        $bookings = array_map(function ($b) {
            return [
                'booking_no'    => (string) $b['booking_no'],
                'customer_name' => (string) $b['customer_name'],
                'phone'         => (string) ($b['phone'] ?? ''),
                'whatsapp_no'   => (string) ($b['whatsapp_no'] ?? ''),
                'room_no'       => (string) ($b['room_no'] ?? ''),
                'room_count'    => (int) ($b['room_count'] ?? 1),
                'checkin_date'  => (string) $b['checkin_date'],
                'checkout_date' => (string) $b['checkout_date'],
                'nights'        => (int) $b['nights'],
                'status'        => (string) $b['status'],
            ];
        }, $bookingStmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'type_id'      => (int) $r['id'],
            'type_name'    => (string) $r['type_name'],
            'total'        => (int) $r['total'],
            'booked'       => (int) $r['booked'],
            'remaining'    => (int) $r['remaining'],
            'default_rate' => (float) $r['default_rate'],
            'bookings'     => $bookings,
        ];
    }, $rooms);

    if (($typeName !== null && $typeName !== '') || $typeId !== null) {
        if (!$out) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Room type not found.']);
            exit;
        }
    }

    echo json_encode([
        'ok' => true,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'rooms' => $out,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unable to load room availability']);
}
