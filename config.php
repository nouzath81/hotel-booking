<?php
/**
 * Database configuration.
 *
 * Set DB_DRIVER to 'sqlite' (default, zero setup), 'mysql', or 'pgsql'.
 *
 * For MySQL: create an empty database first (e.g. via phpMyAdmin or
 *   `CREATE DATABASE hotel_booking CHARACTER SET utf8mb4;`),
 * then fill in the credentials below. The app will create all the
 * tables automatically on first connection — no schema file to import.
 *
 * For PostgreSQL: create an empty database first, e.g.
 *   `CREATE DATABASE hotel_booking;`
 * then fill in DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS below (DB_CHARSET
 * is ignored for PostgreSQL). The app creates all tables automatically
 * on first connection here too.
 *
 * Requires the PHP `pdo_mysql` extension for MySQL mode, or `pdo_pgsql`
 * for PostgreSQL mode.
 *
 * --- Environment variables (e.g. on Render) ---
 * Every value below can be overridden by an environment variable of the
 * same name, WITHOUT editing this file or committing secrets to git:
 *   DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_PGSSLMODE
 * If DATABASE_URL is set (Render's Postgres "Internal Database URL",
 * e.g. postgres://user:pass@host:port/dbname), it's parsed automatically
 * and takes priority over the individual DB_* variables/hardcoded values.
 * Local/default values below are only used when nothing is set.
 */

function envOr(string $name, string $default): string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

$driver = envOr('DB_DRIVER', 'sqlite');
$host = envOr('DB_HOST', 'localhost');
$port = envOr('DB_PORT', '3306');
$name = envOr('DB_NAME', 'hotel_booking');
$user = envOr('DB_USER', 'root');
$pass = envOr('DB_PASS', '');
$sslmode = envOr('DB_PGSSLMODE', '');

$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts) {
        $scheme = $parts['scheme'] ?? '';
        $driver = (strpos($scheme, 'postgres') !== false) ? 'pgsql' : ((strpos($scheme, 'mysql') !== false) ? 'mysql' : $driver);
        $host = $parts['host'] ?? $host;
        $port = isset($parts['port']) ? (string) $parts['port'] : ($driver === 'pgsql' ? '5432' : $port);
        $name = isset($parts['path']) ? ltrim($parts['path'], '/') : $name;
        $user = $parts['user'] ?? $user;
        $pass = $parts['pass'] ?? $pass;
        if ($driver === 'pgsql' && $sslmode === '') {
            $sslmode = 'require'; // Render's managed Postgres requires SSL.
        }
    }
}

define('DB_DRIVER', $driver); // 'sqlite', 'mysql', or 'pgsql'

// Only used when DB_DRIVER is 'mysql' or 'pgsql':
define('DB_HOST', $host);
define('DB_PORT', $port); // MySQL default 3306, PostgreSQL default 5432
define('DB_NAME', $name);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('DB_CHARSET', 'utf8mb4'); // MySQL only

// Only used when DB_DRIVER is 'pgsql'. Set to 'require' if your PostgreSQL
// host needs SSL (e.g. most managed cloud databases); leave blank otherwise.
define('DB_PGSSLMODE', $sslmode);

// Used to create protected public links for WhatsApp booking PDFs. Change this value on production.
define('WHATSAPP_PDF_SECRET', envOr('WHATSAPP_PDF_SECRET', 'change-this-booking-pdf-secret-2026'));
