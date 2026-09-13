<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Professional Systems';
$user = currentUser();
$errors = [];

// Housekeeping actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['housekeeping_action'])) {
    $roomId = (int)($_POST['room_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['Clean', 'Dirty', 'Inspected', 'Out of Service'], true) ? $_POST['status'] : 'Clean';
    if ($roomId > 0) {
        $st = $pdo->prepare('UPDATE hotel_rooms SET housekeeping_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $st->execute([$status, $roomId]);
    }
    header('Location: professional_systems.php?saved=housekeeping');
    exit;
}

// Maintenance ticket creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $roomNo = trim($_POST['maintenance_room_no'] ?? '');
    $title = trim($_POST['maintenance_title'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['Low','Medium','High','Critical'], true) ? $_POST['priority'] : 'Medium';
    if ($title === '') $errors[] = 'Maintenance issue/title is required.';
    if (!$errors) {
        $st = $pdo->prepare('INSERT INTO maintenance_tickets (room_no, title, priority, status, reported_by) VALUES (?, ?, ?, ?, ?)');
        $st->execute([$roomNo, $title, $priority, 'Open', $user['username'] ?? 'staff']);
        header('Location: professional_systems.php?saved=maintenance');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_status'])) {
    $id = (int)($_POST['ticket_id'] ?? 0);
    $status = in_array($_POST['maintenance_status'], ['Open','In Progress','Resolved','Closed'], true) ? $_POST['maintenance_status'] : 'Open';
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE maintenance_tickets SET status = ?, resolved_at = CASE WHEN ? IN (\'Resolved\',\'Closed\') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ?');
        $st->execute([$status, $status, $id]);
    }
    header('Location: professional_systems.php?saved=ticket');
    exit;
}

$roomRows = $pdo->query("SELECT id, room_no, room_type, status, COALESCE(housekeeping_status, 'Clean') AS housekeeping_status FROM hotel_rooms ORDER BY room_type, room_no")->fetchAll(PDO::FETCH_ASSOC);
$ticketRows = $pdo->query('SELECT * FROM maintenance_tickets ORDER BY CASE priority WHEN \'Critical\' THEN 1 WHEN \'High\' THEN 2 WHEN \'Medium\' THEN 3 ELSE 4 END, id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

$bookingCount = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('Cancelled','Checked-Out')")->fetchColumn();
$roomCount = (int)$pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Active'")->fetchColumn();
$dirtyCount = (int)$pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Active' AND COALESCE(housekeeping_status,'Clean') = 'Dirty'")->fetchColumn();
$openMaintenance = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_tickets WHERE status NOT IN ('Resolved','Closed')")->fetchColumn();
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$health = [
    ['Database', 'Online', 'good'],
    ['Bookings', $bookingCount . ' active', 'good'],
    ['Room inventory', $roomCount . ' tracked', 'good'],
    ['Housekeeping', $dirtyCount . ' dirty room(s)', $dirtyCount ? 'warn' : 'good'],
    ['Maintenance', $openMaintenance . ' open ticket(s)', $openMaintenance ? 'warn' : 'good'],
    ['Staff accounts', $userCount . ' account(s)', 'good'],
];

require __DIR__ . '/includes/header.php';
?>

<div class="flex-between">
    <div>
        <h1>Professional Systems</h1>
        <p style="color:var(--muted);margin-top:-8px;">Central operations, housekeeping, maintenance, controls and system health.</p>
    </div>
    <span class="badge badge-confirmed" style="padding:7px 12px;">● System Online</span>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Professional system update saved successfully.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="form-row">
    <div class="card" style="flex:1;min-width:180px;"><div style="color:var(--muted);font-size:.82rem;">Active Bookings</div><div style="font-size:1.6rem;font-weight:700;"> <?= $bookingCount ?></div></div>
    <div class="card" style="flex:1;min-width:180px;"><div style="color:var(--muted);font-size:.82rem;">Tracked Rooms</div><div style="font-size:1.6rem;font-weight:700;"> <?= $roomCount ?></div></div>
    <div class="card" style="flex:1;min-width:180px;"><div style="color:var(--muted);font-size:.82rem;">Housekeeping Alerts</div><div style="font-size:1.6rem;font-weight:700;"> <?= $dirtyCount ?></div></div>
    <div class="card" style="flex:1;min-width:180px;"><div style="color:var(--muted);font-size:.82rem;">Open Maintenance</div><div style="font-size:1.6rem;font-weight:700;"> <?= $openMaintenance ?></div></div>
</div>

<div class="card">
    <div class="flex-between"><h2 style="margin-top:0;">System Health & Controls</h2><span style="font-size:.8rem;color:var(--muted);">Last checked <?= h(date('d M Y H:i')) ?></span></div>
    <div class="form-row">
    <?php foreach ($health as $item): ?>
        <div style="flex:1;min-width:180px;border:1px solid var(--border);border-radius:8px;padding:14px;">
            <div style="font-weight:600;"><?= h($item[0]) ?></div>
            <div style="margin-top:6px;" class="badge <?= $item[2] === 'good' ? 'badge-confirmed' : 'badge-checkedin' ?>"><?= h($item[1]) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" href="migrate_export.php">Backup / Data Export</a>
        <a class="btn btn-secondary" href="settings.php">Users & Company Settings</a>
        <a class="btn btn-accent" href="export_pdf.php?page=professional_systems.php">Export Professional Systems PDF</a>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Housekeeping Control</h2>
    <p style="color:var(--muted);font-size:.85rem;">Update room cleaning status so front desk staff can see operational readiness.</p>
    <?php if (!$roomRows): ?>
        <p style="color:var(--muted);">No individual room numbers have been configured yet. Add them from <a href="rooms.php">Rooms</a>.</p>
    <?php else: ?>
    <div style="overflow-x:auto;"><table><thead><tr><th>Room</th><th>Type</th><th>Room Status</th><th>Housekeeping</th><th>Update</th></tr></thead><tbody>
    <?php foreach ($roomRows as $r): ?>
        <tr>
            <td><strong><?= h($r['room_no']) ?></strong></td><td><?= h($r['room_type']) ?></td><td><?= h($r['status']) ?></td>
            <td><span class="badge <?= $r['housekeeping_status'] === 'Dirty' ? 'badge-cancelled' : ($r['housekeeping_status'] === 'Inspected' ? 'badge-confirmed' : 'badge-checkedin') ?>"><?= h($r['housekeeping_status']) ?></span></td>
            <td><form method="post" style="display:flex;gap:6px;align-items:center;"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="housekeeping_action" value="1"><select name="status" style="width:auto;min-width:140px;"><?php foreach (['Clean','Dirty','Inspected','Out of Service'] as $s): ?><option <?= $s === $r['housekeeping_status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-accent">Save</button></form></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<div class="form-row">
    <div class="card" style="flex:1;min-width:320px;">
        <h2 style="margin-top:0;">Report Maintenance Issue</h2>
        <form method="post">
            <div class="form-group"><label>Room Number (optional)</label><input name="maintenance_room_no" placeholder="e.g. 101"></div>
            <div class="form-group"><label>Issue / Title</label><input name="maintenance_title" required placeholder="Air conditioner not working"></div>
            <div class="form-group"><label>Priority</label><select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div>
            <button class="btn btn-accent" name="add_maintenance" value="1">Create Maintenance Ticket</button>
        </form>
    </div>
    <div class="card" style="flex:2;min-width:420px;">
        <h2 style="margin-top:0;">Maintenance Queue</h2>
        <?php if (!$ticketRows): ?><p style="color:var(--muted);">No maintenance tickets yet.</p><?php else: ?>
        <div style="overflow-x:auto;"><table><thead><tr><th>Room</th><th>Issue</th><th>Priority</th><th>Status</th><th>Reported</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($ticketRows as $t): ?><tr>
            <td><?= h($t['room_no'] ?: '-') ?></td><td><?= h($t['title']) ?></td><td><?= h($t['priority']) ?></td><td><?= h($t['status']) ?></td><td><?= h($t['reported_by']) ?></td>
            <td><form method="post" style="display:flex;gap:5px;"><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>"><select name="maintenance_status" style="width:auto;"><?php foreach (['Open','In Progress','Resolved','Closed'] as $s): ?><option <?= $s === $t['status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select><button class="btn btn-sm">Update</button></form></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Professional Workflow</h2>
    <div class="form-row">
        <div style="flex:1;min-width:220px;"><strong>1. Front Desk</strong><p style="color:var(--muted);font-size:.85rem;">Bookings → availability → check-in → checkout → settlement.</p></div>
        <div style="flex:1;min-width:220px;"><strong>2. Housekeeping</strong><p style="color:var(--muted);font-size:.85rem;">Clean → inspect → release rooms for sale.</p></div>
        <div style="flex:1;min-width:220px;"><strong>3. Maintenance</strong><p style="color:var(--muted);font-size:.85rem;">Open → assign/work → resolve → close.</p></div>
        <div style="flex:1;min-width:220px;"><strong>4. Management</strong><p style="color:var(--muted);font-size:.85rem;">AI insights → reports → POS → cash control → PDF export.</p></div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
