-- RAJPRIYA NOTEBOOKS: CONSOLIDATED INSTALL SQL
-- Built from the live server schema export dated 2026-10-21.
-- Includes current table definitions and catalog/settings seed data only.
-- Customer accounts, carts, orders, order items, parcel records, messages,
-- reviews, and stock history are intentionally NOT included in this install file.
-- Keep rajpriya_notebooks_live_backup.sql as the full live-data backup.
-- IMPORTANT: the existing application currently stores/checks passwords as plain text.
-- Change the default admin password immediately and fix authentication before public deployment.
CREATE DATABASE IF NOT EXISTS `rajpriya_notebooks` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `rajpriya_notebooks`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

SET time_zone = "+00:00";

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `admins` (`id`, `name`, `username`, `password`, `email`, `created_at`, `updated_at`) VALUES
(1, 'Raj Priya Admin', 'admin', 'admin@123', 'admin@rajpriyanotebooks.com', '2026-10-15 13:31:39', '2026-10-15 13:31:39');

CREATE TABLE `ai_designs` (
  `id` int(11) NOT NULL,
  `design_name` varchar(150) NOT NULL,
  `notebook_type` varchar(50) NOT NULL,
  `theme` varchar(100) NOT NULL,
  `style` varchar(100) NOT NULL,
  `colour` varchar(50) NOT NULL,
  `prompt` text NOT NULL,
  `generated_image` varchar(255) NOT NULL,
  `logo_id` int(11) DEFAULT 3,
  `logo_position` varchar(50) DEFAULT 'top-center',
  `status` enum('Draft','Generated','Review','Approved','Rejected','Published') DEFAULT 'Review',
  `created_product_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `session_id` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cart_items` (
  `id` int(11) NOT NULL,
  `cart_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `pieces_per_bundle` int(11) NOT NULL DEFAULT 1,
  `total_pieces` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ruling_type` varchar(150) DEFAULT 'Unruled',
  `unit_price` decimal(10,2) DEFAULT NULL,
  `variety` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT 'assets/images/categories/cat_single_line.webp',
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `image`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Long Exercise Book - Laminated', 'long-exercise-book-laminated', 'Laminated Long Exercise Books in normal, standard, premium, and executive sizes.', 'assets/images/categories/cat_long.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(2, 'Long Exercise Book - Hard Bind', 'long-exercise-book-hard-bind', 'Durable Hardbound Long Exercise Books & Handwriting Practice notebooks.', 'assets/images/categories/cat_record.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(3, 'Practical Record Book', 'practical-record-book', 'Science, Chemistry & Laboratory Practical Record Books.', 'assets/images/categories/cat_practical.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(4, 'Drawing', 'drawing', 'Unruled White Cartridge Art Sketchbooks for Creative Drawing.', 'assets/images/categories/cat_drawing.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(5, 'Student Exercise Book - Laminated', 'student-exercise-book-laminated', 'School & College Exercise Books in Student Sizes A & B.', 'assets/images/categories/cat_short.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(6, 'Stationeries', 'stationeries', 'School Diaries, Pocket Notebooks, Tables, Cash Memos & Meeting Pads.', 'assets/images/categories/cat_single_line.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(7, 'Registers', 'registers', 'Attendance Registers, Suya Uthavi Kulu & Official Hardbind Registers.', 'assets/images/categories/cat_record.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(8, 'Ledgers / Accounts', 'ledgers-accounts', 'Accounts Books, Ledgers (LS, FC, 3-Fold), Pocket Notes & Files.', 'assets/images/categories/cat_four_line.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(9, 'Papers', 'papers', 'Smart Writing Paper, Copiers & Executive Bond Papers, Colour Boards.', 'assets/images/categories/cat_two_line.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39');

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `logos` (
  `id` int(11) NOT NULL,
  `logo_name` varchar(100) NOT NULL,
  `logo_type` enum('primary','secondary','product') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `logos` (`id`, `logo_name`, `logo_type`, `file_path`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Primary Brand Logo (Yellow Arch)', 'primary', 'uploads/logos/logo_primary.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(2, 'Secondary Brand Logo (SVK Wheel)', 'secondary', 'uploads/logos/logo_secondary.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(3, 'Product Notebook Logo (GPRS Series)', 'product', 'uploads/logos/logo_product.webp', 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39');

CREATE TABLE `offers` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT 0.00,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `offers` (`id`, `code`, `title`, `discount_type`, `discount_value`, `min_amount`, `max_discount`, `active`, `expiry_date`, `created_at`) VALUES
(1, 'RAJPRIYA10', 'Welcome 10% Discount', 'percentage', 10.00, 200.00, NULL, 1, NULL, '2026-10-15 13:31:39'),
(2, 'COMBO20', 'Combo Special Discount', 'percentage', 20.00, 500.00, NULL, 1, NULL, '2026-10-15 13:31:39');

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `customer_type` varchar(50) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `shipping_address` text NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `city` varchar(50) NOT NULL,
  `district` varchar(100) DEFAULT NULL,
  `state` varchar(50) NOT NULL,
  `pincode` varchar(10) NOT NULL,
  `landmark` varchar(100) DEFAULT NULL,
  `gst_status` varchar(50) DEFAULT 'Not Registered',
  `gst_number` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash On Delivery',
  `payment_status` enum('Pending','Paid','Failed') DEFAULT 'Pending',
  `order_status` enum('Pending','Confirmed','Processing','Packed','Shipped','Delivered','Cancelled') DEFAULT 'Pending',
  `expected_parcels` int(11) DEFAULT NULL,
  `actual_parcels` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `delivery_fee` decimal(10,2) DEFAULT 40.00,
  `grand_total` decimal(10,2) NOT NULL,
  `total_gst` decimal(10,2) DEFAULT 0.00,
  `total_cgst` decimal(10,2) DEFAULT 0.00,
  `total_sgst` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `transport_remarks` text DEFAULT NULL,
  `transport_name` varchar(150) DEFAULT NULL,
  `transport_branch` varchar(150) DEFAULT NULL,
  `transport_lr_number` varchar(100) DEFAULT NULL,
  `transport_vehicle_number` varchar(50) DEFAULT NULL,
  `transport_date` date DEFAULT NULL,
  `dispatch_date` date DEFAULT NULL,
  `transport_contact` varchar(50) DEFAULT NULL,
  `transport_from` varchar(100) DEFAULT NULL,
  `transport_to` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `product_name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `pieces_per_bundle` int(11) NOT NULL DEFAULT 1,
  `total_pieces` int(11) NOT NULL DEFAULT 1,
  `order_type` varchar(50) DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `taxable_amount` decimal(10,2) DEFAULT NULL,
  `gst_amount` decimal(10,2) DEFAULT NULL,
  `cgst_amount` decimal(10,2) DEFAULT NULL,
  `sgst_amount` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ruling_type` varchar(150) DEFAULT 'Unruled',
  `page_count` int(11) DEFAULT NULL,
  `variety` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_parcels` (
  `id` int(11) NOT NULL,
  `parcel_number` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `parcel_weight` decimal(8,2) DEFAULT 0.00,
  `box_dimensions` varchar(50) DEFAULT NULL,
  `transport_name` varchar(150) DEFAULT NULL,
  `lr_number` varchar(100) DEFAULT NULL,
  `transport_from` varchar(100) DEFAULT NULL,
  `transport_to` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `dispatch_date` date DEFAULT NULL,
  `transport_contact` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `qr_data` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_parcel_items` (
  `id` int(11) NOT NULL,
  `parcel_id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `category_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 50,
  `bundle_stock` int(11) NOT NULL DEFAULT 0,
  `pages` int(11) NOT NULL DEFAULT 192,
  `size` varchar(50) NOT NULL DEFAULT 'A4 Size',
  `paper_type` varchar(50) DEFAULT 'High-grade Ivory Paper',
  `gsm` int(11) DEFAULT 70,
  `binding` varchar(50) DEFAULT 'Softbound / Wire-O',
  `cover_type` varchar(50) DEFAULT 'Laminated Hard Cover',
  `image` varchar(255) NOT NULL,
  `featured` tinyint(1) DEFAULT 0,
  `bestseller` tinyint(1) DEFAULT 0,
  `new_arrival` tinyint(1) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `brand_name` varchar(100) DEFAULT NULL,
  `std_qty` int(11) DEFAULT NULL,
  `mrp` decimal(10,2) DEFAULT NULL,
  `selling_price_unruled` decimal(10,2) DEFAULT NULL,
  `discounted_price_unruled` decimal(10,2) DEFAULT NULL,
  `selling_price_ruled` decimal(10,2) DEFAULT NULL,
  `discounted_price_ruled` decimal(10,2) DEFAULT NULL,
  `ruling_option` varchar(50) DEFAULT 'Both',
  `varieties_managed` tinyint(1) NOT NULL DEFAULT 0,
  `manual_verification_flag` tinyint(1) DEFAULT 0,
  `manual_verification_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `products` (`id`, `name`, `slug`, `sku`, `category_id`, `description`, `price`, `sale_price`, `stock`, `bundle_stock`, `pages`, `size`, `paper_type`, `gsm`, `binding`, `cover_type`, `image`, `featured`, `bestseller`, `new_arrival`, `active`, `created_at`, `updated_at`, `gst_rate`, `brand_name`, `std_qty`, `mrp`, `selling_price_unruled`, `discounted_price_unruled`, `selling_price_ruled`, `discounted_price_ruled`, `ruling_option`, `manual_verification_flag`, `manual_verification_notes`) VALUES
(198, 'POWER', 'power-198', 'RP-OCT26-POW-024', 5, 'POWER Student Special 24 Pages features 24 pages of crisp paper in a portable Student Size (Special) format for daily school tasks. Smooth paper surface ensures effortless pen movement for fast classroom note-taking and homework.', 5.93, 5.64, 4000, 0, 24, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-16 05:26:04', 18.00, 'Deluxe', 1000, NULL, 5.93, 5.64, 5.93, 5.64, 'Both', 0, NULL),
(199, 'POWER', 'power-48-pages', 'RP-OCT26-POW-048', 5, 'POWER Student Special 48 Pages offers a compact Student Size (Special) format with 48 pages of 75 GSM paper for school assignments. Lightweight soft cover fits effortlessly into school backpacks without adding extra bulk.', 7.80, 7.41, 3200, 0, 48, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39', 18.00, 'Deluxe', 600, NULL, 7.80, 7.41, 7.80, 7.41, 'Both', 0, NULL),
(200, 'POWER', 'power-60-pages', 'RP-OCT26-POW-060', 5, 'Designed for classroom learning, POWER Student Special 60 Pages contains 60 pages bound in a light laminated cover. An economical and reliable notebook choice for primary and secondary school students.', 8.73, 8.29, 2700, 0, 60, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39', 18.00, 'Deluxe', 400, NULL, 8.73, 8.29, 8.73, 8.29, 'Both', 0, NULL),
(201, 'POWER', 'power-112-pages', 'RP-OCT26-POW-112', 5, 'POWER Student Special 112 Pages features 112 pages of crisp paper in a portable Student Size (Special) format for daily school tasks. Smooth paper surface ensures effortless pen movement for fast classroom note-taking and homework.', 12.76, 12.13, 2000, 0, 112, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-17 12:20:36', 18.00, 'Deluxe', 400, NULL, 12.76, 12.13, 12.76, 12.13, 'Both', 0, NULL),
(202, 'POWER', 'power-120-pages', 'RP-OCT26-POW-120', 5, 'POWER Student Special 120 Pages offers a compact Student Size (Special) format with 120 pages of 75 GSM paper for school assignments. Lightweight soft cover fits effortlessly into school backpacks without adding extra bulk.', 13.39, 12.72, 1600, 0, 120, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39', 18.00, 'Deluxe', 400, NULL, 13.39, 12.72, 13.39, 12.72, 'Both', 0, NULL),
(203, 'POWER', 'power-144-pages', 'RP-OCT26-POW-144', 5, 'Designed for classroom learning, POWER Student Special 144 Pages contains 144 pages bound in a light laminated cover. An economical and reliable notebook choice for primary and secondary school students.', 15.25, 14.49, 1600, 0, 144, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-15 13:31:39', 18.00, 'Deluxe', 400, NULL, 15.25, 14.49, 15.25, 14.49, 'Both', 0, NULL),
(204, 'POWER', 'power-204', 'RP-OCT26-POW-160', 5, 'The POWER Student Special 160 Pages provides 160 pages of smooth 75 GSM paper tailored for school subject notes. Durable binding keeps pages securely bound through daily school transport and regular study routines.', 16.49, 15.67, 1440, 0, 160, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-17 11:35:40', 18.00, 'Deluxe', 360, NULL, 16.49, 15.67, 16.49, 15.67, 'Both', 0, NULL),
(205, 'POWER', 'power', 'RP-OCT26-POW-192', 5, 'POWER Student Special 192 Pages features 192 pages of crisp paper in a portable Student Size (Special) format for daily school tasks. Smooth paper surface ensures effortless pen movement for fast classroom note-taking and homework.', 19.63, 18.65, 42760, 130, 192, 'Student Size (Special)', 'A Grade Paper', 75, 'Soft Binding', 'Laminated', 'uploads/products/prod_power.jpg', 0, 0, 1, 1, '2026-10-15 13:31:39', '2026-10-21 07:57:29', 18.00, 'Deluxe', 320, NULL, 19.63, 18.65, 19.63, 18.65, 'Both', 0, NULL);

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_code` varchar(100) NOT NULL,
  `page_count` int(11) DEFAULT NULL,
  `variety` varchar(100) NOT NULL,
  `variety_display` varchar(100) DEFAULT NULL,
  `bundle_qty` int(11) DEFAULT NULL,
  `bundle_stock` int(11) NOT NULL DEFAULT 0,
  `loose_stock` int(11) NOT NULL DEFAULT 0,
  `stock` int(11) NOT NULL DEFAULT 50,
  `mrp` decimal(10,2) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `basic_price` decimal(10,2) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `discounted_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `product_variants` (`id`, `product_id`, `product_code`, `page_count`, `variety`, `variety_display`, `bundle_qty`, `bundle_stock`, `loose_stock`, `stock`, `selling_price`, `discounted_price`, `created_at`, `updated_at`) VALUES
(1, 198, 'RP-OCT26-POW-024-SR', 24, 'SR', 'Ruled', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(2, 198, 'RP-OCT26-POW-024-UR', 24, 'UR', 'Unruled', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(3, 198, 'RP-OCT26-POW-024-DR', 24, 'DR', 'Double Line', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(4, 198, 'RP-OCT26-POW-024-FR', 24, 'FR', 'Four Line', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(5, 198, 'RP-OCT26-POW-024-MP', 24, 'MP', 'Maths Plain', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(6, 198, 'RP-OCT26-POW-024-MR', 24, 'MR', 'Maths Ruled', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(7, 198, 'RP-OCT26-POW-024-SQ', 24, 'SQ', 'Square', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(8, 198, 'RP-OCT26-POW-024-OS', 24, 'OS', 'OS', 1000, 0, 500, 500, NULL, NULL, '2026-10-15 13:31:39', '2026-10-16 05:25:15'),
(9, 199, 'RP-OCT26-POW-048-SR', 48, 'SR', 'Ruled', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(10, 199, 'RP-OCT26-POW-048-UR', 48, 'UR', 'Unruled', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(11, 199, 'RP-OCT26-POW-048-DR', 48, 'DR', 'Double Line', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(12, 199, 'RP-OCT26-POW-048-FR', 48, 'FR', 'Four Line', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(13, 199, 'RP-OCT26-POW-048-MP', 48, 'MP', 'Maths Plain', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(14, 199, 'RP-OCT26-POW-048-MR', 48, 'MR', 'Maths Ruled', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(15, 199, 'RP-OCT26-POW-048-SQ', 48, 'SQ', 'Square', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(16, 199, 'RP-OCT26-POW-048-OS', 48, 'OS', 'OS', 600, 0, 400, 400, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(17, 200, 'RP-OCT26-POW-060-SR', 60, 'SR', 'Ruled', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(18, 200, 'RP-OCT26-POW-060-UR', 60, 'UR', 'Unruled', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(19, 200, 'RP-OCT26-POW-060-DR', 60, 'DR', 'Diary', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(20, 200, 'RP-OCT26-POW-060-FR', 60, 'FR', 'File', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(21, 200, 'RP-OCT26-POW-060-MP', 60, 'MP', 'Map', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(22, 200, 'RP-OCT26-POW-060-MR', 60, 'MR', 'Memo', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(23, 200, 'RP-OCT26-POW-060-OSR', 60, 'OSR', 'OSR', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(24, 200, 'RP-OCT26-POW-060-SQ', 60, 'SQ', 'Square', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(25, 200, 'RP-OCT26-POW-060-BR', 60, 'BR', 'Brown', 400, 0, 300, 300, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(26, 201, 'RP-OCT26-POW-112-SR', 112, 'SR', 'Ruled', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(27, 201, 'RP-OCT26-POW-112-UR', 112, 'UR', 'Unruled', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(28, 201, 'RP-OCT26-POW-112-DR', 112, 'DR', 'Double Line', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 12:20:36'),
(29, 201, 'RP-OCT26-POW-112-FR', 112, 'FR', 'Four Line', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(30, 201, 'RP-OCT26-POW-112-MP', 112, 'MP', 'Maths Plain', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(31, 201, 'RP-OCT26-POW-112-MR', 112, 'MR', 'Maths Ruled', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(32, 201, 'RP-OCT26-POW-112-SQ', 112, 'SQ', 'Square', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(33, 201, 'RP-OCT26-POW-112-OS', 112, 'OS', 'OS', 400, 0, 250, 250, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(34, 202, 'RP-OCT26-POW-120-SR', 120, 'SR', 'Ruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(35, 202, 'RP-OCT26-POW-120-UR', 120, 'UR', 'Unruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(36, 202, 'RP-OCT26-POW-120-DR', 120, 'DR', 'Double Line', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(37, 202, 'RP-OCT26-POW-120-FR', 120, 'FR', 'Four Line', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(38, 202, 'RP-OCT26-POW-120-MP', 120, 'MP', 'Maths Plain', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(39, 202, 'RP-OCT26-POW-120-MR', 120, 'MR', 'Maths Ruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(40, 202, 'RP-OCT26-POW-120-SQ', 120, 'SQ', 'Square', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(41, 202, 'RP-OCT26-POW-120-OS', 120, 'OS', 'OS', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(42, 203, 'RP-OCT26-POW-144-SR', 144, 'SR', 'Ruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(43, 203, 'RP-OCT26-POW-144-UR', 144, 'UR', 'Unruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(44, 203, 'RP-OCT26-POW-144-DR', 144, 'DR', 'Double Line', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(45, 203, 'RP-OCT26-POW-144-FR', 144, 'FR', 'Four Line', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(46, 203, 'RP-OCT26-POW-144-MP', 144, 'MP', 'Maths Plain', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(47, 203, 'RP-OCT26-POW-144-MR', 144, 'MR', 'Maths Ruled', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(48, 203, 'RP-OCT26-POW-144-SQ', 144, 'SQ', 'Square', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(49, 203, 'RP-OCT26-POW-144-OS', 144, 'OS', 'OS', 400, 0, 200, 200, NULL, NULL, '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(50, 204, 'RP-OCT26-POW-160-SR', 160, 'SR', 'Ruled', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(51, 204, 'RP-OCT26-POW-160-UR', 160, 'UR', 'Unruled', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(52, 204, 'RP-OCT26-POW-160-DR', 160, 'DR', 'Double Line', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(53, 204, 'RP-OCT26-POW-160-FR', 160, 'FR', 'Four Line', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(54, 204, 'RP-OCT26-POW-160-MP', 160, 'MP', 'Maths Plain', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(55, 204, 'RP-OCT26-POW-160-MR', 160, 'MR', 'Maths Ruled', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(56, 204, 'RP-OCT26-POW-160-SQ', 160, 'SQ', 'Square', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(57, 204, 'RP-OCT26-POW-160-OS', 160, 'OS', 'OS', 360, 0, 180, 180, NULL, NULL, '2026-10-15 13:31:39', '2026-10-17 11:35:40'),
(58, 205, 'RP-OCT26-POW-192-SR', 192, 'SR', 'Ruled', 320, 0, 160, 160, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(59, 205, 'RP-OCT26-POW-192-UR', 192, 'UR', 'Unruled', 320, 0, 160, 160, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(60, 205, 'RP-OCT26-POW-192-DR', 192, 'DR', 'Double Line', 320, 0, 40, 40, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:57:29'),
(61, 205, 'RP-OCT26-POW-192-FR', 192, 'FR', 'Four Line', 320, 10, 160, 3360, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(62, 205, 'RP-OCT26-POW-192-MP', 192, 'MP', 'Maths Plain', 320, 0, 160, 160, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(63, 205, 'RP-OCT26-POW-192-MR', 192, 'MR', 'Maths Ruled', 320, 0, 160, 160, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(64, 205, 'RP-OCT26-POW-192-SQ', 192, 'SQ', 'Square', 320, 0, 160, 160, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19'),
(65, 205, 'RP-OCT26-POW-192-OS', 192, 'OS', 'OS', 320, 120, 160, 38560, NULL, NULL, '2026-10-15 13:31:39', '2026-10-21 07:34:19');

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `rating` int(11) NOT NULL DEFAULT 5,
  `review_text` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 'site_title', 'RAJPRIYA NOTEBOOKS — Write Every Idea. Keep Every Thought.', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(2, 'brand_name', 'RAJPRIYA', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(3, 'tagline', 'Quality Notebooks - Made for Better Learning', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(4, 'phone', '+91 98765 43210', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(5, 'email', 'info@rajpriyanotebooks.com', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(6, 'address', '123, Station Road, Salem - 636001, Tamil Nadu, India', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(7, 'free_shipping_threshold', '499.00', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(8, 'currency', '₹', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(9, 'hero_headline', 'Write Every Idea.\nKeep Every Thought.', '2026-10-15 13:31:39', '2026-10-15 13:31:39'),
(10, 'hero_subtext', 'Premium quality notebooks for students, dreamers and achievers. Because every great idea deserves a perfect notebook.', '2026-10-15 13:31:39', '2026-10-15 13:31:39');

CREATE TABLE `stock_history` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `previous_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `change_amount` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT 'Manual Stock Update',
  `admin_username` varchar(50) DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `previous_customer_codes` text DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `customer_type` enum('Wholesaler','Distributor','Retailer','Other') NOT NULL DEFAULT 'Retailer',
  `state` varchar(50) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `landmark` varchar(100) DEFAULT NULL,
  `gst_status` varchar(50) DEFAULT 'Not Registered',
  `gst_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `ai_designs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `logo_id` (`logo_id`);

ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `cart_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cart_id` (`cart_id`),
  ADD KEY `product_id` (`product_id`);

ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `logos`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `offers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `order_items_ibfk_2` (`product_id`);

ALTER TABLE `order_parcels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `parcel_number` (`parcel_number`),
  ADD KEY `order_id` (`order_id`);

ALTER TABLE `order_parcel_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parcel_id` (`parcel_id`),
  ADD KEY `order_item_id` (`order_item_id`);

ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `category_id` (`category_id`);

ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_prod_variety` (`product_id`,`variety`),
  ADD KEY `product_code_2` (`product_code`);

ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `stock_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `customer_code` (`customer_code`);

ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `ai_designs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `cart_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `logos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `order_parcels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `order_parcel_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;

ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `stock_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

ALTER TABLE `ai_designs`
  ADD CONSTRAINT `ai_designs_ibfk_1` FOREIGN KEY (`logo_id`) REFERENCES `logos` (`id`) ON DELETE SET NULL;

ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `cart_items`
  ADD CONSTRAINT `cart_items_ibfk_1` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `order_parcels`
  ADD CONSTRAINT `order_parcels_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `order_parcel_items`
  ADD CONSTRAINT `order_parcel_items_ibfk_1` FOREIGN KEY (`parcel_id`) REFERENCES `order_parcels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_parcel_items_ibfk_2` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

ALTER TABLE `stock_history`
  ADD CONSTRAINT `stock_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

-- Quotations table
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

-- Quotation Items table
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

SET FOREIGN_KEY_CHECKS=1;
