<?php
/**
 * Safe Database Migration for Rajpriya Notebooks
 * Adds Customer Type & Customer ID enhancements without data loss.
 */
require_once __DIR__ . '/config/database.php';

$db = getDB();

echo "Starting safe migration...\n";

// 1. Check & Add customer_type to users table
$colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'customer_type'");
if (!$colStmt->fetch()) {
    $db->exec("ALTER TABLE users ADD COLUMN customer_type ENUM('Wholesaler', 'Distributor', 'Retailer', 'Other') NOT NULL DEFAULT 'Retailer' AFTER district");
    echo "Added 'customer_type' column to 'users' table.\n";
} else {
    echo "'customer_type' already exists in 'users' table.\n";
}

// 2. Check & Add previous_customer_codes to users table
$colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'previous_customer_codes'");
if (!$colStmt->fetch()) {
    $db->exec("ALTER TABLE users ADD COLUMN previous_customer_codes TEXT DEFAULT NULL AFTER customer_code");
    echo "Added 'previous_customer_codes' column to 'users' table.\n";
} else {
    echo "'previous_customer_codes' already exists in 'users' table.\n";
}

// 3. Check & Add customer_type to orders table
$colStmt = $db->query("SHOW COLUMNS FROM orders LIKE 'customer_type'");
if (!$colStmt->fetch()) {
    $db->exec("ALTER TABLE orders ADD COLUMN customer_type VARCHAR(50) DEFAULT NULL AFTER customer_code");
    echo "Added 'customer_type' column to 'orders' table.\n";
} else {
    echo "'customer_type' already exists in 'orders' table.\n";
}

// 4. Backfill customer_type for users where it's NULL or empty
$db->exec("UPDATE users SET customer_type = 'Retailer' WHERE customer_type IS NULL OR customer_type = ''");
echo "Backfilled empty customer_type values to 'Retailer'.\n";

// 5. Ensure UNIQUE constraint on users.customer_code
$idxStmt = $db->query("SHOW INDEX FROM users WHERE Column_name = 'customer_code'");
$hasUnique = false;
while ($row = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
    if ((int)$row['Non_unique'] === 0) {
        $hasUnique = true;
        break;
    }
}
if (!$hasUnique) {
    // Check if there are any duplicate customer_codes
    $dupStmt = $db->query("SELECT customer_code, COUNT(*) as cnt FROM users WHERE customer_code IS NOT NULL AND customer_code != '' GROUP BY customer_code HAVING cnt > 1");
    $dups = $dupStmt->fetchAll();
    if (empty($dups)) {
        $db->exec("ALTER TABLE users ADD UNIQUE KEY uq_users_customer_code (customer_code)");
        echo "Added UNIQUE constraint 'uq_users_customer_code' on users(customer_code).\n";
    } else {
        echo "Warning: Duplicates found in customer_code, could not add UNIQUE index automatically.\n";
    }
} else {
    echo "UNIQUE constraint on users.customer_code already exists.\n";
}

echo "Migration finished successfully.\n";
