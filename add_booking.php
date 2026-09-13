<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'New Booking';
$errors = [];
$f = [
    'customer_name' => '', 'phone' => '', 'whatsapp_no' => '', 'email' => '', 'address' => '',
    'room_type' => '', 'room_no' => '', 'room_count' => 1, 'checkin_date' => '', 'checkout_date' => '',
    'rate_per_night' => '', 'extra_charges' => 0, 'discount' => 0, 'tax_percent' => 0,
    'advance_paid' => 0, 'status' => 'Confirmed', 'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $key => $default) {
        if ($key === 'room_no') continue;
        $f[$key] = trim((string)($_POST[$key] ?? $default));
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
    if ($f['room_count'] < 1) $errors[] = 'Please select at least one room.';

    if (!$errors) {
        $roomError = validateRoomBooking(
            $pdo, $f['room_type'], $f['room_no'], $f['checkin_date'],
            $f['checkout_date'], $f['status'], null, (int)$f['room_count']
        );
        if ($roomError !== null) {
            $errors[] = $roomError;
        }
    }

    if (!$errors) {
        $nights = calcNights($f['checkin_date'], $f['checkout_date']);
        $bookingNo = generateBookingNo($pdo);

        $stmt = $pdo->prepare("INSERT INTO bookings
            (booking_no, customer_name, phone, whatsapp_no, email, address, room_type, room_no, room_count,
             checkin_date, checkout_date, nights, rate_per_night, extra_charges,
             discount, tax_percent, advance_paid, status, notes)
            VALUES
            (:booking_no, :customer_name, :phone, :whatsapp_no, :email, :address, :room_type, :room_no, :room_count,
             :checkin_date, :checkout_date, :nights, :rate_per_night, :extra_charges,
             :discount, :tax_percent, :advance_paid, :status, :notes)");

        $stmt->execute([
            'booking_no' => $bookingNo,
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
        ]);

        $newId = $pdo->lastInsertId();
        header('Location: view_booking.php?id=' . $newId . '&saved=1');
        exit;
    }
}

$roomTypes = $pdo->query('SELECT * FROM room_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/includes/header.php';
?>

<h1>New Booking</h1>

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
            </select>
        </div>
        <div class="form-group" style="min-width:260px;">
            <label>Room No. <small style="color:var(--muted)">(select multiple)</small></label>
            <select name="room_no[]" id="room_no" multiple size="5" style="min-height:120px;"></select>
            <small style="color:var(--muted);">Hold Ctrl (Windows) / Cmd (Mac) to select more than one room.</small>
            <div id="selected-room-summary" style="margin-top:6px;font-weight:600;"></div>
        </div>
        <div class="form-group">
            <label>Number of Rooms</label>
            <input type="number" min="1" step="1" name="room_count" id="room_count" value="<?= (int)$f['room_count'] ?>">
            <small style="color:var(--muted);">For individually numbered rooms this is filled from your selection.</small>
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
            <small style="color:var(--muted);">For multiple rooms, this is the combined nightly room rate.</small>
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

    <button type="submit" id="save-booking" class="btn btn-accent">Save Booking</button>
    <a href="index.php" class="btn btn-secondary">Cancel</a>
</form>
</div>

<script>
(function () {
    const typeEl = document.getElementById('room_type');
    const inEl = document.getElementById('checkin_date');
    const outEl = document.getElementById('checkout_date');
    const rateEl = document.querySelector('input[name="rate_per_night"]');
    const countEl = document.getElementById('room_count');
    const roomEl = document.getElementById('room_no');
    const note = document.getElementById('availability-note');
    const roomsNote = document.getElementById('availability-rooms');
    const saveBtn = document.getElementById('save-booking');
    const summary = document.getElementById('selected-room-summary');
    const oldRooms = <?= json_encode(array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$f['room_no']))))) ?>;

    function updateSummary(){
        const selected=[...roomEl.selectedOptions].map(o=>o.value);
        summary.textContent=selected.length ? 'Selected: '+selected.join(', ') : '';
        if(selected.length) countEl.value=selected.length;
        const opt=typeEl.selectedOptions[0];
        const unit=opt ? parseFloat(opt.dataset.rate||'0') : 0;
        if(selected.length && unit>=0) rateEl.value=(unit*selected.length).toFixed(2);
    }

    function fillRooms(available){
        roomEl.innerHTML='';
        available.forEach(function(r){
            const o=document.createElement('option'); o.value=r; o.textContent=r;
            if(oldRooms.includes(String(r))) o.selected=true;
            roomEl.appendChild(o);
        });
        updateSummary();
    }

    function checkAvailability() {
        const type = typeEl.value, checkin = inEl.value, checkout = outEl.value;
        if (saveBtn) saveBtn.disabled = false;
        if (!type || !checkin || !checkout || checkout <= checkin) {
            note.textContent = 'Select room type and dates to check.'; note.style.color = 'var(--muted)';
            roomsNote.textContent = ''; roomEl.innerHTML=''; return;
        }
        fetch('availability_check.php?room_type=' + encodeURIComponent(type) + '&checkin=' + encodeURIComponent(checkin) + '&checkout=' + encodeURIComponent(checkout))
        .then(r=>r.json()).then(data=>{
            if(!data.ok){ note.textContent=data.message||'Unable to check availability.'; return; }
            if(data.remaining<=0){ note.textContent='⚠ No '+type+' rooms left for these dates.'; note.style.color='var(--danger)'; if(saveBtn) saveBtn.disabled=true; }
            else { note.textContent='✓ '+data.remaining+' of '+data.total+' '+type+' room(s) available.'; note.style.color='var(--accent)'; }
            if(data.detail && data.detail.tracked){
                let extra='';
                const unassigned=Number(data.detail.unassigned_booked||0);
                const capacity=Number(data.detail.available_capacity ?? data.remaining);
                if(unassigned>0) extra='<br><strong style="color:var(--danger);">Unassigned reservations:</strong> '+unassigned+' room(s) — exact room number not known';
                roomsNote.innerHTML='<strong style="color:var(--accent);">Physically free:</strong> '+(data.detail.available.length?data.detail.available.join(', '):'none')+'<br><strong style="color:var(--danger);">Booked:</strong> '+(data.detail.booked.length?data.detail.booked.join(', '):'none')+extra;
                // When unassigned reservations exist, don't let the user pick a
                // physical room that might actually be occupied by one of them.
                fillRooms(unassigned>0 ? [] : data.detail.available);
                if(saveBtn && capacity<=0) saveBtn.disabled=true;
                if(unassigned>0 && capacity>0) roomsNote.innerHTML += '<br><span style="color:var(--muted);">You can reserve '+capacity+' room(s) by Number of Rooms; assign a room number later.</span>';
            } else {
                roomEl.innerHTML=''; roomsNote.textContent='No individual room numbers are configured for this type. Enter the number of rooms above.';
                updateSummary();
            }
            const opt=typeEl.selectedOptions[0], unit=opt?parseFloat(opt.dataset.rate||'0'):0;
            if(unit>=0 && !rateEl.value) rateEl.value=(unit*Math.max(1,parseInt(countEl.value||1))).toFixed(2);
        }).catch(()=>{ note.textContent='Unable to check availability.'; });
    }
    typeEl.addEventListener('change',checkAvailability); inEl.addEventListener('change',checkAvailability); outEl.addEventListener('change',checkAvailability);
    roomEl.addEventListener('change',updateSummary); countEl.addEventListener('input',()=>{ if(![...roomEl.selectedOptions].length){ const opt=typeEl.selectedOptions[0]; const unit=opt?parseFloat(opt.dataset.rate||'0'):0; rateEl.value=(unit*Math.max(1,parseInt(countEl.value||1))).toFixed(2); }});
    checkAvailability();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
