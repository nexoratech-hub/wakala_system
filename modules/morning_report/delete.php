<?php
// ================================================================
// FILE: modules/morning_report/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE MORNING REPORT
// 
// ✅ Admin only (super_admin + admin)
// ✅ Blocks delete if report is locked
// ✅ Blocks delete if linked to daily report
// ✅ Cascade delete of morning_report_providers
// ✅ Full audit log
// ✅ Auto-redirect based on role
// ✅ Full English UI
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

// ============================================================
// ADMIN ONLY
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    $_SESSION['error_message'] = 'You do not have permission to delete morning reports.';
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET REPORT ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid morning report ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH REPORT
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mr.*,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code
    FROM morning_reports mr
    LEFT JOIN branches b ON mr.branch_id = b.id
    WHERE mr.id = ?
");
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
    $_SESSION['error_message'] = 'Cannot delete a locked morning report. Please unlock it first.';
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
                                  $linked_daily['report_number'] . '. Delete or unlink the daily report first.';
    header('Location: view.php?id=' . $id);
    exit();
}

// ============================================================
// COUNT PROVIDERS BEFORE DELETE (for audit log)
// ============================================================
$stmt = $db->prepare("SELECT COUNT(*) FROM morning_report_providers WHERE report_id = ?");
$stmt->execute([$id]);
$provider_count = intval($stmt->fetchColumn());

// ============================================================
// DELETE (Transaction)
// ============================================================
try {
    $db->beginTransaction();

    // 1) Delete morning_report_providers (explicit, safety)
    $stmt = $db->prepare("DELETE FROM morning_report_providers WHERE report_id = ?");
    $stmt->execute([$id]);

    // 2) Delete morning_reports
    $stmt = $db->prepare("DELETE FROM morning_reports WHERE id = ?");
    $stmt->execute([$id]);

    // 3) Log activity with full details
    $log_details = json_encode([
        'report_number' => $report['report_number'],
        'report_date' => $report['report_date'],
        'branch_id' => intval($report['branch_id']),
        'branch_name' => $report['branch_display_name'] ?? 'N/A',
        'cash_balance' => floatval($report['cash_balance']),
        'cumm_total' => floatval($report['cumm_total']),
        'provider_count' => $provider_count,
        'source_type' => $report['source_type'],
    ]);

    logActivity(
        $user_id,
        'Delete Morning Report',
        'Morning Report',
        $id,
        $report['report_number'],
        'Deleted morning report ' . $report['report_number'] . 
        ' for ' . ($report['branch_display_name'] ?? 'N/A') . 
        ' (Date: ' . $report['report_date'] . 
        ', Providers: ' . $provider_count . 
        ', Cash: ' . number_format($report['cash_balance']) . ')'
    );

    $db->commit();

    $_SESSION['success_message'] = 'Morning report ' . $report['report_number'] . ' deleted successfully.';

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $_SESSION['error_message'] = 'Delete failed: ' . $e->getMessage();
}

// ============================================================
// REDIRECT BASED ON ROLE
// ============================================================
$redirect_url = ($role === 'admin' || $role === 'super_admin') 
    ? 'index.php' 
    : 'index_employee.php';

if (!empty($report['branch_id'])) {
    $redirect_url .= '?branch_id=' . intval($report['branch_id']);
}

header('Location: ' . $redirect_url);
exit();