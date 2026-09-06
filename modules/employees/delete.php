<?php
// ================================================================
// FILE: modules/employees/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE EMPLOYEE
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// ============================================================
// CHECK PERMISSION
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET EMPLOYEE ID
// ============================================================
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header('Location: index.php');
    exit();
}

// ============================================================
// CHECK IF EMPLOYEE HAS DEPENDENT DATA
// ============================================================
try {
    // Check morning reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $morning_count = $stmt->fetchColumn();
    
    // Check evening stocks
    $stmt = $db->prepare("SELECT COUNT(*) FROM evening_stocks WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $evening_count = $stmt->fetchColumn();
    
    // Check commissions
    $stmt = $db->prepare("SELECT COUNT(*) FROM commissions WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $commission_count = $stmt->fetchColumn();
    
    // Check daily reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_reports WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $daily_count = $stmt->fetchColumn();
    
    if ($morning_count > 0 || $evening_count > 0 || $commission_count > 0 || $daily_count > 0) {
        $_SESSION['error_message'] = 'Cannot delete employee "' . $employee['full_name'] . '" because they have associated data.';
        header('Location: index.php');
        exit();
    }
} catch (Exception $e) {
    // If tables don't exist, proceed with deletion
}

// ============================================================
// DELETE EMPLOYEE
// ============================================================
try {
    logActivity($user_id, 'Delete Employee', 'Employees', $employee_id, '', 'Deleted employee: ' . $employee['full_name']);
    
    $stmt = $db->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    
    $_SESSION['success_message'] = 'Employee "' . $employee['full_name'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting employee: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>