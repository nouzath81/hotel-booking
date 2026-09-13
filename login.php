<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $user = verifyLogin($pdo, $username, $password);

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header('Location: ' . ($redirect !== '' ? $redirect : 'index.php'));
        exit;
    }

    $error = 'Invalid username or password.';
}

$companyName = getSetting($pdo, 'company_name', 'Hotel Booking System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= h($companyName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>
.login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
}
.login-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 32px;
    width: 100%;
    max-width: 380px;
}
.login-card h1 {
    margin: 0 0 4px;
    font-size: 1.3rem;
    text-align: center;
}
.login-card .sub {
    text-align: center;
    color: var(--muted);
    font-size: 0.85rem;
    margin-bottom: 22px;
}
.login-card .btn { width: 100%; padding: 11px; font-size: 0.95rem; }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1>🏨 <?= h($companyName) ?></h1>
        <div class="sub">Sign in to continue</div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
            <div class="form-group" style="margin-bottom:14px;">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group" style="margin-bottom:18px;">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-accent">Sign In</button>
        </form>
    </div>
</div>
</body>
</html>
