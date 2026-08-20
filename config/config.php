<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\config\config.php
// WAKALA FINANCIAL SYSTEM - SYSTEM CONFIGURATION
// ================================================================

// ============================================================
// COMPANY / BUSINESS INFORMATION
// ============================================================
define('SITE_NAME', 'Mbembati Kelvin L, T/A Wakala');
define('SITE_URL', 'http://localhost/wakala_system/');
define('SITE_EMAIL', 'info@wakala.com');
define('SITE_PHONE', '+255 700 000 000');

// ============================================================
// FINANCIAL SETTINGS
// ============================================================
define('CURRENCY', 'TSh');
define('CURRENCY_SYMBOL', 'TSh');
define('DATE_FORMAT', 'd-m-Y');
define('TIME_FORMAT', 'H:i:s');
define('DATETIME_FORMAT', 'd-m-Y H:i:s');

// ============================================================
// TIMEZONE
// ============================================================
define('TIMEZONE', 'Africa/Dar_es_Salaam');
date_default_timezone_set(TIMEZONE);

// ============================================================
// FILE PATHS (Absolute paths)
// ============================================================
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('RECEIPT_PATH', UPLOAD_PATH . 'receipts/');
define('PROFILE_PATH', UPLOAD_PATH . 'profiles/');
define('REPORT_PATH', UPLOAD_PATH . 'reports/');

// ============================================================
// ===== FIX: SESSION CONFIGURATION =====
// Session settings MUST be set BEFORE session_start()
// These are set in database.php or login.php before session_start()
// ============================================================

// ============================================================
// SECURITY HEADERS (Safe to set anywhere)
// ============================================================
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ============================================================
// ERROR REPORTING (Disable in production)
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/errors.log');

// ============================================================
// FILE PERMISSIONS
// ============================================================
umask(0);

// ============================================================
// API SETTINGS
// ============================================================
define('API_VERSION', 'v1');
define('API_KEY_HEADER', 'X-API-Key');
define('API_RATE_LIMIT', 60); // Requests per minute

// ============================================================
// NOTIFICATION SETTINGS
// ============================================================
define('AUTO_REFRESH_INTERVAL', 3); // Seconds
define('MAX_NOTIFICATIONS', 50);
define('NOTIFICATION_TTL', 86400); // 24 hours
?>