<?php
// ================================================================
// FILE: modules/evening_stock/delete.php
// EVENING STOCK - DELETE (ADMIN)
// ✅ Deletes evening stock and its providers
// ✅ Reverses nothing (evening stock is just a snapshot)
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    $_SESSION['error_message'] = 'Invalid stock ID.';
    header('Location: index.php');
    exit();
}

try {
    // Get stock for logging
    $stmt = $db->prepare("
        SELECT es.stock_number, es.stock_date, es.branch_id, b.branch_name
        FROM evening_stocks es
        LEFT JOIN branches b ON es.branch_id = b.id
        WHERE es.id = ?
    ");
    $stmt->execute([$stock_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stock) {
        $_SESSION['error_message'] = 'Evening stock not found.';
        header('Location: index.php');
        exit();
    }
    
    $branch_id = $stock['branch_id'];
    
    // Start transaction
    $db->beginTransaction();
    
    // Delete providers (CASCADE should handle this, but explicit)
    $stmt = $db->prepare("DELETE FROM evening_stock_providers WHERE evening_stock_id = ?");
    $stmt->execute([$stock_id]);
    
    // Delete stock
    $stmt = $db->prepare("DELETE FROM evening_stocks WHERE id = ?");
    $stmt->execute([$stock_id]);
    
    // Log activity
    logActivity(
        $user_id,
        'Delete Evening Stock',
        'Evening Stock',
        $stock_id,
        json_encode($stock),
        'Deleted evening stock: ' . $stock['stock_number'] . ' for ' . ($stock['branch_name'] ?? 'N/A') . ' on ' . $stock['stock_date']
    );
    
    $db->commit();
    
    $_SESSION['success_message'] = 'Evening stock ' . $stock['stock_number'] . ' deleted successfully!';
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error deleting evening stock: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error deleting evening stock.';
}

header('Location: index.php?branch=' . ($branch_id ?? 0) . '&deleted=1');
exit();
?>