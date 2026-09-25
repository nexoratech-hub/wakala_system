<?php
// ================================================================
// FILE: modules/evening_stock/delete.php
// EVENING STOCK - DELETE (ADMIN)
// ✅ Deletes evening stock and its providers
// ✅ Reverses nothing (evening stock is just a snapshot)
// ✅ Prevents deletion if linked to daily_reports
// ✅ ALL INSTRUCTIONS IN ENGLISH
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Only admin/super_admin can delete
if ($role !== 'admin' && $role !== 'super_admin') {
    $_SESSION['error_message'] = 'You do not have permission to delete evening stock.';
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    $_SESSION['error_message'] = 'Invalid stock ID.';
    header('Location: index.php');
    exit();
}

try {
    // ========================================================
    // GET STOCK DATA (for logging & safety checks)
    // ========================================================
    $stmt = $db->prepare("
        SELECT es.id, es.stock_number, es.stock_date, es.branch_id, 
               es.daily_report_id, es.status, es.cumm_total, es.cash_balance,
               b.branch_name
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
    
    $branch_id = $stock['branch_id'] ?? 0;
    
    // ========================================================
    // ✅ SAFETY CHECK: Is this stock linked to a daily_report?
    // ========================================================
    $linked_reports = [];
    try {
        $stmt = $db->prepare("
            SELECT id, report_number, report_date 
            FROM daily_reports 
            WHERE evening_stock_id = ?
        ");
        $stmt->execute([$stock_id]);
        $linked_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Column might not exist — ignore
        $linked_reports = [];
    }
    
    // ========================================================
    // ✅ SAFETY: Prevent deletion if stock is APPROVED
    // (Admin must downgrade status to "waiting" first)
    // ========================================================
    if ($stock['status'] === 'approved') {
        $_SESSION['error_message'] = 'Cannot delete an APPROVED evening stock. Please change status to "Waiting" first before deleting.';
        header('Location: view.php?id=' . $stock_id);
        exit();
    }
    
    // ========================================================
    // START TRANSACTION
    // ========================================================
    $db->beginTransaction();
    
    // ========================================================
    // ✅ STEP 1: Unlink from daily_reports (if linked)
    // Set evening_stock_id to NULL to avoid FK constraint issues
    // ========================================================
    if (!empty($linked_reports)) {
        try {
            $stmt = $db->prepare("UPDATE daily_reports SET evening_stock_id = NULL WHERE evening_stock_id = ?");
            $stmt->execute([$stock_id]);
        } catch (PDOException $e) {
            // Column might not exist — ignore
        }
    }
    
    // ========================================================
    // ✅ STEP 2: Delete providers
    // (CASCADE would handle this, but we do it explicitly)
    // ========================================================
    $stmt = $db->prepare("DELETE FROM evening_stock_providers WHERE evening_stock_id = ?");
    $stmt->execute([$stock_id]);
    $providers_deleted = $stmt->rowCount();
    
    // ========================================================
    // ✅ STEP 3: Delete evening stock
    // ========================================================
    $stmt = $db->prepare("DELETE FROM evening_stocks WHERE id = ?");
    $stmt->execute([$stock_id]);
    
    // ========================================================
    // ✅ STEP 4: Log activity (detailed)
    // ========================================================
    logActivity(
        $user_id,
        'Delete Evening Stock',
        'Evening Stock',
        $stock_id,
        json_encode([
            'stock_number'    => $stock['stock_number'],
            'stock_date'      => $stock['stock_date'],
            'branch_id'       => $stock['branch_id'],
            'branch_name'     => $stock['branch_name'],
            'daily_report_id' => $stock['daily_report_id'],
            'status'          => $stock['status'],
            'cumm_total'      => $stock['cumm_total'],
            'cash_balance'    => $stock['cash_balance'],
            'providers_count' => $providers_deleted,
            'linked_reports'  => count($linked_reports)
        ]),
        'Deleted evening stock: ' . $stock['stock_number'] 
            . ' | Branch: ' . ($stock['branch_name'] ?? 'N/A') 
            . ' | Date: ' . $stock['stock_date']
            . ' | Status: ' . $stock['status']
            . ' | Providers: ' . $providers_deleted
            . (!empty($linked_reports) ? ' | Linked Reports: ' . count($linked_reports) : '')
    );
    
    // ========================================================
    // COMMIT
    // ========================================================
    $db->commit();
    
    $_SESSION['success_message'] = 'Evening stock ' . $stock['stock_number'] . ' deleted successfully!';
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error deleting evening stock: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error deleting evening stock: ' . $e->getMessage();
}

// Redirect back to index (with branch filter)
header('Location: index.php?branch=' . ($branch_id ?? 0) . '&deleted=1');
exit();
?>