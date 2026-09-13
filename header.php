<?php
$current = basename($_SERVER['PHP_SELF']);
$companyName = isset($pdo) ? getSetting($pdo, 'company_name', 'Hotel Booking System') : 'Hotel Booking System';
$loggedInUser = function_exists('currentUser') ? currentUser() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h($companyName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar no-print">
    <div class="brand">🏨 <?= h($companyName) ?></div>
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Menu" aria-expanded="false">☰</button>
    <nav id="main-nav">
        <a href="index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="add_booking.php" class="<?= $current === 'add_booking.php' ? 'active' : '' ?>">New Booking</a>
        <a href="booking_list.php" class="<?= $current === 'booking_list.php' ? 'active' : '' ?>">Booking List</a>
        <a href="rooms.php" class="<?= $current === 'rooms.php' ? 'active' : '' ?>">Rooms</a>
        <a href="checkout.php" class="<?= $current === 'checkout.php' ? 'active' : '' ?>">Checkout</a>
        <a href="pos.php" class="<?= in_array($current, ['pos.php', 'pos_products.php', 'pos_sales.php', 'pos_receipt.php', 'pos_accounts.php']) ? 'active' : '' ?>">POS</a>
        <a href="reports.php" class="<?= $current === 'reports.php' ? 'active' : '' ?>">Reports</a>
        <a href="staff.php" class="<?= in_array($current, ['staff.php', 'staff_monthly_report.php', 'staff_monthly_report_pdf.php']) ? 'active' : '' ?>">Staff & Attendance</a>
        <a href="salary_sheet.php" class="<?= $current === 'salary_sheet.php' ? 'active' : '' ?>">Salary Sheet</a>
        <a href="ai.php" class="<?= $current === 'ai.php' ? 'active' : '' ?>">🤖 AI Technologies</a>
        <a href="professional_systems.php" class="<?= $current === 'professional_systems.php' ? 'active' : '' ?>">⚙ Professional Systems</a>
        <a href="business_report.php" class="<?= $current === 'business_report.php' ? 'active' : '' ?>">Trend Report</a>
        <a href="statement.php" class="<?= $current === 'statement.php' ? 'active' : '' ?>">Account Statement</a>
        <a href="due_list.php" class="<?= $current === 'due_list.php' || $current === 'settle_due.php' ? 'active' : '' ?>">Due Settlements</a>
        <a href="petty_cash.php" class="<?= $current === 'petty_cash.php' ? 'active' : '' ?>">Petty Cash</a>
        <?php if ($loggedInUser && $loggedInUser['role'] === 'admin'): ?>
            <a href="settings.php" class="<?= $current === 'settings.php' ? 'active' : '' ?>">Settings</a>
        <?php endif; ?>
        <?php if ($loggedInUser): ?>
            <span style="color:#cfe3f0;margin-left:14px;font-size:0.85rem;">👤 <?= h($loggedInUser['username']) ?></span>
            <a href="logout.php" style="margin-left:6px;">Logout</a>
        <?php endif; ?>
        <a href="export_pdf.php?<?= h(http_build_query(array_merge(['page' => $current], $_GET))) ?>" class="btn btn-sm btn-accent no-print" style="margin-left:10px;">📄 Export PDF</a>
    </nav>
</header>
<script>
(function(){
    var t = document.getElementById('nav-toggle');
    var n = document.getElementById('main-nav');
    if (!t || !n) return;
    t.addEventListener('click', function(){
        var open = n.classList.toggle('open');
        t.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>
<main class="container">
