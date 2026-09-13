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
 */

define('DB_DRIVER', 'sqlite'); // 'sqlite', 'mysql', or 'pgsql'

// Only used when DB_DRIVER is 'mysql' or 'pgsql':
define('DB_HOST', 'localhost');
define('DB_PORT', '3306'); // MySQL default 3306, PostgreSQL default 5432
define('DB_NAME', 'hotel_booking');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4'); // MySQL only

// Only used when DB_DRIVER is 'pgsql'. Set to 'require' if your PostgreSQL
// host needs SSL (e.g. most managed cloud databases); leave blank otherwise.
define('DB_PGSSLMODE', '');

// Used to create protected public links for WhatsApp booking PDFs. Change this value on production.
define('WHATSAPP_PDF_SECRET', 'change-this-booking-pdf-secret-2026');
