<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\config\database.php
// WAKALA FINANCIAL SYSTEM - DATABASE CONNECTION
// FIXED: Session ini settings before session starts
// ================================================================

// ============================================================
// ===== FIX: Session settings - Check if session is NOT active =====
// ============================================================

// Only set session ini settings if session is NOT already active
if (session_status() === PHP_SESSION_NONE) {
    // Session not started yet, safe to set ini settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 for HTTPS
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
    ini_set('session.save_path', __DIR__ . '/../temp/sessions');
} else {
    // Session already active, don't try to change settings
    // These settings were already applied when session started
}

// ============================================================
// DATABASE CONFIGURATION
// ============================================================
$host = 'localhost';
$dbname = 'wakala_system';
$username = 'root';
$password = '';

// Set DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// Set PDO options for security and performance
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];

try {
    // Create PDO connection
    $db = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Return database connection for use in other files
return $db;
?>