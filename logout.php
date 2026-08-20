<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\logout.php
// WAKALA FINANCIAL SYSTEM - LOGOUT HANDLER
// DESTROYS SESSION AND REDIRECTS TO LOGIN
// ================================================================

session_start();

// Include database and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Log the logout activity
if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'Logout', 'Authentication');
}

// Destroy session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
header('Location: login.php');
exit();
?>