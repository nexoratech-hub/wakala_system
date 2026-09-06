<?php
// ================================================================
// FILE: modules/store_cash_out/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE CASH OUT
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
// GET CASH OUT ID
// ============================================================
$cashout_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($cashout_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET CASH OUT DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM store_cash_out WHERE id = ?");
$stmt->execute([$cashout_id]);
$cashout = $stmt->fetch();

if (!$cashout) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only delete their own cash outs
if ($role == 'employee' && $cashout['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// DELETE CASH OUT
// ============================================================
try {
    logActivity($user_id, 'Delete Cash Out', 'Store Cash Out', $cashout_id, '', 'Deleted cash out: ' . $cashout['cashout_number']);
    
    $stmt = $db->prepare("DELETE FROM store_cash_out WHERE id = ?");
    $stmt->execute([$cashout_id]);
    
    $_SESSION['success_message'] = 'Cash out "' . $cashout['cashout_number'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting cash out: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>