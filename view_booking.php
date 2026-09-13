<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    die('Booking not found.');
}

$pageTitle = 'Reservation ' . $b['booking_no'];
$bill = calcBill($b);

require __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success no-print">Booking saved successfully.</div>
<?php endif; ?>

<div class="print-bar no-print flex-between">
    <a href="index.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
    <div>
        <a href="edit_booking.php?id=<?= $b['id'] ?>" class="btn">Edit</a>
        <?php if ($b['status'] !== 'Checked-Out' && $b['status'] !== 'Cancelled'): ?><a href="checkout.php?id=<?= $b['id'] ?>" class="btn btn-accent">Checkout</a><?php endif; ?>
        <a href="print_bill.php?id=<?= $b['id'] ?>" class="btn btn-secondary">View Bill Summary</a>
        <a href="booking_pdf.php?id=<?= $b['id'] ?>&token=<?= h(bookingPdfToken((int)$b['id'])) ?>" class="btn btn-secondary">📄 Booking PDF</a>
        <a href="statement.php?customer=<?= urlencode($b['customer_name']) ?>&phone=<?= urlencode($b['phone']) ?>" class="btn btn-secondary">Account Statement</a>
        <?php if ($bill['balance_due'] > 0.005): ?>
            <a href="settle_due.php?id=<?= $b['id'] ?>" class="btn btn-accent">Settle Due</a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-accent">🖨 Print</button>
    </div>
</div>

<div class="card no-print" style="margin-bottom:16px;">
    <h3>WhatsApp Booking Confirmation</h3>
    <div class="form-row">
        <div class="form-group" style="flex:1;">
            <label>WhatsApp No.</label>
            <input type="text" id="whatsapp_no" value="<?= h($b['whatsapp_no'] ?? $b['phone'] ?? '') ?>" placeholder="e.g. +94771234567">
            <small style="color:var(--muted);">Use country code, e.g. +94 77 123 4567.</small>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;">
            <button type="button" class="btn btn-accent" onclick="sendBookingWhatsApp()">💬 Send Booking PDF on WhatsApp</button>
        </div>
    </div>
    <div id="wa-note" style="font-size:.85rem;color:var(--muted);"></div>
</div>
<script>
function sendBookingWhatsApp(){
    let n=document.getElementById('whatsapp_no').value.trim();
    n=n.replace(/\D/g,'');
    if(n.startsWith('00')) n=n.substring(2);
    if(n.startsWith('0') && n.length===10) n='94'+n.substring(1);
    if(n.length<8){ document.getElementById('wa-note').textContent='Please enter a valid WhatsApp number with country code.'; return; }
    const pdf=<?= json_encode(bookingPdfUrl((int)$b['id'])) ?>;
    const msg=encodeURIComponent('Dear <?= h($b['customer_name']) ?>,\n\nYour hotel booking confirmation is ready.\nBooking No: <?= h($b['booking_no']) ?>\nCheck-in: <?= h($b['checkin_date']) ?>\nCheck-out: <?= h($b['checkout_date']) ?>\nRooms: <?= (int)($b['room_count'] ?? 1) ?><?= $b['room_no'] ? ' - ' . h($b['room_no']) : '' ?>\n\nBooking PDF: '+pdf+'\n\nThank you.');
    window.open('https://wa.me/'+n+'?text='+msg,'_blank');
}
</script>

<div class="invoice">
    <?php require __DIR__ . '/includes/company_header.php'; ?>
    <div class="invoice-header">
        <div>
            <h1>Reservation Confirmation</h1>
            <div>Booking No: <strong><?= h($b['booking_no']) ?></strong></div>
        </div>
        <div class="meta">
            <div>Date Booked: <?= h(date('d M Y', strtotime($b['created_at']))) ?></div>
            <div>Status: <span class="badge <?= statusBadgeClass($b['status']) ?>"><?= h($b['status']) ?></span></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Customer Details</h3>
        <div class="info-grid">
            <div><span class="label">Name:</span> <?= h($b['customer_name']) ?></div>
            <div><span class="label">Phone:</span> <?= h($b['phone']) ?: '—' ?></div>
            <div><span class="label">Email:</span> <?= h($b['email']) ?: '—' ?></div>
            <div><span class="label">Address:</span> <?= nl2br(h($b['address'])) ?: '—' ?></div>
        </div>
    </div>

    <div class="invoice-section">
        <h3>Reservation Details</h3>
        <div class="info-grid">
            <div><span class="label">Room Type:</span> <?= h($b['room_type']) ?: '—' ?></div>
            <div><span class="label">Room No.:</span> <?= h($b['room_no']) ?: '—' ?></div>
            <div><span class="label">Rooms:</span> <?= (int)($b['room_count'] ?? 1) ?></div>
            <div><span class="label">Check-in:</span> <?= h($b['checkin_date']) ?></div>
            <div><span class="label">Check-out:</span> <?= h($b['checkout_date']) ?></div>
            <div><span class="label">Nights:</span> <?= (int) $b['nights'] ?></div>
        </div>
        <?php if ($b['notes']): ?>
            <p><span class="label">Notes:</span> <?= nl2br(h($b['notes'])) ?></p>
        <?php endif; ?>
    </div>

    <div class="invoice-section">
        <h3>Bill Summary</h3>
        <table class="bill-table">
            <tr><td>Room Charges (<?= (int)($b['room_count'] ?? 1) ?> room(s) × <?= (int) $b['nights'] ?> nights × combined nightly rate <?= money($b['rate_per_night']) ?>)</td><td><?= money($bill['room_total']) ?></td></tr>
            <tr><td>Extra Charges</td><td><?= money($bill['extra']) ?></td></tr>
            <tr><td>Discount</td><td>-<?= money($bill['discount']) ?></td></tr>
            <tr><td>Subtotal</td><td><?= money($bill['subtotal']) ?></td></tr>
            <tr><td>Tax (<?= money($bill['tax_percent']) ?>%)</td><td><?= money($bill['tax_amount']) ?></td></tr>
            <tr class="bill-total-row"><td>Grand Total</td><td><?= money($bill['grand_total']) ?></td></tr>
            <tr><td>Advance Paid</td><td><?= money($bill['advance']) ?></td></tr>
            <tr><td class="balance-due">Balance Due</td><td class="balance-due"><?= money($bill['balance_due']) ?></td></tr>
        </table>
    </div>

    <p style="color:var(--muted);font-size:0.85rem;">Thank you for choosing us. This document confirms your reservation and current billing status.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
