<?php
// ================================================================
// FILE: modules/salaries/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE SALARY
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
// GET SALARY ID
// ============================================================
$salary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($salary_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET SALARY DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employee_salaries WHERE id = ?");
$stmt->execute([$salary_id]);
$salary = $stmt->fetch();

if (!$salary) {
    header('Location: index.php');
    exit();
}

// ============================================================
// CHECK IF SALARY HAS DEPENDENT DATA
// ============================================================
try {
    // Check if this salary is linked to expenses
    $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE salary_reference = ?");
    $stmt->execute([$salary_id]);
    $expense_count = $stmt->fetchColumn();
    
    if ($expense_count > 0) {
        $_SESSION['error_message'] = 'Cannot delete this salary because it is linked to expense records.';
        header('Location: index.php');
        exit();
    }
} catch (Exception $e) {
    // If table doesn't exist, proceed with deletion
}

// ============================================================
// DELETE SALARY
// ============================================================
try {
    logActivity($user_id, 'Delete Salary', 'Salaries', $salary_id, '', 'Deleted salary: ' . $salary['salary_number']);
    
    $stmt = $db->prepare("DELETE FROM employee_salaries WHERE id = ?");
    $stmt->execute([$salary_id]);
    
    $_SESSION['success_message'] = 'Salary "' . $salary['salary_number'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting salary: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>