<?php
require_once __DIR__ . '/config/database.php';

$db = getDB();

echo "Running WhatsApp Delivery Logs & Settings migration...\n";

// 1. Create whatsapp_delivery_logs table
$sqlTable = "
CREATE TABLE IF NOT EXISTS `whatsapp_delivery_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `quotation_id` INT NULL,
    `customer_id` INT NULL,
    `recipient_phone` VARCHAR(30) NOT NULL,
    `customer_name` VARCHAR(150) NULL,
    `invoice_number` VARCHAR(50) NOT NULL,
    `file_path` VARCHAR(255) NULL,
    `file_url` VARCHAR(255) NULL,
    `media_id` VARCHAR(100) NULL,
    `message_id` VARCHAR(100) NULL,
    `status` ENUM('queued', 'uploading', 'sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'queued',
    `delivery_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `send_type` ENUM('document_media', 'document_link', 'template') NOT NULL DEFAULT 'document_media',
    `template_name` VARCHAR(100) NULL,
    `attempt_count` INT NOT NULL DEFAULT 1,
    `error_message` TEXT NULL,
    `raw_response` TEXT NULL,
    `webhook_payload` TEXT NULL,
    `sent_at` DATETIME NULL,
    `delivered_at` DATETIME NULL,
    `read_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$db->exec($sqlTable);
echo " [OK] Table `whatsapp_delivery_logs` created or verified.\n";

// 2. Add WhatsApp settings defaults to settings table if not present
$defaultSettings = [
    'whatsapp_enabled'               => '1',
    'whatsapp_phone_number_id'       => '',
    'whatsapp_access_token'          => '',
    'whatsapp_graph_version'         => 'v20.0',
    'whatsapp_webhook_verify_token'  => 'rajpriya_wa_secure_webhook_token_2026',
    'whatsapp_template_name'         => 'invoice_delivery',
    'whatsapp_auto_send'             => '1'
];

$stmtCheck = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
$stmtIns = $db->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");

foreach ($defaultSettings as $key => $val) {
    $stmtCheck->execute([$key]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtIns->execute([$key, $val]);
        echo " [OK] Setting `$key` initialized.\n";
    }
}

echo "Migration completed successfully!\n";
