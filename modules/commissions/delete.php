<?php
// ================================================================
// FILE: modules/commissions/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE COMMISSION
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
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET COMMISSION ID
// ============================================================
$commission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($commission_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET COMMISSION DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM commissions WHERE id = ?");
$stmt->execute([$commission_id]);
$commission = $stmt->fetch();

if (!$commission) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only delete their own commissions
if ($role == 'employee' && $commission['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// DELETE COMMISSION
// ============================================================
try {
    logActivity($user_id, 'Delete Commission', 'Commissions', $commission_id, '', 'Deleted commission: ' . $commission['commission_number']);
    
    $stmt = $db->prepare("DELETE FROM commissions WHERE id = ?");
    $stmt->execute([$commission_id]);
    
    $_SESSION['success_message'] = 'Commission "' . $commission['commission_number'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting commission: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>