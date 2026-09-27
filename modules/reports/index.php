<?php
// ================================================================
// FILE: modules/reports/index.php
// WAKALA FINANCIAL SYSTEM - CENTRAL REPORTS DASHBOARD
// BLUE THEME — SCOPED CSS — SIDEBAR SAFE
// Aggregates data from ALL modules
// ✅ FIXED: All Branches - sums latest report from EACH branch
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

$is_admin = ($role === 'admin' || $role === 'super_admin');

// Get user branch
$stmt = $db->prepare("SELECT branch_id, branch FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$user_branch_id = intval($emp['branch_id'] ?? 0);

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;

if ($is_admin) {
    if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
        $selected_branch = intval($_GET['branch_id']);
    }
} else {
    $selected_branch = $user_branch_id;
}

// Get branches (for admin filter)
$branches = [];
if ($is_admin) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Branch info
$branch_name = 'All Branches';
$branch_code = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
    }
}

// ============================================================
// DATE RANGE FILTER
// ============================================================
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$today = date('Y-m-d');

switch ($filter) {
    case 'all':
        $from_date = '2000-01-01';
        $to_date = $today;
        break;
    case 'today':
        $from_date = $today;
        $to_date = $today;
        break;
    case '1d':
        $from_date = date('Y-m-d', strtotime('-1 day'));
        $to_date = $today;
        break;
    case '1w':
        $from_date = date('Y-m-d', strtotime('-7 days'));
        $to_date = $today;
        break;
    case '1m':
        $from_date = date('Y-m-d', strtotime('-1 month'));
        $to_date = $today;
        break;
    case '3m':
        $from_date = date('Y-m-d', strtotime('-3 months'));
        $to_date = $today;
        break;
    case '6m':
        $from_date = date('Y-m-d', strtotime('-6 months'));
        $to_date = $today;
        break;
    case '1y':
        $from_date = date('Y-m-d', strtotime('-1 year'));
        $to_date = $today;
        break;
    case 'custom':
        $from_date = !empty($from_date) ? $from_date : date('Y-m-01');
        $to_date = !empty($to_date) ? $to_date : $today;
        break;
    default:
        $from_date = date('Y-m-01');
        $to_date = $today;
}

// ============================================================
// HELPER: Branch filter clause
// ============================================================
function branchWhere(&$params, $selected_branch, $column = 'branch_id') {
    if ($selected_branch > 0) {
        $params[] = $selected_branch;
        return " AND {$column} = ?";
    }
    return "";
}

$data = [];

// --------------------------------------------------------
// 1. TRANSACTIONS (Deposits + Withdrawals)
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN transaction_type = 'deposit' THEN 1 ELSE 0 END) as deposit_count,
        SUM(CASE WHEN transaction_type = 'withdrawal' THEN 1 ELSE 0 END) as withdrawal_count,
        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals
    FROM transactions
    WHERE transaction_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['transactions'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 2. TRANSFERS
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN transfer_type = 'cash_to_float' THEN amount ELSE 0 END), 0) as cash_to_float,
        COALESCE(SUM(CASE WHEN transfer_type = 'float_to_cash' THEN amount ELSE 0 END), 0) as float_to_cash
    FROM transfers
    WHERE transfer_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['transfers'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 3. DAILY REPORTS
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_reports,
        COALESCE(SUM(total_deposits), 0) as total_deposits,
        COALESCE(SUM(total_withdrawals), 0) as total_withdrawals,
        COALESCE(SUM(total_commission), 0) as total_commission,
        COALESCE(SUM(other_income), 0) as total_other_income,
        COALESCE(SUM(total_expenses), 0) as total_expenses,
        COALESCE(SUM(total_salaries), 0) as total_salaries,
        COALESCE(SUM(total_cash_out), 0) as total_cash_out,
        COALESCE(SUM(net_profit), 0) as total_profit
    FROM daily_reports
    WHERE report_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['daily_reports'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 4. EXPENSES
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    AND is_business_expense = 1
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['expenses'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 5. SALARIES
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count,
        COALESCE(SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END), 0) as waiting_count,
        COALESCE(SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END), 0) as upcoming_count,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN net_pay ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN status = 'waiting' THEN net_pay ELSE 0 END), 0) as total_waiting,
        COALESCE(SUM(CASE WHEN status = 'upcoming' THEN net_pay ELSE 0 END), 0) as total_upcoming,
        COALESCE(SUM(net_pay), 0) as total_amount
    FROM employee_salaries
    WHERE salary_month BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['salaries'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 6. COMMISSIONS
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(total_commission), 0) as total_commission,
        COALESCE(SUM(other_income), 0) as total_other_income,
        COALESCE(SUM(total_business_income), 0) as total_business_income
    FROM commissions
    WHERE commission_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['commissions'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 7. CAPITAL MANAGEMENT
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN transaction_type = 'opening' THEN amount ELSE 0 END), 0) as opening,
        COALESCE(SUM(CASE WHEN transaction_type = 'additional' THEN amount ELSE 0 END), 0) as additional,
        COALESCE(SUM(CASE WHEN transaction_type = 'profit_allocation' THEN amount ELSE 0 END), 0) as profit_alloc,
        COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
        COALESCE(SUM(CASE WHEN transaction_type = 'adjustment' THEN amount ELSE 0 END), 0) as adjustment
    FROM capital_management
    WHERE transaction_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['capital'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------
// 8. STORE CASH OUT
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM store_cash_out
    WHERE cashout_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['store_cash_out'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ============================================================
// 9. CURRENT CAPITAL (Latest Daily Report)
// ✅ FIXED: Kama All Branches → jumla ya branches zote
// ============================================================
$current_float = 0;
$current_cash = 0;
$current_capital = 0;

if ($selected_branch > 0) {
    // --------------------------------------------------------
    // SINGLE BRANCH
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT * FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
    $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($latest_dr) {
        $current_cash = floatval($latest_dr['current_cash'] ?? 0);
        
        // Get latest record per provider
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(latest.current_float), 0) as total_float
            FROM (
                SELECT drp1.provider_id, drp1.current_float
                FROM daily_report_providers drp1
                INNER JOIN (
                    SELECT provider_id, MAX(id) as max_id
                    FROM daily_report_providers
                    WHERE daily_report_id = ?
                    GROUP BY provider_id
                ) drp2 ON drp1.id = drp2.max_id
            ) latest
        ");
        $stmt->execute([$latest_dr['id']]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_float = floatval($f['total_float'] ?? 0);
        $current_capital = $current_float + $current_cash;
    }
    
} else {
    // --------------------------------------------------------
    // ALL BRANCHES: Sum latest report from EACH active branch
    // --------------------------------------------------------
    $stmt = $db->prepare("SELECT id FROM branches WHERE is_active = 1");
    $stmt->execute();
    $active_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($active_branches as $b) {
        // Get latest daily report for this branch
        $stmt = $db->prepare("
            SELECT * FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$b['id']]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_dr) {
            // Cash
            $branch_cash = floatval($latest_dr['current_cash'] ?? 0);
            $current_cash += $branch_cash;
            
            // Float (latest record per provider)
            $stmt2 = $db->prepare("
                SELECT COALESCE(SUM(latest.current_float), 0) as branch_float
                FROM (
                    SELECT drp1.provider_id, drp1.current_float
                    FROM daily_report_providers drp1
                    INNER JOIN (
                        SELECT provider_id, MAX(id) as max_id
                        FROM daily_report_providers
                        WHERE daily_report_id = ?
                        GROUP BY provider_id
                    ) drp2 ON drp1.id = drp2.max_id
                ) latest
            ");
            $stmt2->execute([$latest_dr['id']]);
            $bf = $stmt2->fetch(PDO::FETCH_ASSOC);
            $current_float += floatval($bf['branch_float'] ?? 0);
        }
    }
    
    $current_capital = $current_float + $current_cash;
}

// --------------------------------------------------------
// 10. RECENT ACTIVITY (Top 10)
// --------------------------------------------------------
$params = [];
$sql = "
    SELECT 
        al.*,
        e.full_name as employee_name,
        e.profile_pic as employee_avatar
    FROM activity_logs al
    LEFT JOIN employees e ON al.employee_id = e.id
    WHERE 1=1
";
if ($selected_branch > 0) {
    $sql .= " AND (al.branch_id = ? OR al.branch_id IS NULL)";
    $params[] = $selected_branch;
}
$sql .= " ORDER BY al.created_at DESC LIMIT 10";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------
// 11. TOP EMPLOYEES (by transactions)
// --------------------------------------------------------
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        e.id,
        e.full_name,
        e.employee_id as emp_code,
        e.profile_pic,
        COUNT(t.id) as txn_count,
        COALESCE(SUM(t.amount), 0) as total_amount
    FROM employees e
    LEFT JOIN transactions t ON t.employee_id = e.id 
        AND t.transaction_date BETWEEN ? AND ?
";
if ($selected_branch > 0) {
    $sql .= " WHERE e.branch_id = ?";
    $params[] = $selected_branch;
} else {
    $sql .= " WHERE 1=1";
}
$sql .= " AND e.is_active = 1 AND e.employment_status = 'active'
    GROUP BY e.id
    HAVING txn_count > 0
    ORDER BY txn_count DESC
    LIMIT 5";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['top_employees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_header.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_sidebar.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_topbar.php';
?>

<style id="reports-dashboard-scoped">
/* ============================================================
   SCOPED VARIABLES
   ============================================================ */
.main-wrapper {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-table-even: #f8fafc;
    --bg-table-hover: #eff6ff;
    --bg-input: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(30, 64, 175, 0.08);
    --shadow-hover: rgba(30, 64, 175, 0.15);
}

html.dark-mode .main-wrapper {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

.main-wrapper,
.main-wrapper *,
.main-wrapper *::before,
.main-wrapper *::after {
    box-sizing: border-box;
}

.main-wrapper {
    background: var(--bg-body) !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
}

.main-wrapper .main-content {
    padding: 16px 20px !important;
    background: var(--bg-body) !important;
    color: var(--text-primary);
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* BRANCH INDICATOR */
.main-wrapper .branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px;
    padding: 14px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap;
    gap: 12px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.main-wrapper .branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    flex: 1;
    position: relative;
    z-index: 1;
    min-width: 0;
}
.main-wrapper .branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #FFFFFF;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.main-wrapper .branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.main-wrapper .branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px;
}
.main-wrapper .branch-indicator-name { font-weight: 800; font-size: 16px; }
.main-wrapper .branch-indicator-code {
    font-size: 11px; font-weight: 700;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: 'Courier New', monospace;
}
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .date-display {
    font-size: 13px;
    color: rgba(255,255,255,0.95);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}

/* PAGE HEADER */
.main-wrapper .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 12px;
}
.main-wrapper .page-header .header-left h2 {
    font-size: 24px; font-weight: 800; margin: 0; color: var(--text-primary);
    display: flex; align-items: center; gap: 10px;
}
.main-wrapper .page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ALERTS */
.main-wrapper .alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideDown 0.4s ease forwards;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.main-wrapper .alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .main-wrapper .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .main-wrapper .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.main-wrapper .alert i { font-size: 20px; flex-shrink: 0; }
.main-wrapper .alert span { flex: 1; font-size: 13px; font-weight: 500; }
.main-wrapper .alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; opacity: 0.6;
}
.main-wrapper .alert-close:hover { opacity: 1; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* FILTER BAR */
.main-wrapper .filter-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 18px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .filter-form {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.main-wrapper .filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 180px;
    flex: 1;
}
.main-wrapper .filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.main-wrapper .filter-group label i { color: #1e40af; }
.main-wrapper .form-control {
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}
.main-wrapper .form-control:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.main-wrapper .filter-actions { display: flex; gap: 8px; }

.main-wrapper .btn {
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.main-wrapper .btn-primary {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.main-wrapper .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-secondary {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.main-wrapper .btn-secondary:hover {
    background: var(--bg-card);
    color: var(--text-primary);
}

/* TIME FILTER */
.main-wrapper .time-filter-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .time-filter-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    flex-shrink: 0;
}
.main-wrapper .time-filter-left i { color: #1e40af; font-size: 14px; }
.main-wrapper .time-filter-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    flex: 1;
}
.main-wrapper .time-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 14px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.main-wrapper .time-btn:hover {
    background: var(--bg-table-hover);
    border-color: #1e40af;
    color: #1e40af;
    transform: translateY(-1px);
}
.main-wrapper .time-btn.active {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF;
    border-color: #1e40af;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.35);
    transform: translateY(-1px);
}

/* KPI GRID */
.main-wrapper .kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.main-wrapper .kpi-card {
    position: relative;
    border-radius: 16px;
    padding: 22px 24px;
    display: flex;
    align-items: center;
    gap: 18px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    color: #FFFFFF;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
}
.main-wrapper .kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
}
.main-wrapper .kpi-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 160px; height: 160px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .kpi-blue { background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); }
.main-wrapper .kpi-green { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.main-wrapper .kpi-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.main-wrapper .kpi-purple { background: linear-gradient(135deg, #7C3AED 0%, #8B5CF6 100%); }
.main-wrapper .kpi-red { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.main-wrapper .kpi-cyan { background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); }
.main-wrapper .kpi-pink { background: linear-gradient(135deg, #DB2777 0%, #EC4899 100%); }
.main-wrapper .kpi-indigo { background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%); }
.main-wrapper .kpi-icon {
    width: 60px; height: 60px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.22);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1;
}
.main-wrapper .kpi-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
    z-index: 1;
}
.main-wrapper .kpi-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255, 255, 255, 0.85);
}
.main-wrapper .kpi-value {
    font-size: 24px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    line-height: 1.15;
    word-break: break-word;
    color: #FFFFFF;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.main-wrapper .kpi-sub {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.main-wrapper .kpi-sub i { font-size: 10px; }

/* SECTION CARD */
.main-wrapper .section-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 20px;
}
.main-wrapper .section-card-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 16px 22px;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.main-wrapper .section-card-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .section-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}
.main-wrapper .section-header-left i {
    font-size: 22px;
    background: rgba(255,255,255,0.18);
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
    flex-shrink: 0;
}
.main-wrapper .section-card-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    white-space: nowrap;
}
.main-wrapper .section-count-badge {
    background: rgba(255,255,255,0.22);
    padding: 4px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    white-space: nowrap;
    position: relative;
    z-index: 1;
}
.main-wrapper .section-card-body { padding: 22px 24px; }

/* MINI STATS GRID */
.main-wrapper .mini-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
}
.main-wrapper .mini-stat {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
    min-width: 0;
}
.main-wrapper .mini-stat:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(59, 130, 246, 0.1);
}
.main-wrapper .mini-stat-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.main-wrapper .mini-stat-icon.blue { background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%); color: #1D4ED8; border: 1.5px solid #93C5FD; }
.main-wrapper .mini-stat-icon.green { background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%); color: #059669; border: 1.5px solid #6EE7B7; }
.main-wrapper .mini-stat-icon.orange { background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%); color: #D97706; border: 1.5px solid #FCD34D; }
.main-wrapper .mini-stat-icon.red { background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%); color: #DC2626; border: 1.5px solid #FCA5A5; }
.main-wrapper .mini-stat-icon.purple { background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%); color: #7C3AED; border: 1.5px solid #C4B5FD; }
.main-wrapper .mini-stat-icon.cyan { background: linear-gradient(135deg, #CFFAFE 0%, #A5F3FC 100%); color: #0891b2; border: 1.5px solid #67E8F9; }
html.dark-mode .main-wrapper .mini-stat-icon.blue { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .main-wrapper .mini-stat-icon.green { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .main-wrapper .mini-stat-icon.orange { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .main-wrapper .mini-stat-icon.red { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .main-wrapper .mini-stat-icon.purple { background: #4C1D95; color: #C4B5FD; border-color: #8B5CF6; }
html.dark-mode .main-wrapper .mini-stat-icon.cyan { background: #164E63; color: #67E8F9; border-color: #06B6D4; }
.main-wrapper .mini-stat-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.main-wrapper .mini-stat-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .mini-stat-value {
    font-size: 16px;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}
.main-wrapper .mini-stat-value.positive { color: #059669; }
.main-wrapper .mini-stat-value.negative { color: #DC2626; }
html.dark-mode .main-wrapper .mini-stat-value.positive { color: #34D399; }
html.dark-mode .main-wrapper .mini-stat-value.negative { color: #FCA5A5; }

/* TWO COLUMN */
.main-wrapper .two-col-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* ACTIVITY LIST */
.main-wrapper .activity-list { display: flex; flex-direction: column; gap: 10px; }
.main-wrapper .activity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}
.main-wrapper .activity-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    border-color: #3b82f6;
}
.main-wrapper .activity-avatar {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 14px;
    flex-shrink: 0;
    border: 2px solid #93c5fd;
    object-fit: cover;
}
.main-wrapper .activity-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.main-wrapper .activity-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .activity-subtitle {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.main-wrapper .activity-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #DBEAFE;
    color: #1D4ED8;
    border: 1px solid #BFDBFE;
}
html.dark-mode .main-wrapper .activity-badge { background: #1E3A5F; color: #60A5FA; }
.main-wrapper .activity-time {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    flex-shrink: 0;
    font-family: 'Courier New', monospace;
    text-align: right;
    min-width: 75px;
}

/* TOP LIST */
.main-wrapper .top-list { display: flex; flex-direction: column; gap: 10px; }
.main-wrapper .top-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}
.main-wrapper .top-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    border-color: #3b82f6;
}
.main-wrapper .top-rank {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 14px;
    flex-shrink: 0;
    border: 2px solid;
}
.main-wrapper .top-rank-1 { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #92400E; border-color: #FCD34D; }
.main-wrapper .top-rank-2 { background: linear-gradient(135deg, #E5E7EB, #D1D5DB); color: #374151; border-color: #9CA3AF; }
.main-wrapper .top-rank-3 { background: linear-gradient(135deg, #FED7AA, #FDBA74); color: #9A3412; border-color: #FB923C; }
.main-wrapper .top-rank-other { background: var(--bg-input); color: var(--text-muted); border-color: var(--border-color); }
.main-wrapper .top-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.main-wrapper .top-name {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .top-sub { font-size: 10px; font-weight: 600; color: var(--text-muted); }
.main-wrapper .top-value {
    font-size: 13px;
    font-weight: 900;
    color: #059669;
    font-family: 'Inter', 'Courier New', monospace;
    flex-shrink: 0;
    white-space: nowrap;
}
html.dark-mode .main-wrapper .top-value { color: #34D399; }

/* QUICK LINKS */
.main-wrapper .quick-links-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
}
.main-wrapper .quick-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    text-decoration: none;
    transition: all 0.25s ease;
    min-width: 0;
}
.main-wrapper .quick-link:hover {
    background: var(--bg-card);
    transform: translateY(-2px);
    border-color: #3b82f6;
    box-shadow: 0 4px 14px rgba(59, 130, 246, 0.15);
}
.main-wrapper .quick-link-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.main-wrapper .quick-link-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.main-wrapper .quick-link-title {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .quick-link-sub { font-size: 10px; font-weight: 600; color: var(--text-muted); }
.main-wrapper .quick-link-arrow {
    color: var(--text-light);
    font-size: 14px;
    flex-shrink: 0;
    transition: transform 0.2s ease;
}
.main-wrapper .quick-link:hover .quick-link-arrow {
    transform: translateX(4px);
    color: #3b82f6;
}

/* EMPTY */
.main-wrapper .empty-mini {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
}
.main-wrapper .empty-mini i {
    font-size: 42px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 10px;
}
.main-wrapper .empty-mini p { font-size: 13px; margin: 0; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .main-wrapper .kpi-grid { grid-template-columns: repeat(3, 1fr); }
    .main-wrapper .two-col-grid { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .main-wrapper .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .main-wrapper .branch-indicator { flex-direction: column; align-items: flex-start; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .header-right .btn { flex: 1; justify-content: center; }
    .main-wrapper .kpi-grid { grid-template-columns: 1fr; }
    .main-wrapper .two-col-grid { grid-template-columns: 1fr; }
    .main-wrapper .filter-form { flex-direction: column; }
    .main-wrapper .filter-group { min-width: 100%; }
    .main-wrapper .filter-actions { width: 100%; }
    .main-wrapper .filter-actions .btn { flex: 1; justify-content: center; }
    .main-wrapper .mini-stats-grid { grid-template-columns: 1fr; }
    .main-wrapper .quick-links-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .main-wrapper .kpi-value { font-size: 20px; }
    .main-wrapper .kpi-icon { width: 50px; height: 50px; font-size: 22px; }
    .main-wrapper .quick-links-grid { grid-template-columns: 1fr; }
    .main-wrapper .mini-stat-value { font-size: 14px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Reports Dashboard</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-chart-pie" style="color:#1E40AF;"></i> Reports Dashboard</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Comprehensive overview of all modules
                </p>
            </div>
            <div class="header-right">
                <a href="export.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&format=pdf" 
                   class="btn btn-primary" target="_blank">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- KPI CARDS ROW 1 -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Transactions</span>
                    <span class="kpi-value"><?php echo number_format($data['transactions']['total_count'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-arrow-down"></i> 
                        <?php echo number_format($data['transactions']['deposit_count'] ?? 0); ?> deposits
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-green">
                <div class="kpi-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Total Deposits</span>
                    <span class="kpi-value"><?php echo formatCurrency($data['transactions']['total_deposits'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-chart-line"></i> 
                        <?php echo number_format($data['transactions']['withdrawal_count'] ?? 0); ?> withdrawals
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-red">
                <div class="kpi-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Total Withdrawals</span>
                    <span class="kpi-value"><?php echo formatCurrency($data['transactions']['total_withdrawals'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-arrow-right"></i> 
                        <?php echo number_format($data['transfers']['total_count'] ?? 0); ?> transfers
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-purple">
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Net Profit</span>
                    <span class="kpi-value"><?php echo formatCurrency($data['daily_reports']['total_profit'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-percent"></i> 
                        <?php echo formatCurrency($data['daily_reports']['total_commission'] ?? 0); ?> commission
                    </span>
                </div>
            </div>
        </div>

        <!-- KPI CARDS ROW 2 -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-indigo">
                <div class="kpi-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Current Capital</span>
                    <span class="kpi-value"><?php echo formatCurrency($current_capital); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-coins"></i> 
                        <?php echo formatCurrency($current_float); ?> float
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-cyan">
                <div class="kpi-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Current Cash</span>
                    <span class="kpi-value"><?php echo formatCurrency($current_cash); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-wallet"></i> 
                        Available balance
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-orange">
                <div class="kpi-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Total Expenses</span>
                    <span class="kpi-value"><?php echo formatCurrency($data['expenses']['total_amount'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-list"></i> 
                        <?php echo number_format($data['expenses']['total_count'] ?? 0); ?> records
                    </span>
                </div>
            </div>

            <div class="kpi-card kpi-pink">
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Total Salaries</span>
                    <span class="kpi-value"><?php echo formatCurrency($data['salaries']['total_paid'] ?? 0); ?></span>
                    <span class="kpi-sub">
                        <i class="fas fa-clock"></i> 
                        <?php echo formatCurrency($data['salaries']['total_waiting'] ?? 0); ?> waiting
                    </span>
                </div>
            </div>
        </div>

        <!-- TIME FILTER -->
        <div class="time-filter-bar">
            <div class="time-filter-left">
                <i class="fas fa-calendar-alt"></i>
                <span>Period:</span>
            </div>
            <div class="time-filter-buttons">
                <a href="?filter=all&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=today&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
                <a href="?filter=1d&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1d' ? 'active' : ''; ?>">1D</a>
                <a href="?filter=1w&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1w' ? 'active' : ''; ?>">1W</a>
                <a href="?filter=1m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1m' ? 'active' : ''; ?>">1M</a>
                <a href="?filter=3m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '3m' ? 'active' : ''; ?>">3M</a>
                <a href="?filter=6m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '6m' ? 'active' : ''; ?>">6M</a>
                <a href="?filter=1y&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1y' ? 'active' : ''; ?>">1Y</a>
                <a href="?filter=custom&branch_id=<?php echo $selected_branch; ?>&from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-d'); ?>" 
                   class="time-btn <?php echo $filter === 'custom' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i> Custom
                </a>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="filter" value="custom">
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> From Date</label>
                    <input type="date" name="from_date" class="form-control" 
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> To Date</label>
                    <input type="date" name="to_date" class="form-control" 
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                
                <?php if ($is_admin): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-store-alt"></i> Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filter
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- FINANCIAL OVERVIEW -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-header-left">
                    <i class="fas fa-coins"></i>
                    <h3>Financial Overview</h3>
                </div>
                <span class="section-count-badge">All Modules</span>
            </div>
            
            <div class="section-card-body">
                <div class="mini-stats-grid">
                    <div class="mini-stat">
                        <div class="mini-stat-icon green"><i class="fas fa-arrow-down"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Total Deposits</span>
                            <span class="mini-stat-value positive"><?php echo formatCurrency($data['transactions']['total_deposits'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon red"><i class="fas fa-arrow-up"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Total Withdrawals</span>
                            <span class="mini-stat-value negative"><?php echo formatCurrency($data['transactions']['total_withdrawals'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon blue"><i class="fas fa-percent"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Total Commission</span>
                            <span class="mini-stat-value"><?php echo formatCurrency($data['commissions']['total_commission'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon purple"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Other Income</span>
                            <span class="mini-stat-value"><?php echo formatCurrency($data['commissions']['total_other_income'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon orange"><i class="fas fa-receipt"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Total Expenses</span>
                            <span class="mini-stat-value negative"><?php echo formatCurrency($data['expenses']['total_amount'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon green"><i class="fas fa-chart-line"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Net Profit</span>
                            <span class="mini-stat-value positive"><?php echo formatCurrency($data['daily_reports']['total_profit'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CAPITAL MOVEMENT -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-header-left">
                    <i class="fas fa-building"></i>
                    <h3>Capital Movement</h3>
                </div>
                <span class="section-count-badge"><?php echo number_format($data['capital']['total_count'] ?? 0); ?> records</span>
            </div>
            
            <div class="section-card-body">
                <div class="mini-stats-grid">
                    <div class="mini-stat">
                        <div class="mini-stat-icon blue"><i class="fas fa-flag"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Opening Capital</span>
                            <span class="mini-stat-value"><?php echo formatCurrency($data['capital']['opening'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon green"><i class="fas fa-plus-circle"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Additional Capital</span>
                            <span class="mini-stat-value positive"><?php echo formatCurrency($data['capital']['additional'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon purple"><i class="fas fa-chart-line"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Profit Allocation</span>
                            <span class="mini-stat-value"><?php echo formatCurrency($data['capital']['profit_alloc'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-icon red"><i class="fas fa-arrow-up"></i></div>
                        <div class="mini-stat-info">
                            <span class="mini-stat-label">Cash Out</span>
                            <span class="mini-stat-value negative"><?php echo formatCurrency($data['capital']['cash_out'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TWO COLUMN -->
        <div class="two-col-grid">
            
            <!-- RECENT ACTIVITY -->
            <div class="section-card" style="margin-bottom: 0;">
                <div class="section-card-header">
                    <div class="section-header-left">
                        <i class="fas fa-history"></i>
                        <h3>Recent Activity</h3>
                    </div>
                    <span class="section-count-badge"><?php echo count($data['recent_activity']); ?> recent</span>
                </div>
                
                <div class="section-card-body">
                    <?php if (count($data['recent_activity']) > 0): ?>
                        <div class="activity-list">
                            <?php foreach ($data['recent_activity'] as $log): 
                                $initial = strtoupper(substr($log['employee_name'] ?? 'N', 0, 1));
                            ?>
                                <div class="activity-item">
                                    <div class="activity-avatar"><?php echo $initial; ?></div>
                                    <div class="activity-content">
                                        <span class="activity-title"><?php echo htmlspecialchars($log['action'] ?? 'Action'); ?></span>
                                        <div class="activity-subtitle">
                                            <span class="activity-badge"><?php echo htmlspecialchars($log['module'] ?? 'System'); ?></span>
                                            <span><?php echo htmlspecialchars($log['employee_name'] ?? 'System'); ?></span>
                                        </div>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('d M H:i', strtotime($log['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-mini">
                            <i class="fas fa-history"></i>
                            <p>No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TOP EMPLOYEES -->
            <div class="section-card" style="margin-bottom: 0;">
                <div class="section-card-header">
                    <div class="section-header-left">
                        <i class="fas fa-trophy"></i>
                        <h3>Top Employees</h3>
                    </div>
                    <span class="section-count-badge">by transactions</span>
                </div>
                
                <div class="section-card-body">
                    <?php if (count($data['top_employees']) > 0): ?>
                        <div class="top-list">
                            <?php foreach ($data['top_employees'] as $index => $top): 
                                $rank = $index + 1;
                                $rank_class = $rank <= 3 ? "top-rank-{$rank}" : 'top-rank-other';
                            ?>
                                <div class="top-item">
                                    <div class="top-rank <?php echo $rank_class; ?>"><?php echo $rank; ?></div>
                                    <div class="top-info">
                                        <span class="top-name"><?php echo htmlspecialchars($top['full_name']); ?></span>
                                        <span class="top-sub">
                                            <?php echo number_format($top['txn_count']); ?> transactions
                                        </span>
                                    </div>
                                    <div class="top-value">
                                        <?php echo formatCurrency($top['total_amount']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-mini">
                            <i class="fas fa-trophy"></i>
                            <p>No employee activity in this period</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>

        <!-- QUICK LINKS -->
        <div class="section-card" style="margin-top: 20px;">
            <div class="section-card-header">
                <div class="section-header-left">
                    <i class="fas fa-th-large"></i>
                    <h3>Quick Report Links</h3>
                </div>
                <span class="section-count-badge">12 Modules</span>
            </div>
            
            <div class="section-card-body">
                <div class="quick-links-grid">
                    
                    <a href="daily.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1D4ED8;">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Daily Reports</span>
                            <span class="quick-link-sub">Detailed breakdown</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="transactions.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669;">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Transactions</span>
                            <span class="quick-link-sub">Deposits & withdrawals</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="transfers.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706;">
                            <i class="fas fa-arrows-alt-h"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Transfers</span>
                            <span class="quick-link-sub">Cash ↔ Float</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="expenses.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #DC2626;">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Expenses</span>
                            <span class="quick-link-sub">Business costs</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="salaries.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Salaries</span>
                            <span class="quick-link-sub">Employee payments</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="capital.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #E0E7FF, #C7D2FE); color: #4F46E5;">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Capital</span>
                            <span class="quick-link-sub">Movement analysis</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="commissions.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #CFFAFE, #A5F3FC); color: #0891b2;">
                            <i class="fas fa-percent"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Commissions</span>
                            <span class="quick-link-sub">Provider earnings</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="employees.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #FCE7F3, #FBCFE8); color: #DB2777;">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Employees</span>
                            <span class="quick-link-sub">Performance</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="providers.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669;">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Providers</span>
                            <span class="quick-link-sub">Float analysis</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="branches.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706;">
                            <i class="fas fa-store-alt"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Branches</span>
                            <span class="quick-link-sub">Comparison</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="activity_logs.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #E5E7EB, #D1D5DB); color: #374151;">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Activity Logs</span>
                            <span class="quick-link-sub">Audit trail</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                    <a href="store_cash_out.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="quick-link">
                        <div class="quick-link-icon" style="background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #DC2626;">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="quick-link-info">
                            <span class="quick-link-title">Store Cash Out</span>
                            <span class="quick-link-sub">Cash movements</span>
                        </div>
                        <i class="fas fa-arrow-right quick-link-arrow"></i>
                    </a>
                    
                </div>
            </div>
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 8000);
    }
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
});
</script>

</body>
</html>