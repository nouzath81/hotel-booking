<?php
/* Professional hotel-style room cards — backed by room_types + hotel_rooms. */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
?>
<style>
.hotel-room-section{margin:20px 0;font-family:Arial,sans-serif}
.hotel-room-head{display:flex;align-items:end;justify-content:space-between;gap:15px;margin-bottom:16px;flex-wrap:wrap}
.hotel-room-head h2{margin:0;font-size:24px;color:#182638}
.hotel-room-head p{margin:5px 0 0;color:#718096;font-size:13px}
.hotel-room-manage{background:#182638;color:#fff!important;padding:10px 15px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600}
.hotel-room-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:20px}
.hotel-room-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;box-shadow:0 5px 18px rgba(20,32,48,.08);transition:transform .2s,box-shadow .2s}
.hotel-room-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(20,32,48,.13)}
.hotel-room-photo{height:190px;background:#eef1f4;position:relative;overflow:hidden}
.hotel-room-photo img{width:100%;height:100%;object-fit:cover;display:block}
.hotel-no-photo{height:100%;display:flex;align-items:center;justify-content:center;color:#8b95a1;font-size:14px;background:linear-gradient(135deg,#f2f4f6,#e7ebef)}
.hotel-room-status{position:absolute;top:12px;right:12px;padding:7px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.12)}
.hotel-room-status.available{color:#176b3a}.hotel-room-status.full{color:#a52a2a}
.hotel-room-body{padding:16px}
.hotel-room-title{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.hotel-room-title h3{margin:0;color:#172235;font-size:18px}
.hotel-room-price{text-align:right;white-space:nowrap;color:#172235;font-weight:700;font-size:16px}
.hotel-room-price span{display:block;color:#8a94a0;font-size:10px;font-weight:400;margin-top:2px}
.hotel-room-availability{display:flex;align-items:center;gap:8px;margin:12px 0 10px;font-size:13px;font-weight:700;color:#334155}
.hotel-room-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
.hotel-room-dot.available{background:#27a05a}.hotel-room-dot.full{background:#d84b4b}
.hotel-room-desc{color:#6b7280;font-size:12px;line-height:1.5;margin:0 0 13px;min-height:36px}
.hotel-amenities{display:flex;flex-wrap:wrap;gap:6px;padding-top:12px;border-top:1px solid #edf0f2}
.hotel-amenity{font-size:11px;color:#455468;background:#f6f8fa;border:1px solid #e6e9ed;border-radius:999px;padding:5px 8px}
.hotel-room-footer{margin-top:14px}
.hotel-book-btn{display:block;text-align:center;text-decoration:none;padding:10px;border-radius:8px;font-size:12px;font-weight:700;background:#182638;color:#fff}
.hotel-book-btn.disabled{background:#e9ecef;color:#8a9199;pointer-events:none}
@media(max-width:600px){.hotel-room-grid{grid-template-columns:1fr}.hotel-room-photo{height:210px}.hotel-room-title h3{font-size:17px}}
</style>

<section class="hotel-room-section" id="roomTypeShowcase">
  <div class="hotel-room-head">
    <div><h2>Rooms &amp; Live Availability</h2><p>Current room availability, pricing and amenities</p></div>
    <a class="hotel-room-manage" href="room-types.php">Manage Room Types</a>
  </div>

  <div class="hotel-room-grid">
  <?php
  try {
      // room_types is the canonical source. hotel_rooms is used automatically
      // when individual room numbers have been configured.
      $today = date('Y-m-d');
      $tomorrow = date('Y-m-d', strtotime('+1 day'));
      $types = allRoomAvailability($pdo, $today, $tomorrow);

      // Details are optional on older installations.
      $details = [];
      try {
          $rows = $pdo->query('SELECT room_type, description, photo, ac, wifi, tv, breakfast FROM room_type_details')->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $d) $details[(string)$d['room_type']] = $d;
      } catch (Throwable $ignored) {}

      foreach ($types as $rt):
          $name = (string)$rt['type_name'];
          $d = $details[$name] ?? [];
          $total = (int)$rt['total'];
          $available = (int)$rt['remaining'];
          $isAvailable = $available > 0;
          $amenities = [];
          if (!empty($d['ac'])) $amenities[]='❄ AC';
          if (!empty($d['wifi'])) $amenities[]='⌁ Wi-Fi';
          if (!empty($d['tv'])) $amenities[]='▣ TV';
          if (!empty($d['breakfast'])) $amenities[]='☕ Breakfast';
  ?>
    <article class="hotel-room-card">
      <div class="hotel-room-photo">
        <?php if (!empty($d['photo'])): ?>
          <img src="uploads/room-types/<?=h((string)$d['photo'])?>" alt="<?=h($name)?>">
        <?php else: ?><div class="hotel-no-photo">No Photo Available</div><?php endif; ?>
        <div class="hotel-room-status <?= $isAvailable?'available':'full' ?>"><?= $isAvailable?'● Available':'● Fully Booked' ?></div>
      </div>
      <div class="hotel-room-body">
        <div class="hotel-room-title">
          <h3><?=h($name)?></h3>
          <div class="hotel-room-price"><?=number_format((float)$rt['default_rate'],2)?><span>per night</span></div>
        </div>
        <div class="hotel-room-availability">
          <span class="hotel-room-dot <?= $isAvailable?'available':'full' ?>"></span>
          <?= $available ?> Available <span style="font-weight:400;color:#9aa3ad;">/ <?= $total ?> Total</span>
        </div>
        <p class="hotel-room-desc"><?= !empty($d['description']) ? nl2br(h((string)$d['description'])) : 'Comfortable accommodation with modern hotel facilities.' ?></p>
        <?php if ($amenities): ?><div class="hotel-amenities"><?php foreach($amenities as $a): ?><span class="hotel-amenity"><?=h($a)?></span><?php endforeach; ?></div><?php endif; ?>
        <div class="hotel-room-footer"><a class="hotel-book-btn <?= $isAvailable?'':'disabled' ?>" href="<?= $isAvailable?'rooms.php':'' ?>"><?= $isAvailable?'View / Book Room':'No Rooms Available' ?></a></div>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$types): ?>
    <div style="grid-column:1/-1;padding:18px;border:1px solid #e5e7eb;background:#f8fafc;color:#64748b;border-radius:10px;">No room types have been configured yet. <a href="room-types.php">Add a room type</a>.</div>
  <?php endif; ?>
  <?php
  } catch (Throwable $e) {
      echo '<div style="grid-column:1/-1;padding:18px;border:1px solid #f0caca;background:#fff6f6;color:#9b2c2c;border-radius:10px;">Room information could not be loaded.</div>';
  }
  ?>
  </div>
</section>
