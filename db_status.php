<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "DB_DRIVER: " . DB_DRIVER . "\n";
echo "DB_HOST:   " . DB_HOST . "\n";
echo "DB_PORT:   " . DB_PORT . "\n";
echo "DB_NAME:   " . DB_NAME . "\n";
echo "DB_USER:   " . DB_USER . "\n";
echo "\n";

if (DB_DRIVER === 'sqlite') {
    echo "WARNING: still using local SQLite storage.\n";
    echo "This file lives inside the container and is WIPED on every\n";
    echo "restart/redeploy. Your DATABASE_URL env var is not being\n";
    echo "picked up — double-check it's set correctly on Render.\n";
} else {
    echo "OK: using an external " . strtoupper(DB_DRIVER) . " database.\n";
    echo "Data written here should persist across restarts/redeploys.\n";
}

$rawUrl = getenv('DATABASE_URL');
echo "\nDATABASE_URL env var is " . ($rawUrl ? "SET (starts with: " . substr($rawUrl, 0, 15) . "...)" : "NOT SET") . "\n";
