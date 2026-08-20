<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\api\dashboard_data.php
// WAKALA FINANCIAL SYSTEM - DASHBOARD DATA API
// ================================================================

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $is_admin = isAdmin();
    $today = date('Y-m-d');
    $employee_id = $_SESSION['user_id'];
    
    $data = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'cards' => [],
        'activities' => [],
        'alerts' => []
    ];
    
    // Get morning total
    $stmt = $db->prepare("SELECT cumm_total FROM morning_reports WHERE report_date = ?" . ($is_admin ? '' : ' AND employee_id = ?'));
    if ($is_admin) {
        $stmt->execute([$today]);
    } else {
        $stmt->execute([$today, $employee_id]);
    }
    $morning = $stmt->fetch();
    $data['cards']['morning'] = $morning['cumm_total'] ?? 0;
    
    // Get evening total
    $stmt = $db->prepare("SELECT cumm_total FROM evening_stocks WHERE stock_date = ?" . ($is_admin ? '' : ' AND employee_id = ?'));
    if ($is_admin) {
        $stmt->execute([$today]);
    } else {
        $stmt->execute([$today, $employee_id]);
    }
    $evening = $stmt->fetch();
    $data['cards']['evening'] = $evening['cumm_total'] ?? 0;
    
    // Calculate difference
    $data['cards']['diff'] = $data['cards']['evening'] - $data['cards']['morning'];
    
    // Get commission
    $stmt = $db->prepare("SELECT SUM(total_commission) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?" . ($is_admin ? '' : ' AND employee_id = ?'));
    if ($is_admin) {
        $stmt->execute([date('m'), date('Y')]);
    } else {
        $stmt->execute([date('m'), date('Y'), $employee_id]);
    }
    $commission = $stmt->fetch();
    $data['cards']['commission'] = $commission['total'] ?? 0;
    
    // Get expenses
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ? AND is_business_expense = 1" . ($is_admin ? '' : ' AND employee_id = ?'));
    if ($is_admin) {
        $stmt->execute([date('m'), date('Y')]);
    } else {
        $stmt->execute([date('m'), date('Y'), $employee_id]);
    }
    $expenses = $stmt->fetch();
    $data['cards']['expenses'] = $expenses['total'] ?? 0;
    
    // Get salaries
    $stmt = $db->prepare("SELECT SUM(net_pay) as total FROM employee_salaries WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ?");
    $stmt->execute([date('m'), date('Y')]);
    $salaries = $stmt->fetch();
    $data['cards']['salaries'] = $salaries['total'] ?? 0;
    
    // Calculate profit
    $data['cards']['profit'] = $data['cards']['commission'] - $data['cards']['expenses'] - $data['cards']['salaries'];
    
    // Get cash out
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM store_cash_out WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ?" . ($is_admin ? '' : ' AND employee_id = ?'));
    if ($is_admin) {
        $stmt->execute([date('m'), date('Y')]);
    } else {
        $stmt->execute([date('m'), date('Y'), $employee_id]);
    }
    $cashout = $stmt->fetch();
    $data['cards']['cashout'] = $cashout['total'] ?? 0;
    
    // Get capital
    $opening = getSetting('opening_capital') ?? 0;
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management WHERE transaction_type IN ('additional', 'profit_allocation')");
    $stmt->execute();
    $additions = $stmt->fetch();
    $data['cards']['capital'] = floatval($opening) + floatval($additions['total'] ?? 0) - floatval($cashout['total'] ?? 0);
    
    // Get activities
    $limit = 10;
    $stmt = $db->prepare("SELECT al.*, e.full_name as employee 
                          FROM activity_logs al 
                          JOIN employees e ON al.employee_id = e.id 
                          " . ($is_admin ? '' : 'WHERE al.employee_id = ?') . "
                          ORDER BY al.created_at DESC LIMIT ?");
    if ($is_admin) {
        $stmt->execute([$limit]);
    } else {
        $stmt->execute([$employee_id, $limit]);
    }
    $data['activities'] = $stmt->fetchAll();
    
    // Get alerts
    $alerts = [];
    if (!morningReportExists($employee_id, $today)) {
        $alerts[] = ['type' => 'warning', 'message' => '⚠️ Morning Report has not been submitted today'];
    }
    if (!eveningStockExists($employee_id, $today)) {
        $alerts[] = ['type' => 'warning', 'message' => '⚠️ Evening Stock has not been submitted today'];
    }
    $data['alerts'] = $alerts;
    
    echo json_encode($data);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>