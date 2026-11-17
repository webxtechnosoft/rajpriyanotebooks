<?php
/**
 * Safe Database Migration for Rajpriya Notebooks - Order Quotation System
 */
require_once __DIR__ . '/config/database.php';

$db = getDB();

echo "Starting Quotation System Database Migration...\n";

// 1. Create quotations table if not exists
$sql_quotations = "
CREATE TABLE IF NOT EXISTS `quotations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_number` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `shipping_address` text DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_gst` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cgst` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_sgst` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `admin_notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `status` enum('Draft','Sent','Accepted','Declined') NOT NULL DEFAULT 'Draft',
  `version` int(11) NOT NULL DEFAULT 1,
  `sent_at` datetime DEFAULT NULL,
  `customer_response_at` datetime DEFAULT NULL,
  `customer_decline_reason` text DEFAULT NULL,
  `created_by_admin` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotation_number` (`quotation_number`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

$db->exec($sql_quotations);
echo "[OK] 'quotations' table verified/created successfully.\n";

// 2. Create quotation_items table if not exists
$sql_quotation_items = "
CREATE TABLE IF NOT EXISTS `quotation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `product_name` varchar(150) NOT NULL,
  `variety` varchar(100) DEFAULT NULL,
  `ruling_type` varchar(150) DEFAULT NULL,
  `page_count` int(11) DEFAULT NULL,
  `order_type` varchar(50) DEFAULT 'Bundle',
  `pieces_per_bundle` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_pieces` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cgst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation_id` (`quotation_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

$db->exec($sql_quotation_items);
echo "[OK] 'quotation_items' table verified/created successfully.\n";

echo "Migration finished successfully!\n";
