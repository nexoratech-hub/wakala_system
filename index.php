<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\index.php
// WAKALA FINANCIAL SYSTEM - ENTRY POINT
// FIXED: Redirect loop issue
// ================================================================

// ============================================================
// ===== Start session properly =====
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// ===== Check login with proper redirect =====
// ============================================================

// Check if user is logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'employee';
    
    // Use absolute path to avoid redirect loops
    if ($role === 'super_admin' || $role === 'admin') {
        header('Location: modules/dashboard/admin.php');
        exit();
    } else {
        header('Location: modules/dashboard/employee.php');
        exit();
    }
}

// Not logged in, redirect to login
header('Location: login.php');
exit();
?>