<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this migration from the command line.\n");
}

require_once __DIR__ . '/config/database.php';

$db = getDB();
$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_variants'
      AND COLUMN_NAME = 'basic_price'
");
$stmt->execute();

if ((int)$stmt->fetchColumn() === 0) {
    $db->exec('ALTER TABLE product_variants ADD COLUMN basic_price DECIMAL(10,2) DEFAULT NULL AFTER gst_rate');
    echo "Added nullable basic_price column to product_variants.\n";
} else {
    echo "product_variants.basic_price already exists; no changes made.\n";
}
