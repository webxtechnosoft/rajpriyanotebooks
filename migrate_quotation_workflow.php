<?php
/**
 * Safe Database Migration for Rajpriya Notebooks - Quotation Management Enhancements:
 * - Product-wise Accept/Decline status
 * - Customer Change Requests tracking
 * - Quotation Revisions History
 */
require_once __DIR__ . '/config/database.php';

$db = getDB();

echo "Starting Quotation Workflow Database Migration...\n";

// Helper to check if a column exists
function columnExists($db, $table, $column) {
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $stmt->rowCount() > 0;
}

// 1. Update quotations table status ENUM and add change request tracking fields
try {
    $db->exec("
        ALTER TABLE `quotations` 
        MODIFY COLUMN `status` ENUM('Draft','Sent','Change Requested','Accepted','Declined') NOT NULL DEFAULT 'Draft'
    ");
    echo "[OK] 'quotations.status' enum updated with 'Change Requested'.\n";
} catch (Exception $e) {
    echo "[INFO] Update quotations status enum: " . $e->getMessage() . "\n";
}

if (!columnExists($db, 'quotations', 'customer_notes')) {
    $db->exec("ALTER TABLE `quotations` ADD COLUMN `customer_notes` TEXT DEFAULT NULL AFTER `admin_notes`");
    echo "[OK] Added 'customer_notes' to 'quotations'.\n";
}

if (!columnExists($db, 'quotations', 'change_requested_at')) {
    $db->exec("ALTER TABLE `quotations` ADD COLUMN `change_requested_at` DATETIME DEFAULT NULL AFTER `customer_response_at`");
    echo "[OK] Added 'change_requested_at' to 'quotations'.\n";
}

if (!columnExists($db, 'quotations', 'invoice_synced_at')) {
    $db->exec("ALTER TABLE `quotations` ADD COLUMN `invoice_synced_at` DATETIME DEFAULT NULL AFTER `change_requested_at`");
    echo "[OK] Added 'invoice_synced_at' to 'quotations'.\n";
}

if (!columnExists($db, 'quotations', 'is_final_approved')) {
    $db->exec("ALTER TABLE `quotations` ADD COLUMN `is_final_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `invoice_synced_at`");
    echo "[OK] Added 'is_final_approved' to 'quotations'.\n";
}

// 2. Update quotation_items table with item-level status and customer change tracking
if (!columnExists($db, 'quotation_items', 'item_status')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `item_status` ENUM('Pending','Accepted','Declined','Change Requested') NOT NULL DEFAULT 'Pending' AFTER `sgst_amount`
    ");
    echo "[OK] Added 'item_status' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'original_quantity')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `original_quantity` INT(11) DEFAULT NULL AFTER `item_status`
    ");
    echo "[OK] Added 'original_quantity' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'customer_requested_quantity')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `customer_requested_quantity` INT(11) DEFAULT NULL AFTER `original_quantity`
    ");
    echo "[OK] Added 'customer_requested_quantity' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'customer_action')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `customer_action` ENUM('none','keep','modified','removed','added') NOT NULL DEFAULT 'none' AFTER `customer_requested_quantity`
    ");
    echo "[OK] Added 'customer_action' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'is_customer_removed')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `is_customer_removed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_action`
    ");
    echo "[OK] Added 'is_customer_removed' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'admin_comment')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `admin_comment` VARCHAR(255) DEFAULT NULL AFTER `is_customer_removed`
    ");
    echo "[OK] Added 'admin_comment' to 'quotation_items'.\n";
}

if (!columnExists($db, 'quotation_items', 'reviewed_at')) {
    $db->exec("
        ALTER TABLE `quotation_items` 
        ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `admin_comment`
    ");
    echo "[OK] Added 'reviewed_at' to 'quotation_items'.\n";
}

// 3. Create quotation_revisions table for audit history
$sql_revisions = "
CREATE TABLE IF NOT EXISTS `quotation_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `revision_number` int(11) NOT NULL DEFAULT 1,
  `revision_type` enum('initial','admin_update','customer_change_request','admin_review','final_approval','customer_acceptance') NOT NULL,
  `status` varchar(50) NOT NULL,
  `created_by_role` enum('admin','customer') NOT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `items_snapshot` longtext NOT NULL,
  `totals_snapshot` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation_id` (`quotation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

$db->exec($sql_revisions);
echo "[OK] 'quotation_revisions' table verified/created successfully.\n";

$revisionTypeColumn = $db->query("SHOW COLUMNS FROM `quotation_revisions` LIKE 'revision_type'")->fetch();
if ($revisionTypeColumn && strpos($revisionTypeColumn['Type'], "'customer_acceptance'") === false) {
    $db->exec("
        ALTER TABLE `quotation_revisions`
        MODIFY COLUMN `revision_type` ENUM(
            'initial',
            'admin_update',
            'customer_change_request',
            'admin_review',
            'final_approval',
            'customer_acceptance'
        ) NOT NULL
    ");
    echo "[OK] Added 'customer_acceptance' to 'quotation_revisions.revision_type'.\n";
}

// Populate original_quantity for existing quotation_items if not set
$db->exec("UPDATE `quotation_items` SET `original_quantity` = `quantity` WHERE `original_quantity` IS NULL");
echo "[OK] Synchronized original_quantity for existing items.\n";

echo "Quotation Workflow Database Migration completed successfully!\n";
