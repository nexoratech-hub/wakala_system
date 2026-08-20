<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\modules\dashboard\admin.php
// WAKALA FINANCIAL SYSTEM - COMPLETE ADMIN DASHBOARD
// FIXED: Header compact, fits well
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: employee.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$profile_image = '../../assets/images/logo.PNG';

// ============================================================
// GET DASHBOARD DATA
// ============================================================
$today = date('Y-m-d');
$month = date('Y-m-01');
$employee_id = $_SESSION['user_id'];

// --- Morning Total ---
$stmt = $db->prepare("SELECT cumm_total FROM morning_reports WHERE report_date = ?");
$stmt->execute([$today]);
$morning = $stmt->fetch();
$morning_total = $morning['cumm_total'] ?? 0;

// --- Evening Total ---
$stmt = $db->prepare("SELECT cumm_total FROM evening_stocks WHERE stock_date = ?");
$stmt->execute([$today]);
$evening = $stmt->fetch();
$evening_total = $evening['cumm_total'] ?? 0;

// --- Float Difference ---
$float_diff = $evening_total - $morning_total;

// --- Total Commission (This Month) ---
$stmt = $db->prepare("SELECT SUM(total_commission) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?");
$stmt->execute([date('m'), date('Y')]);
$commission = $stmt->fetch();
$total_commission = $commission['total'] ?? 0;

// --- Other Income (This Month) ---
$stmt = $db->prepare("SELECT SUM(other_income) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?");
$stmt->execute([date('m'), date('Y')]);
$other_income = $stmt->fetch();
$total_other_income = $other_income['total'] ?? 0;

// --- Total Business Income ---
$total_income = $total_commission + $total_other_income;

// --- Total Expenses (This Month) ---
$stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ? AND is_business_expense = 1");
$stmt->execute([date('m'), date('Y')]);
$expenses = $stmt->fetch();
$total_expenses = $expenses['total'] ?? 0;

// --- Total Salaries (This Month) ---
$stmt = $db->prepare("SELECT SUM(net_pay) as total FROM employee_salaries WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ?");
$stmt->execute([date('m'), date('Y')]);
$salaries = $stmt->fetch();
$total_salaries = $salaries['total'] ?? 0;

// --- Net Profit ---
$net_profit = $total_income - $total_expenses - $total_salaries;

// --- Store Cash Out (This Month) ---
$stmt = $db->prepare("SELECT SUM(amount) as total FROM store_cash_out WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ?");
$stmt->execute([date('m'), date('Y')]);
$cashout = $stmt->fetch();
$total_cashout = $cashout['total'] ?? 0;

// --- Current Capital ---
$opening_capital = getSetting('opening_capital') ?? 0;

$stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management WHERE transaction_type IN ('additional', 'profit_allocation')");
$stmt->execute();
$additions = $stmt->fetch();
$total_additions = $additions['total'] ?? 0;

$stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management WHERE transaction_type = 'cash_out'");
$stmt->execute();
$withdrawals = $stmt->fetch();
$total_withdrawals = $withdrawals['total'] ?? 0;

$current_capital = floatval($opening_capital) + floatval($total_additions) - floatval($total_withdrawals);

// --- Recent Activities ---
$stmt = $db->prepare("SELECT al.*, e.full_name as employee 
                      FROM activity_logs al 
                      JOIN employees e ON al.employee_id = e.id 
                      ORDER BY al.created_at DESC LIMIT 10");
$stmt->execute();
$activities = $stmt->fetchAll();

// --- Alerts ---
$alerts = [];

if (!morningReportExists($employee_id, $today)) {
    $alerts[] = ['type' => 'warning', 'message' => '⚠️ Morning Report has not been submitted today'];
}

if (!eveningStockExists($employee_id, $today)) {
    $alerts[] = ['type' => 'warning', 'message' => '⚠️ Evening Stock has not been submitted today'];
}

if ($total_expenses > 0 && $total_commission > 0 && $total_expenses > $total_commission * 0.5) {
    $alerts[] = ['type' => 'danger', 'message' => '🔴 Expenses are more than 50% of commissions'];
}

if ($net_profit > 0) {
    $alerts[] = ['type' => 'success', 'message' => '✅ Current monthly net profit: ' . formatCurrency($net_profit)];
} else {
    $alerts[] = ['type' => 'danger', 'message' => '🔴 Business is making a loss this month'];
}

// --- Monthly Commission Trend for Chart ---
$stmt = $db->prepare("SELECT DATE_FORMAT(commission_date, '%b') as month, SUM(total_commission) as total 
                      FROM commissions 
                      WHERE YEAR(commission_date) = ? 
                      GROUP BY MONTH(commission_date) 
                      ORDER BY MONTH(commission_date)");
$stmt->execute([date('Y')]);
$commission_trend = $stmt->fetchAll();

// --- Monthly Expense Trend for Chart ---
$stmt = $db->prepare("SELECT DATE_FORMAT(expense_date, '%b') as month, SUM(amount) as total 
                      FROM expenses 
                      WHERE YEAR(expense_date) = ? AND is_business_expense = 1
                      GROUP BY MONTH(expense_date) 
                      ORDER BY MONTH(expense_date)");
$stmt->execute([date('Y')]);
$expense_trend = $stmt->fetchAll();

// Prepare chart data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$commission_data = array_fill(0, 12, 0);
$expense_data = array_fill(0, 12, 0);

foreach ($commission_trend as $row) {
    $index = array_search($row['month'], $months);
    if ($index !== false) {
        $commission_data[$index] = floatval($row['total']);
    }
}

foreach ($expense_trend as $row) {
    $index = array_search($row['month'], $months);
    if ($index !== false) {
        $expense_data[$index] = floatval($row['total']);
    }
}

// ================================================================
// HTML STARTS HERE
// ================================================================
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Wakala Financial System</title>
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="shortcut icon" href="../../assets/images/logo.PNG" type="image/png">
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- CSS -->
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <style>
        /* ============================================================
           COMPLETE DASHBOARD STYLES
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --bg-body: #F3F4F6;
            --bg-card: #FFFFFF;
            --topbar-bg: #FFFFFF;
            --text-primary: #1F2937;
            --text-secondary: #6B7280;
            --text-light: #9CA3AF;
            --border-color: #E5E7EB;
            --bg-input: #F9FAFB;
            --bg-hover: #F3F4F6;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        /* ============================================================
           SIDEBAR - PURE RED
           ============================================================ */
        .admin-sidebar {
            width: 250px;
            background: #8B0000;
            height: 100vh;
            padding: 0;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            overflow-y: auto;
            color: #FFFFFF;
            z-index: 1000;
            transition: transform 0.3s ease;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.2) transparent;
        }
        
        .admin-sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .admin-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
        }
        
        .sidebar-brand {
            padding: 16px 20px 14px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 6px;
        }
        
        .sidebar-brand img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.25);
            background: white;
            padding: 3px;
        }
        
        .sidebar-brand h3 {
            font-size: 15px;
            font-weight: 700;
            color: #FFFFFF;
            margin-top: 8px;
        }
        
        .sidebar-brand small {
            font-size: 10px;
            color: rgba(255,255,255,0.6);
            display: block;
            margin-top: 1px;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0 10px 20px;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 1px;
        }
        
        .sidebar-menu .menu-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            padding: 12px 12px 4px;
            color: rgba(255,255,255,0.35);
            font-weight: 600;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
        }
        
        .sidebar-menu a i {
            width: 20px;
            font-size: 14px;
            margin-right: 12px;
            color: rgba(255,255,255,0.5);
            text-align: center;
        }
        
        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.12);
            color: #FFFFFF;
        }
        
        .sidebar-menu a:hover i {
            color: #FFFFFF;
        }
        
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.18);
            color: #FFFFFF;
        }
        
        .sidebar-menu a.active i {
            color: #FFFFFF;
        }
        
        .sidebar-menu .logout-link {
            color: #FF6B6B !important;
            margin-top: 6px;
            border-top: 1px solid rgba(255,255,255,0.06);
            padding-top: 10px !important;
        }
        
        .sidebar-menu .logout-link:hover {
            background: rgba(255,0,0,0.15) !important;
            color: #FF4444 !important;
        }
        
        .sidebar-menu .logout-link i {
            color: #FF6B6B !important;
        }
        
        .sidebar-menu .logout-link:hover i {
            color: #FF4444 !important;
        }
        
        /* ============================================================
           MAIN WRAPPER
           ============================================================ */
        .main-wrapper {
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* ============================================================
           TOPBAR - COMPACT HEADER
           ============================================================ */
        .admin-topbar {
            background: var(--topbar-bg);
            padding: 6px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 500;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border-bottom: 1px solid var(--border-color);
            transition: background 0.3s ease;
            min-height: 52px;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .topbar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 18px;
            color: var(--text-primary);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
        }
        
        .topbar-toggle:hover {
            background: var(--bg-hover);
        }
        
        .topbar-left h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .topbar-left h2 .page-icon {
            margin-right: 6px;
            color: #DC2626;
            font-size: 15px;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Global Search - Compact */
        .global-search {
            position: relative;
        }
        
        .global-search .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 12px;
        }
        
        .global-search input {
            width: 200px;
            padding: 6px 10px 6px 32px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            background: var(--bg-input);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .global-search input:focus {
            outline: none;
            border-color: #DC2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
        }
        
        .global-search .search-shortcut {
            display: none;
        }
        
        .search-results {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: var(--bg-card);
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            max-height: 350px;
            overflow-y: auto;
            z-index: 1001;
            padding: 4px 0;
        }
        
        .search-results.active {
            display: block;
        }
        
        .search-results .result-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-primary);
            text-decoration: none;
            transition: background 0.2s ease;
            font-size: 13px;
        }
        
        .search-results .result-item:hover {
            background: var(--bg-hover);
        }
        
        .search-results .result-item i {
            width: 16px;
            color: var(--text-light);
            font-size: 13px;
        }
        
        .search-results .result-empty {
            padding: 16px;
            text-align: center;
            color: var(--text-light);
            font-size: 13px;
        }
        
        /* Dark Mode Toggle - Compact */
        .dark-mode-toggle {
            background: none;
            border: none;
            font-size: 17px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .dark-mode-toggle:hover {
            background: var(--bg-hover);
        }
        
        /* Live Date/Time - Compact */
        .live-datetime {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 3px 10px;
            background: var(--bg-hover);
            border-radius: 6px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }
        
        .live-datetime i {
            color: #DC2626;
            font-size: 11px;
        }
        
        .live-datetime .date-separator {
            color: var(--text-light);
            margin: 0 2px;
        }
        
        /* Notifications - Compact */
        .notification-wrapper {
            position: relative;
        }
        
        .notification-btn {
            background: none;
            border: none;
            font-size: 17px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .notification-btn:hover {
            background: var(--bg-hover);
        }
        
        .notification-badge {
            position: absolute;
            top: 0px;
            right: 0px;
            background: #DC2626;
            color: white;
            border-radius: 50%;
            padding: 1px 5px;
            font-size: 8px;
            font-weight: 700;
            min-width: 16px;
            text-align: center;
            display: none;
        }
        
        .notification-badge.show {
            display: block;
        }
        
        .notification-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            background: var(--bg-card);
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            width: 320px;
            max-height: 400px;
            overflow: hidden;
            z-index: 1001;
        }
        
        .notification-dropdown.show {
            display: block;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .notification-header h4 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .notification-header .mark-all-read {
            background: none;
            border: none;
            color: #DC2626;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .notification-list {
            max-height: 320px;
            overflow-y: auto;
        }
        
        .notification-item {
            display: flex;
            gap: 10px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: var(--bg-hover);
        }
        
        .notification-item .notif-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        .notification-item .notif-icon.info { background: #DBEAFE; color: #2563EB; }
        .notification-item .notif-icon.success { background: #D1FAE5; color: #059669; }
        .notification-item .notif-icon.warning { background: #FEF3C7; color: #D97706; }
        .notification-item .notif-icon.danger { background: #FEE2E2; color: #DC2626; }
        
        .notification-item .notif-content {
            flex: 1;
        }
        
        .notification-item .notif-title {
            font-weight: 500;
            font-size: 12px;
            color: var(--text-primary);
        }
        
        .notification-item .notif-message {
            font-size: 11px;
            color: var(--text-secondary);
        }
        
        .notification-item .notif-time {
            font-size: 10px;
            color: var(--text-light);
        }
        
        /* User Profile - Compact */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 2px 8px 2px 2px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
            position: relative;
        }
        
        .user-profile:hover {
            background: var(--bg-hover);
        }
        
        .user-profile img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #DC2626;
            background: white;
            padding: 2px;
        }
        
        .user-profile .user-info {
            line-height: 1.2;
        }
        
        .user-profile .user-name {
            font-weight: 600;
            font-size: 12px;
            color: var(--text-primary);
        }
        
        .user-profile .user-role {
            font-size: 8px;
            font-weight: 600;
            color: #DC2626;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .user-dropdown-btn {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            padding: 1px;
            font-size: 10px;
            transition: transform 0.3s ease;
        }
        
        .user-dropdown-btn.rotate {
            transform: rotate(180deg);
        }
        
        .user-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            background: var(--bg-card);
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            min-width: 170px;
            padding: 4px 0;
            z-index: 1001;
        }
        
        .user-dropdown.show {
            display: block;
        }
        
        .user-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            transition: background 0.2s ease;
        }
        
        .user-dropdown a:hover {
            background: var(--bg-hover);
        }
        
        .user-dropdown a i {
            width: 16px;
            color: var(--text-light);
            font-size: 13px;
        }
        
        .user-dropdown hr {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 3px 10px;
        }
        
        .user-dropdown .logout-dropdown {
            color: #DC2626;
        }
        
        .user-dropdown .logout-dropdown i {
            color: #DC2626;
        }
        
        .last-updated {
            font-size: 10px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        
        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main-content {
            padding: 20px 24px;
            flex: 1;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        
        .btn {
            padding: 7px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-primary {
            background: #DC2626;
            color: white;
        }
        .btn-primary:hover {
            background: #8B0000;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220,38,38,0.3);
        }
        
        .btn-outline {
            background: transparent;
            color: #DC2626;
            border: 2px solid #DC2626;
        }
        .btn-outline:hover {
            background: #DC2626;
            color: white;
        }
        
        /* Alerts */
        .alerts-container {
            margin-bottom: 18px;
        }
        
        .alert-item {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-item.success {
            background: #D1FAE5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }
        .alert-item.warning {
            background: #FEF3C7;
            border: 1px solid #FDE68A;
            color: #92400E;
        }
        .alert-item.danger {
            background: #FEE2E2;
            border: 1px solid #FECACA;
            color: #991B1B;
        }
        .alert-item.info {
            background: #DBEAFE;
            border: 1px solid #BFDBFE;
            color: #1E40AF;
        }
        
        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        
        .card {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 14px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border-left: 4px solid #DC2626;
            position: relative;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .card .card-icon {
            float: right;
            font-size: 24px;
            opacity: 0.1;
            margin-top: -2px;
        }
        
        .card .card-label {
            font-size: 10px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .card .card-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 4px 0 2px;
        }
        
        .card .card-sub {
            font-size: 11px;
            color: var(--text-light);
        }
        
        .card.positive .card-value {
            color: #10B981;
        }
        .card.negative .card-value {
            color: #DC2626;
        }
        .card.neutral .card-value {
            color: var(--text-secondary);
        }
        
        .card.border-red { border-left-color: #DC2626; }
        .card.border-green { border-left-color: #10B981; }
        .card.border-orange { border-left-color: #F59E0B; }
        .card.border-blue { border-left-color: #3B82F6; }
        .card.border-purple { border-left-color: #8B5CF6; }
        
        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 18px;
        }
        
        .chart-card {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        
        .chart-card h4 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .chart-card canvas {
            max-height: 180px;
            max-width: 100%;
        }
        
        /* Activity Log */
        .activity-section {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        
        .activity-section .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .activity-section .section-header h3 {
            font-size: 14px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .activity-section .section-header .view-all {
            font-size: 12px;
            color: #DC2626;
            text-decoration: none;
            font-weight: 500;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        .activity-icon.success { background: #D1FAE5; color: #059669; }
        .activity-icon.info { background: #DBEAFE; color: #2563EB; }
        .activity-icon.warning { background: #FEF3C7; color: #D97706; }
        .activity-icon.danger { background: #FEE2E2; color: #DC2626; }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-details .action {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
        }
        
        .activity-details .module {
            font-size: 11px;
            color: var(--text-secondary);
        }
        
        .activity-details .module .employee {
            color: var(--text-light);
            font-size: 10px;
        }
        
        .activity-time {
            font-size: 11px;
            color: var(--text-light);
            white-space: nowrap;
        }
        
        /* Footer */
        .admin-footer {
            background: var(--topbar-bg);
            padding: 10px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            transition: background 0.3s ease;
        }
        
        .admin-footer p {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .admin-footer .footer-info {
            font-size: 11px;
            color: var(--text-light);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .admin-footer .footer-info strong {
            color: #DC2626;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1024px) {
            .global-search input {
                width: 150px;
            }
        }
        
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .admin-sidebar.open {
                transform: translateX(0);
            }
            
            .main-wrapper {
                margin-left: 0;
            }
            
            .topbar-toggle {
                display: block;
            }
            
            .admin-topbar {
                padding: 6px 14px;
                min-height: 48px;
            }
            
            .topbar-left h2 {
                font-size: 14px;
            }
            
            .topbar-right {
                gap: 6px;
            }
            
            .global-search {
                order: 10;
                width: 100%;
            }
            
            .global-search input {
                width: 100%;
                font-size: 12px;
                padding: 5px 10px 5px 30px;
            }
            
            .live-datetime {
                font-size: 10px;
                padding: 2px 8px;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr 1fr;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 14px 16px;
            }
            
            .admin-footer {
                padding: 10px 16px;
                flex-direction: column;
                text-align: center;
            }
            
            .user-profile .user-info {
                display: none;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -30px;
            }
            
            .last-updated {
                display: none;
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            
            .sidebar-overlay.active {
                display: block;
            }
        }
        
        @media (max-width: 480px) {
            .admin-topbar {
                padding: 4px 10px;
                min-height: 44px;
            }
            
            .topbar-left h2 {
                font-size: 12px;
            }
            
            .topbar-left h2 .page-icon {
                display: none;
            }
            
            .global-search input {
                font-size: 11px;
                padding: 4px 8px 4px 28px;
            }
            
            .global-search .search-icon {
                font-size: 10px;
                left: 8px;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .card {
                padding: 12px 14px;
            }
            
            .card .card-value {
                font-size: 18px;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .quick-actions .btn {
                width: 100%;
                justify-content: center;
                padding: 6px 14px;
                font-size: 11px;
            }
            
            .main-content {
                padding: 10px 12px;
            }
            
            .live-datetime {
                font-size: 9px;
                padding: 2px 6px;
            }
            
            .live-datetime .date-separator {
                margin: 0 1px;
            }
            
            .user-profile img {
                width: 26px;
                height: 26px;
            }
            
            .notification-dropdown {
                width: 260px;
                right: -50px;
            }
            
            .activity-item {
                flex-wrap: wrap;
                gap: 6px;
            }
            
            .activity-time {
                width: 100%;
                padding-left: 42px;
            }
            
            .activity-section {
                padding: 12px 14px;
            }
            
            .activity-section .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            
            .admin-footer p {
                font-size: 11px;
            }
            
            .admin-footer .footer-info {
                font-size: 10px;
                gap: 6px;
            }
            
            .chart-card {
                padding: 12px 14px;
            }
            
            .chart-card h4 {
                font-size: 12px;
                margin-bottom: 8px;
            }
        }
        
        /* ============================================================
           ANIMATIONS
           ============================================================ */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulseUpdate {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); background-color: rgba(220,38,38,0.05); }
            100% { transform: scale(1); }
        }
        
        .pulse-update {
            animation: pulseUpdate 0.5s ease-in-out;
        }
    </style>
</head>
<body>
    
    <!-- ============================================================
    SIDEBAR
    ============================================================ -->
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $profile_image; ?>" alt="Wakala" 
                 onerror="this.src='../../assets/images/default-avatar.png'">
            <h3>Wakala</h3>
            <small>Financial System</small>
        </div>
        <ul class="sidebar-menu">
            <li class="menu-label">Main</li>
            <li><a href="#" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            
            <li class="menu-label">Daily Operations</li>
            <li><a href="../morning_report/index.php"><i class="fas fa-sun"></i> Morning Report</a></li>
            <li><a href="../evening_stock/index.php"><i class="fas fa-moon"></i> Evening Stock</a></li>
            <li><a href="../daily_report/index.php"><i class="fas fa-file-alt"></i> Daily Report</a></li>
            
            <li class="menu-label">Financial</li>
            <li><a href="../commissions/index.php"><i class="fas fa-hand-holding-usd"></i> Commissions</a></li>
            <li><a href="../expenses/index.php"><i class="fas fa-receipt"></i> Expenses</a></li>
            <li><a href="../store_cash_out/index.php"><i class="fas fa-money-bill-wave"></i> Cash Out</a></li>
            <li><a href="../capital_management/index.php"><i class="fas fa-building"></i> Capital</a></li>
            <li><a href="../salaries/index.php"><i class="fas fa-wallet"></i> Salaries</a></li>
            
            <li class="menu-label">Reports</li>
            <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i> All Reports</a></li>
            
            <li class="menu-label">Management</li>
            <li><a href="../employees/index.php"><i class="fas fa-users"></i> Employees</a></li>
            <li><a href="../activity_logs/index.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <li><a href="../settings/index.php"><i class="fas fa-cog"></i> Settings</a></li>
            
            <li class="menu-label">Account</li>
            <li><a href="../profile/index.php"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="../../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>
    
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- ============================================================
    MAIN WRAPPER
    ============================================================ -->
    <div class="main-wrapper">
        
        <!-- ============================================================
        TOPBAR - COMPACT HEADER
        ============================================================ -->
        <header class="admin-topbar">
            <div class="topbar-left">
                <button class="topbar-toggle" id="topbarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>
                    <i class="fas fa-chart-pie page-icon"></i>
                    Dashboard
                </h2>
            </div>
            
            <div class="topbar-right">
                <!-- Global Search -->
                <div class="global-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="globalSearch" placeholder="Search..." autocomplete="off">
                    <div class="search-results" id="searchResults"></div>
                </div>
                
                <!-- Dark Mode Toggle -->
                <button class="dark-mode-toggle" id="darkModeToggle">
                    <i class="fas fa-moon" id="darkModeIcon"></i>
                </button>
                
                <!-- Live Date & Time -->
                <div class="live-datetime">
                    <i class="fas fa-clock"></i>
                    <span id="liveTime">--:--:--</span>
                    <span class="date-separator">|</span>
                    <span id="liveDate">--/--/----</span>
                </div>
                
                <!-- Notifications -->
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge">0</span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <h4>Notifications</h4>
                            <button class="mark-all-read">Mark all read</button>
                        </div>
                        <div class="notification-list" id="notificationList">
                            <div style="padding:16px;text-align:center;color:var(--text-light);font-size:13px;">
                                <i class="fas fa-bell-slash" style="font-size:20px;display:block;margin-bottom:6px;"></i>
                                No notifications
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- User Profile -->
                <div class="user-profile">
                    <img src="<?php echo $profile_image; ?>" alt="Profile">
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
                        <span class="user-role"><?php echo strtoupper($role); ?></span>
                    </div>
                    <button class="user-dropdown-btn" id="userDropdownBtn">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    
                    <div class="user-dropdown" id="userDropdown">
                        <a href="../profile/index.php">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="../profile/change_password.php">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <hr>
                        <a href="../../logout.php" class="logout-dropdown">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
                
                <!-- Last Updated -->
                <div class="last-updated" id="lastUpdated">
                    <i class="fas fa-check-circle" style="color:#10B981;"></i>
                    <span>Updating...</span>
                </div>
            </div>
        </header>
        
        <!-- ============================================================
        MAIN CONTENT
        ============================================================ -->
        <main class="main-content">
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="../morning_report/add.php" class="btn btn-primary">
                    <i class="fas fa-sun"></i> Morning Report
                </a>
                <a href="../evening_stock/add.php" class="btn btn-primary">
                    <i class="fas fa-moon"></i> Evening Stock
                </a>
                <a href="../commissions/add.php" class="btn btn-primary">
                    <i class="fas fa-hand-holding-usd"></i> Commission
                </a>
                <a href="../expenses/add.php" class="btn btn-primary">
                    <i class="fas fa-receipt"></i> Expense
                </a>
                <a href="../store_cash_out/add.php" class="btn btn-primary">
                    <i class="fas fa-money-bill-wave"></i> Cash Out
                </a>
                <a href="../daily_report/generate.php" class="btn btn-outline">
                    <i class="fas fa-file-alt"></i> Daily Report
                </a>
            </div>
            
            <!-- Alerts -->
            <div class="alerts-container" id="alertsContainer">
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert-item <?php echo $alert['type']; ?>">
                        <?php echo $alert['message']; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Summary Cards -->
            <div class="dashboard-cards" id="dashboardCards">
                <!-- Morning Float -->
                <div class="card border-red">
                    <div class="card-icon"><i class="fas fa-sun"></i></div>
                    <div class="card-label">Morning Float &amp; Cash</div>
                    <div class="card-value" id="cardMorning"><?php echo formatCurrency($morning_total); ?></div>
                    <div class="card-sub">Today</div>
                </div>
                
                <!-- Evening Float -->
                <div class="card border-red">
                    <div class="card-icon"><i class="fas fa-moon"></i></div>
                    <div class="card-label">Evening Float &amp; Cash</div>
                    <div class="card-value" id="cardEvening"><?php echo formatCurrency($evening_total); ?></div>
                    <div class="card-sub">Today</div>
                </div>
                
                <!-- Float Difference -->
                <div class="card <?php echo $float_diff > 0 ? 'positive' : ($float_diff < 0 ? 'negative' : 'neutral'); ?> border-orange">
                    <div class="card-icon"><i class="fas fa-arrows-alt-h"></i></div>
                    <div class="card-label">Float Difference</div>
                    <div class="card-value" id="cardDiff"><?php echo ($float_diff >= 0 ? '+' : '') . formatCurrency($float_diff); ?></div>
                    <div class="card-sub">Evening - Morning</div>
                </div>
                
                <!-- Commission -->
                <div class="card border-green">
                    <div class="card-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="card-label">Total Commission</div>
                    <div class="card-value" id="cardCommission"><?php echo formatCurrency($total_commission); ?></div>
                    <div class="card-sub">This Month</div>
                </div>
                
                <!-- Expenses -->
                <div class="card border-orange">
                    <div class="card-icon"><i class="fas fa-receipt"></i></div>
                    <div class="card-label">Total Expenses</div>
                    <div class="card-value" id="cardExpenses"><?php echo formatCurrency($total_expenses); ?></div>
                    <div class="card-sub">This Month</div>
                </div>
                
                <!-- Salaries -->
                <div class="card border-purple">
                    <div class="card-icon"><i class="fas fa-wallet"></i></div>
                    <div class="card-label">Total Salaries</div>
                    <div class="card-value" id="cardSalaries"><?php echo formatCurrency($total_salaries); ?></div>
                    <div class="card-sub">This Month</div>
                </div>
                
                <!-- Net Profit -->
                <div class="card <?php echo $net_profit > 0 ? 'positive' : ($net_profit < 0 ? 'negative' : 'neutral'); ?> border-green">
                    <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="card-label">Net Profit</div>
                    <div class="card-value" id="cardProfit"><?php echo formatCurrency($net_profit); ?></div>
                    <div class="card-sub">After Salaries</div>
                </div>
                
                <!-- Current Capital -->
                <div class="card border-blue">
                    <div class="card-icon"><i class="fas fa-building"></i></div>
                    <div class="card-label">Current Capital</div>
                    <div class="card-value" id="cardCapital"><?php echo formatCurrency($current_capital); ?></div>
                    <div class="card-sub">Available</div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="charts-row">
                <!-- Commission Chart -->
                <div class="chart-card">
                    <h4><i class="fas fa-chart-line" style="color:#10B981;"></i> Commission Trend</h4>
                    <canvas id="commissionChart"></canvas>
                </div>
                
                <!-- Expense Chart -->
                <div class="chart-card">
                    <h4><i class="fas fa-chart-bar" style="color:#DC2626;"></i> Expense Trend</h4>
                    <canvas id="expenseChart"></canvas>
                </div>
            </div>
            
            <!-- Activity Log -->
            <div class="activity-section">
                <div class="section-header">
                    <h3><i class="fas fa-history" style="color:#DC2626;"></i> Recent Activity</h3>
                    <a href="../activity_logs/index.php" class="view-all">View All →</a>
                </div>
                <div id="activityLog">
                    <?php if (count($activities) > 0): ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo strtolower($activity['action']) === 'login' ? 'success' : 'info'; ?>">
                                    <i class="fas <?php echo $activity['action'] === 'Login' ? 'fa-sign-in-alt' : 'fa-edit'; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="action"><?php echo htmlspecialchars($activity['action']); ?></div>
                                    <div class="module">
                                        <?php echo htmlspecialchars($activity['module']); ?>
                                        <span class="employee">by <?php echo htmlspecialchars($activity['employee']); ?></span>
                                    </div>
                                </div>
                                <div class="activity-time"><?php echo formatDateTime($activity['created_at']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:16px;text-align:center;color:var(--text-light);font-size:13px;">
                            <i class="fas fa-inbox" style="font-size:20px;display:block;margin-bottom:6px;"></i>
                            No recent activity
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </main>
        
        <!-- ============================================================
        FOOTER
        ============================================================ -->
        <footer class="admin-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?></p>
            <div class="footer-info">
                <span>Version 2.0.0</span>
                <span>|</span>
                <span>Powered by <strong>Wakala System</strong></span>
                <span>|</span>
                <span id="footerDateTime"></span>
            </div>
        </footer>
        
    </div>
    
    <!-- ============================================================
    JAVASCRIPT
    ============================================================ -->
    <script>
        // ============================================================
        // SIDEBAR TOGGLE (Mobile)
        // ============================================================
        const topbarToggle = document.getElementById('topbarToggle');
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (topbarToggle && sidebar && overlay) {
            topbarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
            });
            
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        }
        
        // ============================================================
        // DARK MODE TOGGLE
        // ============================================================
        const darkToggle = document.getElementById('darkModeToggle');
        const darkIcon = document.getElementById('darkModeIcon');
        const htmlRoot = document.documentElement;
        
        // Load saved preference
        const savedDarkMode = localStorage.getItem('darkMode') === 'true';
        if (savedDarkMode) {
            htmlRoot.classList.add('dark-mode');
            darkIcon.className = 'fas fa-sun';
        }
        
        if (darkToggle) {
            darkToggle.addEventListener('click', function() {
                htmlRoot.classList.toggle('dark-mode');
                const isDark = htmlRoot.classList.contains('dark-mode');
                darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                localStorage.setItem('darkMode', isDark);
            });
        }
        
        // ============================================================
        // USER DROPDOWN
        // ============================================================
        const userDropdownBtn = document.getElementById('userDropdownBtn');
        const userDropdown = document.getElementById('userDropdown');
        
        if (userDropdownBtn && userDropdown) {
            userDropdownBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
                this.classList.toggle('rotate');
            });
            
            document.addEventListener('click', function(e) {
                if (!userDropdown.contains(e.target) && !userDropdownBtn.contains(e.target)) {
                    userDropdown.classList.remove('show');
                    userDropdownBtn.classList.remove('rotate');
                }
            });
        }
        
        // ============================================================
        // NOTIFICATIONS
        // ============================================================
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        if (notificationBtn && notificationDropdown) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                    notificationDropdown.classList.remove('show');
                }
            });
            
            const markAllRead = document.querySelector('.mark-all-read');
            if (markAllRead) {
                markAllRead.addEventListener('click', function() {
                    const badge = document.getElementById('notificationBadge');
                    if (badge) {
                        badge.textContent = '0';
                        badge.classList.remove('show');
                    }
                    const list = document.getElementById('notificationList');
                    if (list) {
                        list.innerHTML = `
                            <div style="padding:16px;text-align:center;color:var(--text-light);font-size:13px;">
                                <i class="fas fa-check-circle" style="font-size:20px;display:block;margin-bottom:6px;color:#10B981;"></i>
                                All notifications read
                            </div>
                        `;
                    }
                    notificationDropdown.classList.remove('show');
                });
            }
        }
        
        // ============================================================
        // GLOBAL SEARCH
        // ============================================================
        const searchInput = document.getElementById('globalSearch');
        const searchResults = document.getElementById('searchResults');
        
        if (searchInput && searchResults) {
            const searchData = [
                { title: 'Dashboard', icon: 'fa-home', url: '#' },
                { title: 'Morning Report', icon: 'fa-sun', url: '../morning_report/index.php' },
                { title: 'Evening Stock', icon: 'fa-moon', url: '../evening_stock/index.php' },
                { title: 'Daily Report', icon: 'fa-file-alt', url: '../daily_report/index.php' },
                { title: 'Commissions', icon: 'fa-hand-holding-usd', url: '../commissions/index.php' },
                { title: 'Expenses', icon: 'fa-receipt', url: '../expenses/index.php' },
                { title: 'Store Cash Out', icon: 'fa-money-bill-wave', url: '../store_cash_out/index.php' },
                { title: 'Capital Management', icon: 'fa-building', url: '../capital_management/index.php' },
                { title: 'Salaries', icon: 'fa-wallet', url: '../salaries/index.php' },
                { title: 'Reports', icon: 'fa-chart-bar', url: '../reports/index.php' },
                { title: 'Employees', icon: 'fa-users', url: '../employees/index.php' },
                { title: 'Activity Logs', icon: 'fa-history', url: '../activity_logs/index.php' },
                { title: 'Settings', icon: 'fa-cog', url: '../settings/index.php' },
                { title: 'My Profile', icon: 'fa-user', url: '../profile/index.php' },
                { title: 'Change Password', icon: 'fa-key', url: '../profile/change_password.php' },
            ];
            
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                
                if (query.length === 0) {
                    searchResults.classList.remove('active');
                    return;
                }
                
                const results = searchData.filter(item => 
                    item.title.toLowerCase().includes(query)
                );
                
                if (results.length === 0) {
                    searchResults.innerHTML = `
                        <div class="result-empty">
                            <i class="fas fa-search"></i>
                            <p>No results for "<strong>${query}</strong>"</p>
                        </div>
                    `;
                } else {
                    let html = '';
                    results.forEach(item => {
                        html += `
                            <a href="${item.url}" class="result-item">
                                <i class="fas ${item.icon}"></i>
                                <span>${item.title}</span>
                            </a>
                        `;
                    });
                    searchResults.innerHTML = html;
                }
                
                searchResults.classList.add('active');
            });
            
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.remove('active');
                }
            });
            
            // Ctrl+K shortcut
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    searchInput.focus();
                    searchInput.select();
                }
            });
        }
        
        // ============================================================
        // LIVE DATE & TIME
        // ============================================================
        function updateLiveDateTime() {
            const now = new Date();
            
            const timeEl = document.getElementById('liveTime');
            if (timeEl) {
                timeEl.textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }
            
            const dateEl = document.getElementById('liveDate');
            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }
            
            const footerEl = document.getElementById('footerDateTime');
            if (footerEl) {
                footerEl.textContent = now.toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }
        }
        
        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000);
        
        // ============================================================
        // CHARTS
        // ============================================================
        // Commission Chart
        const ctx1 = document.getElementById('commissionChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [{
                    label: 'Commission',
                    data: <?php echo json_encode($commission_data); ?>,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'TSh ' + value.toLocaleString();
                            },
                            font: { size: 9 }
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 9 }
                        }
                    }
                }
            }
        });
        
        // Expense Chart
        const ctx2 = document.getElementById('expenseChart').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [{
                    label: 'Expenses',
                    data: <?php echo json_encode($expense_data); ?>,
                    backgroundColor: 'rgba(220, 38, 38, 0.7)',
                    borderColor: '#DC2626',
                    borderWidth: 1.5,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'TSh ' + value.toLocaleString();
                            },
                            font: { size: 9 }
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 9 }
                        }
                    }
                }
            }
        });
        
        // ============================================================
        // AUTO-UPDATE DASHBOARD (Every 3 seconds)
        // ============================================================
        function fetchDashboardData() {
            fetch('../../api/dashboard_data.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateCards(data.cards);
                        updateActivityLog(data.activities);
                        updateAlerts(data.alerts);
                        updateLastUpdated(data.timestamp);
                    }
                })
                .catch(error => console.error('Error fetching data:', error));
        }
        
        function updateCards(cards) {
            const cardMap = {
                morning: 'cardMorning',
                evening: 'cardEvening',
                diff: 'cardDiff',
                commission: 'cardCommission',
                expenses: 'cardExpenses',
                salaries: 'cardSalaries',
                profit: 'cardProfit',
                capital: 'cardCapital'
            };
            
            Object.keys(cardMap).forEach(key => {
                const element = document.getElementById(cardMap[key]);
                if (element && cards[key] !== undefined) {
                    const newValue = formatCurrency(cards[key]);
                    if (element.textContent !== newValue) {
                        element.textContent = newValue;
                        element.parentElement.classList.add('pulse-update');
                        setTimeout(() => {
                            element.parentElement.classList.remove('pulse-update');
                        }, 500);
                    }
                }
            });
        }
        
        function updateActivityLog(activities) {
            const container = document.getElementById('activityLog');
            if (!container || !activities) return;
            
            if (activities.length > 0) {
                let html = '';
                activities.forEach(activity => {
                    const iconClass = activity.action === 'Login' ? 'success' : 'info';
                    const icon = activity.action === 'Login' ? 'fa-sign-in-alt' : 'fa-edit';
                    html += `
                        <div class="activity-item">
                            <div class="activity-icon ${iconClass}">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div class="activity-details">
                                <div class="action">${escapeHtml(activity.action)}</div>
                                <div class="module">
                                    ${escapeHtml(activity.module)}
                                    <span class="employee">by ${escapeHtml(activity.employee)}</span>
                                </div>
                            </div>
                            <div class="activity-time">${formatDateTime(activity.created_at)}</div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            }
        }
        
        function updateAlerts(alerts) {
            const container = document.getElementById('alertsContainer');
            if (!container || !alerts) return;
            
            let html = '';
            alerts.forEach(alert => {
                html += `
                    <div class="alert-item ${alert.type}">
                        ${escapeHtml(alert.message)}
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        function updateLastUpdated(timestamp) {
            const element = document.getElementById('lastUpdated');
            if (element) {
                const time = new Date(timestamp).toLocaleTimeString();
                element.innerHTML = `
                    <i class="fas fa-check-circle" style="color:#10B981;"></i>
                    <span>Updated: ${time}</span>
                `;
            }
        }
        
        function formatCurrency(amount) {
            return 'TSh ' + Number(amount).toLocaleString();
        }
        
        function formatDateTime(timestamp) {
            return new Date(timestamp).toLocaleString();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ============================================================
        // START AUTO-UPDATE
        // ============================================================
        fetchDashboardData();
        setInterval(fetchDashboardData, 3000);
        
        // ============================================================
        // CONSOLE
        // ============================================================
        console.log('%c WAKALA ADMIN DASHBOARD v2.0 ',
            'background:#8B0000; color:white; padding:6px 12px; border-radius:4px; font-size:13px; font-weight:bold;');
        console.log('%c ⚡ Auto-Update (3s) | 🔍 Ctrl+K Search | 🌙 Dark Mode ',
            'color:#6B7280; font-size:11px;');
    </script>
</body>
</html>