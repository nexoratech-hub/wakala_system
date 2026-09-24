<?php
// ================================================================
// FILE: modules/morning_report/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE MORNING REPORT
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
$role    = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid morning report ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH REPORT
// ============================================================
$stmt = $db->prepare("SELECT * FROM morning_reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error_message'] = 'Morning report not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// BLOCK DELETE IF LOCKED
// ============================================================
if (intval($report['is_locked']) === 1) {
    $_SESSION['error_message'] = 'Cannot delete a locked morning report.';
    header('Location: view.php?id=' . $id);
    exit();
}

// ============================================================
// CHECK IF DAILY REPORT REFERENCES THIS MORNING REPORT
// ============================================================
$stmt = $db->prepare("
    SELECT id, report_number 
    FROM daily_reports 
    WHERE morning_report_id = ? 
    LIMIT 1
");
$stmt->execute([$id]);
$linked_daily = $stmt->fetch(PDO::FETCH_ASSOC);

if ($linked_daily) {
    $_SESSION['error_message'] = 'Cannot delete — this morning report is linked to daily report ' . 
                                  $linked_daily['report_number'] . '.';
    header('Location: view.php?id=' . $id);
    exit();
}

// ============================================================
// DELETE
// ============================================================
try {
    $db->beginTransaction();

    // Delete providers (CASCADE in DB but explicit for safety)
    $stmt = $db->prepare("DELETE FROM morning_report_providers WHERE report_id = ?");
    $stmt->execute([$id]);

    // Delete report
    $stmt = $db->prepare("DELETE FROM morning_reports WHERE id = ?");
    $stmt->execute([$id]);

    // Log
    logActivity(
        $user_id,
        'Delete Morning Report',
        'Morning Report',
        $id,
        $report['report_number'],
        'Deleted morning report ' . $report['report_number']
    );

    $db->commit();

    $_SESSION['success_message'] = 'Morning report ' . $report['report_number'] . ' deleted successfully.';

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $_SESSION['error_message'] = 'Delete failed: ' . $e->getMessage();
}

header('Location: index.php?branch_id=' . intval($report['branch_id']));
exit();