<?php
// ================================================================
// FILE: logout.php
// WAKALA SYSTEM - LOGOUT
// ================================================================

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// LOG ACTIVITY BEFORE DESTROYING SESSION
// ============================================================
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Include functions
    require_once 'includes/functions.php';
    
    // Check if employee still exists before logging
    try {
        global $db;
        if (isset($db)) {
            $check_stmt = $db->prepare("SELECT id FROM employees WHERE id = ? AND is_active = 1");
            $check_stmt->execute([$_SESSION['user_id']]);
            $employee_exists = $check_stmt->fetch();
            
            if ($employee_exists) {
                // Only log if employee exists
                logActivity($_SESSION['user_id'], 'Logout', 'Authentication');
            }
        }
    } catch (Exception $e) {
        // If logging fails, just continue with logout
    }
}

// ============================================================
// DESTROY SESSION
// ============================================================
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// ============================================================
// REDIRECT TO LOGIN
// ============================================================
header('Location: login.php');
exit();
?>