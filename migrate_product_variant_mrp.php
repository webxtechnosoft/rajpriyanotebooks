<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this migration from the command line.\n");
}

require_once __DIR__ . '/config/database.php';

$db = getDB();
$columns = [
    'mrp' => ['definition' => 'DECIMAL(10,2) DEFAULT NULL', 'after' => 'stock'],
    'price' => ['definition' => 'DECIMAL(10,2) DEFAULT NULL', 'after' => 'mrp'],
    'sale_price' => ['definition' => 'DECIMAL(10,2) DEFAULT NULL', 'after' => 'price'],
    'gst_rate' => ['definition' => 'DECIMAL(5,2) DEFAULT NULL', 'after' => 'sale_price']
];
$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_variants'
      AND COLUMN_NAME = ?
");

foreach ($columns as $name => $column) {
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE product_variants ADD COLUMN `' . $name . '` '
            . $column['definition'] . ' AFTER `' . $column['after'] . '`'
        );
        echo "Added nullable {$name} column to product_variants.\n";
    } else {
        echo "product_variants.{$name} already exists; no changes made.\n";
    }
}
