<?php
// ================================================================
// FILE: modules/evening_stock/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE EVENING STOCK
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
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET STOCK DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM evening_stocks WHERE id = ?");
$stmt->execute([$stock_id]);
$stock = $stmt->fetch();

if (!$stock) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only delete their own stocks
if ($role == 'employee' && $stock['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// CHECK IF STOCK HAS DEPENDENT DATA
// ============================================================
try {
    // Check if this stock is linked to daily reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_reports WHERE evening_stock_id = ?");
    $stmt->execute([$stock_id]);
    $daily_count = $stmt->fetchColumn();
    
    if ($daily_count > 0) {
        $_SESSION['error_message'] = 'Cannot delete this evening stock because it is linked to daily reports.';
        header('Location: index.php');
        exit();
    }
} catch (Exception $e) {
    // If table doesn't exist, proceed with deletion
}

// ============================================================
// DELETE STOCK
// ============================================================
try {
    logActivity($user_id, 'Delete Evening Stock', 'Evening Stock', $stock_id, '', 'Deleted evening stock: ' . $stock['stock_number']);
    
    $stmt = $db->prepare("DELETE FROM evening_stocks WHERE id = ?");
    $stmt->execute([$stock_id]);
    
    $_SESSION['success_message'] = 'Evening stock "' . $stock['stock_number'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting evening stock: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>