<?php
// includes/database_connect.php

require_once __DIR__ . '/config.php';

Config::load();

$host = Config::getRequired('DB_HOST');
$port = Config::getRequired('DB_PORT');
$dbname = Config::getRequired('DB_NAME');
$username = Config::getRequired('DB_USER');
$password = Config::getRequired('DB_PASS');

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    if (Config::get('APP_ENV') === 'development') {
        die("Database Connection Failed: " . $e->getMessage());
    } else {
        error_log("Database Connection Failed: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    }
}
?>