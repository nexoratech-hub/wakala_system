<?php
// ================================================================
// FILE: modules/branches/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE BRANCH
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
// GET BRANCH ID
// ============================================================
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: index.php');
    exit();
}

// ============================================================
// CHECK IF BRANCH HAS DEPENDENT DATA
// ============================================================
try {
    // Check morning reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $morning_count = $stmt->fetchColumn();
    
    // Check evening stocks
    $stmt = $db->prepare("SELECT COUNT(*) FROM evening_stocks WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $evening_count = $stmt->fetchColumn();
    
    // Check daily reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_reports WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $daily_count = $stmt->fetchColumn();
    
    // Check employees
    $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $employee_count = $stmt->fetchColumn();
    
    if ($morning_count > 0 || $evening_count > 0 || $daily_count > 0 || $employee_count > 0) {
        $_SESSION['error_message'] = 'Cannot delete branch "' . $branch['branch_name'] . '" because it has dependent data.';
        header('Location: index.php');
        exit();
    }
} catch (Exception $e) {
    // If tables don't exist, proceed with deletion
}

// ============================================================
// DELETE BRANCH
// ============================================================
try {
    // Delete branch providers first (foreign key)
    $stmt = $db->prepare("DELETE FROM branch_providers WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    
    // Log activity
    logActivity($user_id, 'Delete Branch', 'Branches', $branch_id, '', 'Deleted branch: ' . $branch['branch_name']);
    
    // Delete branch
    $stmt = $db->prepare("DELETE FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    
    $_SESSION['success_message'] = 'Branch "' . $branch['branch_name'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting branch: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>