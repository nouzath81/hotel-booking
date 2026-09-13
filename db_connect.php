<?php

    
**
 * Database connection + auto-setup.
 * Supports SQLite (default, zero setup), MySQL, or PostgreSQL — pick the
 * driver and (for MySQL/PostgreSQL) fill in credentials in config.php.
 */

require __DIR__ . '/config.php';

$isMysql = (DB_DRIVER === 'mysql');
$isPgsql = (DB_DRIVER === 'pgsql');

try {
    if ($isMysql) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } elseif ($isPgsql) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
        if (defined('DB_PGSSLMODE') && DB_PGSSLMODE !== '') {
            $dsn .= ';sslmode=' . DB_PGSSLMODE;
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dbFile = __DIR__ . '/data/booking.sqlite';
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage() .
        (($isMysql || $isPgsql) ? ' — check the credentials in config.php and that the database exists.' : ''));
}

/**
 * Returns true if $table already has a column named $column, for whichever
 * driver is active. Used by the safe "add this column if missing" migration
 * blocks below so each one is written once instead of three times.
 */
function columnExists(PDO $pdo, bool $isMysql, bool $isPgsql, string $table, string $column): bool
{
    if ($isMysql) {
        $cols = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
        return in_array($column, $cols, true);
    }
    if ($isPgsql) {
        $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_name = ?');
        $stmt->execute([$table]);
        return in_array($column, $stmt->fetchAll(PDO::FETCH_COLUMN), true);
    }
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    return in_array($column, array_column($cols, 'name'), true);
}

/**
 * Dialect fragments — the only bits that differ between SQLite, MySQL, and
 * PostgreSQL DDL. Every CREATE TABLE below is written once and assembled
 * from these, so all three engines get the exact same schema without
 * maintaining three copies of it.
 */
if ($isPgsql) {
    $pk      = 'id SERIAL PRIMARY KEY';
    $autoNow = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
    $tsNull  = 'TIMESTAMP NULL';
    $engine  = '';
    $vc50    = 'VARCHAR(50)';
    $vc100   = 'VARCHAR(100)';
    $vc20    = 'VARCHAR(20)';
} else {
    $pk       = $isMysql ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    $autoNow  = $isMysql ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
    $tsNull   = $isMysql ? 'DATETIME NULL' : 'TEXT';
    $engine   = $isMysql ? ' ENGINE=InnoDB' : '';
    // Indexed/unique text columns need a bounded length in MySQL (TEXT can't be indexed without one);
    // VARCHAR(n) works identically to TEXT in SQLite, so it's safe to use for both.
    $vc50     = $isMysql ? 'VARCHAR(50)'  : 'TEXT';
    $vc100    = $isMysql ? 'VARCHAR(100)' : 'TEXT';
    // Older MySQL/MariaDB versions reject a literal DEFAULT on a TEXT column,
    // so short enum-like columns (status, role, etc.) use a bounded VARCHAR instead.
    $vc20     = $isMysql ? 'VARCHAR(20)'  : 'TEXT';
}

// Auto-create tables on first run
// Backfill WhatsApp number for existing booking databases.
try {
    if (!columnExists($pdo, $isMysql, $isPgsql, 'bookings', 'whatsapp_no')) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN whatsapp_no ' . ($isMysql || $isPgsql ? 'VARCHAR(30) NULL' : 'TEXT'));
    }
} catch (PDOException $e) {
    // Keep existing installations usable if an optional migration is unavailable.
}

// Backfill room_count so one booking can represent several rooms for one guest.
try {
    if (!columnExists($pdo, $isMysql, $isPgsql, 'bookings', 'room_count')) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN room_count ' . (($isMysql || $isPgsql) ? 'INT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1'));
    }
} catch (PDOException $e) {}

$pdo->exec("
CREATE TABLE IF NOT EXISTS bookings (
    $pk,
    booking_no $vc50 UNIQUE,
    customer_name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    room_type TEXT,
    room_no TEXT,
    room_count INTEGER NOT NULL DEFAULT 1,
    checkin_date TEXT NOT NULL,
    checkout_date TEXT NOT NULL,
    nights INTEGER NOT NULL DEFAULT 1,
    rate_per_night REAL NOT NULL DEFAULT 0,
    extra_charges REAL NOT NULL DEFAULT 0,
    discount REAL NOT NULL DEFAULT 0,
    tax_percent REAL NOT NULL DEFAULT 0,
    advance_paid REAL NOT NULL DEFAULT 0,
    status $vc20 NOT NULL DEFAULT 'Confirmed',
    notes TEXT,
    created_at $autoNow
)$engine
");


// Multiple rooms per customer/booking are stored as a comma-separated room_no list
// for backward compatibility with the existing bookings table. One booking still
// produces one invoice, while availability counts each selected room.

// Only seed the built-in room types when this table is being created for
// the first time.  Do NOT re-seed them on every request: administrators must
// be able to permanently delete Standard / Deluxe / Suite just like manually
// added room types.
$roomTypesTableExisted = true;
try {
    $pdo->query('SELECT 1 FROM room_types LIMIT 1');
} catch (Throwable $e) {
    $roomTypesTableExisted = false;
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS room_types (
    $pk,
    type_name $vc100 NOT NULL UNIQUE,
    total_rooms INTEGER NOT NULL DEFAULT 0,
    default_rate REAL NOT NULL DEFAULT 0
)$engine
");

if (!$roomTypesTableExisted) {
    $seed = $pdo->prepare('INSERT INTO room_types (type_name, total_rooms, default_rate) VALUES (?, ?, ?)');
    foreach ([
        ['Standard', 10, 50],
        ['Deluxe', 6, 80],
        ['Suite', 3, 150],
    ] as $defaultRoomType) {
        $seed->execute($defaultRoomType);
    }
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS hotel_rooms (
    $pk,
    room_no $vc50 NOT NULL UNIQUE,
    room_type TEXT NOT NULL,
    status $vc20 NOT NULL DEFAULT 'Active',
    created_at $autoNow
)$engine
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS petty_cash (
    $pk,
    entry_date TEXT NOT NULL,
    description TEXT NOT NULL,
    category TEXT,
    type $vc20 NOT NULL DEFAULT 'Out',
    amount REAL NOT NULL DEFAULT 0,
    created_at $autoNow
)$engine
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS payments (
    $pk,
    booking_id INTEGER NOT NULL,
    payment_date TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    method TEXT,
    notes TEXT,
    created_at $autoNow,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
)$engine
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS settings (
    setting_key $vc100 PRIMARY KEY,
    setting_value TEXT
)$engine
");

// Default company info — editable later from settings.php. Also backfills any
// new setting keys added by later versions of the app onto an existing database.
$settingsSeed = [
    'company_name'  => 'Your Hotel Name',
    'address'       => '123 Main Street, Your City',
    'phone'         => '+1 234 567 8900',
    'email'         => 'info@yourhotel.com',
    'website'       => '',
    'footer_note'   => 'Thank you for your business!',
    'pos_tax_percent' => '0',
];
$checkSetting = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
$insSetting = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
foreach ($settingsSeed as $k => $v) {
    $checkSetting->execute([$k]);
    if ((int) $checkSetting->fetchColumn() === 0) {
        $insSetting->execute([$k, $v]);
    }
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS pos_products (
    $pk,
    name TEXT NOT NULL,
    sku TEXT,
    category TEXT,
    price REAL NOT NULL DEFAULT 0,
    stock_qty INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at $autoNow
)$engine
");

$posProductCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_products')->fetchColumn();
if ($posProductCount === 0) {
    $seedProd = $pdo->prepare('INSERT INTO pos_products (name, sku, category, price, stock_qty) VALUES (?, ?, ?, ?, ?)');
    $seedProd->execute(['Bottled Water', 'BEV-001', 'Beverages', 1.50, 100]);
    $seedProd->execute(['Soft Drink', 'BEV-002', 'Beverages', 2.00, 80]);
    $seedProd->execute(['Snack Pack', 'SNK-001', 'Snacks', 3.50, 50]);
    $seedProd->execute(['Laundry Service', 'SVC-001', 'Services', 8.00, 999]);
    $seedProd->execute(['Room Service Breakfast', 'SVC-002', 'Services', 12.00, 999]);
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS pos_sales (
    $pk,
    sale_no $vc50 UNIQUE,
    customer_name TEXT,
    subtotal REAL NOT NULL DEFAULT 0,
    discount REAL NOT NULL DEFAULT 0,
    tax_percent REAL NOT NULL DEFAULT 0,
    tax_amount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    payment_method $vc20 NOT NULL DEFAULT 'Cash',
    amount_tendered REAL NOT NULL DEFAULT 0,
    change_due REAL NOT NULL DEFAULT 0,
    cashier TEXT,
    notes TEXT,
    booking_id INTEGER NULL,
    voided INTEGER NOT NULL DEFAULT 0,
    created_at $autoNow
)$engine
");

// Backfill the hotel booking link for POS sales created by older versions.
try {
    if (!columnExists($pdo, $isMysql, $isPgsql, 'pos_sales', 'booking_id')) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN booking_id ' . (($isMysql || $isPgsql) ? 'INT NULL' : 'INTEGER NULL'));
    }
} catch (PDOException $e) {
    // Existing installations remain usable if this optional migration is unavailable.
}

// Backfill the POS account column for databases created by older versions.
try {
    if (!columnExists($pdo, $isMysql, $isPgsql, 'pos_sales', 'pos_account_id')) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN pos_account_id ' . (($isMysql || $isPgsql) ? 'INT NULL' : 'INTEGER NULL'));
    }
} catch (PDOException $e) {
    // Existing installations should remain usable even if an optional migration is unavailable.
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS pos_sale_items (
    $pk,
    sale_id INTEGER NOT NULL,
    product_id INTEGER,
    product_name TEXT NOT NULL,
    unit_price REAL NOT NULL DEFAULT 0,
    qty INTEGER NOT NULL DEFAULT 1,
    line_total REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
)$engine
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS pos_accounts (
    $pk,
    account_name $vc100 NOT NULL UNIQUE,
    account_type $vc20 NOT NULL DEFAULT 'Cash',
    opening_balance REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at $autoNow
)$engine
");

$posAccountCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_accounts')->fetchColumn();
if ($posAccountCount === 0) {
    $seedAccount = $pdo->prepare('INSERT INTO pos_accounts (account_name, account_type, opening_balance) VALUES (?, ?, ?)');
    $seedAccount->execute(['Cash', 'Cash', 0]);
    $seedAccount->execute(['Card', 'Card', 0]);
    $seedAccount->execute(['Bank Transfer', 'Bank', 0]);
    $seedAccount->execute(['Online', 'Online', 0]);
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS maintenance_tickets (
    $pk,
    room_no $vc50,
    title TEXT NOT NULL,
    priority $vc20 NOT NULL DEFAULT 'Medium',
    status $vc20 NOT NULL DEFAULT 'Open',
    reported_by $vc100,
    resolved_at $tsNull
)$engine
");

// Professional room operations fields are added safely to existing installations.
try {
    if (!columnExists($pdo, $isMysql, $isPgsql, 'hotel_rooms', 'housekeeping_status')) {
        $pdo->exec("ALTER TABLE hotel_rooms ADD COLUMN housekeeping_status " . (($isMysql || $isPgsql) ? "VARCHAR(30) NOT NULL DEFAULT 'Clean'" : "TEXT NOT NULL DEFAULT 'Clean'"));
    }
    if (!columnExists($pdo, $isMysql, $isPgsql, 'hotel_rooms', 'updated_at')) {
        $pdo->exec('ALTER TABLE hotel_rooms ADD COLUMN updated_at ' . ($isMysql ? 'DATETIME NULL' : ($isPgsql ? 'TIMESTAMP NULL' : 'TEXT')));
    }
} catch (PDOException $e) {
    // Existing installations remain usable if the optional migration is unavailable.
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    $pk,
    username $vc100 NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role $vc20 NOT NULL DEFAULT 'staff',
    created_at $autoNow
)$engine
");

// Seed one default admin account on first run — CHANGE THIS PASSWORD IMMEDIATELY.
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
}
