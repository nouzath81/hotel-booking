<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'AI Technologies';
$question = trim((string)($_POST['question'] ?? ''));
$answer = $question !== '' ? aiAssistantAnswer($pdo, $question) : '';
$ai = aiHotelInsights($pdo);

require __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print flex-between">
    <div>
        <h1 style="margin:0;">🤖 AI Technologies</h1>
        <p style="margin:4px 0 0;color:var(--muted);">Smart hotel analytics, forecasting and operational recommendations.</p>
    </div>
    <a class="btn btn-accent" href="export_pdf.php?page=ai.php">📄 Export AI Report PDF</a>
</div>

<div class="card" style="background:linear-gradient(135deg,#eef7ff,#f7fbff);">
    <h3>AI Hotel Intelligence</h3>
    <p style="color:var(--muted);margin-bottom:0;">This module analyzes your live booking, room, payment and POS data locally. It does not require an external AI API or internet connection.</p>
</div>

<div class="form-row">
    <div class="card" style="flex:1;min-width:190px;"><div style="color:var(--muted);font-size:.85rem;">Current Occupancy</div><div style="font-size:1.7rem;font-weight:700;color:var(--primary-dark);"> <?= number_format($ai['occupancy'],1) ?>%</div><div><?= $ai['today_booked'] ?> / <?= $ai['total_rooms'] ?> rooms</div></div>
    <div class="card" style="flex:1;min-width:190px;"><div style="color:var(--muted);font-size:.85rem;">30-Day Booking Revenue</div><div style="font-size:1.7rem;font-weight:700;color:var(--accent);"> <?= money($ai['last30_revenue']) ?></div><div>Invoice estimate</div></div>
    <div class="card" style="flex:1;min-width:190px;"><div style="color:var(--muted);font-size:.85rem;">7-Day Booking Rate</div><div style="font-size:1.7rem;font-weight:700;color:var(--primary-dark);"> <?= number_format($ai['avg_daily_bookings'],1) ?></div><div>Average bookings/day</div></div>
    <div class="card" style="flex:1;min-width:190px;"><div style="color:var(--muted);font-size:.85rem;">Peak Forecast</div><div style="font-size:1.3rem;font-weight:700;color:var(--danger);"> <?= h(date('d M', strtotime($ai['peak_date']))) ?></div><div><?= number_format($ai['peak_occupancy'],1) ?>% estimated occupancy</div></div>
</div>

<div class="form-row">
    <div class="card" style="flex:1;min-width:300px;">
        <h3>🔎 AI Insights</h3>
        <ul style="line-height:1.8;"><?php foreach ($ai['insights'] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul>
    </div>
    <div class="card" style="flex:1;min-width:300px;">
        <h3>💡 Recommendations</h3>
        <ul style="line-height:1.8;"><?php foreach ($ai['recommendations'] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul>
    </div>
</div>

<div class="card no-print">
    <h3>💬 AI Assistant</h3>
    <p style="color:var(--muted);">Ask a simple question such as “What is occupancy?”, “How is revenue?”, “Which days are busy?” or “Who has dues?”</p>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group" style="flex:3;"><label>Your question</label><input name="question" value="<?= h($question) ?>" placeholder="Ask about hotel performance..."></div>
        <div class="form-group" style="flex:0;"><button class="btn btn-accent" type="submit">Ask AI</button></div>
    </form>
    <?php if ($answer): ?><div class="alert alert-success" style="margin-top:12px;"><strong>AI:</strong> <?= h($answer) ?></div><?php endif; ?>
</div>

<div class="card">
    <h3>AI Features</h3>
    <div class="form-row">
        <div class="card" style="flex:1;min-width:220px;"><strong>Demand Forecasting</strong><p style="color:var(--muted);">Estimates upcoming occupancy from active reservations.</p></div>
        <div class="card" style="flex:1;min-width:220px;"><strong>Revenue Intelligence</strong><p style="color:var(--muted);">Tracks recent booking revenue and booking velocity.</p></div>
        <div class="card" style="flex:1;min-width:220px;"><strong>Risk Alerts</strong><p style="color:var(--muted);">Highlights outstanding balances and high-demand periods.</p></div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
