<?php
// Guard against accidental database resets or web-triggered reseeding
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access Forbidden: Database seeding cannot be executed via web browser requests.\n");
}

if (!isset($argv[1]) || $argv[1] !== '--force-reseed') {
    die("Safety Lock Active: To reseed the database from database.sql, execute: php init_db.php --force-reseed\n");
}

$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = file_get_contents(__DIR__ . '/database.sql');
    $pdo->exec($sql);
    echo "Database rajpriya_notebooks created and seeded successfully!\n";
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}

