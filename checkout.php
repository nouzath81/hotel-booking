<?php
require __DIR__ . '/includes/auth.php'; requireLogin(); require __DIR__ . '/db_connect.php'; require __DIR__ . '/includes/functions.php';
$id=(int)($_GET['id']??$_POST['id']??0); $st=$pdo->prepare('SELECT * FROM bookings WHERE id=?'); $st->execute([$id]); $b=$st->fetch(PDO::FETCH_ASSOC); if(!$b) die('Booking not found.');
$bill=calcBill($b); $errors=[]; $success=false;
$roomAvailabilityAfter = null;
$roomDetailAfter = null;

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['checkout'])) {
  $amount=(float)($_POST['amount']??0); $method=trim($_POST['method']??'Cash'); $notes=trim($_POST['notes']??''); $allowDue=isset($_POST['allow_due']);
  if($bill['balance_due']>0.005 && $amount>0) { if($amount>$bill['balance_due']+0.005) $errors[]='Payment cannot exceed the balance due.'; else { if(!recordPayment($pdo,$id,date('Y-m-d'),$amount,$method,$notes)) $errors[]='Could not record payment.'; } }
  if(!$errors) { $st=$pdo->prepare('SELECT * FROM bookings WHERE id=?'); $st->execute([$id]); $b=$st->fetch(PDO::FETCH_ASSOC); $bill=calcBill($b); if($bill['balance_due']>0.005 && !$allowDue) $errors[]='Please settle the full balance or tick “Allow outstanding balance”.'; else { $st=$pdo->prepare("UPDATE bookings SET status='Checked-Out' WHERE id=?"); $st->execute([$id]);
      $success=true;
    }
  }
}
if ($success) {
  // Recalculate availability AFTER changing the booking to Checked-Out,
  // so the customer's room is immediately shown as released.
  $roomAvailabilityAfter = roomAvailability($pdo, $b['room_type'], $b['checkin_date'], $b['checkout_date'], $id);
  $roomDetailAfter = roomAvailabilityDetailed($pdo, $b['room_type'], $b['checkin_date'], $b['checkout_date'], $id);
}
$pageTitle='Checkout '.$b['booking_no']; require __DIR__ . '/includes/header.php';
?>
<div class="print-bar no-print flex-between"><h1>Guest Checkout</h1><div><a href="view_booking.php?id=<?=$id?>" class="btn btn-secondary">Back</a><a href="print_bill.php?id=<?=$id?>" class="btn">Bill</a></div></div>
<?php if($success):?><div class="alert alert-success">Checkout completed successfully. Booking <?=h($b['booking_no'])?> is now Checked-Out.</div><?php endif;?>
<?php if($errors):?><div class="alert alert-error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif;?>
<div class="form-row"><div class="card" style="flex:1"><div style="color:var(--muted)">Guest</div><h2 style="margin:4px 0"><?=h($b['customer_name'])?></h2><div>Room: <?=h($b['room_type'])?> <?= $b['room_no'] ? '('.h($b['room_no']).')':'' ?></div></div><div class="card" style="flex:1"><div>Grand Total</div><h2><?=money($bill['grand_total'])?></h2><div>Paid: <?=money($bill['advance'])?> &nbsp; <strong>Balance: <?=money($bill['balance_due'])?></strong></div></div></div>
<?php if($success && $roomAvailabilityAfter): ?>
<div class="card">
  <h3>Room Availability After Checkout</h3>
  <div class="form-row">
    <div style="flex:1">
      <div style="color:var(--muted)">Room Type</div>
      <strong><?=h($b['room_type'])?></strong>
    </div>
    <div style="flex:1">
      <div style="color:var(--muted)">Total Rooms</div>
      <strong><?= (int)$roomAvailabilityAfter['total'] ?></strong>
    </div>
    <div style="flex:1">
      <div style="color:var(--muted)">Booked</div>
      <strong><?= (int)$roomAvailabilityAfter['booked'] ?></strong>
    </div>
    <div style="flex:1">
      <div style="color:var(--muted)">Remaining</div>
      <strong style="color:var(--accent);font-size:1.2rem"><?= (int)$roomAvailabilityAfter['remaining'] ?></strong>
    </div>
  </div>
  <?php if($roomDetailAfter && $roomDetailAfter['tracked']): ?>
    <div style="margin-top:12px">
      <strong style="color:var(--accent);">Available rooms:</strong>
      <?= $roomDetailAfter['available'] ? h(implode(', ', $roomDetailAfter['available'])) : 'None' ?>
      <br>
      <strong style="color:var(--danger);">Currently booked rooms:</strong>
      <?= $roomDetailAfter['booked'] ? h(implode(', ', $roomDetailAfter['booked'])) : 'None' ?>
    </div>
  <?php endif; ?>
  <div style="margin-top:10px;color:var(--muted);font-size:.88rem">
    Room <?= $b['room_no'] ? h($b['room_no']) : '' ?> has been released because this booking is now Checked-Out.
  </div>
</div>
<?php endif; ?>

<div class="card"><h3>Final Settlement & Checkout</h3><form method="post" class="form-row" style="align-items:flex-end"><input type="hidden" name="id" value="<?=$id?>"><div class="form-group"><label>Final Payment</label><input type="number" step="0.01" min="0" max="<?=h($bill['balance_due'])?>" name="amount" value="<?=h(max(0,$bill['balance_due']))?>"></div><div class="form-group"><label>Payment Method</label><select name="method"><option>Cash</option><option>Card</option><option>Bank Transfer</option><option>Online</option><option>Other</option></select></div><div class="form-group"><label>Notes</label><input name="notes" placeholder="Optional"></div><div class="form-group" style="flex:0"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="allow_due"> Allow outstanding balance</label></div><div class="form-group" style="flex:0"><button class="btn btn-accent" name="checkout" value="1">Complete Checkout</button></div></form></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
