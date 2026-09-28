<?php
// ================================================================
// FILE: modules/reports/salaries.php
// WAKALA FINANCIAL SYSTEM - SALARIES REPORT
// 🔴 RED THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ Quick Filters: All, Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom
// ✅ Summary Cards + Status Breakdown
// ✅ Live Search + Scroll buttons
// ✅ Export PDF
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

// ============================================================
// 🔥 QUICK FILTER LOGIC
// ============================================================
$quick = isset($_GET['quick']) ? trim($_GET['quick']) : '';
$today = date('Y-m-d');
$from_date = $today;
$to_date = $today;
$filter_label = 'Today';

if ($quick !== '') {
    switch ($quick) {
        case 'all':
            $from_date = '2000-01-01';
            $to_date = $today;
            $filter_label = 'All Time';
            break;
        case 'today':
            $from_date = $today;
            $to_date = $today;
            $filter_label = 'Today';
            break;
        case '1d':
            $from_date = date('Y-m-d', strtotime('-1 day'));
            $to_date = $today;
            $filter_label = 'Last 1 Day';
            break;
        case '1w':
            $from_date = date('Y-m-d', strtotime('-7 days'));
            $to_date = $today;
            $filter_label = 'Last 1 Week';
            break;
        case '1m':
            $from_date = date('Y-m-d', strtotime('-30 days'));
            $to_date = $today;
            $filter_label = 'Last 1 Month';
            break;
        case '3m':
            $from_date = date('Y-m-d', strtotime('-90 days'));
            $to_date = $today;
            $filter_label = 'Last 3 Months';
            break;
        case '6m':
            $from_date = date('Y-m-d', strtotime('-180 days'));
            $to_date = $today;
            $filter_label = 'Last 6 Months';
            break;
        case '1y':
            $from_date = date('Y-m-d', strtotime('-365 days'));
            $to_date = $today;
            $filter_label = 'Last 1 Year';
            break;
        case 'custom':
            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $today;
            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $today;
            $filter_label = 'Custom Range';
            break;
        default:
            $from_date = $today;
            $to_date = $today;
            $filter_label = 'Today';
    }
} else {
    if (isset($_GET['from_date']) || isset($_GET['to_date'])) {
        $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $today;
        $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $today;
        $filter_label = 'Custom Range';
        $quick = 'custom';
    } else {
        $from_date = $today;
        $to_date = $today;
        $filter_label = 'Today';
        $quick = 'today';
    }
}

$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$selected_status = isset($_GET['status']) ? trim($_GET['status']) : '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) $from_date = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) $to_date = $today;

if (!$is_admin) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_branch = intval($emp['branch_id'] ?? 0);
}

// ============================================================
// BRANCH INFO
// ============================================================
$branch_name = 'All Branches';
$branch_code = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
    }
}

$branches = [];
if ($is_admin) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// GET SALARIES
// ============================================================
$salaries = [];
$summary = [
    'total_count' => 0,
    'total_amount' => 0,
    'paid_amount' => 0,
    'paid_count' => 0,
    'waiting_amount' => 0,
    'waiting_count' => 0,
    'upcoming_amount' => 0,
    'upcoming_count' => 0,
    'cancelled_count' => 0,
    'total_tax' => 0,
    'total_deductions' => 0,
];

try {
    $sql = "
        SELECT 
            es.*,
            e.full_name AS employee_name,
            e.employee_id AS employee_code,
            e.profile_pic AS employee_avatar,
            e.position AS employee_position,
            b.branch_name AS branch_display_name,
            b.branch_code AS branch_display_code
        FROM employee_salaries es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        WHERE es.salary_month BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND es.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($selected_status)) {
        $sql .= " AND es.status = ?";
        $params[] = $selected_status;
    }

    $sql .= " ORDER BY es.salary_month DESC, es.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $salaries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($salaries as $s) {
        $summary['total_count']++;
        $amount = floatval($s['net_pay']);
        $summary['total_amount'] += $amount;
        $summary['total_tax'] += floatval($s['tax']);
        $summary['total_deductions'] += floatval($s['deductions']);

        switch ($s['status']) {
            case 'paid':
                $summary['paid_amount'] += $amount;
                $summary['paid_count']++;
                break;
            case 'waiting':
                $summary['waiting_amount'] += $amount;
                $summary['waiting_count']++;
                break;
            case 'upcoming':
                $summary['upcoming_amount'] += $amount;
                $summary['upcoming_count']++;
                break;
            case 'cancelled':
                $summary['cancelled_count']++;
                break;
        }
    }

    // Monthly breakdown
    $sql_monthly = "
        SELECT 
            DATE_FORMAT(es.salary_month, '%Y-%m') AS month_key,
            DATE_FORMAT(es.salary_month, '%b %Y') AS month_label,
            COUNT(*) AS count,
            COALESCE(SUM(es.net_pay), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.net_pay ELSE 0 END), 0) AS paid_amount
        FROM employee_salaries es
        WHERE es.salary_month BETWEEN ? AND ?
    ";
    $params_monthly = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql_monthly .= " AND es.branch_id = ?";
        $params_monthly[] = $selected_branch;
    }

    $sql_monthly .= " GROUP BY month_key, month_label ORDER BY month_key DESC LIMIT 8";

    $stmt = $db->prepare($sql_monthly);
    $stmt->execute($params_monthly);
    $monthly_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Salaries error: " . $e->getMessage());
    $salaries = [];
    $monthly_breakdown = [];
}

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_header.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_sidebar.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_topbar.php';
?>

<style id="salaries-report-scoped">
/* ============================================================
   🔴 RED THEME VARIABLES
   ============================================================ */
.main-wrapper {
    --bg-body: #fef5f5;
    --bg-card: #ffffff;
    --bg-table-even: #fff5f5;
    --bg-table-hover: #fee2e2;
    --bg-input: #fef2f2;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #fecaca;
    --shadow-color: rgba(220, 38, 38, 0.08);
    --shadow-hover: rgba(220, 38, 38, 0.15);
    
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
    --red-darker: #991B1B;
    --red-mid: #EF4444;
    --red-light: #F87171;
    --red-lighter: #FEE2E2;
    --red-lightest: #FEF2F2;
    --red-accent: #FCA5A5;
}
html.dark-mode .main-wrapper {
    --bg-body: #1a0a0a;
    --bg-card: #2a1515;
    --bg-table-even: #2a1515;
    --bg-table-hover: #3a1d1d;
    --bg-input: #3a1d1d;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #7f1d1d;
    --red-lighter: #7F1D1D;
    --red-lightest: #450A0A;
}

.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after {
    box-sizing: border-box;
}
.main-wrapper { background: var(--bg-body) !important; overflow-x: hidden !important; max-width: 100% !important; }
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
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.main-wrapper .branch-indicator::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08); border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .branch-indicator-left {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    flex: 1; position: relative; z-index: 1; min-width: 0;
}
.main-wrapper .branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.main-wrapper .branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.main-wrapper .branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; }
.main-wrapper .branch-indicator-name { font-weight: 800; font-size: 16px; }
.main-wrapper .branch-indicator-code {
    font-size: 11px; font-weight: 700; padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2); border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: 'Courier New', monospace;
}
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .date-display {
    font-size: 13px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center;
    gap: 6px; font-weight: 600;
}

/* PAGE HEADER */
.main-wrapper .page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.main-wrapper .page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary);
    display: flex; align-items: center; gap: 10px;
}
.main-wrapper .page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.main-wrapper .btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.main-wrapper .btn-primary {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
.main-wrapper .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-secondary {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.main-wrapper .btn-secondary:hover {
    background: var(--red-lighter);
    color: var(--red-dark);
    border-color: var(--red-accent);
}

/* QUICK FILTERS */
.main-wrapper .quick-filters {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    margin-bottom: 12px; padding: 10px 14px;
    background: var(--bg-card); border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .quick-filters-label {
    font-size: 11px; font-weight: 800; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px; margin-right: 6px;
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .quick-filters-label i { color: #DC2626; font-size: 12px; }
.main-wrapper .quick-filter-btn {
    padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800;
    cursor: pointer; text-decoration: none;
    transition: all 0.25s ease;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card); color: var(--text-secondary);
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap; font-family: 'Inter', sans-serif;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.main-wrapper .quick-filter-btn i { font-size: 11px; }
.main-wrapper .quick-filter-btn:hover {
    background: var(--red-lighter);
    border-color: var(--red-accent);
    color: var(--red-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.15);
}
.main-wrapper .quick-filter-btn.active {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border-color: #991B1B; color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.4);
    transform: translateY(-2px);
}
.main-wrapper .quick-filter-btn.active i { color: #FCD34D; }

/* SUMMARY CARDS */
.main-wrapper .summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.main-wrapper .summary-card {
    position: relative; border-radius: 14px; padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0; overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.main-wrapper .summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(220, 38, 38, 0.15);
}
.main-wrapper .summary-card-red {
    background: rgba(220, 38, 38, 0.08); border-color: rgba(220, 38, 38, 0.2);
}
.main-wrapper .summary-card-red .summary-icon {
    background: rgba(220, 38, 38, 0.15); color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.main-wrapper .summary-card-red .summary-value { color: #991B1B; }

.main-wrapper .summary-card-green {
    background: rgba(5, 150, 105, 0.08); border-color: rgba(5, 150, 105, 0.2);
}
.main-wrapper .summary-card-green .summary-icon {
    background: rgba(5, 150, 105, 0.15); color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.main-wrapper .summary-card-green .summary-value { color: #047857; }

.main-wrapper .summary-card-orange {
    background: rgba(217, 119, 6, 0.08); border-color: rgba(217, 119, 6, 0.2);
}
.main-wrapper .summary-card-orange .summary-icon {
    background: rgba(217, 119, 6, 0.15); color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.main-wrapper .summary-card-orange .summary-value { color: #B45309; }

.main-wrapper .summary-card-purple {
    background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2);
}
.main-wrapper .summary-card-purple .summary-icon {
    background: rgba(124, 58, 237, 0.15); color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.main-wrapper .summary-card-purple .summary-value { color: #6D28D9; }

html.dark-mode .main-wrapper .summary-card-red { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .main-wrapper .summary-card-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .main-wrapper .summary-card-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .main-wrapper .summary-card-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .main-wrapper .summary-card-red .summary-value { color: #FCA5A5; }
html.dark-mode .main-wrapper .summary-card-green .summary-value { color: #6EE7B7; }
html.dark-mode .main-wrapper .summary-card-orange .summary-value { color: #FBBF24; }
html.dark-mode .main-wrapper .summary-card-purple .summary-value { color: #C4B5FD; }

.main-wrapper .summary-icon {
    width: 50px; height: 50px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0; transition: all 0.3s ease;
}
.main-wrapper .summary-card:hover .summary-icon { transform: scale(1.08) rotate(-4deg); }
.main-wrapper .summary-info {
    display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px;
}
.main-wrapper .summary-label {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.main-wrapper .summary-value {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2; word-break: break-word;
}
.main-wrapper .summary-sub {
    font-size: 10px; font-weight: 600; color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;
}

/* MONTHLY BREAKDOWN */
.main-wrapper .category-section {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    padding: 18px 20px; margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .category-section-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
}
.main-wrapper .category-section-title {
    font-size: 14px; font-weight: 800; color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.main-wrapper .category-section-title i { color: #DC2626; }
.main-wrapper .category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.main-wrapper .category-card-mini {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
    min-width: 0;
}
.main-wrapper .category-card-mini:hover {
    transform: translateY(-2px);
    border-color: #DC2626;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.15);
}
.main-wrapper .category-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .category-icon {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.main-wrapper .category-info {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 2px;
}
.main-wrapper .category-name {
    font-size: 13px; font-weight: 800; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.main-wrapper .category-count {
    font-size: 10px; color: var(--text-muted); font-weight: 600;
}
.main-wrapper .category-amount {
    font-size: 13px; font-weight: 900;
    color: #DC2626;
    font-family: 'Inter', 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .main-wrapper .category-amount { color: #FCA5A5; }

/* FILTER BAR */
.main-wrapper .filter-bar {
    background: var(--bg-card); border-radius: 12px;
    padding: 14px 18px; margin-bottom: 18px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .filter-form {
    display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
}
.main-wrapper .filter-group {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 160px; flex: 1;
}
.main-wrapper .filter-group label {
    font-size: 11px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .filter-group label i { color: #DC2626; }
.main-wrapper .form-control {
    padding: 10px 14px; border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 13px;
    color: var(--text-primary); background: var(--bg-input);
    transition: all 0.3s ease; font-family: 'Inter', sans-serif;
    width: 100%;
}
.main-wrapper .form-control:focus {
    outline: none; border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.main-wrapper .filter-actions { display: flex; gap: 8px; }

/* TABLE */
.main-wrapper .table-container {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .table-header {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    padding: 16px 20px; color: #FFFFFF;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px;
    position: relative; overflow: hidden;
}
.main-wrapper .table-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .table-header-left {
    display: flex; align-items: center; gap: 12px;
    position: relative; z-index: 1; flex-shrink: 0;
}
.main-wrapper .table-header-left i {
    font-size: 22px;
    background: rgba(255,255,255,0.18);
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
    flex-shrink: 0;
}
.main-wrapper .table-header h3 {
    font-size: 16px; font-weight: 800;
    margin: 0; white-space: nowrap;
}
.main-wrapper .count-badge {
    background: rgba(255,255,255,0.22);
    padding: 4px 12px; border-radius: 10px;
    font-size: 11px; font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    white-space: nowrap;
}
.main-wrapper .table-header-right {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; position: relative; z-index: 1;
    flex: 1; justify-content: flex-end; min-width: 0;
}
.main-wrapper .table-search-live {
    position: relative; display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.98);
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 10px; padding: 8px 14px;
    min-width: 240px; max-width: 300px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.main-wrapper .table-search-live:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
}
.main-wrapper .table-search-live > i {
    color: #DC2626; font-size: 13px; flex-shrink: 0;
}
.main-wrapper .table-search-live input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1e293b; outline: none; min-width: 0; font-weight: 500;
}
.main-wrapper .table-search-live input::placeholder { color: #94a3b8; font-size: 12px; }
.main-wrapper .table-search-live .search-clear-btn {
    width: 22px; height: 22px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626; border: none;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; font-size: 10px;
    transition: all 0.2s ease; flex-shrink: 0;
}
.main-wrapper .table-search-live .search-clear-btn:hover {
    background: #DC2626; color: #FFFFFF;
}
.main-wrapper .table-search-live .search-count-badge {
    font-size: 10px; font-weight: 800; padding: 3px 9px;
    background: #FCD34D; color: #78350F;
    border-radius: 8px; white-space: nowrap; flex-shrink: 0;
}
.main-wrapper .table-scroll-buttons {
    display: flex; align-items: center; gap: 6px; flex-shrink: 0;
}
.main-wrapper .scroll-btn {
    width: 38px; height: 38px; border-radius: 10px;
    border: 2px solid #FFFFFF; background: #FFFFFF;
    color: #DC2626; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0; padding: 0; line-height: 1;
}
.main-wrapper .scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D; transform: translateY(-2px);
}
.main-wrapper .scroll-btn i { font-size: 14px; display: block; line-height: 1; }

.main-wrapper .table-wrapper {
    overflow-x: auto !important;
    overflow-y: hidden;
    max-width: 100% !important;
    width: 100% !important;
    -webkit-overflow-scrolling: touch;
    display: block;
    scroll-behavior: smooth;
}
.main-wrapper .table-wrapper::-webkit-scrollbar { height: 8px; }
.main-wrapper .table-wrapper::-webkit-scrollbar-track {
    background: var(--bg-table-even); border-radius: 4px;
}
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border-radius: 4px;
}
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #B91C1C, #991B1B);
}

.main-wrapper .data-table {
    width: 100%; border-collapse: collapse;
    font-size: 12px; min-width: 1300px;
}
.main-wrapper .data-table thead tr { background: var(--bg-table-even); }
.main-wrapper .data-table thead th {
    padding: 14px 12px; text-align: left; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase;
    font-size: 10px; letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.main-wrapper .data-table thead th.text-right { text-align: right; }
.main-wrapper .data-table thead th.text-center { text-align: center; }
.main-wrapper .data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.main-wrapper .data-table tbody tr:hover { background: var(--bg-table-hover); }
.main-wrapper .data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.main-wrapper .data-table tbody td {
    padding: 12px;
    color: var(--text-primary);
    vertical-align: middle;
}
.main-wrapper .data-table tbody td.text-right { text-align: right; }
.main-wrapper .data-table tbody td.text-center { text-align: center; }

.main-wrapper .data-table tbody tr.search-match {
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important;
    border-left: 4px solid #F59E0B;
}
.main-wrapper .data-table tbody tr.search-hidden { display: none !important; }
.main-wrapper .data-table mark {
    background: #FEF08A; color: #78350F;
    padding: 1px 3px; border-radius: 3px;
    font-weight: 800;
}

.main-wrapper .row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--red-lighter); font-size: 11px; font-weight: 800;
    color: var(--red-dark); border: 1.5px solid var(--red-accent);
}
html.dark-mode .main-wrapper .row-number {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

.main-wrapper .date-cell {
    display: inline-flex; flex-direction: column; gap: 2px;
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary); white-space: nowrap;
}
.main-wrapper .date-main {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px; font-weight: 800;
    color: #DC2626;
}
html.dark-mode .main-wrapper .date-main { color: #FCA5A5; }

.main-wrapper .salary-number {
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 800;
    color: #DC2626; background: #FEE2E2;
    padding: 3px 10px; border-radius: 6px;
    white-space: nowrap;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .salary-number {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

.main-wrapper .employee-cell {
    display: flex; align-items: center; gap: 8px;
    min-width: 0;
}
.main-wrapper .employee-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 12px; color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border: 2px solid #FCA5A5;
    object-fit: cover;
}
.main-wrapper .employee-info {
    display: flex; flex-direction: column; gap: 2px; min-width: 0;
}
.main-wrapper .employee-name-sm {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 140px;
}
.main-wrapper .employee-position {
    font-size: 9px; color: var(--text-muted);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 140px;
}

.main-wrapper .branch-cell {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 100px;
}
.main-wrapper .branch-name {
    font-size: 12px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}
.main-wrapper .branch-code-sm {
    font-size: 9px; font-weight: 700;
    color: #DC2626; font-family: 'Courier New', monospace;
    background: #FEE2E2; padding: 1px 6px;
    border-radius: 4px; align-self: flex-start;
}
html.dark-mode .main-wrapper .branch-code-sm {
    background: #7F1D1D; color: #FCA5A5;
}

.main-wrapper .amount-badge {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 8px;
    font-weight: 900; font-size: 12px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.main-wrapper .amount-net {
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
.main-wrapper .amount-gross {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
html.dark-mode .main-wrapper .amount-net {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

.main-wrapper .status-badge-sm {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1.5px solid;
}
.main-wrapper .status-paid {
    background: #DCFCE7; color: #15803D;
    border-color: #86EFAC;
}
.main-wrapper .status-waiting {
    background: #FEF3C7; color: #B45309;
    border-color: #FCD34D;
}
.main-wrapper .status-upcoming {
    background: #DBEAFE; color: #1D4ED8;
    border-color: #93C5FD;
}
.main-wrapper .status-cancelled {
    background: #FEE2E2; color: #991B1B;
    border-color: #FCA5A5;
}
html.dark-mode .main-wrapper .status-paid { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .main-wrapper .status-waiting { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .main-wrapper .status-upcoming { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .main-wrapper .status-cancelled { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.main-wrapper .data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%) !important;
    border-top: 3px solid #DC2626;
    border-bottom: 3px solid #DC2626;
}
html.dark-mode .main-wrapper .data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 100%) !important;
}
.main-wrapper .data-table tbody tr.totals-row td {
    padding: 14px 12px;
    font-weight: 900;
    color: var(--text-primary);
    font-size: 12px;
}

.main-wrapper .empty-state {
    text-align: center; padding: 60px 20px;
}
.main-wrapper .empty-state i {
    font-size: 56px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 16px;
}
.main-wrapper .empty-state h3 {
    font-size: 18px; color: var(--text-primary);
    margin: 0 0 8px 0;
}
.main-wrapper .empty-state p {
    color: var(--text-muted);
    font-size: 14px; margin: 0 0 16px 0;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .main-wrapper .summary-cards { grid-template-columns: repeat(2, 1fr); }
    .main-wrapper .data-table { min-width: 1100px; }
}
@media (max-width: 900px) {
    .main-wrapper .table-header { flex-direction: column; align-items: stretch; }
    .main-wrapper .table-header-right {
        width: 100%; justify-content: space-between; flex-wrap: wrap;
    }
    .main-wrapper .table-search-live { min-width: 0; max-width: none; flex: 1; }
}
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .main-wrapper .branch-indicator { flex-direction: column; align-items: flex-start; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .header-right .btn { flex: 1; justify-content: center; }
    .main-wrapper .summary-cards { grid-template-columns: 1fr; }
    .main-wrapper .filter-form { flex-direction: column; }
    .main-wrapper .filter-group { min-width: 100%; }
    .main-wrapper .filter-actions { width: 100%; }
    .main-wrapper .filter-actions .btn { flex: 1; justify-content: center; }
    .main-wrapper .data-table { min-width: 1000px; }
    .main-wrapper .quick-filters { padding: 8px 10px; gap: 5px; }
    .main-wrapper .quick-filter-btn { padding: 7px 12px; font-size: 11px; flex: 1; justify-content: center; }
}
@media (max-width: 480px) {
    .main-wrapper .summary-value { font-size: 16px; }
    .main-wrapper .summary-icon { width: 44px; height: 44px; font-size: 18px; }
    .main-wrapper .table-search-live { width: 100%; }
    .main-wrapper .table-scroll-buttons { width: 100%; justify-content: center; }
    .main-wrapper .scroll-btn { width: 42px; height: 42px; font-size: 16px; }
    .main-wrapper .quick-filter-btn { padding: 6px 10px; font-size: 10px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-users"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Salaries Report</span>
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
                <h2><i class="fas fa-users" style="color:#DC2626;"></i> Salaries Report</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($from_date)); ?> — <?php echo date('d M Y', strtotime($to_date)); ?>
                    •
                    <i class="fas fa-filter"></i>
                    <?php echo htmlspecialchars($filter_label); ?>
                    •
                    <i class="fas fa-list"></i>
                    <?php echo number_format($summary['total_count']); ?> salaries
                </p>
            </div>
            <div class="header-right">
                <a href="export.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&type=salaries&format=pdf" 
                   class="btn btn-primary" target="_blank">
                    <i class="fas fa-file-pdf"></i> Export
                </a>
                <a href="index.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>" 
                   class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div style="padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- QUICK FILTERS -->
        <div class="quick-filters">
            <span class="quick-filters-label">
                <i class="fas fa-bolt"></i>
                Quick Filter:
            </span>
            
            <?php
            $quick_options = [
                ['key' => 'all',    'label' => 'All',     'icon' => 'fa-infinity'],
                ['key' => 'today',  'label' => 'Today',   'icon' => 'fa-calendar-day'],
                ['key' => '1d',     'label' => '1D',      'icon' => 'fa-clock'],
                ['key' => '1w',     'label' => '1W',      'icon' => 'fa-calendar-week'],
                ['key' => '1m',     'label' => '1M',      'icon' => 'fa-calendar-alt'],
                ['key' => '3m',     'label' => '3M',      'icon' => 'fa-calendar-alt'],
                ['key' => '6m',     'label' => '6M',      'icon' => 'fa-calendar-alt'],
                ['key' => '1y',     'label' => '1Y',      'icon' => 'fa-calendar'],
                ['key' => 'custom', 'label' => 'Custom',  'icon' => 'fa-sliders-h'],
            ];
            
            foreach ($quick_options as $opt):
                $url = '?' . http_build_query([
                    'quick' => $opt['key'],
                    'branch_id' => $selected_branch,
                    'status' => $selected_status,
                    'from_date' => ($opt['key'] === 'custom') ? $from_date : '',
                    'to_date'   => ($opt['key'] === 'custom') ? $to_date : '',
                ]);
                $is_active = ($quick === $opt['key']);
            ?>
                <a href="<?php echo $url; ?>" 
                   class="quick-filter-btn <?php echo $is_active ? 'active' : ''; ?>"
                   title="<?php echo htmlspecialchars($opt['label']); ?> filter">
                    <i class="fas <?php echo $opt['icon']; ?>"></i>
                    <?php echo htmlspecialchars($opt['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards">
            <div class="summary-card summary-card-red">
                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Salaries</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['total_count']); ?> records
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-green">
                <div class="summary-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Paid</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['paid_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['paid_count']); ?> paid
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-orange">
                <div class="summary-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Waiting</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['waiting_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['waiting_count']); ?> waiting
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-purple">
                <div class="summary-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Upcoming</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['upcoming_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['upcoming_count']); ?> upcoming
                    </span>
                </div>
            </div>
        </div>

        <!-- MONTHLY BREAKDOWN -->
        <?php if (count($monthly_breakdown) > 0): ?>
        <div class="category-section">
            <div class="category-section-header">
                <div class="category-section-title">
                    <i class="fas fa-chart-bar"></i>
                    Monthly Breakdown
                </div>
                <span style="font-size: 11px; font-weight: 800; background: #FEE2E2; color: #991B1B; padding: 4px 12px; border-radius: 8px; border: 1.5px solid #FCA5A5;">
                    <?php echo count($monthly_breakdown); ?> months
                </span>
            </div>
            <div class="category-grid">
                <?php foreach ($monthly_breakdown as $mb): ?>
                    <a href="?quick=custom&from_date=<?php echo $mb['month_key']; ?>-01&to_date=<?php echo date('Y-m-t', strtotime($mb['month_key'] . '-01')); ?>&branch_id=<?php echo $selected_branch; ?>" 
                       class="category-card-mini" style="text-decoration: none;">
                        <div class="category-icon">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($mb['month_label']); ?></span>
                            <span class="category-count"><?php echo number_format($mb['count']); ?> employees</span>
                        </div>
                        <span class="category-amount"><?php echo formatCurrency($mb['total_amount']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quick); ?>">
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> From</label>
                    <input type="date" name="from_date" class="form-control" 
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> To</label>
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
                
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="paid" <?php echo $selected_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="waiting" <?php echo $selected_status === 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                        <option value="upcoming" <?php echo $selected_status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="cancelled" <?php echo $selected_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-header-left">
                    <i class="fas fa-list-alt"></i>
                    <h3>Salaries</h3>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($salaries); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search salaries..."
                               oninput="performLiveSearch(this.value)"
                               autocomplete="off">
                        <button type="button" 
                                class="search-clear-btn" 
                                id="searchClearBtn" 
                                onclick="clearLiveSearch()" 
                                style="display:none;"
                                title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count-badge" 
                              id="searchCountBadge" 
                              style="display:none;">0</span>
                    </div>
                    
                    <div class="table-scroll-buttons">
                        <button type="button" class="scroll-btn" onclick="scrollTable('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button type="button" class="scroll-btn" onclick="scrollTable('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (count($salaries) > 0): ?>
                <div class="table-wrapper" id="tableWrapper">
                    <table class="data-table" id="salariesTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Salary #</th>
                                <th>Month</th>
                                <th>Employee</th>
                                <th class="text-right">Base</th>
                                <th class="text-right">Bonus</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">Tax</th>
                                <th class="text-right">Deductions</th>
                                <th class="text-right">Net Pay</th>
                                <th class="text-center">Status</th>
                                <th>Branch</th>
                            </tr>
                        </thead>
                        <tbody id="salariesTableBody">
                            <?php $i = 1; foreach ($salaries as $s): 
                                $month_display = date('M Y', strtotime($s['salary_month']));
                                $emp_initial = strtoupper(substr($s['employee_name'] ?? 'N', 0, 1));
                                $emp_avatar = $s['employee_avatar'] ?? '';
                                $status = $s['status'] ?? 'waiting';
                                
                                $search_text = strtolower(
                                    ($s['salary_number'] ?? '') . ' ' . 
                                    ($s['employee_name'] ?? '') . ' ' . 
                                    ($s['employee_position'] ?? '') . ' ' .
                                    ($s['branch_display_name'] ?? '') . ' ' .
                                    $status . ' ' . $month_display . ' ' .
                                    $s['net_pay']
                                );
                            ?>
                                <tr class="salary-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <span class="salary-number"><?php echo htmlspecialchars($s['salary_number']); ?></span>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date-main"><?php echo $month_display; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                                                <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                                     alt="" class="employee-avatar">
                                            <?php else: ?>
                                                <div class="employee-avatar"><?php echo $emp_initial; ?></div>
                                            <?php endif; ?>
                                            <div class="employee-info">
                                                <span class="employee-name-sm"><?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?></span>
                                                <?php if (!empty($s['employee_position'])): ?>
                                                    <span class="employee-position"><?php echo htmlspecialchars($s['employee_position']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['base_salary']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['bonus']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['allowances']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['total_gross']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['tax']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-gross">
                                            <?php echo formatCurrency($s['deductions']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge amount-net">
                                            <?php echo formatCurrency($s['net_pay']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $status_classes = [
                                            'paid' => 'status-paid',
                                            'waiting' => 'status-waiting',
                                            'upcoming' => 'status-upcoming',
                                            'cancelled' => 'status-cancelled',
                                        ];
                                        $status_icons = [
                                            'paid' => 'check-circle',
                                            'waiting' => 'clock',
                                            'upcoming' => 'calendar-check',
                                            'cancelled' => 'times-circle',
                                        ];
                                        $s_class = $status_classes[$status] ?? 'status-waiting';
                                        $s_icon = $status_icons[$status] ?? 'clock';
                                        ?>
                                        <span class="status-badge-sm <?php echo $s_class; ?>">
                                            <i class="fas fa-<?php echo $s_icon; ?>"></i>
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="branch-cell">
                                            <span class="branch-name"><?php echo htmlspecialchars($s['branch_display_name'] ?? 'Main'); ?></span>
                                            <?php if (!empty($s['branch_display_code'])): ?>
                                                <span class="branch-code-sm"><?php echo htmlspecialchars($s['branch_display_code']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="totals-row">
                                <td colspan="10" style="text-align:right;">TOTAL (<?php echo number_format($summary['total_count']); ?> salaries)</td>
                                <td class="text-right">
                                    <span style="color: #DC2626; font-weight: 900; font-size: 14px;">
                                        <?php echo formatCurrency($summary['total_amount']); ?>
                                    </span>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="empty-state" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                    <p>No salaries match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Salaries Found</h3>
                    <p>No salaries for this period (<?php echo htmlspecialchars($filter_label); ?>).</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Try changing the quick filter, date range, branch, or status.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
</div>

<script>
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('salariesTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.salary-row');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResults = document.getElementById('noSearchResults');
    const tableWrapper = document.getElementById('tableWrapper');
    
    const term = searchTerm.trim();
    
    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    
    if (term.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeAllMarks(row);
        });
        if (countBadge) { countBadge.style.display = 'none'; countBadge.textContent = '0'; }
        if (noResults) noResults.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = '';
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    
    rows.forEach(row => {
        removeAllMarks(row);
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();
        
        if (searchText.includes(searchLower) || rowText.includes(searchLower)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatchesInRow(row, term);
            matchCount++;
        } else {
            row.classList.add('search-hidden');
            row.classList.remove('search-match');
        }
    });
    
    if (countBadge) {
        countBadge.textContent = matchCount;
        countBadge.style.display = matchCount > 0 ? 'inline-block' : 'none';
    }
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
    if (tableWrapper) tableWrapper.style.display = matchCount === 0 ? 'none' : '';
}

function removeAllMarks(row) {
    const marks = row.querySelectorAll('mark');
    if (marks.length === 0) return;
    marks.forEach(mark => {
        if (mark.parentNode) {
            const textNode = document.createTextNode(mark.textContent);
            mark.parentNode.replaceChild(textNode, mark);
        }
    });
    const cells = row.querySelectorAll('td');
    cells.forEach(cell => cell.normalize());
}

function highlightMatchesInRow(row, term) {
    if (!term || term.length === 0) return;
    const searchLower = term.toLowerCase();
    const termLength = term.length;
    const cells = row.querySelectorAll('td');
    
    cells.forEach(cell => {
        if (cell.querySelector('button') || cell.querySelector('a')) return;
        if (cell.querySelector('img') && cell.textContent.trim() === '') return;
        
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node) {
                if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        
        textNodes.forEach(textNode => {
            const text = textNode.textContent;
            const lowerText = text.toLowerCase();
            if (!lowerText.includes(searchLower)) return;
            
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let index = lowerText.indexOf(searchLower);
            
            while (index !== -1) {
                if (index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex, index)));
                }
                const mark = document.createElement('mark');
                mark.textContent = text.substring(index, index + termLength);
                fragment.appendChild(mark);
                lastIndex = index + termLength;
                index = lowerText.indexOf(searchLower, lastIndex);
            }
            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
            }
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    });
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) { input.value = ''; performLiveSearch(''); input.focus(); }
}

function scrollTable(direction) {
    const wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({
        left: direction === 'left' ? -350 : 350,
        behavior: 'smooth'
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const i = document.getElementById('liveSearchInput');
        if (i && i.value.length > 0) clearLiveSearch();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const i = document.getElementById('liveSearchInput');
        if (i) { i.focus(); i.select(); }
    }
});

document.addEventListener('DOMContentLoaded', function() {
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