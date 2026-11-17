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
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'varieties_managed'
");
$stmt->execute();

if ((int)$stmt->fetchColumn() === 0) {
    $db->exec("ALTER TABLE products ADD COLUMN varieties_managed TINYINT(1) NOT NULL DEFAULT 0 AFTER ruling_option");
    echo "Added products.varieties_managed.\n";
} else {
    echo "products.varieties_managed already exists; no changes made.\n";
}
