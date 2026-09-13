<?php
/**
 * Shared helper functions for the booking system.
 */

function generateBookingNo(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_no LIKE 'BK{$year}%'");
    $count = (int) $stmt->fetchColumn() + 1;
    return sprintf('BK%s-%04d', $year, $count);
}

function calcNights(string $checkin, string $checkout): int
{
    $in  = new DateTime($checkin);
    $out = new DateTime($checkout);
    $diff = $out->diff($in)->days;
    return $diff > 0 ? $diff : 1;
}

/**
 * Returns the full bill breakdown as an associative array.
 */
function calcBill(array $b): array
{
    $nights   = (int) $b['nights'];
    $rate     = (float) $b['rate_per_night'];
    $extra    = (float) $b['extra_charges'];
    $discount = (float) $b['discount'];
    $taxPct   = (float) $b['tax_percent'];
    $advance  = (float) $b['advance_paid'];

    $roomTotal = $nights * $rate;
    $subtotal  = $roomTotal + $extra - $discount;
    if ($subtotal < 0) $subtotal = 0;
    $tax       = $subtotal * ($taxPct / 100);
    $grandTotal = $subtotal + $tax;

    return [
        'room_total'  => $roomTotal,
        'extra'       => $extra,
        'discount'    => $discount,
        'subtotal'    => $subtotal,
        'tax_percent' => $taxPct,
        'tax_amount'  => $tax,
        'grand_total' => $grandTotal,
        'advance'     => $advance,
        'balance_due' => $grandTotal - $advance,
    ];
}

/** Linked, non-voided POS charges for a hotel booking/invoice. */
function posSalesForBooking(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE booking_id = ? AND voided = 0 ORDER BY created_at ASC, id ASC');
    $stmt->execute([$bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** POS sales that can be attached to a hotel booking, newest first. */
function posBookingOptions(PDO $pdo, string $q = ''): array
{
    $sql = "SELECT id, booking_no, customer_name, phone, room_type, room_no, checkin_date, checkout_date, status
            FROM bookings WHERE status <> 'Cancelled'";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (booking_no LIKE :q OR customer_name LIKE :q OR phone LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Add linked POS sales to a hotel invoice while keeping payment semantics clear. */
function hotelInvoiceWithPos(PDO $pdo, array $booking): array
{
    $bill = calcBill($booking);
    $sales = posSalesForBooking($pdo, (int)$booking['id']);
    $posTotal = 0.0;
    $posPaid = 0.0;
    foreach ($sales as $sale) {
        $posTotal += (float)$sale['total'];
        if (strcasecmp((string)$sale['payment_method'], 'Room Charge') !== 0) {
            $posPaid += (float)$sale['total'];
        }
    }
    $grand = $bill['grand_total'] + $posTotal;
    $credits = $bill['advance'] + $posPaid;
    return [
        'bill' => $bill,
        'pos_sales' => $sales,
        'pos_total' => $posTotal,
        'pos_paid' => $posPaid,
        'grand_total' => $grand,
        'credits' => $credits,
        'balance_due' => max(0, $grand - $credits),
    ];
}

function money(float $n): string
{
    return number_format($n, 2);
}

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'Confirmed' => 'badge-confirmed',
        'Checked-In' => 'badge-checkedin',
        'Checked-Out' => 'badge-checkedout',
        'Cancelled' => 'badge-cancelled',
        default => 'badge-default',
    };
}

/**
 * Number of rooms of a given type already booked for a date range.
 * Two stays overlap when: existing.checkin < newCheckout AND existing.checkout > newCheckin.
 * Cancelled bookings don't hold a room. Pass $excludeId when editing a booking
 * so it doesn't count against itself.
 */
function bookingRoomList(?string $roomNo): array
{
    if ($roomNo === null || trim($roomNo) === '') return [];
    $parts = preg_split('/\s*,\s*/', trim($roomNo));
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static fn($v) => $v !== '')));
    return $parts;
}

/** Number of physical rooms represented by one legacy booking row. */
function bookingRoomCount(?string $roomNo, ?int $roomCount = null): int
{
    $rooms = bookingRoomList($roomNo);
    if ($rooms) return count($rooms);
    return ($roomCount !== null && $roomCount > 0) ? $roomCount : 1;
}

/**
 * Number of rooms of a given type already booked for a date range. Supports
 * both old one-room bookings and new multi-room bookings stored in room_no.
 */
function roomsBookedForRange(PDO $pdo, string $roomType, string $checkin, string $checkout, ?int $excludeId = null): int
{
    // Count occupied capacity, not booking rows. If specific room numbers are
    // assigned, the same physical room must never be counted twice. Bookings
    // without a room number still consume capacity using room_count.
    $sql = "SELECT room_no, room_count FROM bookings
            WHERE room_type = :room_type
              AND status NOT IN ('Cancelled', 'Checked-Out')
              AND checkin_date < :checkout
              AND checkout_date > :checkin";
    $params = ['room_type' => $roomType, 'checkin' => $checkin, 'checkout' => $checkout];
    if ($excludeId !== null) { $sql .= ' AND id != :excludeId'; $params['excludeId'] = $excludeId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $assignedRooms = [];
    $unassigned = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rooms = bookingRoomList($row['room_no'] ?? '');
        if ($rooms) {
            foreach ($rooms as $roomNo) $assignedRooms[$roomNo] = true;
            // room_count may contain a higher value than the listed rooms in
            // older records. Preserve that capacity rather than under-counting.
            $listed = count($rooms);
            $count = isset($row['room_count']) ? (int)$row['room_count'] : 0;
            if ($count > $listed) $unassigned += ($count - $listed);
        } else {
            $unassigned += max(1, (int)($row['room_count'] ?? 1));
        }
    }
    return count($assignedRooms) + $unassigned;
}

/**
 * Returns remaining rooms for a type/date-range: ['total' => .., 'booked' => .., 'remaining' => ..]
 * Total is the count of individually-tracked rooms (hotel_rooms) for that type if any exist,
 * otherwise it falls back to the manually-entered total_rooms on room_types.
 */
function roomAvailability(PDO $pdo, string $roomType, string $checkin, string $checkout, ?int $excludeId = null): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE room_type = ? AND status = 'Active'");
    $stmt->execute([$roomType]);
    $individualCount = (int) $stmt->fetchColumn();

    if ($individualCount > 0) {
        $total = $individualCount;
    } else {
        $stmt2 = $pdo->prepare('SELECT total_rooms FROM room_types WHERE type_name = ?');
        $stmt2->execute([$roomType]);
        $total = (int) ($stmt2->fetchColumn() ?: 0);
    }

    $booked = roomsBookedForRange($pdo, $roomType, $checkin, $checkout, $excludeId);
    $remaining = max(0, $total - $booked);

    return ['total' => $total, 'booked' => $booked, 'remaining' => $remaining];
}

/**
 * Which specific room numbers (for a type that has individually-tracked rooms)
 * are available vs. booked for a date range.
 * Returns ['tracked' => bool, 'available' => [room_no,...], 'booked' => [room_no,...]].
 * 'tracked' is false when no individual room numbers have been added for this type yet
 * (i.e. it's still only using the manual total_rooms count).
 */
/**
 * Validate that a new/updated booking has an actual room available.
 * Returns a human-readable error, or null when the reservation can be saved.
 * Cancelled bookings do not reserve rooms.
 */
function validateRoomBooking(PDO $pdo, string $roomType, string $roomNo, string $checkin, string $checkout, string $status = 'Confirmed', ?int $excludeId = null, int $requestedCount = 0): ?string
{
    if ($status === 'Cancelled' || $roomType === '') return null;

    $requested = bookingRoomList($roomNo);
    $requestedCount = $requested ? count($requested) : max(1, $requestedCount);
    $availability = roomAvailability($pdo, $roomType, $checkin, $checkout, $excludeId);
    if ($availability['total'] <= 0) return 'This room type has no rooms available. Please add rooms or increase the room type capacity.';
    if ($requestedCount > $availability['remaining']) {
        return 'Cannot book ' . $requestedCount . ' room(s): only ' . $availability['remaining'] . ' ' . $roomType . ' room(s) are available for the selected dates.';
    }

    $detail = roomAvailabilityDetailed($pdo, $roomType, $checkin, $checkout, $excludeId);
    if ($detail['tracked']) {
        // If there are no unassigned legacy reservations, a selected room number
        // can be validated exactly. If a booking has no room number, allow it to
        // reserve capacity by count; a specific room can be assigned later.
        if (!$requested) {
            return $requestedCount <= (int)($detail['available_capacity'] ?? $availability['remaining'])
                ? null
                : 'Cannot reserve ' . $requestedCount . ' room(s): only ' . (int)($detail['available_capacity'] ?? $availability['remaining']) . ' room(s) are available.';
        }
        if ((int)($detail['unassigned_booked'] ?? 0) > 0) {
            return 'Specific room selection is temporarily unavailable because some existing reservations have no room number. Book by room count and assign the room later.';
        }
        foreach ($requested as $roomNoItem) {
            $roomStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE room_no = ? AND room_type = ? AND status = 'Active'");
            $roomStmt->execute([$roomNoItem, $roomType]);
            if ((int) $roomStmt->fetchColumn() === 0) return 'Room ' . $roomNoItem . ' does not belong to the selected room type or is inactive.';
            if (!in_array($roomNoItem, $detail['available'], true)) return 'Cannot book room ' . $roomNoItem . ': this room is already booked for the selected dates.';
        }
        return null;
    }

    if ($availability['remaining'] < $requestedCount) {
        return 'Cannot book ' . $requestedCount . ' room(s): only ' . $availability['remaining'] . ' available.';
    }
    return null;
}

function roomAvailabilityDetailed(PDO $pdo, string $roomType, string $checkin, string $checkout, ?int $excludeId = null): array
{
    $stmt = $pdo->prepare("SELECT room_no FROM hotel_rooms WHERE room_type = ? AND status = 'Active' ORDER BY room_no");
    $stmt->execute([$roomType]);
    $roomNos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$roomNos) return ['tracked' => false, 'available' => [], 'booked' => [], 'unassigned_booked' => 0];
    $sql = "SELECT room_no, room_count FROM bookings WHERE room_type = :room_type AND status NOT IN ('Cancelled', 'Checked-Out') AND checkin_date < :checkout AND checkout_date > :checkin";
    $params = ['room_type'=>$roomType,'checkin'=>$checkin,'checkout'=>$checkout];
    if ($excludeId !== null) { $sql .= ' AND id != :excludeId'; $params['excludeId']=$excludeId; }
    $stmt2=$pdo->prepare($sql); $stmt2->execute($params);
    $bookedNos=[]; $unassigned=0;
    foreach($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row){
        $list=bookingRoomList($row['room_no']??'');
        if($list){foreach($list as $rn)$bookedNos[]=$rn;} else {$unassigned += max(1,(int)($row['room_count']??1));}
    }
    $bookedNos=array_values(array_unique($bookedNos));
    $available = array_values(array_diff($roomNos, $bookedNos));
    $booked = array_values(array_intersect($roomNos, $bookedNos));
    // An old booking may have no room number. It still consumes capacity, so
    // never report all physically-free rooms as bookable when capacity is held
    // by unassigned reservations.
    $availableCapacity = max(0, count($available) - $unassigned);
    return [
        'tracked'=>true,
        'available'=>$available,
        'booked'=>$booked,
        'unassigned_booked'=>$unassigned,
        'available_capacity'=>$availableCapacity,
    ];
}

/**
 * Individually-tracked rooms for one type (active only by default), room number ascending.
 */
/**
 * Live room status for a date range. Physical Maintenance always wins; otherwise
 * an overlapping Reserved/Confirmed/Checked-In booking determines the display.
 */
function liveRoomStatus(PDO $pdo, string $roomNo, string $checkin, string $checkout): string
{
    $st = $pdo->prepare("SELECT status FROM hotel_rooms WHERE room_no = ? LIMIT 1");
    $st->execute([$roomNo]);
    $physical = $st->fetchColumn();
    if ($physical === 'Maintenance') return 'Maintenance';
    if ($physical === false) return 'Available';

    $st = $pdo->prepare("SELECT status, room_no FROM bookings
        WHERE status NOT IN ('Cancelled','Checked-Out')
          AND checkin_date < ? AND checkout_date > ?
        ORDER BY id DESC");
    $st->execute([$checkout, $checkin]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        if (in_array($roomNo, bookingRoomList($booking['room_no'] ?? ''), true)) {
            return $booking['status'] === 'Reserved' ? 'Reserved' : 'Booked';
        }
    }
    return 'Available';
}

function listRoomsForType(PDO $pdo, string $roomType, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM hotel_rooms WHERE room_type = ?';
    if ($activeOnly) {
        $sql .= " AND status = 'Active'";
    }
    $sql .= ' ORDER BY room_no';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every individually-tracked room across all types, for the management screen.
 */
function listAllHotelRooms(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM hotel_rooms ORDER BY room_type, room_no')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adds one room number under a room type. Returns an error string on failure, or null on success.
 */
function addHotelRoom(PDO $pdo, string $roomNo, string $roomType): ?string
{
    if ($roomNo === '') return 'Room number is required.';
    if ($roomType === '') return 'Room type is required.';

    try {
        $stmt = $pdo->prepare("INSERT INTO hotel_rooms (room_no, room_type, status) VALUES (?, ?, 'Active')");
        $stmt->execute([$roomNo, $roomType]);
        return null;
    } catch (PDOException $e) {
        return 'Room number "' . $roomNo . '" already exists.';
    }
}

function deleteHotelRoom(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM hotel_rooms WHERE id = ?');
    $stmt->execute([$id]);
}

function updateHotelRoomStatus(PDO $pdo, int $id, string $status): void
{
    $stmt = $pdo->prepare('UPDATE hotel_rooms SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

function updateRoomType(PDO $pdo, int $id, string $typeName, int $totalRooms, float $defaultRate): ?string
{
    if ($typeName === '') return 'Room type name is required.';
    if ($totalRooms < 0) return 'Total rooms cannot be negative.';
    $oldStmt = $pdo->prepare('SELECT type_name FROM room_types WHERE id = ?');
    $oldStmt->execute([$id]);
    $old = $oldStmt->fetchColumn();
    if ($old === false) return 'Room type not found.';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE room_types SET type_name = ?, total_rooms = ?, default_rate = ? WHERE id = ?');
        $stmt->execute([$typeName, $totalRooms, $defaultRate, $id]);
        // Keep individual room records aligned with a renamed room type.
        if ($old !== $typeName) {
            $r = $pdo->prepare('UPDATE hotel_rooms SET room_type = ? WHERE room_type = ?');
            $r->execute([$typeName, $old]);
        }
        $pdo->commit();
        return null;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Could not update the room type. The name may already exist.';
    }
}

/**
 * Full availability table for every room type, for a given date range (defaults to today/tomorrow).
 */
function allRoomAvailability(PDO $pdo, string $checkin, string $checkout): array
{
    $types = $pdo->query('SELECT * FROM room_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($types as $t) {
        $avail = roomAvailability($pdo, $t['type_name'], $checkin, $checkout);
        $detail = roomAvailabilityDetailed($pdo, $t['type_name'], $checkin, $checkout);
        $result[] = array_merge($t, $avail, ['detail' => $detail]);
    }
    return $result;
}

/**
 * Aggregate invoice totals across bookings (excludes Cancelled by default).
 * Returns: total_invoice, total_advance, total_balance_due, count
 */
function invoiceTotals(PDO $pdo, bool $includeCancelled = false): array
{
    $sql = 'SELECT * FROM bookings';
    if (!$includeCancelled) {
        $sql .= " WHERE status != 'Cancelled'";
    }
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $totalInvoice = 0.0;
    $totalAdvance = 0.0;
    $totalBalance = 0.0;

    foreach ($rows as $r) {
        $bill = calcBill($r);
        $totalInvoice += $bill['grand_total'];
        $totalAdvance += $bill['advance'];
        $totalBalance += $bill['balance_due'];
    }

    return [
        'total_invoice' => $totalInvoice,
        'total_advance' => $totalAdvance,
        'total_balance_due' => $totalBalance,
        'count' => count($rows),
    ];
}

/**
 * Bookings whose check-in date falls within [from, to] (inclusive), optionally filtered by status.
 */
function bookingsInDateRange(PDO $pdo, string $from, string $to, string $status = ''): array
{
    $sql = 'SELECT * FROM bookings WHERE checkin_date BETWEEN :from AND :to';
    $params = ['from' => $from, 'to' => $to];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY checkin_date ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Summary totals for a set of already-fetched booking rows.
 */
function summarizeBookings(array $rows): array
{
    $totalInvoice = 0.0;
    $totalAdvance = 0.0;
    $totalBalance = 0.0;

    foreach ($rows as $r) {
        $bill = calcBill($r);
        $totalInvoice += $bill['grand_total'];
        $totalAdvance += $bill['advance'];
        $totalBalance += $bill['balance_due'];
    }

    return [
        'total_invoice' => $totalInvoice,
        'total_advance' => $totalAdvance,
        'total_balance_due' => $totalBalance,
        'count' => count($rows),
    ];
}

/**
 * Groups booking rows by room_type, returning per-group count and invoice total.
 */
function groupByRoomType(array $rows): array
{
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['room_type'] !== '' ? $r['room_type'] : 'Unspecified';
        if (!isset($groups[$key])) {
            $groups[$key] = ['room_type' => $key, 'count' => 0, 'total' => 0.0];
        }
        $groups[$key]['count']++;
        $groups[$key]['total'] += calcBill($r)['grand_total'];
    }
    usort($groups, fn($a, $b) => $b['total'] <=> $a['total']);
    return $groups;
}

/**
 * Groups booking rows by status, returning per-group count and invoice total.
 */
function groupByStatus(array $rows): array
{
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['status'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['status' => $key, 'count' => 0, 'total' => 0.0];
        }
        $groups[$key]['count']++;
        $groups[$key]['total'] += calcBill($r)['grand_total'];
    }
    return $groups;
}

/**
 * Distinct customers (by name+phone) across all bookings, matching an optional search term.
 */
function searchCustomers(PDO $pdo, string $q = ''): array
{
    $sql = 'SELECT customer_name, phone, email, COUNT(*) AS booking_count
            FROM bookings';
    $params = [];
    if ($q !== '') {
        $sql .= ' WHERE customer_name LIKE :q OR phone LIKE :q';
        $params['q'] = '%' . $q . '%';
    }
    $sql .= ' GROUP BY customer_name, phone ORDER BY customer_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * All bookings for one customer (matched by exact name, and phone if given), oldest first.
 */
function customerBookings(PDO $pdo, string $name, string $phone = ''): array
{
    $sql = 'SELECT * FROM bookings WHERE customer_name = :name';
    $params = ['name' => $name];
    if ($phone !== '') {
        $sql .= ' AND phone = :phone';
        $params['phone'] = $phone;
    }
    $sql .= ' ORDER BY checkin_date ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Petty cash entries within an optional date range (inclusive), oldest first.
 */
function pettyCashEntries(PDO $pdo, string $from = '', string $to = ''): array
{
    $sql = 'SELECT * FROM petty_cash';
    $params = [];
    if ($from !== '' && $to !== '') {
        $sql .= ' WHERE entry_date BETWEEN :from AND :to';
        $params = ['from' => $from, 'to' => $to];
    }
    $sql .= ' ORDER BY entry_date ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Petty cash totals: total cash in, total cash out, and net balance.
 * Balance is computed across ALL entries regardless of any date filter passed in $rows,
 * so pass all-time rows here for a true running balance, or a filtered set for period totals only.
 */
function pettyCashTotals(array $rows): array
{
    $totalIn = 0.0;
    $totalOut = 0.0;
    foreach ($rows as $r) {
        if ($r['type'] === 'In') {
            $totalIn += (float) $r['amount'];
        } else {
            $totalOut += (float) $r['amount'];
        }
    }
    return [
        'total_in' => $totalIn,
        'total_out' => $totalOut,
        'balance' => $totalIn - $totalOut,
    ];
}

/**
 * Combined business report grouped by day OR month.
 * $groupBy: 'day' groups by checkin_date/entry_date (YYYY-MM-DD),
 *           'month' groups by YYYY-MM.
 * Returns rows sorted by period, each with: period, bookings_count, invoice_total,
 * collected_total, petty_cash_in, petty_cash_out, petty_cash_net.
 */
function combinedBusinessReport(PDO $pdo, string $from, string $to, string $groupBy = 'day'): array
{
    $bookings = bookingsInDateRange($pdo, $from, $to);
    $pettyCash = pettyCashEntries($pdo, $from, $to);
    $posSales = posSalesInRange($pdo, $from, $to);

    $len = $groupBy === 'month' ? 7 : 10; // 'YYYY-MM' vs 'YYYY-MM-DD'

    $periods = [];

    foreach ($bookings as $b) {
        $key = substr($b['checkin_date'], 0, $len);
        if (!isset($periods[$key])) {
            $periods[$key] = periodTemplate($key);
        }
        $bill = calcBill($b);
        $periods[$key]['bookings_count']++;
        $periods[$key]['invoice_total'] += $bill['grand_total'];
        $periods[$key]['collected_total'] += $bill['advance'];
    }

    foreach ($posSales as $sale) {
        $key = substr($sale['created_at'], 0, $len);
        if (!isset($periods[$key])) $periods[$key] = periodTemplate($key);
        $periods[$key]['pos_sales_count']++;
        $periods[$key]['pos_sales_total'] += (float) $sale['total'];
    }

    foreach ($pettyCash as $p) {
        $key = substr($p['entry_date'], 0, $len);
        if (!isset($periods[$key])) {
            $periods[$key] = periodTemplate($key);
        }
        if ($p['type'] === 'In') {
            $periods[$key]['petty_cash_in'] += (float) $p['amount'];
        } else {
            $periods[$key]['petty_cash_out'] += (float) $p['amount'];
        }
    }

    foreach ($periods as &$row) {
        $row['petty_cash_net'] = $row['petty_cash_in'] - $row['petty_cash_out'];
    }
    unset($row);

    ksort($periods);
    return array_values($periods);
}

function periodTemplate(string $key): array
{
    return [
        'period' => $key,
        'bookings_count' => 0,
        'invoice_total' => 0.0,
        'collected_total' => 0.0,
        'petty_cash_in' => 0.0,
        'petty_cash_out' => 0.0,
        'petty_cash_net' => 0.0,
        'pos_sales_count' => 0,
        'pos_sales_total' => 0.0,
    ];
}

/**
 * Net petty cash balance from all entries strictly before the given date.
 */
function pettyCashOpeningBalance(PDO $pdo, string $beforeDate): float
{
    $stmt = $pdo->prepare('SELECT type, amount FROM petty_cash WHERE entry_date < ?');
    $stmt->execute([$beforeDate]);
    $net = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $net += ($r['type'] === 'In' ? 1 : -1) * (float) $r['amount'];
    }
    return $net;
}

/**
 * Payment/settlement history for one booking, newest first.
 */
function getPaymentsForBooking(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_date DESC, id DESC');
    $stmt->execute([$bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Records a due settlement: logs it in the payments table AND increases the
 * booking's advance_paid so balance_due (via calcBill) reflects it everywhere
 * automatically — dashboard, reports, statements, invoices all stay in sync.
 * Returns false if the booking doesn't exist.
 */
function recordPayment(PDO $pdo, int $bookingId, string $date, float $amount, string $method, string $notes): bool
{
    $stmt = $pdo->prepare('SELECT id FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    if (!$stmt->fetchColumn()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO payments (booking_id, payment_date, amount, method, notes) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$bookingId, $date, $amount, $method, $notes]);

        $upd = $pdo->prepare('UPDATE bookings SET advance_paid = advance_paid + ? WHERE id = ?');
        $upd->execute([$amount, $bookingId]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Full payment/settlement history for a customer across all their bookings, newest first.
 */
function customerPayments(PDO $pdo, string $name, string $phone = ''): array
{
    $sql = 'SELECT p.*, b.booking_no, b.room_type
            FROM payments p
            JOIN bookings b ON b.id = p.booking_id
            WHERE b.customer_name = :name';
    $params = ['name' => $name];
    if ($phone !== '') {
        $sql .= ' AND b.phone = :phone';
        $params['phone'] = $phone;
    }
    $sql .= ' ORDER BY p.payment_date DESC, p.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * All non-cancelled bookings that still have a balance due, largest due first.
 */
function bookingsWithDue(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM bookings WHERE status != 'Cancelled' ORDER BY checkin_date ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $due = [];
    foreach ($rows as $r) {
        $bill = calcBill($r);
        if ($bill['balance_due'] > 0.005) {
            $r['bill'] = $bill;
            $due[] = $r;
        }
    }
    usort($due, fn($a, $b) => $b['bill']['balance_due'] <=> $a['bill']['balance_due']);
    return $due;
}

/**
 * All company/settings key-value pairs as an associative array.
 */
function getSettings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

/**
 * A single setting value, with a fallback default if not set.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? $val : $default;
}

/**
 * Insert or update one setting.
 */
function setSetting(PDO $pdo, string $key, string $value): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = :v2');
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v2');
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    }
}

/**
 * Verifies a username/password against the users table.
 * Returns the user row (without the hash) on success, or null on failure.
 */
function verifyLogin(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']);
        return $user;
    }
    return null;
}

/**
 * All users (without password hashes), newest first.
 */
function listUsers(PDO $pdo): array
{
    return $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/* =======================================================================
 * POS (Point of Sale)
 * ===================================================================== */

function generateSaleNo(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) FROM pos_sales WHERE sale_no LIKE 'POS{$year}%'");
    $count = (int) $stmt->fetchColumn() + 1;
    return sprintf('POS%s-%05d', $year, $count);
}

/**
 * Active POS products, optionally filtered by a search term (name/sku/category).
 */
function posProductList(PDO $pdo, string $q = ''): array
{
    $sql = 'SELECT * FROM pos_products WHERE active = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (name LIKE :q OR sku LIKE :q OR category LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY category, name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ALL products including inactive ones, for the management screen.
 */
function posProductListAll(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM pos_products ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Processes a checkout: validates stock server-side, computes totals,
 * inserts the sale + line items, and decrements stock — all in one transaction.
 *
 * $cartItems: array of ['id' => productId, 'qty' => int]
 * Returns ['ok' => bool, 'sale_id' => int|null, 'error' => string|null]
 */
function posCreateSale(PDO $pdo, array $cartItems, float $discount, float $taxPercent,
                        string $paymentMethod, float $tendered, string $customerName,
                        string $notes, string $cashier, ?int $posAccountId = null, ?int $bookingId = null): array
{
    if (!$cartItems) {
        return ['ok' => false, 'sale_id' => null, 'error' => 'Cart is empty.'];
    }

    $pdo->beginTransaction();
    try {
        $subtotal = 0.0;
        $lines = [];

        foreach ($cartItems as $item) {
            $productId = (int) $item['id'];
            $qty = max(1, (int) $item['qty']);

            $stmt = $pdo->prepare('SELECT * FROM pos_products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception('A product in the cart no longer exists.');
            }
            if ($product['stock_qty'] < $qty) {
                throw new Exception('Not enough stock for "' . $product['name'] . '" (only ' . (int) $product['stock_qty'] . ' left).');
            }

            $lineTotal = $product['price'] * $qty;
            $subtotal += $lineTotal;
            $lines[] = [
                'product_id' => $productId,
                'product_name' => $product['name'],
                'unit_price' => $product['price'],
                'qty' => $qty,
                'line_total' => $lineTotal,
            ];
        }

        if ($discount < 0) $discount = 0;
        $taxableAmount = max(0, $subtotal - $discount);
        $taxAmount = $taxableAmount * ($taxPercent / 100);
        $total = $taxableAmount + $taxAmount;
        $changeDue = max(0, $tendered - $total);

        $saleNo = generateSaleNo($pdo);
        $ins = $pdo->prepare("INSERT INTO pos_sales
            (sale_no, customer_name, subtotal, discount, tax_percent, tax_amount, total,
             payment_method, amount_tendered, change_due, cashier, notes, pos_account_id, booking_id)
            VALUES (:sale_no, :customer_name, :subtotal, :discount, :tax_percent, :tax_amount, :total,
             :payment_method, :amount_tendered, :change_due, :cashier, :notes, :pos_account_id, :booking_id)");
        $ins->execute([
            'sale_no' => $saleNo,
            'customer_name' => $customerName,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'payment_method' => $paymentMethod,
            'amount_tendered' => $tendered,
            'change_due' => $changeDue,
            'cashier' => $cashier,
            'notes' => $notes,
            'pos_account_id' => $posAccountId,
            'booking_id' => $bookingId,
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO pos_sale_items (sale_id, product_id, product_name, unit_price, qty, line_total)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE pos_products SET stock_qty = stock_qty - ? WHERE id = ?');

        foreach ($lines as $l) {
            $itemStmt->execute([$saleId, $l['product_id'], $l['product_name'], $l['unit_price'], $l['qty'], $l['line_total']]);
            $stockStmt->execute([$l['qty'], $l['product_id']]);
        }

        $pdo->commit();
        return ['ok' => true, 'sale_id' => $saleId, 'error' => null];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'sale_id' => null, 'error' => $e->getMessage()];
    }
}

function posAccountList(PDO $pdo, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM pos_accounts';
    if ($activeOnly) $sql .= ' WHERE active = 1';
    $sql .= ' ORDER BY account_name';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function posAccountTotals(PDO $pdo, string $from, string $to): array
{
    $accounts = posAccountList($pdo, false);
    $stmt = $pdo->prepare("SELECT pos_account_id, payment_method, COUNT(*) AS sale_count, COALESCE(SUM(total),0) AS total
        FROM pos_sales WHERE date(created_at) BETWEEN ? AND ? AND voided = 0
        GROUP BY pos_account_id, payment_method");
    $stmt->execute([$from, $to]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(string)$r['pos_account_id']] = $r;
    $out = [];
    foreach ($accounts as $a) {
        $r = $map[(string)$a['id']] ?? ['sale_count'=>0,'total'=>0];
        $out[] = ['id'=>(int)$a['id'], 'account_name'=>$a['account_name'], 'account_type'=>$a['account_type'], 'sale_count'=>(int)$r['sale_count'], 'total'=>(float)$r['total']];
    }
    return $out;
}

function posGetSale(PDO $pdo, int $saleId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = ?');
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    return $sale ?: null;
}

function posGetSaleItems(PDO $pdo, int $saleId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY id ASC');
    $stmt->execute([$saleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Non-voided sales within a date range (inclusive), newest first.
 */
function posSalesInRange(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT * FROM pos_sales
        WHERE date(created_at) BETWEEN :from AND :to AND voided = 0
        ORDER BY created_at DESC");
    $stmt->execute(['from' => $from, 'to' => $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Totals for a set of already-fetched pos_sales rows.
 */
function posSalesTotals(array $rows): array
{
    $totals = ['count' => count($rows), 'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0];
    foreach ($rows as $r) {
        $totals['subtotal'] += (float) $r['subtotal'];
        $totals['discount'] += (float) $r['discount'];
        $totals['tax'] += (float) $r['tax_amount'];
        $totals['total'] += (float) $r['total'];
    }
    return $totals;
}

/**
 * Voids a sale and restocks its items (use for mistaken/cancelled sales).
 */
function posVoidSale(PDO $pdo, int $saleId): bool
{
    $pdo->beginTransaction();
    try {
        $items = posGetSaleItems($pdo, $saleId);
        $stockStmt = $pdo->prepare('UPDATE pos_products SET stock_qty = stock_qty + ? WHERE id = ?');
        foreach ($items as $it) {
            if ($it['product_id']) {
                $stockStmt->execute([$it['qty'], $it['product_id']]);
            }
        }
        $upd = $pdo->prepare('UPDATE pos_sales SET voided = 1 WHERE id = ?');
        $upd->execute([$saleId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}


function whatsappDigits(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    // Sri Lankan local numbers are commonly entered as 07xxxxxxxx; convert to 947xxxxxxxx.
    if (str_starts_with($digits, '0') && strlen($digits) === 10) $digits = '94' . substr($digits, 1);
    return $digits;
}

function bookingPdfToken(int $bookingId): string
{
    return hash_hmac('sha256', (string) $bookingId, WHATSAPP_PDF_SECRET);
}

function bookingPdfUrl(int $bookingId): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base === '.') $base = '';
    return $scheme . '://' . $host . $base . '/booking_pdf.php?id=' . $bookingId . '&token=' . bookingPdfToken($bookingId);
}

/* =======================================================================
 * AI Technologies / Hotel Intelligence
 * Local, dependency-free smart analytics. It works without an external API
 * and can be extended later with a hosted AI provider.
 * ======================================================================= */
function aiHotelInsights(PDO $pdo): array
{
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $next7 = date('Y-m-d', strtotime('+7 days'));
    $totalRooms = 0;
    foreach ($pdo->query('SELECT type_name, total_rooms FROM room_types')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE room_type = ? AND status = 'Active'");
        $st->execute([$r['type_name']]);
        $tracked = (int)$st->fetchColumn();
        $totalRooms += $tracked > 0 ? $tracked : (int)$r['total_rooms'];
    }
    $todayBooked = 0;
    if ($totalRooms > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('Cancelled','Checked-Out') AND checkin_date < ? AND checkout_date > ?");
        $st->execute([$tomorrow, $today]);
        $todayBooked = (int)$st->fetchColumn();
    }
    $occupancy = $totalRooms > 0 ? min(100, ($todayBooked / $totalRooms) * 100) : 0;

    $last30 = bookingsInDateRange($pdo, date('Y-m-d', strtotime('-29 days')), $today);
    $last30Revenue = summarizeBookings($last30)['total_invoice'];
    $last7 = bookingsInDateRange($pdo, date('Y-m-d', strtotime('-6 days')), $today);
    $avgDailyBookings = count($last7) / 7;

    $next7Counts = [];
    for ($i=0; $i<7; $i++) {
        $d = date('Y-m-d', strtotime('+' . $i . ' days'));
        $d2 = date('Y-m-d', strtotime($d . ' +1 day'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('Cancelled','Checked-Out') AND checkin_date < ? AND checkout_date > ?");
        $st->execute([$d2, $d]);
        $next7Counts[$d] = (int)$st->fetchColumn();
    }
    $peakDate = array_keys($next7Counts, max($next7Counts))[0];
    $peakOccupancy = $totalRooms > 0 ? min(100, (max($next7Counts) / $totalRooms) * 100) : 0;

    $due = bookingsWithDue($pdo);
    $roomDemand = groupByRoomType($last30);
    $insights = [
        'Current occupancy is ' . number_format($occupancy, 1) . '% (' . $todayBooked . '/' . $totalRooms . ' rooms).',
        'Last 30 days generated ' . count($last30) . ' bookings with estimated invoice revenue of ' . money($last30Revenue) . '.',
        'Average booking volume over the last 7 days is ' . number_format($avgDailyBookings, 1) . ' booking(s) per day.',
        'The busiest forecast day in the next 7 days is ' . date('d M Y', strtotime($peakDate)) . ' at about ' . number_format($peakOccupancy, 1) . '% room occupancy.'
    ];
    if ($roomDemand) $insights[] = 'Highest recent room-type demand: ' . $roomDemand[0]['room_type'] . ' (' . $roomDemand[0]['count'] . ' booking(s) in the last 30 days).';

    $recommendations = [];
    if ($occupancy < 40) $recommendations[] = 'Low occupancy detected: consider a short-term promotion or flexible rate for the next few days.';
    elseif ($occupancy >= 80) $recommendations[] = 'High occupancy detected: consider protecting inventory and reviewing rates for peak dates.';
    else $recommendations[] = 'Occupancy is in a moderate range: keep monitoring demand and room-type mix.';
    if (count($due) > 0) $recommendations[] = count($due) . ' booking(s) have outstanding balances; prioritize follow-up before checkout.';
    if ($roomDemand && $roomDemand[0]['count'] >= 3) $recommendations[] = 'The ' . $roomDemand[0]['room_type'] . ' category is showing strong demand; consider keeping some inventory open for it.';
    if ($peakOccupancy >= 80) $recommendations[] = 'Prepare staffing and housekeeping capacity for ' . date('d M Y', strtotime($peakDate)) . '.';
    if (!$recommendations) $recommendations[] = 'No major operational alerts detected from current data.';

    return [
        'occupancy' => $occupancy,
        'today_booked' => $todayBooked,
        'total_rooms' => $totalRooms,
        'last30_revenue' => $last30Revenue,
        'avg_daily_bookings' => $avgDailyBookings,
        'peak_date' => $peakDate,
        'peak_occupancy' => $peakOccupancy,
        'due_count' => count($due),
        'insights' => $insights,
        'recommendations' => $recommendations,
    ];
}

function aiAssistantAnswer(PDO $pdo, string $question): string
{
    $q = strtolower(trim($question));
    $ai = aiHotelInsights($pdo);
    if ($q === '') return 'Ask about occupancy, revenue, room demand, outstanding dues, or the next 7 days.';
    if (strpos($q, 'occup') !== false || strpos($q, 'room') !== false) return 'Current occupancy is ' . number_format($ai['occupancy'], 1) . '% (' . $ai['today_booked'] . '/' . $ai['total_rooms'] . ' rooms). The forecast peak in the next 7 days is ' . date('d M Y', strtotime($ai['peak_date'])) . ' at about ' . number_format($ai['peak_occupancy'], 1) . '%.';
    if (strpos($q, 'revenue') !== false || strpos($q, 'income') !== false || strpos($q, 'sales') !== false) return 'Estimated booking invoice revenue for the last 30 days is ' . money($ai['last30_revenue']) . '. Average booking volume for the last 7 days is ' . number_format($ai['avg_daily_bookings'], 1) . ' per day.';
    if (strpos($q, 'due') !== false || strpos($q, 'payment') !== false || strpos($q, 'balance') !== false) return 'There are currently ' . $ai['due_count'] . ' booking(s) with outstanding balances. Use Due Settlements to follow them up.';
    if (strpos($q, 'forecast') !== false || strpos($q, 'next') !== false || strpos($q, 'future') !== false) return 'The busiest forecast day in the next 7 days is ' . date('d M Y', strtotime($ai['peak_date'])) . ', with estimated occupancy around ' . number_format($ai['peak_occupancy'], 1) . '%.';
    return 'Based on the latest hotel data: ' . $ai['recommendations'][0];
}
