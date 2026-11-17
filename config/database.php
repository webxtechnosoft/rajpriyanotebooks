<?php
// Config: Database Connection for XAMPP MySQL

// Centralized Timezone Configuration: Indian Standard Time (IST, UTC+05:30)
date_default_timezone_set('Asia/Kolkata');

// XAMPP defaults are used locally; hosting environments can override them.
//  define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
//  define('DB_USER', getenv('DB_USER') ?: 'root');
//  define('DB_PASS', getenv('DB_PASS') ?: '');
// define('DB_NAME', getenv('DB_NAME') ?: 'rajpriya_notebooks');


define('DB_HOST', 'localhost');
define('DB_USER', 'webxtech_db');
define('DB_PASS', 'Webxtech_db');
define('DB_NAME', 'webxtech_rajpriyanotebooks');

// Configurable Admin WhatsApp Number (Country code required, digits only)
if (!defined('ADMIN_WHATSAPP_NUMBER')) {
    define('ADMIN_WHATSAPP_NUMBER', '919842746472');
}

// Official Business Email Addresses
if (!defined('SALES_EMAIL')) {
    define('SALES_EMAIL', 'sales@rajpriyanotebooks.com');
}
if (!defined('INFO_EMAIL')) {
    define('INFO_EMAIL', 'info@rajpriyanotebooks.com');
}
if (!defined('ADMIN_EMAIL')) {
    define('ADMIN_EMAIL', 'admin@rajpriyanotebooks.com');
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Ensure MySQL session time zone is strictly Indian Standard Time (UTC+05:30)
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

// Start Output Buffering and PHP session if not already started
if (ob_get_level() === 0) {
    ob_start();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}