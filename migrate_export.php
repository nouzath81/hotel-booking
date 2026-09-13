<?php
/**
 * Export script — dumps every table from the CURRENTLY CONFIGURED database
 * (whatever driver config.php currently points to) into a single JSON file.
 *
 * Usage (command line only, not a web page):
 *   php migrate_export.php
 *
 * Run this BEFORE changing config.php to a different driver, so it captures
 * data from the database you're migrating FROM.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line, e.g.:\n  php migrate_export.php\n");
}

require __DIR__ . '/db_connect.php'; // connects using whatever config.php currently says

// Order doesn't matter for export (only for import), but keep it readable/consistent.
$tables = [
    'room_types', 'settings', 'users', 'bookings', 'payments',
    'petty_cash', 'pos_products', 'pos_sales', 'pos_sale_items',
];

echo "Exporting from driver: " . DB_DRIVER . "\n\n";

$data = [];
foreach ($tables as $t) {
    $stmt = $pdo->query("SELECT * FROM $t");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data[$t] = $rows;
    echo str_pad($t, 20) . count($rows) . " row(s)\n";
}

if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

$outFile = __DIR__ . '/data/export_' . date('Ymd_His') . '.json';
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($outFile, $json);

echo "\nExported to:\n  $outFile\n";
echo "\nNext steps:\n";
echo "  1. Edit config.php to point to the database you're migrating TO.\n";
echo "  2. Run: php migrate_import.php " . basename($outFile) . "\n";
echo "     (or the full path if running from a different directory)\n";
