<?php
// ================================================================
// FILE: modules/morning_report/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE MORNING REPORT
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
// GET REPORT ID
// ============================================================
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM morning_reports WHERE id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only delete their own reports
if ($role == 'employee' && $report['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// DELETE REPORT
// ============================================================
try {
    // Log activity before deletion
    try {
        $stmt = $db->prepare("INSERT INTO activity_logs (employee_id, action, module, record_id, old_value, branch_id) 
                              VALUES (?, 'Delete Morning Report', 'Morning Report', ?, ?, ?)");
        $stmt->execute([$user_id, $report_id, 'Morning report deleted: ' . $report['report_number'], $report['branch_id']]);
    } catch (Exception $e) {
        // Activity log table might not exist, ignore
    }
    
    // Delete the report
    $stmt = $db->prepare("DELETE FROM morning_reports WHERE id = ?");
    $stmt->execute([$report_id]);
    
    $_SESSION['success_message'] = 'Morning report deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting report: ' . $e->getMessage();
}

// Redirect back to index
$branch_param = $report['branch_id'] > 0 ? '?branch=' . $report['branch_id'] : '';
header('Location: index.php' . $branch_param);
exit();
?>