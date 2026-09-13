<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Edit Booking';
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    die('Booking not found.');
}

$errors = [];
$f = $existing;
$f['room_count'] = isset($f['room_count']) ? (int)$f['room_count'] : max(1, count(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$f['room_no'])))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['customer_name','phone','whatsapp_no','email','address','room_type',
              'checkin_date','checkout_date','rate_per_night','extra_charges',
              'discount','tax_percent','advance_paid','status','notes'] as $key) {
        $f[$key] = trim($_POST[$key] ?? '');
    }
    $selectedRooms = $_POST['room_no'] ?? [];
    if (!is_array($selectedRooms)) $selectedRooms = [$selectedRooms];
    $selectedRooms = array_values(array_unique(array_filter(array_map('trim', $selectedRooms), static fn($v) => $v !== '')));
    $f['room_no'] = implode(', ', $selectedRooms);
    $f['room_count'] = max(1, (int)($_POST['room_count'] ?? count($selectedRooms) ?: 1));

    if ($f['customer_name'] === '') $errors[] = 'Customer name is required.';
    if ($f['checkin_date'] === '') $errors[] = 'Check-in date is required.';
    if ($f['checkout_date'] === '') $errors[] = 'Check-out date is required.';
    if ($f['checkin_date'] && $f['checkout_date'] && $f['checkout_date'] <= $f['checkin_date']) {
        $errors[] = 'Check-out date must be after check-in date.';
    }
    if ($f['rate_per_night'] === '' || !is_numeric($f['rate_per_night'])) {
        $errors[] = 'Rate per night must be a number.';
    }

    if (!$errors) {
        $roomError = validateRoomBooking(
            $pdo, $f['room_type'], $f['room_no'], $f['checkin_date'],
            $f['checkout_date'], $f['status'], $id, (int)$f['room_count']
        );
        if ($roomError !== null) {
            $errors[] = $roomError;
        }
    }

    if (!$errors) {
        $nights = calcNights($f['checkin_date'], $f['checkout_date']);

        $stmt = $pdo->prepare("UPDATE bookings SET
            customer_name = :customer_name, phone = :phone, whatsapp_no = :whatsapp_no, email = :email, address = :address,
            room_type = :room_type, room_no = :room_no, room_count = :room_count, checkin_date = :checkin_date,
            checkout_date = :checkout_date, nights = :nights, rate_per_night = :rate_per_night,
            extra_charges = :extra_charges, discount = :discount, tax_percent = :tax_percent,
            advance_paid = :advance_paid, status = :status, notes = :notes
            WHERE id = :id");

        $stmt->execute([
            'customer_name' => $f['customer_name'],
            'phone' => $f['phone'],
            'whatsapp_no' => $f['whatsapp_no'],
            'email' => $f['email'],
            'address' => $f['address'],
            'room_type' => $f['room_type'],
            'room_no' => $f['room_no'],
            'room_count' => (int)$f['room_count'],
            'checkin_date' => $f['checkin_date'],
            'checkout_date' => $f['checkout_date'],
            'nights' => $nights,
            'rate_per_night' => (float) $f['rate_per_night'],
            'extra_charges' => (float) ($f['extra_charges'] ?: 0),
            'discount' => (float) ($f['discount'] ?: 0),
            'tax_percent' => (float) ($f['tax_percent'] ?: 0),
            'advance_paid' => (float) ($f['advance_paid'] ?: 0),
            'status' => $f['status'],
            'notes' => $f['notes'],
            'id' => $id,
        ]);

        header('Location: view_booking.php?id=' . $id . '&saved=1');
        exit;
    }
}

$roomTypes = $pdo->query('SELECT * FROM room_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/includes/header.php';
?>

<h1>Edit Booking — <?= h($existing['booking_no']) ?></h1>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
<form method="post">
    <h3>Customer Details</h3>
    <div class="form-row">
        <div class="form-group">
            <label>Customer Name *</label>
            <input type="text" name="customer_name" value="<?= h($f['customer_name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= h($f['phone']) ?>">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= h($f['email']) ?>">
        </div>
        <div class="form-group">
            <label>WhatsApp No.</label>
            <input type="text" name="whatsapp_no" value="<?= h($f['whatsapp_no']) ?>" placeholder="e.g. +94771234567">
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Address</label>
            <textarea name="address" rows="2"><?= h($f['address']) ?></textarea>
        </div>
    </div>

    <h3>Reservation Details</h3>
    <div class="form-row">
        <div class="form-group">
            <label>Room Type</label>
            <select name="room_type" id="room_type">
                <option value="">— Select —</option>
                <?php foreach ($roomTypes as $rt): ?>
                    <option value="<?= h($rt['type_name']) ?>"
                        data-rate="<?= h($rt['default_rate']) ?>"
                        <?= $f['room_type'] === $rt['type_name'] ? 'selected' : '' ?>>
                        <?= h($rt['type_name']) ?> (<?= (int) $rt['total_rooms'] ?> total)
                    </option>
                <?php endforeach; ?>
                <?php if ($f['room_type'] && !in_array($f['room_type'], array_column($roomTypes, 'type_name'), true)): ?>
                    <option value="<?= h($f['room_type']) ?>" selected><?= h($f['room_type']) ?> (custom)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Room No. (multiple allowed)</label>
            <input type="text" name="room_no" id="room_no" value="<?= h($f['room_no']) ?>" placeholder="e.g. 101, 102">
            <small style="color:var(--muted);">Enter multiple room numbers separated by commas.</small>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status">
                <?php foreach (['Confirmed', 'Reserved', 'Checked-In', 'Checked-Out', 'Cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Check-in Date *</label>
            <input type="date" id="checkin_date" name="checkin_date" value="<?= h($f['checkin_date']) ?>" required>
        </div>
        <div class="form-group">
            <label>Check-out Date *</label>
            <input type="date" id="checkout_date" name="checkout_date" value="<?= h($f['checkout_date']) ?>" required>
        </div>
        <div class="form-group">
            <label>Room Availability</label>
            <div id="availability-note" style="padding:9px 0;color:var(--muted);font-size:0.88rem;">Select room type and dates to check.</div>
            <div id="availability-rooms" style="font-size:0.82rem;color:var(--muted);"></div>
        </div>
    </div>

    <h3>Billing</h3>
    <div class="form-row">
        <div class="form-group">
            <label>Total Room Rate / Night *</label>
            <input type="number" step="0.01" min="0" name="rate_per_night" value="<?= h($f['rate_per_night']) ?>" required>
        </div>
        <div class="form-group">
            <label>Extra Charges</label>
            <input type="number" step="0.01" min="0" name="extra_charges" value="<?= h($f['extra_charges']) ?>">
        </div>
        <div class="form-group">
            <label>Discount</label>
            <input type="number" step="0.01" min="0" name="discount" value="<?= h($f['discount']) ?>">
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Tax (%)</label>
            <input type="number" step="0.01" min="0" name="tax_percent" value="<?= h($f['tax_percent']) ?>">
        </div>
        <div class="form-group">
            <label>Advance Paid</label>
            <input type="number" step="0.01" min="0" name="advance_paid" value="<?= h($f['advance_paid']) ?>">
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="2"><?= h($f['notes']) ?></textarea>
        </div>
    </div>

    <button type="submit" id="update-booking" class="btn btn-accent">Update Booking</button>
    <a href="view_booking.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
</form>
</div>

<script>
(function () {
    const typeEl = document.getElementById('room_type');
    const inEl = document.getElementById('checkin_date');
    const outEl = document.getElementById('checkout_date');
    const note = document.getElementById('availability-note');
    const roomsNote = document.getElementById('availability-rooms');
    const roomNoList = document.getElementById('available-rooms-list');
    const saveBtn = document.getElementById('update-booking');
    const excludeId = <?= (int) $id ?>;

    function checkAvailability() {
        const type = typeEl.value;
        const checkin = inEl.value;
        const checkout = outEl.value;
        if (saveBtn) saveBtn.disabled = false;
        if (!type || !checkin || !checkout || checkout <= checkin) {
            note.textContent = 'Select room type and dates to check.';
            note.style.color = 'var(--muted)';
            roomsNote.textContent = '';
            roomNoList.innerHTML = '';
            return;
        }
        fetch('availability_check.php?room_type=' + encodeURIComponent(type) +
              '&checkin=' + encodeURIComponent(checkin) + '&checkout=' + encodeURIComponent(checkout) +
              '&exclude_id=' + excludeId)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    note.textContent = data.message || 'Unable to check availability.';
                    note.style.color = 'var(--muted)';
                    roomsNote.textContent = '';
                    roomNoList.innerHTML = '';
                    return;
                }
                if (data.remaining <= 0) {
                    note.textContent = '⚠ No ' + type + ' rooms left for these dates (' + data.booked + '/' + data.total + ' booked).';
                    note.style.color = 'var(--danger)';
                    if (saveBtn) saveBtn.disabled = true;
                } else {
                    note.textContent = '✓ ' + data.remaining + ' of ' + data.total + ' ' + type + ' room(s) available.';
                    note.style.color = 'var(--accent)';
                }

                roomNoList.innerHTML = '';
                if (data.detail && data.detail.tracked) {
                    const avail = data.detail.available.length ? data.detail.available.join(', ') : 'none';
                    const booked = data.detail.booked.length ? data.detail.booked.join(', ') : 'none';
                    const unassigned = Number(data.detail.unassigned_booked || 0);
                    const capacity = Number(data.detail.available_capacity ?? data.remaining);
                    roomsNote.innerHTML = '<strong style="color:var(--accent);">Physically free:</strong> ' + avail +
                        '<br><strong style="color:var(--danger);">Booked:</strong> ' + booked +
                        (unassigned ? '<br><strong style="color:var(--danger);">Unassigned reservations:</strong> ' + unassigned + ' room(s)' : '');
                    if (saveBtn && capacity <= 0) saveBtn.disabled = true;
                    if (!unassigned) data.detail.available.forEach(function (roomNo) {
                        const opt = document.createElement('option');
                        opt.value = roomNo;
                        roomNoList.appendChild(opt);
                    });
                    if (unassigned && capacity > 0) roomsNote.innerHTML += '<br><span style="color:var(--muted);">Reserve by room count; assign a room number later.</span>';
                } else {
                    roomsNote.textContent = 'Add individual room numbers on the Rooms page to see which specific rooms are free.';
                }
            })
            .catch(() => { note.textContent = 'Unable to check availability.'; });
    }

    typeEl.addEventListener('change', checkAvailability);
    inEl.addEventListener('change', checkAvailability);
    outEl.addEventListener('change', checkAvailability);
    checkAvailability();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
