<?php
// ================================================================
// FILE: modules/reports/expenses.php
// WAKALA FINANCIAL SYSTEM - EXPENSES REPORT
// 🔴 RED THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ Quick Filter Buttons: All, Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom
// ✅ Detailed expenses with category breakdown
// ✅ Summary cards + Category + Provider breakdown
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
// Kama mtumiaji amebonyeza quick filter (e.g. ?quick=today)
// basi tunahesabu from_date/to_date automatically

$quick = isset($_GET['quick']) ? trim($_GET['quick']) : '';
$today = date('Y-m-d');

// Default values
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
    // Kama hakuna quick filter, tumia from_date/to_date kutoka URL
    // Kama hazipo, tumia Today kama default
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
$selected_category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Validate dates
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

// Get expense categories
$categories = [];
try {
    $stmt = $db->prepare("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// ============================================================
// GET EXPENSES
// ============================================================
$expenses = [];
$summary = [
    'total_count' => 0,
    'total_amount' => 0,
    'business_amount' => 0,
    'non_business_amount' => 0,
    'salary_amount' => 0,
    'other_amount' => 0,
];

try {
    $sql = "
        SELECT 
            ex.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code
        FROM expenses ex
        LEFT JOIN employees e ON ex.employee_id = e.id
        LEFT JOIN branches b ON ex.branch_id = b.id
        WHERE ex.expense_date BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND ex.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($selected_category)) {
        $sql .= " AND ex.category = ?";
        $params[] = $selected_category;
    }

    $sql .= " ORDER BY ex.expense_date DESC, ex.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary
    foreach ($expenses as $ex) {
        $summary['total_count']++;
        $amount = floatval($ex['amount']);
        $summary['total_amount'] += $amount;

        if (!empty($ex['is_business_expense'])) {
            $summary['business_amount'] += $amount;
        } else {
            $summary['non_business_amount'] += $amount;
        }

        if (!empty($ex['is_salary_related'])) {
            $summary['salary_amount'] += $amount;
        } else {
            $summary['other_amount'] += $amount;
        }
    }

    // Category Breakdown
    $sql_categories = "
        SELECT 
            ex.category,
            COUNT(*) as count,
            COALESCE(SUM(ex.amount), 0) as total_amount
        FROM expenses ex
        WHERE ex.expense_date BETWEEN ? AND ?
    ";
    $params_cat = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql_categories .= " AND ex.branch_id = ?";
        $params_cat[] = $selected_branch;
    }

    $sql_categories .= " GROUP BY ex.category ORDER BY total_amount DESC LIMIT 8";

    $stmt = $db->prepare($sql_categories);
    $stmt->execute($params_cat);
    $category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Expenses error: " . $e->getMessage());
    $expenses = [];
    $category_breakdown = [];
}

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Helper function to build URL with filter params
function buildFilterUrl($quick_type, $from_date, $to_date, $branch_id, $category = '') {
    $params = [
        'quick' => $quick_type,
        'from_date' => $from_date,
        'to_date' => $to_date,
        'branch_id' => $branch_id,
    ];
    if (!empty($category)) {
        $params['category'] = $category;
    }
    return '?' . http_build_query($params);
}

include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_header.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_sidebar.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_topbar.php';
?>

<style id="expenses-report-scoped">
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

/* ============================================================
   🔴 BRANCH INDICATOR
   ============================================================ */
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

/* BUTTONS */
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

/* ============================================================
   🔥 QUICK FILTER BUTTONS - RED THEME
   ============================================================ */
.main-wrapper .quick-filters {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    padding: 10px 14px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .quick-filters-label {
    font-size: 11px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-right: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.main-wrapper .quick-filters-label i {
    color: #DC2626;
    font-size: 12px;
}
.main-wrapper .quick-filter-btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.25s ease;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.main-wrapper .quick-filter-btn i {
    font-size: 11px;
}
.main-wrapper .quick-filter-btn:hover {
    background: var(--red-lighter);
    border-color: var(--red-accent);
    color: var(--red-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.15);
}
.main-wrapper .quick-filter-btn.active {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border-color: #991B1B;
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.4);
    transform: translateY(-2px);
}
.main-wrapper .quick-filter-btn.active i {
    color: #FCD34D;
}
.main-wrapper .quick-filter-btn.active:hover {
    background: linear-gradient(135deg, #B91C1C, #991B1B);
    color: #FFFFFF;
}

/* Dark mode */
html.dark-mode .main-wrapper .quick-filter-btn {
    background: var(--bg-card);
    border-color: var(--border-color);
    color: var(--text-secondary);
}
html.dark-mode .main-wrapper .quick-filter-btn:hover {
    background: var(--red-lighter);
    color: #FCA5A5;
    border-color: #DC2626;
}
html.dark-mode .main-wrapper .quick-filter-btn.active {
    background: linear-gradient(135deg, #DC2626, #991B1B);
    color: #FFFFFF;
    border-color: #FCA5A5;
}

/* ============================================================
   🔴 SUMMARY CARDS
   ============================================================ */
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
.main-wrapper .summary-card-blue {
    background: rgba(220, 38, 38, 0.08); border-color: rgba(220, 38, 38, 0.2);
}
.main-wrapper .summary-card-blue .summary-icon {
    background: rgba(220, 38, 38, 0.15); color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.main-wrapper .summary-card-blue .summary-value { color: #991B1B; }
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

html.dark-mode .main-wrapper .summary-card-blue { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .main-wrapper .summary-card-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .main-wrapper .summary-card-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .main-wrapper .summary-card-blue .summary-value { color: #FCA5A5; }
html.dark-mode .main-wrapper .summary-card-green .summary-value { color: #6EE7B7; }
html.dark-mode .main-wrapper .summary-card-orange .summary-value { color: #FBBF24; }

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

/* CATEGORY BREAKDOWN */
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

/* TABLE CONTAINER */
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
.main-wrapper .table-search-live input::placeholder {
    color: #94a3b8; font-size: 12px;
}
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

/* TABLE WRAPPER */
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

/* DATA TABLE */
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

/* ROW NUMBER */
.main-wrapper .row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--red-lighter); font-size: 11px; font-weight: 800;
    color: var(--red-dark); border: 1.5px solid var(--red-accent);
}
html.dark-mode .main-wrapper .row-number {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* DATE CELL */
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
.main-wrapper .time-sub {
    font-size: 10px; color: var(--text-muted);
}

/* EXPENSE NUMBER */
.main-wrapper .expense-number {
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 800;
    color: #DC2626; background: #FEE2E2;
    padding: 3px 10px; border-radius: 6px;
    white-space: nowrap;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .expense-number {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* CATEGORY BADGE */
.main-wrapper .category-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .category-badge {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* EXPENSE NAME */
.main-wrapper .expense-name-cell {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0;
}
.main-wrapper .expense-name-text {
    font-size: 12px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 220px;
}
.main-wrapper .expense-desc {
    font-size: 10px; color: var(--text-muted);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 220px;
}

/* AMOUNT */
.main-wrapper .amount-expense {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 8px;
    font-weight: 900; font-size: 12px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .amount-expense {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* STATUS BADGES */
.main-wrapper .status-badge-sm {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 6px;
    font-size: 9px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.main-wrapper .status-business {
    background: #DCFCE7; color: #15803D;
    border: 1.5px solid #86EFAC;
}
.main-wrapper .status-non-business {
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
.main-wrapper .status-salary {
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.main-wrapper .status-other {
    background: #FEF3C7; color: #B45309;
    border: 1.5px solid #FCD34D;
}
html.dark-mode .main-wrapper .status-business { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .main-wrapper .status-non-business { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .main-wrapper .status-salary { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .main-wrapper .status-other { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

/* BRANCH CELL */
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

/* EMPLOYEE CELL */
.main-wrapper .employee-cell {
    display: flex; align-items: center; gap: 8px;
    min-width: 0;
}
.main-wrapper .employee-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 12px; color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border: 2px solid #FCA5A5;
    object-fit: cover;
}
.main-wrapper .employee-name-sm {
    font-size: 12px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 120px;
}

/* RECEIPT */
.main-wrapper .receipt-cell {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px;
    background: #F0FDF4; color: #059669;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-decoration: none;
    border: 1.5px solid #BBF7D0;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.main-wrapper .receipt-cell:hover {
    background: #059669; color: #FFFFFF;
    border-color: #059669;
}
.main-wrapper .no-receipt {
    font-size: 10px; color: var(--text-light);
    font-style: italic;
}
html.dark-mode .main-wrapper .receipt-cell {
    background: #14532D; color: #4ADE80; border-color: #16A34A;
}
html.dark-mode .main-wrapper .receipt-cell:hover {
    background: #059669; color: #FFFFFF;
}

/* TOTALS ROW */
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

/* EMPTY STATE */
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
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Expenses Report</span>
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
                <h2><i class="fas fa-receipt" style="color:#DC2626;"></i> Expenses Report</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($from_date)); ?> — <?php echo date('d M Y', strtotime($to_date)); ?>
                    •
                    <i class="fas fa-filter"></i>
                    <?php echo htmlspecialchars($filter_label); ?>
                    •
                    <i class="fas fa-list"></i>
                    <?php echo number_format($summary['total_count']); ?> expenses
                </p>
            </div>
            <div class="header-right">
                <a href="export.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&format=pdf" 
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

        <!-- 🔥 QUICK FILTER BUTTONS -->
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
                    'category' => $selected_category,
                    // Kwa custom, tunahifadhi from_date/to_date za sasa
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
            <div class="summary-card summary-card-blue">
                <div class="summary-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Expenses</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['total_count']); ?> records
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-green">
                <div class="summary-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Business Expenses</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['business_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-check-circle"></i>
                        Deductible
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-blue">
                <div class="summary-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Salary Related</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['salary_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-user-tie"></i>
                        Employee payments
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-orange">
                <div class="summary-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Other Expenses</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['other_amount']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        Non-salary
                    </span>
                </div>
            </div>
        </div>

        <!-- CATEGORY BREAKDOWN -->
        <?php if (count($category_breakdown) > 0): ?>
        <div class="category-section">
            <div class="category-section-header">
                <div class="category-section-title">
                    <i class="fas fa-chart-pie"></i>
                    Top Expense Categories
                </div>
                <span style="font-size: 11px; font-weight: 800; background: #FEE2E2; color: #991B1B; padding: 4px 12px; border-radius: 8px; border: 1.5px solid #FCA5A5;">
                    <?php echo count($category_breakdown); ?> categories
                </span>
            </div>
            <div class="category-grid">
                <?php foreach ($category_breakdown as $cat): ?>
                    <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&category=<?php echo urlencode($cat['category']); ?>" 
                       class="category-card-mini" style="text-decoration: none;">
                        <div class="category-icon">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                            <span class="category-count"><?php echo number_format($cat['count']); ?> transactions</span>
                        </div>
                        <span class="category-amount"><?php echo formatCurrency($cat['total_amount']); ?></span>
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
                    <label><i class="fas fa-filter"></i> Category</label>
                    <select name="category" class="form-control">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['category_name']); ?>" 
                                <?php echo $selected_category === $c['category_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
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
                    <h3>Expenses<?php echo !empty($selected_category) ? ' - ' . htmlspecialchars($selected_category) : ''; ?></h3>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($expenses); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search expenses..."
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
            
            <?php if (count($expenses) > 0): ?>
                <div class="table-wrapper" id="tableWrapper">
                    <table class="data-table" id="expensesTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date</th>
                                <th>Expense #</th>
                                <th>Category</th>
                                <th>Expense Name</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Type</th>
                                <th>Receipt</th>
                                <th>Branch</th>
                                <th>Employee</th>
                            </tr>
                        </thead>
                        <tbody id="expensesTableBody">
                            <?php $i = 1; foreach ($expenses as $ex): 
                                $ex_date = date('d M Y', strtotime($ex['expense_date']));
                                $emp_initial = strtoupper(substr($ex['employee_name'] ?? 'N', 0, 1));
                                $emp_avatar = $ex['employee_avatar'] ?? '';
                                $is_business = !empty($ex['is_business_expense']);
                                $is_salary = !empty($ex['is_salary_related']);
                                
                                $search_text = strtolower(
                                    ($ex['expense_number'] ?? '') . ' ' . 
                                    ($ex['expense_name'] ?? '') . ' ' . 
                                    ($ex['category'] ?? '') . ' ' .
                                    ($ex['description'] ?? '') . ' ' .
                                    ($ex['employee_name'] ?? '') . ' ' .
                                    ($ex['branch_display_name'] ?? '') . ' ' .
                                    $ex['amount']
                                );
                            ?>
                                <tr class="expense-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date-main"><?php echo $ex_date; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="expense-number"><?php echo htmlspecialchars($ex['expense_number']); ?></span>
                                    </td>
                                    <td>
                                        <span class="category-badge">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars($ex['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="expense-name-cell">
                                            <span class="expense-name-text"><?php echo htmlspecialchars($ex['expense_name']); ?></span>
                                            <?php if (!empty($ex['description'])): ?>
                                                <span class="expense-desc"><?php echo htmlspecialchars($ex['description']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-expense">
                                            <?php echo formatCurrency($ex['amount']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($is_salary): ?>
                                            <span class="status-badge-sm status-salary">
                                                <i class="fas fa-user-tie"></i> Salary
                                            </span>
                                        <?php elseif ($is_business): ?>
                                            <span class="status-badge-sm status-business">
                                                <i class="fas fa-briefcase"></i> Business
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge-sm status-non-business">
                                                <i class="fas fa-times"></i> Personal
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($ex['receipt_path']) && file_exists('../../' . $ex['receipt_path'])): ?>
                                            <a href="../../<?php echo htmlspecialchars($ex['receipt_path']); ?>" 
                                               target="_blank" 
                                               class="receipt-cell">
                                                <i class="fas fa-paperclip"></i>
                                                View
                                            </a>
                                        <?php else: ?>
                                            <span class="no-receipt">No receipt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="branch-cell">
                                            <span class="branch-name"><?php echo htmlspecialchars($ex['branch_display_name'] ?? 'Main'); ?></span>
                                            <?php if (!empty($ex['branch_display_code'])): ?>
                                                <span class="branch-code-sm"><?php echo htmlspecialchars($ex['branch_display_code']); ?></span>
                                            <?php endif; ?>
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
                                            <span class="employee-name-sm"><?php echo htmlspecialchars($ex['employee_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="totals-row">
                                <td colspan="5" style="text-align:right;">TOTAL (<?php echo number_format($summary['total_count']); ?> expenses)</td>
                                <td class="text-right">
                                    <span style="color: #DC2626; font-weight: 900; font-size: 14px;">
                                        <?php echo formatCurrency($summary['total_amount']); ?>
                                    </span>
                                </td>
                                <td colspan="4"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="empty-state" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                    <p>No expenses match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Expenses Found</h3>
                    <p>No expenses for this period (<?php echo htmlspecialchars($filter_label); ?>).</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Try changing the quick filter, date range, branch, or category.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
</div>

<script>
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('expensesTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.expense-row');
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