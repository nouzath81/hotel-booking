<?php
/**
 * Import script — loads a JSON export (produced by migrate_export.php) into
 * the CURRENTLY CONFIGURED database (whatever driver config.php currently
 * points to), preserving original row IDs so relationships (booking_id,
 * sale_id, product_id, etc.) stay intact.
 *
 * Usage (command line only, not a web page):
 *   php migrate_import.php data/export_20260101_120000.json
 *   php migrate_import.php data/export_20260101_120000.json --force
 *
 * By default it refuses to run if the target database already has data in
 * any of these tables (to avoid accidental duplicates/overwrites). Pass
 * --force to import anyway.
 *
 * Run this AFTER changing config.php to point to the database you're
 * migrating TO.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line, e.g.:\n  php migrate_import.php data/export_20260101_120000.json\n");
}

$file = $argv[1] ?? null;
$force = in_array('--force', $argv, true);

if (!$file) {
    die("Usage: php migrate_import.php path/to/export.json [--force]\n");
}
if (!file_exists($file)) {
    die("File not found: $file\n");
}

$data = json_decode(file_get_contents($file), true);
if (!is_array($data)) {
    die("Could not parse $file as valid JSON.\n");
}

require __DIR__ . '/db_connect.php'; // connects using whatever config.php currently says
// (db_connect.php also auto-creates all tables on the target if they don't exist yet)

echo "Importing into driver: " . DB_DRIVER . "\n\n";

// Import order matters — parents before children, so foreign keys resolve.
$order = [
    'room_types', 'settings', 'users', 'bookings', 'payments',
    'petty_cash', 'pos_products', 'pos_sales', 'pos_sale_items',
];

// Safety check: refuse if the target already has data, unless --force is given.
if (!$force) {
    foreach ($order as $t) {
        if (!isset($data[$t])) continue;
        $count = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        if ($count > 0) {
            die("Table '$t' already has $count row(s) in the target database.\n" .
                "Re-run with --force to import anyway (may cause duplicate-key errors\n" .
                "on rows that already exist), or start from an empty target database.\n");
        }
    }
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'mysql') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
} elseif ($driver === 'pgsql') {
    // No PRAGMA/SET equivalent needed here — $order already inserts
    // parents before children, so FK constraints are satisfied as-is.
} else {
    $pdo->exec('PRAGMA foreign_keys = OFF');
}

$pdo->beginTransaction();
try {
    foreach ($order as $t) {
        $rows = $data[$t] ?? [];
        if (!$rows) {
            echo str_pad($t, 20) . "0 row(s) (skipped)\n";
            continue;
        }

        $cols = array_keys($rows[0]);
        $colList = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO $t ($colList) VALUES ($placeholders)");

        $inserted = 0;
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
            $inserted++;
        }
        echo str_pad($t, 20) . "$inserted row(s) imported\n";
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } elseif ($driver !== 'pgsql') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    die("\nImport failed and was rolled back: " . $e->getMessage() . "\n" .
        "No changes were made to the target database.\n");
}

if ($driver === 'mysql') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} elseif ($driver !== 'pgsql') {
    $pdo->exec('PRAGMA foreign_keys = ON');
}

echo "\nImport complete. Log in and spot-check a few bookings/sales to confirm everything looks right.\n";
