<?php
/**
 * Session + authentication helpers.
 * Include this at the very top of every protected page (before any output),
 * then call requireLogin() to force a redirect to login.php if not signed in.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
    ];
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $redirect = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Access denied — this page is for admins only.');
    }
}
