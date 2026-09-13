<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Dashboard';

// Handle delete
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: index.php?deleted=1');
    exit;
}

$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM bookings
        WHERE customer_name LIKE :q OR phone LIKE :q OR booking_no LIKE :q
        ORDER BY id DESC");
    $stmt->execute(['q' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query('SELECT * FROM bookings ORDER BY id DESC');
}
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = invoiceTotals($pdo);
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$roomSnapshot = allRoomAvailability($pdo, $today, $tomorrow);
$todayPos = posSalesTotals(posSalesInRange($pdo, $today, $today));

require __DIR__ . '/includes/header.php';

// Render room cards only after the page header has been sent.
// This prevents header() redirects (such as booking deletion) from
// failing with 'headers already sent' warnings.
require __DIR__ . '/room-type-cards.php';
?>

<div class="flex-between">
    <h1>Bookings</h1>
    <a href="add_booking.php" class="btn btn-accent">+ New Booking</a>
</div>

<div class="form-row">
    <div class="card" style="flex:1;min-width:200px;">
        <div style="color:var(--muted);font-size:0.85rem;">Total Invoice Amount</div>
        <div style="font-size:1.6rem;font-weight:700;color:var(--primary-dark);"><?= money($totals['total_invoice']) ?></div>
        <div style="color:var(--muted);font-size:0.8rem;">across <?= (int) $totals['count'] ?> active booking(s)</div>
    </div>
    <div class="card" style="flex:1;min-width:200px;">
        <div style="color:var(--muted);font-size:0.85rem;">Advance Collected</div>
        <div style="font-size:1.6rem;font-weight:700;color:var(--accent);"><?= money($totals['total_advance']) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:200px;">
        <div style="color:var(--muted);font-size:0.85rem;">Today's POS Sales</div>
        <div style="font-size:1.6rem;font-weight:700;color:var(--primary-dark);"><?= money($todayPos['total']) ?></div>
        <div style="font-size:0.8rem;"><a href="pos_sales.php">View POS sales →</a></div>
    </div>
    <div class="card" style="flex:1;min-width:200px;">
        <div style="color:var(--muted);font-size:0.85rem;">Outstanding Balance</div>
        <div style="font-size:1.6rem;font-weight:700;color:var(--danger);"><?= money($totals['total_balance_due']) ?></div>
        <div style="font-size:0.8rem;"><a href="due_list.php">View &amp; settle dues &rarr;</a></div>
    </div>
</div>

<div class="card" id="live-room-snapshot">
    <div class="flex-between">
        <h3 style="margin:0;">Live Room Availability</h3>
        <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><label style="font-size:.75rem;">From <input type="date" id="availability-from" value="<?= h($today) ?>" style="padding:5px;"></label><label style="font-size:.75rem;">To <input type="date" id="availability-to" value="<?= h($tomorrow) ?>" style="padding:5px;"></label><span id="availability-live-status" style="font-size:.75rem;color:var(--muted);">Live loading…</span><button type="button" id="availability-refresh" class="btn btn-sm btn-secondary">Refresh</button><a href="rooms.php" class="btn btn-sm btn-secondary">Manage Rooms</a></span>
    </div>
    <?php if (!$roomSnapshot): ?>
        <p style="color:var(--muted);">No room types set up yet. <a href="rooms.php">Add some here.</a></p>
    <?php else: ?>
        <div class="form-row" style="margin-top:10px;">
        <?php foreach ($roomSnapshot as $t): ?>
            <div style="flex:1;min-width:150px;padding:10px;border:1px solid var(--border);border-radius:6px;">
                <div style="font-weight:600;"><?= h($t['type_name']) ?></div>
                <?php if ($t['remaining'] <= 0): ?>
                    <span class="badge badge-cancelled">Full</span>
                <?php elseif ($t['remaining'] <= 2): ?>
                    <span class="badge badge-checkedin"><?= (int) $t['remaining'] ?> left</span>
                <?php else: ?>
                    <span class="badge badge-confirmed"><?= (int) $t['remaining'] ?> available</span>
                <?php endif; ?>
                <div style="color:var(--muted);font-size:0.8rem;margin-top:4px;"><?= (int) $t['booked'] ?> / <?= (int) $t['total'] ?> booked</div>
                <?php if (!empty($t['detail']['tracked'])): ?>
                    <div style="color:var(--muted);font-size:0.75rem;margin-top:2px;">
                        <a href="rooms.php">see room numbers &rarr;</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function(){
  const grid=document.querySelector('#live-room-snapshot .form-row');
  const status=document.getElementById('availability-live-status');
  const refresh=document.getElementById('availability-refresh');
  if(!grid||!status||!refresh)return;
  function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
  function render(d){
    if(!d.ok)throw Error();
    grid.innerHTML=d.rooms.map(t=>{
      const rem=Number(t.remaining||0);
      const cls=rem<=0?'badge badge-cancelled':(rem<=2?'badge badge-checkedin':'badge badge-confirmed');
      const label=rem<=0?'Full':(rem<=2?rem+' left':rem+' available');
      const detail=t.detail&&t.detail.tracked;
      const avail=detail&&t.detail.available&&t.detail.available.length?'<div style="font-size:.75rem;color:var(--muted);margin-top:3px;">Available: '+esc(t.detail.available.join(', '))+'</div>':'';
      return '<div style="flex:1;min-width:150px;padding:10px;border:1px solid var(--border);border-radius:6px;"><div style="font-weight:600;">'+esc(t.type_name)+'</div><span class="'+cls+'">'+label+'</span><div style="color:var(--muted);font-size:.8rem;margin-top:4px;">'+Number(t.booked||0)+' / '+Number(t.total||0)+' booked</div>'+avail+'</div>';
    }).join('');
    status.textContent='Live • '+d.updated_at;
  }
  async function load(){
    const from=document.getElementById('availability-from').value, to=document.getElementById('availability-to').value;
    if(!from||!to||to<=from){status.textContent='Choose a valid date range';return;}
    status.textContent='Updating…';
    try{const r=await fetch('api/room-availability.php?checkin='+encodeURIComponent(from)+'&checkout='+encodeURIComponent(to)+'&t='+Date.now(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});render(await r.json());}
    catch(e){status.textContent='Live update failed';}
  }
  refresh.addEventListener('click',load); document.getElementById('availability-from').addEventListener('change',load); document.getElementById('availability-to').addEventListener('change',load); load(); setInterval(load,15000);
})();
</script>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Booking deleted.</div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Booking saved successfully.</div>
<?php endif; ?>

<form method="get" class="search-box">
    <input type="text" name="q" placeholder="Search by customer name, phone or booking no..." value="<?= h($search) ?>" style="max-width:360px;display:inline-block;">
    <button class="btn btn-sm" type="submit">Search</button>
    <?php if ($search !== ''): ?><a class="btn btn-sm btn-secondary" href="index.php">Clear</a><?php endif; ?>
</form>

<div class="card" style="padding:0; overflow-x:auto;">
<table>
    <thead>
        <tr>
            <th>Booking #</th>
            <th>Customer</th>
            <th>Room</th>
            <th>Check-in</th>
            <th>Check-out</th>
            <th>Status</th>
            <th>Total</th>
            <th>Advance</th>
            <th>Balance Due</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$bookings): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:30px;">No bookings found.</td></tr>
    <?php endif; ?>
    <?php foreach ($bookings as $b):
        $bill = calcBill($b);
    ?>
        <tr>
            <td><?= h($b['booking_no']) ?></td>
            <td><?= h($b['customer_name']) ?></td>
            <td><?= h($b['room_type']) ?> <?= $b['room_no'] ? '(' . h($b['room_no']) . ')' : '' ?></td>
            <td><?= h($b['checkin_date']) ?></td>
            <td><?= h($b['checkout_date']) ?></td>
            <td><span class="badge <?= statusBadgeClass($b['status']) ?>"><?= h($b['status']) ?></span></td>
            <td><?= money($bill['grand_total']) ?></td>
            <td><?= money($bill['advance']) ?></td>
            <td<?= $bill['balance_due'] > 0.005 ? ' class="balance-due"' : '' ?>><?= money($bill['balance_due']) ?></td>
            <td class="actions">
                <a class="btn btn-sm" href="view_booking.php?id=<?= $b['id'] ?>">View</a>
                <?php if ($b['status'] !== 'Checked-Out' && $b['status'] !== 'Cancelled'): ?><a class="btn btn-sm btn-accent" href="checkout.php?id=<?= $b['id'] ?>">Checkout</a><?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="print_bill.php?id=<?= $b['id'] ?>">Bill</a>
                <a class="btn btn-sm" href="edit_booking.php?id=<?= $b['id'] ?>">Edit</a>
                <?php if ($bill['balance_due'] > 0.005): ?>
                    <a class="btn btn-sm btn-accent" href="settle_due.php?id=<?= $b['id'] ?>">Settle</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-danger" href="index.php?delete=<?= $b['id'] ?>"
                   onclick="return confirm('Delete this booking?');">Delete</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

<a href="room-types.php" style="display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;text-decoration:none;margin:8px 0;">🏨 Manage Room Types</a>


