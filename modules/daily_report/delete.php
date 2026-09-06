<?php
// ================================================================
// FILE: modules/daily_report/delete.php
// DELETE DAILY REPORT
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get report to log
    $stmt = $db->prepare("SELECT report_number, report_date, branch FROM daily_reports WHERE id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($report) {
        // Delete
        $stmt = $db->prepare("DELETE FROM daily_reports WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            logActivity($user_id, 'Delete Daily Report', 'Daily Report', $id, 
                        json_encode($report), 'Deleted');
        }
    }
} catch (PDOException $e) {
    error_log("Error deleting daily report: " . $e->getMessage());
}

header('Location: index.php?deleted=1');
exit();
?>