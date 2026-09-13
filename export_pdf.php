<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/simple_pdf.php';

$page = basename((string)($_GET['page'] ?? 'index.php'));
$lines = [];
$company = getSetting($pdo, 'company_name', 'Hotel Booking System');
$lines[] = strtoupper($company);
$lines[] = 'PDF EXPORT — ' . date('d M Y H:i');
$lines[] = str_repeat('=', 88);

$addBookingLines = function(array $b) use (&$lines) {
    $bill = calcBill($b);
    $lines[] = 'BOOKING ' . ($b['booking_no'] ?: '-');
    $lines[] = 'Customer: ' . ($b['customer_name'] ?: '-');
    $lines[] = 'Phone: ' . ($b['phone'] ?: '-');
    $lines[] = 'Room: ' . trim(($b['room_type'] ?: '-') . ' ' . (($b['room_no'] ?? '') ? '#' . $b['room_no'] : ''));
    $lines[] = 'Check-in: ' . ($b['checkin_date'] ?: '-') . ' | Check-out: ' . ($b['checkout_date'] ?: '-');
    $lines[] = 'Status: ' . ($b['status'] ?: '-');
    $lines[] = 'Invoice: ' . money($bill['grand_total']) . ' | Paid: ' . money($bill['advance']) . ' | Due: ' . money($bill['balance_due']);
};

switch ($page) {
    case 'index.php':
        $lines[] = 'DASHBOARD / BOOKINGS';
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT * FROM bookings'; $params = [];
        if ($q !== '') { $sql .= ' WHERE customer_name LIKE :q OR phone LIKE :q OR booking_no LIKE :q'; $params['q'] = '%' . $q . '%'; }
        $sql .= ' ORDER BY checkin_date DESC, id DESC';
        $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sum = summarizeBookings(array_filter($rows, fn($r) => $r['status'] !== 'Cancelled'));
        $lines[] = 'Active bookings: ' . $sum['count'] . ' | Invoice: ' . money($sum['total_invoice']) . ' | Collected: ' . money($sum['total_advance']) . ' | Due: ' . money($sum['total_balance_due']);
        $lines[] = '';
        foreach ($rows as $b) $addBookingLines($b);
        break;

    case 'booking_list.php':
        $lines[] = 'BOOKING LIST (FULL DETAILS)';
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $dueOnly = isset($_GET['due_only']) && $_GET['due_only'] === '1';
        $where = []; $params = [];
        if ($q !== '') { $where[] = '(customer_name LIKE :q OR phone LIKE :q OR booking_no LIKE :q OR room_type LIKE :q OR room_no LIKE :q)'; $params['q'] = '%' . $q . '%'; }
        if ($status !== '') { $where[] = 'status = :status'; $params['status'] = $status; }
        $sql = 'SELECT * FROM bookings'; if ($where) $sql .= ' WHERE ' . implode(' AND ', $where); $sql .= ' ORDER BY id DESC';
        $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sumTotal = 0.0; $sumAdvance = 0.0; $sumDue = 0.0; $shown = [];
        foreach ($rows as $b) {
            $bill = calcBill($b);
            if ($dueOnly && $bill['balance_due'] <= 0.005) continue;
            $shown[] = $b;
            $sumTotal += $bill['grand_total']; $sumAdvance += $bill['advance']; $sumDue += $bill['balance_due'];
        }
        $lines[] = 'Showing: ' . count($shown) . ' | Total: ' . money($sumTotal) . ' | Advance: ' . money($sumAdvance) . ' | Due: ' . money($sumDue);
        $lines[] = '';
        foreach ($shown as $b) $addBookingLines($b);
        break;

    case 'rooms.php':
        $lines[] = 'ROOMS & LIVE AVAILABILITY';
        $from = $_GET['checkin'] ?? date('Y-m-d');
        $to = $_GET['checkout'] ?? date('Y-m-d', strtotime($from . ' +1 day'));
        foreach (allRoomAvailability($pdo, $from, $to) as $r) {
            $detail = $r['detail'];
            $lines[] = ($r['type_name'] ?: '-') . ': ' . $r['remaining'] . ' available / ' . $r['total'] . ' total | Rate: ' . money((float)$r['default_rate']);
            if ($detail['tracked']) {
                $lines[] = '  Available rooms: ' . ($detail['available'] ? implode(', ', $detail['available']) : 'None');
                $lines[] = '  Booked rooms: ' . ($detail['booked'] ? implode(', ', $detail['booked']) : 'None');
            }
        }
        break;

    case 'room-types.php':
        $lines[] = 'ROOM TYPE MANAGEMENT';
        foreach ($pdo->query('SELECT * FROM room_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC) as $r)
            $lines[] = ($r['type_name'] ?: '-') . ' | Total rooms: ' . (int)$r['total_rooms'] . ' | Default rate: ' . money((float)$r['default_rate']);
        break;

    case 'add_booking.php':
        $lines[] = 'NEW BOOKING';
        $lines[] = 'This PDF contains the current booking form page. No booking has been saved yet.';
        break;

    case 'edit_booking.php':
    case 'checkout.php':
    case 'view_booking.php':
    case 'print_bill.php':
    case 'settle_due.php':
        $id = (int)($_GET['id'] ?? 0); $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); $b = $st->fetch(PDO::FETCH_ASSOC);
        $lines[] = strtoupper(str_replace('.php', '', $page));
        if ($b) { $addBookingLines($b); foreach (getPaymentsForBooking($pdo, $id) as $p) $lines[] = 'Payment: ' . $p['payment_date'] . ' | ' . money((float)$p['amount']) . ' | ' . ($p['method'] ?: '-'); }
        else $lines[] = 'Booking not found.';
        break;

    case 'reports.php':
        $mode = $_GET['mode'] ?? 'range';
        if ($mode === 'daily') { $from = $to = $_GET['date'] ?? date('Y-m-d'); }
        elseif ($mode === 'monthly') { $from = ($_GET['ym'] ?? date('Y-m')) . '-01'; $to = date('Y-m-t', strtotime($from)); }
        else { $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-t'); }
        if ($to < $from) $to = $from;
        $rows = bookingsInDateRange($pdo, $from, $to, (string)($_GET['status'] ?? '')); $s = summarizeBookings($rows);
        $lines[] = 'BUSINESS REPORT ' . $from . ' to ' . $to;
        $lines[] = 'Bookings: ' . $s['count'] . ' | Invoice: ' . money($s['total_invoice']) . ' | Collected: ' . money($s['total_advance']) . ' | Due: ' . money($s['total_balance_due']);
        foreach ($rows as $b) $addBookingLines($b);
        break;

    case 'business_report.php':
        $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-t');
        $view = ($_GET['view'] ?? 'daily') === 'monthly' ? 'monthly' : 'daily';
        $rows = combinedBusinessReport($pdo, $from, $to, $view === 'monthly' ? 'month' : 'day');
        $lines[] = 'TREND REPORT ' . $from . ' to ' . $to . ' (' . strtoupper($view) . ')';
        foreach ($rows as $r) $lines[] = $r['period'] . ' | Bookings ' . $r['bookings_count'] . ' | Invoice ' . money($r['invoice_total']) . ' | POS ' . money($r['pos_sales_total']) . ' | Cash net ' . money($r['petty_cash_net']);
        break;

    case 'statement.php':
        $name = trim((string)($_GET['name'] ?? $_GET['customer_name'] ?? ''));
        $phone = trim((string)($_GET['phone'] ?? ''));
        $lines[] = 'CUSTOMER ACCOUNT STATEMENT';
        $lines[] = 'Customer: ' . ($name ?: 'Not selected');
        if ($name !== '') {
            $rows = customerBookings($pdo, $name, $phone); foreach ($rows as $b) $addBookingLines($b);
        }
        break;

    case 'petty_cash.php':
        $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-t'); $rows = pettyCashEntries($pdo, $from, $to); $tot = pettyCashTotals($rows);
        $lines[] = 'PETTY CASH ' . $from . ' to ' . $to;
        $lines[] = 'Cash in: ' . money($tot['total_in']) . ' | Cash out: ' . money($tot['total_out']) . ' | Net: ' . money($tot['balance']);
        foreach ($rows as $r) $lines[] = $r['entry_date'] . ' | ' . ($r['type'] === 'In' ? 'IN' : 'OUT') . ' | ' . ($r['category'] ?: '-') . ' | ' . ($r['description'] ?: '-') . ' | ' . money((float)$r['amount']);
        break;

    case 'due_list.php':
        $lines[] = 'DUE SETTLEMENTS';
        foreach (bookingsWithDue($pdo) as $b) $addBookingLines($b);
        break;

    case 'pos.php':
    case 'pos_products.php':
        $lines[] = 'POS PRODUCTS / CATALOG';
        foreach (posProductListAll($pdo) as $p) $lines[] = ($p['name'] ?: '-') . ' | ' . ($p['category'] ?: '-') . ' | Price ' . money((float)$p['price']) . ' | Stock ' . (int)$p['stock_qty'] . ' | ' . ((int)$p['active'] ? 'Active' : 'Inactive');
        break;

    case 'pos_sales.php':
        $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-t'); $sales = posSalesInRange($pdo, $from, $to); $tot = posSalesTotals($sales);
        $lines[] = 'POS SALES ' . $from . ' to ' . $to;
        $lines[] = 'Sales: ' . $tot['count'] . ' | Total: ' . money($tot['total']);
        foreach ($sales as $s) $lines[] = ($s['sale_no'] ?: '-') . ' | ' . $s['created_at'] . ' | ' . ($s['customer_name'] ?: 'Walk-in') . ' | ' . ($s['payment_method'] ?: '-') . ' | ' . money((float)$s['total']);
        break;

    case 'pos_accounts.php':
        $lines[] = 'POS ACCOUNTS';
        foreach (posAccountList($pdo, false) as $a) $lines[] = ($a['account_name'] ?: '-') . ' | ' . ($a['account_type'] ?: '-') . ' | Opening balance ' . money((float)$a['opening_balance']) . ' | ' . ((int)$a['active'] ? 'Active' : 'Inactive');
        break;

    case 'pos_receipt.php':
        $id = (int)($_GET['id'] ?? 0); $sale = posGetSale($pdo, $id); $lines[] = 'POS RECEIPT';
        if ($sale) { $lines[] = 'Sale: ' . $sale['sale_no']; foreach (posGetSaleItems($pdo, $id) as $i) $lines[] = $i['product_name'] . ' x ' . (int)$i['qty'] . ' = ' . money((float)$i['line_total']); $lines[] = 'Total: ' . money((float)$sale['total']); }
        else $lines[] = 'Sale not found.';
        break;

    case 'settings.php':
        $lines[] = 'SETTINGS'; foreach (getSettings($pdo) as $k => $v) { if (stripos($k, 'password') !== false || stripos($k, 'secret') !== false) continue; $lines[] = $k . ': ' . $v; } $lines[] = 'Users: ' . count(listUsers($pdo));
        break;

    case 'ai.php':
        $lines[] = 'AI TECHNOLOGIES — HOTEL INTELLIGENCE';
        $ai = aiHotelInsights($pdo);
        foreach ($ai['insights'] as $x) $lines[] = '- ' . $x;
        $lines[] = ''; $lines[] = 'Recommendations'; foreach ($ai['recommendations'] as $x) $lines[] = '- ' . $x;
        break;

    case 'professional_systems.php':
        $lines[] = 'PROFESSIONAL SYSTEMS — OPERATIONS CONTROL';
        $lines[] = 'Generated: ' . date('d M Y H:i');
        $lines[] = 'Active bookings: ' . (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('Cancelled','Checked-Out')")->fetchColumn();
        $lines[] = 'Tracked active rooms: ' . (int)$pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Active'")->fetchColumn();
        $lines[] = 'Dirty rooms: ' . (int)$pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Active' AND COALESCE(housekeeping_status,'Clean') = 'Dirty'")->fetchColumn();
        $lines[] = 'Open maintenance: ' . (int)$pdo->query("SELECT COUNT(*) FROM maintenance_tickets WHERE status NOT IN ('Resolved','Closed')")->fetchColumn();
        $lines[] = '';
        $lines[] = 'HOUSEKEEPING';
        foreach ($pdo->query("SELECT room_no, room_type, status, COALESCE(housekeeping_status,'Clean') AS housekeeping_status FROM hotel_rooms ORDER BY room_type, room_no")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $lines[] = 'Room ' . $r['room_no'] . ' | ' . $r['room_type'] . ' | Room: ' . $r['status'] . ' | Housekeeping: ' . $r['housekeeping_status'];
        $lines[] = '';
        $lines[] = 'MAINTENANCE';
        foreach ($pdo->query("SELECT room_no,title,priority,status,reported_by FROM maintenance_tickets ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $t)
            $lines[] = ($t['room_no'] ?: '-') . ' | ' . $t['priority'] . ' | ' . $t['status'] . ' | ' . $t['title'] . ' | By: ' . ($t['reported_by'] ?: '-');
        break;

    default:
        $lines[] = strtoupper(str_replace('.php', '', $page));
        $lines[] = 'Page exported successfully.';
        foreach ($_GET as $k => $v) $lines[] = $k . ': ' . (is_scalar($v) ? $v : '[value]');
        break;
}

spdf_output(spdf_build($lines, $page), preg_replace('/[^A-Za-z0-9_-]/', '-', str_replace('.php', '', $page)) . '-' . date('Ymd-His') . '.pdf');
