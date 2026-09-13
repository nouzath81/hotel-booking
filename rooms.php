<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Rooms & Availability';
$errors = [];

// Add new room type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_type'])) {
    $typeName = trim($_POST['type_name'] ?? '');
    $totalRooms = (int) ($_POST['total_rooms'] ?? 0);
    $defaultRate = (float) ($_POST['default_rate'] ?? 0);

    if ($typeName === '') $errors[] = 'Room type name is required.';
    if ($totalRooms < 0) $errors[] = 'Total rooms cannot be negative.';

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO room_types (type_name, total_rooms, default_rate) VALUES (?, ?, ?)');
            $stmt->execute([$typeName, $totalRooms, $defaultRate]);
            header('Location: rooms.php?added=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'A room type with that name already exists.';
        }
    }
}

// Update existing room type count / rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_type'])) {
    $id = (int) $_POST['id'];
    $typeName = trim($_POST['type_name'] ?? '');
    $totalRooms = (int) ($_POST['total_rooms'] ?? 0);
    $defaultRate = (float) ($_POST['default_rate'] ?? 0);
    $err = updateRoomType($pdo, $id, $typeName, $totalRooms, $defaultRate);
    if ($err) {
        $errors[] = $err;
    } else {
        header('Location: rooms.php?updated=1');
        exit;
    }
}

// Delete a room type. Use POST so accidental clicks cannot remove data, and
// remove dependent room records/details in the same transaction. Existing
// bookings keep their room-type text and are intentionally NOT deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_type'])) {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT type_name FROM room_types WHERE id = ?');
        $stmt->execute([$id]);
        $typeName = $stmt->fetchColumn();
        if ($typeName === false) {
            throw new RuntimeException('Room type not found.');
        }

        // These tables store current room/type metadata only. Do not touch bookings.
        $pdo->prepare('DELETE FROM hotel_rooms WHERE room_type = ?')->execute([$typeName]);
        $pdo->prepare('DELETE FROM room_type_details WHERE room_type = ?')->execute([$typeName]);
        $pdo->prepare('DELETE FROM room_types WHERE id = ?')->execute([$id]);
        $pdo->commit();
        header('Location: rooms.php?deleted=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Room type could not be deleted: ' . $e->getMessage();
    }
}

// Add an individual room number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    $roomNo = trim($_POST['room_no'] ?? '');
    $roomType = trim($_POST['room_type'] ?? '');
    $err = addHotelRoom($pdo, $roomNo, $roomType);
    if ($err) {
        $errors[] = $err;
    } else {
        header('Location: rooms.php?room_added=1');
        exit;
    }
}

// Toggle a room's status (Active / Maintenance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_room_status'])) {
    $id = (int) $_POST['id'];
    $status = $_POST['status'] === 'Maintenance' ? 'Maintenance' : 'Active';
    updateHotelRoomStatus($pdo, $id, $status);
    header('Location: rooms.php?room_updated=1');
    exit;
}

// Delete an individual room
if (isset($_GET['delete_room'])) {
    deleteHotelRoom($pdo, (int) $_GET['delete_room']);
    header('Location: rooms.php?room_deleted=1');
    exit;
}

// Date range for availability check (defaults: today -> tomorrow)
$checkin  = $_GET['checkin']  ?? date('Y-m-d');
$checkout = $_GET['checkout'] ?? date('Y-m-d', strtotime('+1 day'));
if ($checkout <= $checkin) {
    $checkout = date('Y-m-d', strtotime($checkin . ' +1 day'));
}

$availability = allRoomAvailability($pdo, $checkin, $checkout);
$roomTypeNames = array_column($availability, 'type_name');
$allRooms = listAllHotelRooms($pdo);

require __DIR__ . '/includes/header.php';
?>

<h1>Rooms &amp; Availability</h1>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Room type added.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Room type updated.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Room type removed.</div><?php endif; ?>
<?php if (isset($_GET['room_added'])): ?><div class="alert alert-success">Room number added.</div><?php endif; ?>
<?php if (isset($_GET['room_updated'])): ?><div class="alert alert-success">Room status updated.</div><?php endif; ?>
<?php if (isset($_GET['room_deleted'])): ?><div class="alert alert-success">Room number removed.</div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Check Availability for a Date Range</h3>
    <form method="get" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Check-in</label>
            <input type="date" name="checkin" value="<?= h($checkin) ?>">
        </div>
        <div class="form-group">
            <label>Check-out</label>
            <input type="date" name="checkout" value="<?= h($checkout) ?>">
        </div>
        <div class="form-group" style="flex:0;">
            <button class="btn" type="submit">Check</button>
        </div>
    </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;" id="rooms-availability-table">
<div style="padding:12px 16px;background:#f7fafc;border-bottom:1px solid #e5e7eb;font-size:.85rem;color:var(--muted);">
    <strong>Live availability:</strong> Booked counts overlapping Confirmed, Reserved and Checked-In room capacity for the selected dates. Cancelled and Checked-Out bookings do not reduce availability.
</div>
<table>
    <thead>
        <tr>
            <th>Room Type</th>
            <th>Total Rooms</th>
            <th>Booked (<?= h($checkin) ?> &rarr; <?= h($checkout) ?>)</th>
            <th>Remaining</th>
            <th>Default Rate</th>
            <th>Manage</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$availability): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px;">No room types yet — add one below.</td></tr>
    <?php endif; ?>
    <?php foreach ($availability as $t): ?>
        <tr>
            <td><?= h($t['type_name']) ?></td>
            <td><?= (int) $t['total'] ?></td>
            <td><?= (int) $t['booked'] ?></td>
            <td>
                <?php if ($t['remaining'] <= 0): ?>
                    <span class="badge badge-cancelled">Full</span>
                <?php elseif ($t['remaining'] <= 2): ?>
                    <span class="badge badge-checkedin"><?= (int) $t['remaining'] ?> left</span>
                <?php else: ?>
                    <span class="badge badge-confirmed"><?= (int) $t['remaining'] ?> available</span>
                <?php endif; ?>
            </td>
            <td><?= money($t['default_rate']) ?></td>
            <td class="actions">
                <button type="button" class="btn btn-sm" onclick="toggleEdit(<?= $t['id'] ?>)">Edit</button>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this room type? Its current room records will be removed, but existing bookings will keep their saved room-type text.');">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit" name="delete_type" value="1">Delete</button>
                </form>
            </td>
        </tr>
        <tr id="edit-row-<?= $t['id'] ?>" style="display:none;">
            <td colspan="6">
                <form method="post" class="form-row" style="align-items:flex-end;margin:0;">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <div class="form-group">
                        <label>Room Type Name</label>
                        <input type="text" name="type_name" value="<?= h($t['type_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Total Rooms <?= $t['detail']['tracked'] ? '(auto, from room numbers below)' : '(manual)' ?></label>
                        <input type="number" min="0" name="total_rooms" value="<?= (int) $t['total_rooms'] ?>" <?= $t['detail']['tracked'] ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Default Rate</label>
                        <input type="number" step="0.01" min="0" name="default_rate" value="<?= h($t['default_rate']) ?>">
                    </div>
                    <div class="form-group" style="flex:0;">
                        <button type="submit" name="update_type" value="1" class="btn btn-accent btn-sm">Save</button>
                    </div>
                </form>
            </td>
        </tr>
        <tr>
            <td colspan="6" style="background:#f9fbfd;">
                <?php if ($t['detail']['tracked']): ?>
                    <div style="font-size:0.85rem;padding:6px 4px;">
                        <strong style="color:var(--accent);">Physically free (<?= count($t['detail']['available']) ?>):</strong>
                        <?= $t['detail']['available'] ? h(implode(', ', $t['detail']['available'])) : '<span style="color:var(--muted);">none</span>' ?>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong style="color:var(--danger);">Booked rooms (<?= count($t['detail']['booked']) ?>):</strong>
                        <?= $t['detail']['booked'] ? h(implode(', ', $t['detail']['booked'])) : '<span style="color:var(--muted);">none</span>' ?>
                        <?php if ((int)($t['detail']['unassigned_booked'] ?? 0) > 0): ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;<strong style="color:var(--danger);">Unassigned booked: <?= (int)$t['detail']['unassigned_booked'] ?></strong>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:0.82rem;color:var(--muted);padding:6px 4px;">
                        No individual room numbers added for this type yet — showing total count only.
                        Add room numbers below to see exactly which rooms are available/booked.
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>


<!-- Monthly booking calendar -->
<?php
$calendarMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$calendarStart = new DateTimeImmutable($calendarMonth . '-01');
$calendarEnd = $calendarStart->modify('first day of next month');
$gridStart = $calendarStart->modify('monday this week');
$gridEnd = $calendarEnd->modify('sunday this week');

$calStmt = $pdo->prepare("SELECT * FROM bookings WHERE checkin_date < :month_end AND checkout_date > :month_start ORDER BY checkin_date, room_type, room_no, customer_name");
$calStmt->execute([
    ':month_start' => $calendarStart->format('Y-m-d'),
    ':month_end' => $calendarEnd->format('Y-m-d'),
]);
$calendarBookings = $calStmt->fetchAll(PDO::FETCH_ASSOC);
$calendarByDate = [];
foreach ($calendarBookings as $cb) {
    $ci = new DateTimeImmutable($cb['checkin_date']);
    $co = new DateTimeImmutable($cb['checkout_date']);
    // Show the booking on every occupied night in the calendar.
    $from = $ci > $gridStart ? $ci : $gridStart;
    $to = $co < $gridEnd ? $co : $gridEnd;
    for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $calendarByDate[$key][] = $cb;
    }
}
$prevMonth = $calendarStart->modify('-1 month')->format('Y-m');
$nextMonth = $calendarStart->modify('+1 month')->format('Y-m');
$todayKey = date('Y-m-d');
?>

<div class="card rooms-booking-calendar" id="booking-calendar" style="margin-top:18px;">
    <div class="calendar-head">
        <div>
            <h3 style="margin:0 0 4px;">Booking Calendar</h3>
            <div class="calendar-subtitle">All booking details are shown on each occupied date. Font size is optimized for the calendar.</div>
        </div>
        <div class="calendar-controls">
            <a class="btn btn-sm btn-secondary" href="rooms.php?month=<?= h($prevMonth) ?>#booking-calendar">&larr; Previous</a>
            <strong class="calendar-month-title"><?= h($calendarStart->format('F Y')) ?></strong>
            <a class="btn btn-sm btn-secondary" href="rooms.php?month=<?= h($nextMonth) ?>#booking-calendar">Next &rarr;</a>
            <a class="btn btn-sm" href="rooms.php?month=<?= h(date('Y-m')) ?>#booking-calendar">Today</a>
        </div>
    </div>

    <div class="calendar-legend">
        <span><i class="legend-dot confirmed"></i> Confirmed</span>
        <span><i class="legend-dot reserved"></i> Reserved</span>
        <span><i class="legend-dot checkedin"></i> Checked-In</span>
        <span><i class="legend-dot cancelled"></i> Cancelled</span>
        <span class="calendar-note">Click a booking to open the full booking details.</span>
    </div>

    <div class="booking-calendar-grid">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $wd): ?>
            <div class="calendar-weekday"><?= $wd ?></div>
        <?php endforeach; ?>

        <?php for ($d = $gridStart; $d < $gridEnd; $d = $d->modify('+1 day')):
            $key = $d->format('Y-m-d');
            $inMonth = $d->format('Y-m') === $calendarMonth;
            $dayBookings = $calendarByDate[$key] ?? [];
        ?>
            <div class="calendar-day <?= $inMonth ? '' : 'outside-month' ?> <?= $key === $todayKey ? 'today' : '' ?>">
                <div class="calendar-day-number"><?= (int)$d->format('j') ?></div>
                <?php if (!$dayBookings): ?>
                    <div class="calendar-empty">No booking</div>
                <?php else: ?>
                    <?php foreach ($dayBookings as $cb):
                        $status = (string)($cb['status'] ?? 'Confirmed');
                        $statusClass = strtolower(str_replace([' ', '_'], '-', $status));
                        $bill = calcBill($cb);
                        $isArrival = $cb['checkin_date'] === $key;
                        $isDeparture = $cb['checkout_date'] === $key;
                    ?>
                        <a class="calendar-booking status-<?= h($statusClass) ?>" href="view_booking.php?id=<?= (int)$cb['id'] ?>" title="Open booking <?= h($cb['booking_no']) ?>">
                            <div class="cb-top">
                                <strong><?= h($cb['booking_no'] ?: 'Booking #'.$cb['id']) ?></strong>
                                <span><?= h($status) ?></span>
                            </div>
                            <div class="cb-name"><?= h($cb['customer_name']) ?></div>
                            <div class="cb-room"><b>Room:</b> <?= h($cb['room_type'] ?: '-') ?><?= $cb['room_no'] ? ' · '.h($cb['room_no']) : ' · Unassigned' ?> · <?= (int)($cb['room_count'] ?? 1) ?> room(s)</div>
                            <div class="cb-dates"><b>Stay:</b> <?= h($cb['checkin_date']) ?> → <?= h($cb['checkout_date']) ?> · <?= (int)($cb['nights']) ?> night(s)</div>
                            <div class="cb-contact"><b>Phone:</b> <?= h($cb['phone'] ?: '-') ?><?= $cb['whatsapp_no'] ? ' · <b>WA:</b> '.h($cb['whatsapp_no']) : '' ?></div>
                            <div class="cb-money"><b>Total:</b> <?= money($bill['grand_total']) ?> · <b>Advance:</b> <?= money($bill['advance']) ?> · <b>Due:</b> <?= money($bill['balance_due']) ?></div>
                            <?php if ($cb['notes']): ?><div class="cb-notes"><b>Note:</b> <?= h($cb['notes']) ?></div><?php endif; ?>
                            <div class="cb-markers"><?= $isArrival ? '<span>ARRIVAL</span>' : '' ?><?= $isDeparture ? '<span>DEPARTURE</span>' : '' ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>

<style>
.rooms-booking-calendar { overflow:hidden; }
.calendar-head { display:flex; justify-content:space-between; gap:14px; align-items:center; flex-wrap:wrap; padding-bottom:12px; border-bottom:1px solid #e5e7eb; }
.calendar-subtitle { color:var(--muted); font-size:.76rem; }
.calendar-controls { display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
.calendar-month-title { min-width:125px; text-align:center; font-size:.95rem; }
.calendar-legend { display:flex; gap:13px; flex-wrap:wrap; align-items:center; padding:10px 0; color:var(--muted); font-size:.72rem; }
.legend-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:4px; background:#64748b; }
.legend-dot.confirmed { background:#16a34a; } .legend-dot.reserved { background:#f59e0b; } .legend-dot.checkedin { background:#2563eb; } .legend-dot.cancelled { background:#dc2626; }
.calendar-note { margin-left:auto; }
.booking-calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); border-top:1px solid #dfe5ec; border-left:1px solid #dfe5ec; min-width:980px; }
.calendar-weekday { background:#f3f6f9; color:#475569; font-weight:700; font-size:.72rem; padding:8px; text-align:center; border-right:1px solid #dfe5ec; border-bottom:1px solid #dfe5ec; }
.calendar-day { min-height:190px; padding:5px; background:#fff; border-right:1px solid #dfe5ec; border-bottom:1px solid #dfe5ec; overflow:hidden; }
.calendar-day.outside-month { background:#f8fafc; opacity:.78; }
.calendar-day.today { box-shadow:inset 0 0 0 2px #2563eb; }
.calendar-day-number { font-size:.8rem; font-weight:800; color:#334155; margin:1px 2px 5px; }
.calendar-empty { color:#94a3b8; font-size:.65rem; text-align:center; padding:22px 2px; }
.calendar-booking { display:block; text-decoration:none; color:#172033; background:#f8fafc; border:1px solid #dbe2ea; border-left:3px solid #64748b; border-radius:5px; padding:5px 5px 4px; margin:0 0 5px; font-size:10px; line-height:1.28; }
.calendar-booking:hover { box-shadow:0 2px 7px rgba(0,0,0,.12); transform:translateY(-1px); }
.calendar-booking.status-confirmed { border-left-color:#16a34a; } .calendar-booking.status-reserved { border-left-color:#f59e0b; } .calendar-booking.status-checked-in { border-left-color:#2563eb; } .calendar-booking.status-cancelled { border-left-color:#dc2626; opacity:.72; }
.cb-top { display:flex; justify-content:space-between; gap:4px; font-size:9.5px; } .cb-top span { font-weight:700; white-space:nowrap; }
.cb-name { font-size:10.5px; font-weight:800; margin:2px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cb-room,.cb-dates,.cb-contact,.cb-money,.cb-notes { margin-top:1px; word-break:break-word; }
.cb-money { font-weight:500; } .cb-notes { color:#64748b; }
.cb-markers { display:flex; gap:3px; margin-top:3px; } .cb-markers span { font-size:7.5px; font-weight:800; padding:1px 3px; border-radius:3px; background:#e2e8f0; color:#334155; }
@media (max-width:1100px) { .rooms-booking-calendar { overflow-x:auto; } }
@media print { .rooms-booking-calendar { box-shadow:none; } .calendar-controls { display:none; } .booking-calendar-grid { min-width:0; } .calendar-booking { font-size:8px; } .cb-name { font-size:9px; } }
</style>

<div class="card">
    <h3>Add Room Type</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Type Name</label>
            <input type="text" name="type_name" placeholder="e.g. Executive Suite" required>
        </div>
        <div class="form-group">
            <label>Total Rooms</label>
            <input type="number" min="0" name="total_rooms" value="0" required>
        </div>
        <div class="form-group">
            <label>Default Rate / Night</label>
            <input type="number" step="0.01" min="0" name="default_rate" value="0">
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" name="add_type" value="1" class="btn btn-accent">Add</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Room Numbers</h3>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:-6px;">
        Add individual room numbers under a room type to track exactly which rooms are booked or
        available (instead of just a total count). Once a type has at least one room number added,
        its Total Rooms above becomes automatic.
    </p>

    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Room Number</label>
            <input type="text" name="room_no" placeholder="e.g. 101" required>
        </div>
        <div class="form-group">
            <label>Room Type</label>
            <select name="room_type" required>
                <option value="">— Select —</option>
                <?php foreach ($roomTypeNames as $rt): ?>
                    <option value="<?= h($rt) ?>"><?= h($rt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" name="add_room" value="1" class="btn btn-accent">Add Room</button>
        </div>
    </form>

    <div style="overflow-x:auto;margin-top:14px;">
    <table>
        <thead><tr><th>Room No.</th><th>Type</th><th>Status</th><th>Manage</th></tr></thead>
        <tbody>
        <?php if (!$allRooms): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:20px;">No individual room numbers added yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($allRooms as $r): ?>
            <tr>
                <?php $liveStatus = liveRoomStatus($pdo, (string)$r['room_no'], $checkin, $checkout); ?>
                <td><?= h($r['room_no']) ?></td>
                <td><?= h($r['room_type']) ?></td>
                <td>
                    <?php if ($liveStatus === 'Booked'): ?>
                        <span class="badge badge-cancelled">Booked</span>
                    <?php elseif ($liveStatus === 'Reserved'): ?>
                        <span class="badge badge-checkedin">Reserved</span>
                    <?php elseif ($liveStatus === 'Maintenance'): ?>
                        <span class="badge badge-checkedin">Maintenance</span>
                    <?php else: ?>
                        <span class="badge badge-confirmed">Available</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $r['status'] === 'Active' ? 'Maintenance' : 'Active' ?>">
                        <button type="submit" name="toggle_room_status" value="1" class="btn btn-sm">
                            Mark <?= $r['status'] === 'Active' ? 'Maintenance' : 'Active' ?>
                        </button>
                    </form>
                    <a class="btn btn-sm btn-danger" href="rooms.php?delete_room=<?= $r['id'] ?>"
                       onclick="return confirm('Remove room <?= h($r['room_no']) ?>?');">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-row-' + id);
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>

<div class="card" style="margin-top:15px;"><strong>Availability rules:</strong> <span style="color:var(--muted);">Bookings overlap when check-in is before checkout and checkout is after check-in. Cancelled and Checked-Out bookings do not reserve rooms. Maintenance rooms are excluded from active room capacity.</span> <button type="button" class="btn btn-sm btn-secondary" id="rooms-live-refresh">Refresh availability</button> <span id="rooms-live-status" style="font-size:.78rem;color:var(--muted);"></span></div>
<script>(function(){const b=document.getElementById('rooms-live-refresh'),s=document.getElementById('rooms-live-status');if(!b)return;b.onclick=async()=>{s.textContent='Refreshing…';try{const r=await fetch('api/room-availability.php?checkin=<?=rawurlencode($checkin)?>&checkout=<?=rawurlencode($checkout)?>&t='+Date.now(),{cache:'no-store'});const d=await r.json();if(!d.ok)throw 0;s.textContent='Live • '+d.updated_at;setTimeout(()=>location.reload(),150)}catch(e){s.textContent='Refresh failed'}}})();</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
