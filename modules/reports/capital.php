<?php
// ================================================================
// FILE: modules/reports/capital.php
// WAKALA FINANCIAL SYSTEM - CAPITAL REPORT
// 🟢 GREEN THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ Quick Filters: All, Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom
// ✅ Summary Cards + Transaction Type Breakdown
// ✅ Current Capital Card (Float + Cash + Total)
// ✅ BRANCH GROUPING - Green line separator between branches
// ✅ PROVIDER COLUMN - with icon
// ✅ FIXED: Grand Total Row (last row) with proper colspan
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
            $from_date = '2000-01-01'; $to_date = $today; $filter_label = 'All Time'; break;
        case 'today':
            $from_date = $today; $to_date = $today; $filter_label = 'Today'; break;
        case '1d':
            $from_date = date('Y-m-d', strtotime('-1 day')); $to_date = $today; $filter_label = 'Last 1 Day'; break;
        case '1w':
            $from_date = date('Y-m-d', strtotime('-7 days')); $to_date = $today; $filter_label = 'Last 1 Week'; break;
        case '1m':
            $from_date = date('Y-m-d', strtotime('-30 days')); $to_date = $today; $filter_label = 'Last 1 Month'; break;
        case '3m':
            $from_date = date('Y-m-d', strtotime('-90 days')); $to_date = $today; $filter_label = 'Last 3 Months'; break;
        case '6m':
            $from_date = date('Y-m-d', strtotime('-180 days')); $to_date = $today; $filter_label = 'Last 6 Months'; break;
        case '1y':
            $from_date = date('Y-m-d', strtotime('-365 days')); $to_date = $today; $filter_label = 'Last 1 Year'; break;
        case 'custom':
            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $today;
            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $today;
            $filter_label = 'Custom Range';
            break;
        default:
            $from_date = $today; $to_date = $today; $filter_label = 'Today';
    }
} else {
    if (isset($_GET['from_date']) || isset($_GET['to_date'])) {
        $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $today;
        $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $today;
        $filter_label = 'Custom Range';
        $quick = 'custom';
    } else {
        $from_date = $today; $to_date = $today; $filter_label = 'Today';
        $quick = 'today';
    }
}

$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$selected_type = isset($_GET['transaction_type']) ? trim($_GET['transaction_type']) : '';

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
// GET CAPITAL TRANSACTIONS
// ============================================================
$capital_records = [];
$summary = [
    'total_count' => 0,
    'opening' => 0,
    'additional' => 0,
    'profit_alloc' => 0,
    'cash_out' => 0,
    'adjustment' => 0,
    'opening_count' => 0,
    'additional_count' => 0,
    'profit_alloc_count' => 0,
    'cash_out_count' => 0,
    'adjustment_count' => 0,
];

try {
    $sql = "
        SELECT 
            cm.*,
            e.full_name AS employee_name,
            e.employee_id AS employee_code,
            e.profile_pic AS employee_avatar,
            b.branch_name AS branch_display_name,
            b.branch_code AS branch_display_code,
            p.provider_name AS provider_display_name,
            p.provider_code AS provider_display_code,
            p.icon_class AS provider_icon,
            p.color_code AS provider_color,
            p.provider_type AS provider_type,
            bp.provider_code AS branch_provider_code
        FROM capital_management cm
        LEFT JOIN employees e ON cm.employee_id = e.id
        LEFT JOIN branches b ON cm.branch_id = b.id
        LEFT JOIN branch_providers bp ON cm.reference_id = bp.id AND cm.reference_module = 'provider'
        LEFT JOIN providers p ON bp.provider_id = p.id
        WHERE cm.transaction_date BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND cm.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($selected_type)) {
        $sql .= " AND cm.transaction_type = ?";
        $params[] = $selected_type;
    }

    $sql .= " ORDER BY cm.branch_id ASC, cm.transaction_date DESC, cm.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $capital_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($capital_records as $c) {
        $summary['total_count']++;
        $amount = floatval($c['amount']);
        
        switch ($c['transaction_type']) {
            case 'opening':
                $summary['opening'] += $amount;
                $summary['opening_count']++;
                break;
            case 'additional':
                $summary['additional'] += $amount;
                $summary['additional_count']++;
                break;
            case 'profit_allocation':
                $summary['profit_alloc'] += $amount;
                $summary['profit_alloc_count']++;
                break;
            case 'cash_out':
                $summary['cash_out'] += $amount;
                $summary['cash_out_count']++;
                break;
            case 'adjustment':
                $summary['adjustment'] += $amount;
                $summary['adjustment_count']++;
                break;
        }
    }

    // ============================================================
    // GROUP BY BRANCH
    // ============================================================
    $grouped_records = [];
    foreach ($capital_records as $c) {
        $bid = intval($c['branch_id'] ?? 0);
        if (!isset($grouped_records[$bid])) {
            $grouped_records[$bid] = [
                'branch_id' => $bid,
                'branch_name' => $c['branch_display_name'] ?? 'Unknown',
                'branch_code' => $c['branch_display_code'] ?? '',
                'records' => [],
                'subtotal' => 0,
                'count' => 0,
            ];
        }
        $grouped_records[$bid]['records'][] = $c;
        $grouped_records[$bid]['subtotal'] += floatval($c['amount']);
        $grouped_records[$bid]['count']++;
    }

} catch (PDOException $e) {
    error_log("Capital error: " . $e->getMessage());
    $capital_records = [];
    $grouped_records = [];
}

// ============================================================
// CURRENT CAPITAL (Float + Cash + Total)
// ============================================================
$current_float = 0;
$current_cash = 0;
$current_capital = 0;

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM daily_reports WHERE branch_id = ? ORDER BY report_date DESC, id DESC LIMIT 1");
    $stmt->execute([$selected_branch]);
    $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($latest_dr) {
        $current_cash = floatval($latest_dr['current_cash'] ?? 0);
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
    $stmt = $db->prepare("SELECT id FROM branches WHERE is_active = 1");
    $stmt->execute();
    $active_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($active_branches as $b) {
        $stmt = $db->prepare("SELECT * FROM daily_reports WHERE branch_id = ? ORDER BY report_date DESC, id DESC LIMIT 1");
        $stmt->execute([$b['id']]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_dr) {
            $current_cash += floatval($latest_dr['current_cash'] ?? 0);
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

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_header.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_sidebar.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_topbar.php';
?>

<style id="capital-report-scoped">
/* ============================================================
   🟢 GREEN THEME VARIABLES
   ============================================================ */
.main-wrapper {
    --bg-body: #f0fdf4;
    --bg-card: #ffffff;
    --bg-table-even: #f0fdf4;
    --bg-table-hover: #dcfce7;
    --bg-input: #f0fdf4;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #bbf7d0;
    --shadow-color: rgba(5, 150, 105, 0.08);
    --shadow-hover: rgba(5, 150, 105, 0.15);
    
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --green-mid: #10b981;
    --green-light: #34d399;
    --green-lighter: #d1fae5;
    --green-lightest: #ecfdf5;
    --green-accent: #6ee7b7;
}
html.dark-mode .main-wrapper {
    --bg-body: #0a1a0a;
    --bg-card: #152a15;
    --bg-table-even: #152a15;
    --bg-table-hover: #1d3d1d;
    --bg-input: #1d3d1d;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #065F46;
    --green-lighter: #065F46;
    --green-lightest: #064e3b;
}

.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after { box-sizing: border-box; }
.main-wrapper { background: var(--bg-body) !important; overflow-x: hidden !important; max-width: 100% !important; }
.main-wrapper .main-content { padding: 16px 20px !important; background: var(--bg-body) !important; color: var(--text-primary); overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }

/* BRANCH INDICATOR */
.main-wrapper .branch-indicator { background: linear-gradient(135deg, #059669 0%, #047857 50%, #065F46 100%); border-radius: 12px; padding: 14px 22px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(5, 150, 105, 0.35); flex-wrap: wrap; gap: 12px; color: #FFFFFF; position: relative; overflow: hidden; }
.main-wrapper .branch-indicator::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.08); border-radius: 50%; pointer-events: none; }
.main-wrapper .branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1; position: relative; z-index: 1; min-width: 0; }
.main-wrapper .branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0; border: 1.5px solid rgba(255, 255, 255, 0.3); }
.main-wrapper .branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.main-wrapper .branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; }
.main-wrapper .branch-indicator-name { font-weight: 800; font-size: 16px; }
.main-wrapper .branch-indicator-code { font-size: 11px; font-weight: 700; padding: 3px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.25); font-family: 'Courier New', monospace; }
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .date-display { font-size: 13px; color: rgba(255,255,255,0.95); padding: 6px 14px; background: rgba(255, 255, 255, 0.15); border-radius: 16px; display: flex; align-items: center; gap: 6px; font-weight: 600; }

/* PAGE HEADER */
.main-wrapper .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.main-wrapper .page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
.main-wrapper .page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0; display: flex; align-items: center; gap: 6px; }
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.main-wrapper .btn { padding: 10px 20px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.3s ease; font-family: 'Inter', sans-serif; white-space: nowrap; text-transform: uppercase; letter-spacing: 0.5px; }
.main-wrapper .btn-primary { background: linear-gradient(135deg, #059669, #047857); color: #FFFFFF; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); }
.main-wrapper .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5); color: #FFFFFF; }
.main-wrapper .btn-secondary { background: var(--bg-input); color: var(--text-secondary); border: 1.5px solid var(--border-color); }
.main-wrapper .btn-secondary:hover { background: var(--green-lighter); color: var(--green-dark); border-color: var(--green-accent); }

/* QUICK FILTERS */
.main-wrapper .quick-filters { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; padding: 10px 14px; background: var(--bg-card); border-radius: 12px; border: 1.5px solid var(--border-color); box-shadow: 0 2px 8px var(--shadow-color); }
.main-wrapper .quick-filters-label { font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; margin-right: 6px; display: flex; align-items: center; gap: 6px; }
.main-wrapper .quick-filters-label i { color: #059669; font-size: 12px; }
.main-wrapper .quick-filter-btn { padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; text-decoration: none; transition: all 0.25s ease; border: 1.5px solid var(--border-color); background: var(--bg-card); color: var(--text-secondary); display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; font-family: 'Inter', sans-serif; text-transform: uppercase; letter-spacing: 0.3px; }
.main-wrapper .quick-filter-btn i { font-size: 11px; }
.main-wrapper .quick-filter-btn:hover { background: var(--green-lighter); border-color: var(--green-accent); color: var(--green-dark); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(5, 150, 105, 0.15); }
.main-wrapper .quick-filter-btn.active { background: linear-gradient(135deg, #059669, #047857); border-color: #065F46; color: #FFFFFF; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4); transform: translateY(-2px); }
.main-wrapper .quick-filter-btn.active i { color: #FCD34D; }

/* SUMMARY CARDS */
.main-wrapper .summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
.main-wrapper .summary-card { position: relative; border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); min-width: 0; overflow: hidden; border: 1.5px solid transparent; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); }
.main-wrapper .summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(5, 150, 105, 0.15); }
.main-wrapper .summary-card-green { background: rgba(5, 150, 105, 0.08); border-color: rgba(5, 150, 105, 0.2); }
.main-wrapper .summary-card-green .summary-icon { background: rgba(5, 150, 105, 0.15); color: #059669; border: 1.5px solid rgba(5, 150, 105, 0.3); }
.main-wrapper .summary-card-green .summary-value { color: #047857; }
.main-wrapper .summary-card-blue { background: rgba(37, 99, 235, 0.08); border-color: rgba(37, 99, 235, 0.2); }
.main-wrapper .summary-card-blue .summary-icon { background: rgba(37, 99, 235, 0.15); color: #2563EB; border: 1.5px solid rgba(37, 99, 235, 0.3); }
.main-wrapper .summary-card-blue .summary-value { color: #1D4ED8; }
.main-wrapper .summary-card-purple { background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2); }
.main-wrapper .summary-card-purple .summary-icon { background: rgba(124, 58, 237, 0.15); color: #7C3AED; border: 1.5px solid rgba(124, 58, 237, 0.3); }
.main-wrapper .summary-card-purple .summary-value { color: #6D28D9; }
.main-wrapper .summary-card-red { background: rgba(220, 38, 38, 0.08); border-color: rgba(220, 38, 38, 0.2); }
.main-wrapper .summary-card-red .summary-icon { background: rgba(220, 38, 38, 0.15); color: #DC2626; border: 1.5px solid rgba(220, 38, 38, 0.3); }
.main-wrapper .summary-card-red .summary-value { color: #B91C1C; }
html.dark-mode .main-wrapper .summary-card-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .main-wrapper .summary-card-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .main-wrapper .summary-card-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .main-wrapper .summary-card-red { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .main-wrapper .summary-card-green .summary-value { color: #6EE7B7; }
html.dark-mode .main-wrapper .summary-card-blue .summary-value { color: #60A5FA; }
html.dark-mode .main-wrapper .summary-card-purple .summary-value { color: #C4B5FD; }
html.dark-mode .main-wrapper .summary-card-red .summary-value { color: #FCA5A5; }

.main-wrapper .summary-icon { width: 50px; height: 50px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; transition: all 0.3s ease; }
.main-wrapper .summary-card:hover .summary-icon { transform: scale(1.08) rotate(-4deg); }
.main-wrapper .summary-info { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px; }
.main-wrapper .summary-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.main-wrapper .summary-value { font-size: 18px; font-weight: 900; font-family: 'Inter', 'Courier New', monospace; letter-spacing: -0.3px; line-height: 1.2; word-break: break-word; }
.main-wrapper .summary-sub { font-size: 10px; font-weight: 600; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; margin-top: 2px; }

/* 🟢 CURRENT CAPITAL MERGED CARD */
.main-wrapper .current-capital-section { margin-bottom: 18px; }
.main-wrapper .current-capital-card { position: relative; border-radius: 16px; padding: 24px 28px; background: linear-gradient(135deg, #059669 0%, #047857 50%, #065F46 100%); color: #FFFFFF; box-shadow: 0 8px 28px rgba(5, 150, 105, 0.3); overflow: hidden; display: flex; align-items: center; gap: 28px; flex-wrap: wrap; }
.main-wrapper .current-capital-card::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; pointer-events: none; }
.main-wrapper .current-capital-card::after { content: ''; position: absolute; bottom: -40%; left: -5%; width: 250px; height: 250px; background: rgba(255,255,255,0.06); border-radius: 50%; pointer-events: none; }
.main-wrapper .current-capital-icon { width: 76px; height: 76px; border-radius: 20px; background: rgba(255, 255, 255, 0.22); display: flex; align-items: center; justify-content: center; font-size: 34px; flex-shrink: 0; border: 2px solid rgba(255, 255, 255, 0.35); backdrop-filter: blur(8px); position: relative; z-index: 1; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15); }
.main-wrapper .current-capital-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.main-wrapper .current-capital-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255, 255, 255, 0.9); display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.main-wrapper .current-capital-title i { font-size: 14px; color: #FCD34D; }
.main-wrapper .current-capital-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.main-wrapper .current-capital-item { padding: 12px 16px; background: rgba(255, 255, 255, 0.12); border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.18); display: flex; flex-direction: column; gap: 4px; transition: all 0.25s ease; }
.main-wrapper .current-capital-item:hover { background: rgba(255, 255, 255, 0.2); transform: translateY(-2px); }
.main-wrapper .current-capital-item.total { background: rgba(255, 255, 255, 0.25); border-color: rgba(252, 211, 77, 0.5); box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15); }
.main-wrapper .current-capital-item-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(255, 255, 255, 0.9); display: flex; align-items: center; gap: 5px; }
.main-wrapper .current-capital-item-label i { font-size: 11px; }
.main-wrapper .current-capital-item-label i.float-icon { color: #FCD34D; }
.main-wrapper .current-capital-item-label i.cash-icon { color: #6EE7B7; }
.main-wrapper .current-capital-item-label i.total-icon { color: #FCA5A5; }
.main-wrapper .current-capital-item-value { font-family: 'Inter', 'Courier New', monospace; font-size: 20px; font-weight: 900; color: #FFFFFF; text-shadow: 0 2px 6px rgba(0, 0, 0, 0.2); word-break: break-word; line-height: 1.15; }
.main-wrapper .current-capital-item.total .current-capital-item-value { font-size: 24px; color: #FCD34D; text-shadow: 0 2px 10px rgba(252, 211, 77, 0.5); }

/* TYPE BREAKDOWN */
.main-wrapper .category-section { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 2px 8px var(--shadow-color); }
.main-wrapper .category-section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.main-wrapper .category-section-title { font-size: 14px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.main-wrapper .category-section-title i { color: #059669; }
.main-wrapper .category-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.main-wrapper .category-card-mini { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: var(--bg-input); border-radius: 12px; border: 1.5px solid var(--border-color); transition: all 0.25s ease; min-width: 0; }
.main-wrapper .category-card-mini:hover { transform: translateY(-2px); border-color: #059669; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.15); }
.main-wrapper .category-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; border: 1.5px solid #6ee7b7; }
html.dark-mode .main-wrapper .category-icon { background: #065F46; color: #6EE7B7; border-color: #059669; }
.main-wrapper .category-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.main-wrapper .category-name { font-size: 13px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.main-wrapper .category-count { font-size: 10px; color: var(--text-muted); font-weight: 600; }
.main-wrapper .category-amount { font-size: 13px; font-weight: 900; color: #059669; font-family: 'Inter', 'Courier New', monospace; white-space: nowrap; }
html.dark-mode .main-wrapper .category-amount { color: #6EE7B7; }

/* FILTER BAR */
.main-wrapper .filter-bar { background: var(--bg-card); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; border: 1.5px solid var(--border-color); box-shadow: 0 2px 8px var(--shadow-color); }
.main-wrapper .filter-form { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.main-wrapper .filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 160px; flex: 1; }
.main-wrapper .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px; }
.main-wrapper .filter-group label i { color: #059669; }
.main-wrapper .form-control { padding: 10px 14px; border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 13px; color: var(--text-primary); background: var(--bg-input); transition: all 0.3s ease; font-family: 'Inter', sans-serif; width: 100%; }
.main-wrapper .form-control:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15); background: var(--bg-card); }
.main-wrapper .filter-actions { display: flex; gap: 8px; }

/* TABLE */
.main-wrapper .table-container { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); }
.main-wrapper .table-header { background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 16px 20px; color: #FFFFFF; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: relative; overflow: hidden; }
.main-wrapper .table-header::before { content: ''; position: absolute; top: -50%; right: -5%; width: 200px; height: 200px; background: rgba(255,255,255,0.08); border-radius: 50%; pointer-events: none; }
.main-wrapper .table-header-left { display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .table-header-left i { font-size: 22px; background: rgba(255,255,255,0.18); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.25); flex-shrink: 0; }
.main-wrapper .table-header h3 { font-size: 16px; font-weight: 800; margin: 0; white-space: nowrap; }
.main-wrapper .count-badge { background: rgba(255,255,255,0.22); padding: 4px 12px; border-radius: 10px; font-size: 11px; font-weight: 800; border: 1px solid rgba(255,255,255,0.3); white-space: nowrap; }
.main-wrapper .table-header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; position: relative; z-index: 1; flex: 1; justify-content: flex-end; min-width: 0; }
.main-wrapper .table-search-live { position: relative; display: flex; align-items: center; gap: 8px; background: rgba(255, 255, 255, 0.98); border: 2px solid rgba(255,255,255,0.3); border-radius: 10px; padding: 8px 14px; min-width: 240px; max-width: 300px; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); }
.main-wrapper .table-search-live:focus-within { background: #FFFFFF; border-color: #FCD34D; box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3); }
.main-wrapper .table-search-live > i { color: #059669; font-size: 13px; flex-shrink: 0; }
.main-wrapper .table-search-live input { flex: 1; border: none; background: transparent; padding: 4px 0; font-size: 13px; font-family: 'Inter', sans-serif; color: #1e293b; outline: none; min-width: 0; font-weight: 500; }
.main-wrapper .table-search-live input::placeholder { color: #94a3b8; font-size: 12px; }
.main-wrapper .table-search-live .search-clear-btn { width: 22px; height: 22px; border-radius: 50%; background: #d1fae5; color: #059669; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all 0.2s ease; flex-shrink: 0; }
.main-wrapper .table-search-live .search-clear-btn:hover { background: #059669; color: #FFFFFF; }
.main-wrapper .table-search-live .search-count-badge { font-size: 10px; font-weight: 800; padding: 3px 9px; background: #FCD34D; color: #78350F; border-radius: 8px; white-space: nowrap; flex-shrink: 0; }
.main-wrapper .table-scroll-buttons { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.main-wrapper .scroll-btn { width: 38px; height: 38px; border-radius: 10px; border: 2px solid #FFFFFF; background: #FFFFFF; color: #059669; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; transition: all 0.2s ease; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25); flex-shrink: 0; padding: 0; line-height: 1; }
.main-wrapper .scroll-btn:hover { background: #FCD34D; color: #78350F; border-color: #FCD34D; transform: translateY(-2px); }
.main-wrapper .scroll-btn i { font-size: 14px; display: block; line-height: 1; }

.main-wrapper .table-wrapper { overflow-x: auto !important; overflow-y: hidden; max-width: 100% !important; width: 100% !important; -webkit-overflow-scrolling: touch; display: block; scroll-behavior: smooth; }
.main-wrapper .table-wrapper::-webkit-scrollbar { height: 8px; }
.main-wrapper .table-wrapper::-webkit-scrollbar-track { background: var(--bg-table-even); border-radius: 4px; }
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #059669, #047857); border-radius: 4px; }
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #047857, #065F46); }

.main-wrapper .data-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1250px; table-layout: auto; }
.main-wrapper .data-table thead tr { background: var(--bg-table-even); }
.main-wrapper .data-table thead th { padding: 14px 12px; text-align: left; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; border-bottom: 2px solid var(--border-color); white-space: nowrap; }
.main-wrapper .data-table thead th.text-right { text-align: right; }
.main-wrapper .data-table thead th.text-center { text-align: center; }
.main-wrapper .data-table tbody tr { border-bottom: 1px solid var(--border-color); transition: background 0.2s ease; }
.main-wrapper .data-table tbody tr:hover { background: var(--bg-table-hover); }
.main-wrapper .data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.main-wrapper .data-table tbody td { padding: 12px; color: var(--text-primary); vertical-align: middle; }
.main-wrapper .data-table tbody td.text-right { text-align: right; }
.main-wrapper .data-table tbody td.text-center { text-align: center; }

.main-wrapper .data-table tbody tr.search-match { background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important; border-left: 4px solid #F59E0B; }
.main-wrapper .data-table tbody tr.search-hidden { display: none !important; }
.main-wrapper .data-table mark { background: #FEF08A; color: #78350F; padding: 1px 3px; border-radius: 3px; font-weight: 800; }

/* ============================================================
   🟢 BRANCH HEADER ROW
   ============================================================ */
.main-wrapper .data-table tbody tr.branch-header-row {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%) !important;
    border-top: 4px solid #059669 !important;
    border-bottom: 2px solid #059669 !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.branch-header-row {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%) !important;
    border-top: 4px solid #10b981 !important;
    border-bottom: 2px solid #10b981 !important;
}
.main-wrapper .data-table tbody tr.branch-header-row td {
    padding: 14px 16px !important;
    font-weight: 900 !important;
    font-size: 13px !important;
    color: #065F46 !important;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
html.dark-mode .main-wrapper .data-table tbody tr.branch-header-row td {
    color: #6EE7B7 !important;
}
.main-wrapper .branch-header-cell { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.main-wrapper .branch-header-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #059669, #047857); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 16px; border: 2px solid #FFFFFF; box-shadow: 0 3px 10px rgba(5, 150, 105, 0.4); flex-shrink: 0; }
.main-wrapper .branch-header-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.main-wrapper .branch-header-name { font-size: 14px; font-weight: 900; color: #065F46; display: inline-flex; align-items: center; gap: 6px; }
html.dark-mode .main-wrapper .branch-header-name { color: #6EE7B7; }
.main-wrapper .branch-header-code { font-size: 10px; font-weight: 800; padding: 3px 10px; background: rgba(255, 255, 255, 0.7); color: #047857; border-radius: 8px; font-family: 'Courier New', monospace; border: 1.5px solid #6ee7b7; }
html.dark-mode .main-wrapper .branch-header-code { background: rgba(0,0,0,0.3); color: #6EE7B7; }
.main-wrapper .branch-header-count { font-size: 11px; font-weight: 800; padding: 4px 12px; background: rgba(255, 255, 255, 0.6); color: #047857; border-radius: 10px; border: 1.5px solid #6ee7b7; }
html.dark-mode .main-wrapper .branch-header-count { background: rgba(0,0,0,0.3); color: #6EE7B7; }
.main-wrapper .branch-header-total { margin-left: auto; font-size: 13px; font-weight: 900; font-family: 'Courier New', monospace; color: #047857; background: rgba(255, 255, 255, 0.8); padding: 6px 14px; border-radius: 10px; border: 2px solid #059669; }
html.dark-mode .main-wrapper .branch-header-total { background: rgba(0,0,0,0.3); color: #6EE7B7; border-color: #10b981; }

/* ============================================================
   🟢 BRANCH SUBTOTAL ROW
   ============================================================ */
.main-wrapper .data-table tbody tr.branch-subtotal-row {
    background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%) !important;
    border-top: 2px dashed #059669 !important;
    border-bottom: 4px solid #059669 !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.branch-subtotal-row {
    background: linear-gradient(135deg, #064e3b 0%, #065F46 100%) !important;
    border-top: 2px dashed #10b981 !important;
    border-bottom: 4px solid #10b981 !important;
}
.main-wrapper .data-table tbody tr.branch-subtotal-row td {
    padding: 12px 16px !important;
    font-weight: 900 !important;
    font-size: 12px !important;
    color: #065F46 !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.branch-subtotal-row td {
    color: #6EE7B7 !important;
}
.main-wrapper .subtotal-label { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.8px; color: #065F46; }
html.dark-mode .main-wrapper .subtotal-label { color: #6EE7B7; }
.main-wrapper .subtotal-label i { color: #059669; font-size: 14px; }
.main-wrapper .subtotal-value { display: inline-block; font-family: 'Courier New', monospace; font-size: 15px; font-weight: 900; color: #047857; background: rgba(255, 255, 255, 0.9); padding: 6px 14px; border-radius: 10px; border: 2px solid #059669; }
html.dark-mode .main-wrapper .subtotal-value { background: rgba(0,0,0,0.3); color: #6EE7B7; border-color: #10b981; }

/* PROVIDER CELL */
.main-wrapper .provider-cell { display: flex; align-items: center; gap: 8px; min-width: 0; }
.main-wrapper .provider-icon-badge { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #FFFFFF; font-size: 13px; flex-shrink: 0; border: 2px solid rgba(255, 255, 255, 0.6); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15); }
.main-wrapper .provider-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.main-wrapper .provider-name-sm { font-size: 12px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
.main-wrapper .provider-code-sm { font-size: 9px; font-weight: 700; color: #059669; font-family: 'Courier New', monospace; background: #d1fae5; padding: 1px 6px; border-radius: 4px; align-self: flex-start; }
html.dark-mode .main-wrapper .provider-code-sm { background: #065F46; color: #6EE7B7; }
.main-wrapper .provider-cash-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; background: linear-gradient(135deg, #fef3c7, #fde68a); color: #78350F; border-radius: 8px; font-size: 10px; font-weight: 800; border: 1.5px solid #fcd34d; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

/* ROW NUMBER */
.main-wrapper .row-number { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: var(--green-lighter); font-size: 11px; font-weight: 800; color: var(--green-dark); border: 1.5px solid var(--green-accent); }
html.dark-mode .main-wrapper .row-number { background: #065F46; color: #6EE7B7; border-color: #059669; }

/* DATE CELL */
.main-wrapper .date-cell { display: inline-flex; flex-direction: column; gap: 2px; font-size: 11px; font-weight: 700; color: var(--text-secondary); white-space: nowrap; }
.main-wrapper .date-main { font-family: 'Inter', 'Courier New', monospace; font-size: 12px; font-weight: 800; color: #059669; }
html.dark-mode .main-wrapper .date-main { color: #6EE7B7; }

/* CAPITAL NUMBER */
.main-wrapper .capital-number { font-family: 'Courier New', monospace; font-size: 11px; font-weight: 800; color: #059669; background: #d1fae5; padding: 3px 10px; border-radius: 6px; white-space: nowrap; border: 1.5px solid #6ee7b7; }
html.dark-mode .main-wrapper .capital-number { background: #065F46; color: #6EE7B7; border-color: #059669; }

/* TYPE BADGES */
.main-wrapper .type-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; border: 1.5px solid; }
.main-wrapper .type-opening { background: #DBEAFE; color: #1E40AF; border-color: #93C5FD; }
.main-wrapper .type-additional { background: #d1fae5; color: #047857; border-color: #6ee7b7; }
.main-wrapper .type-profit { background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD; }
.main-wrapper .type-cashout { background: #FEE2E2; color: #991B1B; border-color: #FCA5A5; }
.main-wrapper .type-adjustment { background: #FEF3C7; color: #B45309; border-color: #FCD34D; }
html.dark-mode .main-wrapper .type-opening { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .main-wrapper .type-additional { background: #065F46; color: #6EE7B7; border-color: #059669; }
html.dark-mode .main-wrapper .type-profit { background: #4C1D95; color: #C4B5FD; border-color: #8B5CF6; }
html.dark-mode .main-wrapper .type-cashout { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .main-wrapper .type-adjustment { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

/* AMOUNT */
.main-wrapper .amount-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 8px; font-weight: 900; font-size: 12px; font-family: 'Courier New', monospace; white-space: nowrap; }
.main-wrapper .amount-capital { background: #d1fae5; color: #047857; border: 1.5px solid #6ee7b7; }
html.dark-mode .main-wrapper .amount-capital { background: #065F46; color: #6EE7B7; border-color: #059669; }

/* DESCRIPTION */
.main-wrapper .description-cell { font-size: 11px; color: var(--text-secondary); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* REFERENCE */
.main-wrapper .reference-cell { font-family: 'Courier New', monospace; font-size: 10px; font-weight: 700; color: var(--text-muted); background: var(--bg-input); padding: 3px 8px; border-radius: 6px; border: 1px solid var(--border-color); white-space: nowrap; }

/* EMPLOYEE CELL */
.main-wrapper .employee-cell { display: flex; align-items: center; gap: 8px; min-width: 0; }
.main-wrapper .employee-avatar { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #FFFFFF; flex-shrink: 0; background: linear-gradient(135deg, #059669, #047857); border: 2px solid #6ee7b7; object-fit: cover; }
.main-wrapper .employee-name-sm { font-size: 12px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }

/* BRANCH CELL */
.main-wrapper .branch-cell { display: flex; flex-direction: column; gap: 2px; min-width: 100px; }
.main-wrapper .branch-name { font-size: 12px; font-weight: 700; color: var(--text-primary); white-space: nowrap; }
.main-wrapper .branch-code-sm { font-size: 9px; font-weight: 700; color: #059669; font-family: 'Courier New', monospace; background: #d1fae5; padding: 1px 6px; border-radius: 4px; align-self: flex-start; }
html.dark-mode .main-wrapper .branch-code-sm { background: #065F46; color: #6EE7B7; }

/* ============================================================
   🟢 GRAND TOTAL ROW (LAST ROW) - FIXED
   ============================================================ */
.main-wrapper .data-table tbody tr.totals-row,
.main-wrapper .data-table tbody tr.grand-total-row {
    background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%) !important;
    border-top: 4px solid #047857 !important;
    border-bottom: 5px solid #047857 !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.totals-row,
html.dark-mode .main-wrapper .data-table tbody tr.grand-total-row {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%) !important;
    border-top: 4px solid #10b981 !important;
    border-bottom: 5px solid #10b981 !important;
}
.main-wrapper .data-table tbody tr.totals-row td,
.main-wrapper .data-table tbody tr.grand-total-row td {
    padding: 18px 16px !important;
    font-weight: 900 !important;
    color: #065F46 !important;
    font-size: 14px !important;
    border-bottom: none !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.totals-row td,
html.dark-mode .main-wrapper .data-table tbody tr.grand-total-row td {
    color: #D1FAE5 !important;
}

.main-wrapper .grand-total-label {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #065F46;
}
html.dark-mode .main-wrapper .grand-total-label { color: #D1FAE5; }
.main-wrapper .grand-total-label i { color: #047857; font-size: 18px; }
html.dark-mode .main-wrapper .grand-total-label i { color: #6EE7B7; }

.main-wrapper .grand-total-value {
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-size: 20px;
    font-weight: 900;
    color: #FFFFFF;
    background: linear-gradient(135deg, #059669, #047857);
    padding: 10px 24px;
    border-radius: 12px;
    border: 2px solid #FFFFFF;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.4);
    white-space: nowrap;
    letter-spacing: 0.5px;
}
html.dark-mode .main-wrapper .grand-total-value {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #FFFFFF;
    border-color: #6EE7B7;
}

/* EMPTY STATE */
.main-wrapper .empty-state { text-align: center; padding: 60px 20px; }
.main-wrapper .empty-state i { font-size: 56px; color: var(--text-light); opacity: 0.4; display: block; margin-bottom: 16px; }
.main-wrapper .empty-state h3 { font-size: 18px; color: var(--text-primary); margin: 0 0 8px 0; }
.main-wrapper .empty-state p { color: var(--text-muted); font-size: 14px; margin: 0 0 16px 0; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .main-wrapper .summary-cards { grid-template-columns: repeat(2, 1fr); }
    .main-wrapper .data-table { min-width: 1100px; }
    .main-wrapper .current-capital-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
    .main-wrapper .table-header { flex-direction: column; align-items: stretch; }
    .main-wrapper .table-header-right { width: 100%; justify-content: space-between; flex-wrap: wrap; }
    .main-wrapper .table-search-live { min-width: 0; max-width: none; flex: 1; }
    .main-wrapper .current-capital-grid { grid-template-columns: 1fr; }
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
    .main-wrapper .current-capital-card { flex-direction: column; text-align: center; padding: 20px; }
    .main-wrapper .current-capital-icon { width: 64px; height: 64px; font-size: 28px; }
    .main-wrapper .branch-header-cell { flex-wrap: wrap; gap: 8px; }
    .main-wrapper .branch-header-total { margin-left: 0; width: 100%; text-align: center; }
    .main-wrapper .grand-total-value { font-size: 16px; padding: 8px 18px; }
}
@media (max-width: 480px) {
    .main-wrapper .summary-value { font-size: 16px; }
    .main-wrapper .summary-icon { width: 44px; height: 44px; font-size: 18px; }
    .main-wrapper .table-search-live { width: 100%; }
    .main-wrapper .table-scroll-buttons { width: 100%; justify-content: center; }
    .main-wrapper .scroll-btn { width: 42px; height: 42px; font-size: 16px; }
    .main-wrapper .quick-filter-btn { padding: 6px 10px; font-size: 10px; }
    .main-wrapper .current-capital-item-value { font-size: 16px; }
    .main-wrapper .current-capital-item.total .current-capital-item-value { font-size: 20px; }
    .main-wrapper .grand-total-value { font-size: 14px; padding: 6px 14px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-building"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Capital Report</span>
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
                <h2><i class="fas fa-building" style="color:#059669;"></i> Capital Report</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($from_date)); ?> — <?php echo date('d M Y', strtotime($to_date)); ?>
                    •
                    <i class="fas fa-filter"></i>
                    <?php echo htmlspecialchars($filter_label); ?>
                    •
                    <i class="fas fa-list"></i>
                    <?php echo number_format($summary['total_count']); ?> records
                </p>
            </div>
            <div class="header-right">
                <a href="export.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&type=capital&format=pdf" 
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
                    'transaction_type' => $selected_type,
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
                <div class="summary-icon"><i class="fas fa-flag"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Opening Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['opening']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['opening_count']); ?> records
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-green">
                <div class="summary-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Additional Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['additional']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['additional_count']); ?> records
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-purple">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Profit Allocation</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['profit_alloc']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['profit_alloc_count']); ?> records
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-red">
                <div class="summary-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Cash Out</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['cash_out']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary['cash_out_count']); ?> records
                    </span>
                </div>
            </div>
        </div>

        <!-- 🟢 CURRENT CAPITAL MERGED CARD -->
        <div class="current-capital-section">
            <div class="current-capital-card">
                <div class="current-capital-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="current-capital-content">
                    <div class="current-capital-title">
                        <i class="fas fa-building"></i>
                        Current Capital Status
                    </div>
                    
                    <div class="current-capital-grid">
                        <div class="current-capital-item">
                            <span class="current-capital-item-label">
                                <i class="fas fa-coins float-icon"></i>
                                Current Float
                            </span>
                            <span class="current-capital-item-value">
                                <?php echo formatCurrency($current_float); ?>
                            </span>
                        </div>
                        
                        <div class="current-capital-item">
                            <span class="current-capital-item-label">
                                <i class="fas fa-money-bill-wave cash-icon"></i>
                                Current Cash
                            </span>
                            <span class="current-capital-item-value">
                                <?php echo formatCurrency($current_cash); ?>
                            </span>
                        </div>
                        
                        <div class="current-capital-item total">
                            <span class="current-capital-item-label">
                                <i class="fas fa-chart-line total-icon"></i>
                                Total Capital
                            </span>
                            <span class="current-capital-item-value">
                                <?php echo formatCurrency($current_capital); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TYPE BREAKDOWN -->
        <?php if ($summary['total_count'] > 0): ?>
        <div class="category-section">
            <div class="category-section-header">
                <div class="category-section-title">
                    <i class="fas fa-chart-pie"></i>
                    Transaction Type Breakdown
                </div>
                <span style="font-size: 11px; font-weight: 800; background: #d1fae5; color: #047857; padding: 4px 12px; border-radius: 8px; border: 1.5px solid #6ee7b7;">
                    <?php echo $summary['total_count']; ?> total records
                </span>
            </div>
            <div class="category-grid">
                <?php if ($summary['opening'] > 0): ?>
                <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&transaction_type=opening" class="category-card-mini" style="text-decoration: none;">
                    <div class="category-icon"><i class="fas fa-flag"></i></div>
                    <div class="category-info">
                        <span class="category-name">Opening Capital</span>
                        <span class="category-count"><?php echo number_format($summary['opening_count']); ?> records</span>
                    </div>
                    <span class="category-amount"><?php echo formatCurrency($summary['opening']); ?></span>
                </a>
                <?php endif; ?>
                <?php if ($summary['additional'] > 0): ?>
                <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&transaction_type=additional" class="category-card-mini" style="text-decoration: none;">
                    <div class="category-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="category-info">
                        <span class="category-name">Additional Capital</span>
                        <span class="category-count"><?php echo number_format($summary['additional_count']); ?> records</span>
                    </div>
                    <span class="category-amount"><?php echo formatCurrency($summary['additional']); ?></span>
                </a>
                <?php endif; ?>
                <?php if ($summary['profit_alloc'] > 0): ?>
                <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&transaction_type=profit_allocation" class="category-card-mini" style="text-decoration: none;">
                    <div class="category-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="category-info">
                        <span class="category-name">Profit Allocation</span>
                        <span class="category-count"><?php echo number_format($summary['profit_alloc_count']); ?> records</span>
                    </div>
                    <span class="category-amount"><?php echo formatCurrency($summary['profit_alloc']); ?></span>
                </a>
                <?php endif; ?>
                <?php if ($summary['cash_out'] > 0): ?>
                <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&branch_id=<?php echo $selected_branch; ?>&transaction_type=cash_out" class="category-card-mini" style="text-decoration: none;">
                    <div class="category-icon"><i class="fas fa-arrow-up"></i></div>
                    <div class="category-info">
                        <span class="category-name">Cash Out</span>
                        <span class="category-count"><?php echo number_format($summary['cash_out_count']); ?> records</span>
                    </div>
                    <span class="category-amount"><?php echo formatCurrency($summary['cash_out']); ?></span>
                </a>
                <?php endif; ?>
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
                    <label><i class="fas fa-filter"></i> Type</label>
                    <select name="transaction_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="opening" <?php echo $selected_type === 'opening' ? 'selected' : ''; ?>>Opening</option>
                        <option value="additional" <?php echo $selected_type === 'additional' ? 'selected' : ''; ?>>Additional</option>
                        <option value="profit_allocation" <?php echo $selected_type === 'profit_allocation' ? 'selected' : ''; ?>>Profit Allocation</option>
                        <option value="cash_out" <?php echo $selected_type === 'cash_out' ? 'selected' : ''; ?>>Cash Out</option>
                        <option value="adjustment" <?php echo $selected_type === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
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
                    <h3>Capital Transactions<?php echo !empty($selected_type) ? ' - ' . ucfirst(str_replace('_', ' ', $selected_type)) : ''; ?></h3>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($capital_records); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search capital..."
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
            
            <?php if (count($capital_records) > 0): ?>
                <div class="table-wrapper" id="tableWrapper">
                    <table class="data-table" id="capitalTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date</th>
                                <th>Capital #</th>
                                <th>Type</th>
                                <th>Provider</th>
                                <th>Description</th>
                                <th class="text-right" style="width: 160px;">Amount</th>
                                <th>Reference</th>
                                <th>Branch</th>
                                <th>Employee</th>
                            </tr>
                        </thead>
                        <tbody id="capitalTableBody">
                            <?php 
                            $global_counter = 1;
                            foreach ($grouped_records as $branch_id => $group): 
                                $branch_total = floatval($group['subtotal']);
                                $branch_count = $group['count'];
                            ?>
                                
                                <!-- 🟢 BRANCH HEADER ROW -->
                                <tr class="branch-header-row" data-branch-id="<?php echo $branch_id; ?>">
                                    <td colspan="10">
                                        <div class="branch-header-cell">
                                            <div class="branch-header-icon">
                                                <i class="fas fa-store-alt"></i>
                                            </div>
                                            <div class="branch-header-info">
                                                <span class="branch-header-name">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars($group['branch_name']); ?>
                                                </span>
                                                <?php if (!empty($group['branch_code'])): ?>
                                                    <span class="branch-header-code"><?php echo htmlspecialchars($group['branch_code']); ?></span>
                                                <?php endif; ?>
                                                <span class="branch-header-count">
                                                    <i class="fas fa-list"></i>
                                                    <?php echo number_format($branch_count); ?> records
                                                </span>
                                            </div>
                                            <span class="branch-header-total">
                                                <?php echo formatCurrency($branch_total); ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- RECORDS ZA BRANCH -->
                                <?php foreach ($group['records'] as $c): 
                                    $c_date = date('d M Y', strtotime($c['transaction_date']));
                                    $emp_initial = strtoupper(substr($c['employee_name'] ?? 'N', 0, 1));
                                    $emp_avatar = $c['employee_avatar'] ?? '';
                                    $ttype = $c['transaction_type'] ?? 'opening';
                                    
                                    $type_labels = [
                                        'opening' => ['label' => 'Opening', 'class' => 'type-opening', 'icon' => 'fa-flag'],
                                        'additional' => ['label' => 'Additional', 'class' => 'type-additional', 'icon' => 'fa-plus-circle'],
                                        'profit_allocation' => ['label' => 'Profit', 'class' => 'type-profit', 'icon' => 'fa-chart-line'],
                                        'cash_out' => ['label' => 'Cash Out', 'class' => 'type-cashout', 'icon' => 'fa-arrow-up'],
                                        'adjustment' => ['label' => 'Adjustment', 'class' => 'type-adjustment', 'icon' => 'fa-sliders-h'],
                                    ];
                                    $tinfo = $type_labels[$ttype] ?? ['label' => ucfirst($ttype), 'class' => 'type-adjustment', 'icon' => 'fa-circle'];
                                    
                                    $provider_name = $c['provider_display_name'] ?? null;
                                    $provider_icon = $c['provider_icon'] ?? 'fas fa-university';
                                    $provider_color = $c['provider_color'] ?? '#059669';
                                    $provider_code = $c['branch_provider_code'] ?? null;
                                    $is_cash = empty($provider_name);
                                    
                                    $search_text = strtolower(
                                        ($c['capital_number'] ?? '') . ' ' . 
                                        ($c['description'] ?? '') . ' ' . 
                                        $ttype . ' ' . $tinfo['label'] . ' ' .
                                        ($c['employee_name'] ?? '') . ' ' . 
                                        ($c['branch_display_name'] ?? '') . ' ' .
                                        ($provider_name ?? 'cash') . ' ' .
                                        $c['amount']
                                    );
                                ?>
                                    <tr class="capital-row" data-search="<?php echo htmlspecialchars($search_text); ?>" data-branch="<?php echo $branch_id; ?>">
                                        <td><span class="row-number"><?php echo $global_counter++; ?></span></td>
                                        <td>
                                            <div class="date-cell">
                                                <span class="date-main"><?php echo $c_date; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="capital-number"><?php echo htmlspecialchars($c['capital_number']); ?></span>
                                        </td>
                                        <td>
                                            <span class="type-badge <?php echo $tinfo['class']; ?>">
                                                <i class="fas <?php echo $tinfo['icon']; ?>"></i>
                                                <?php echo $tinfo['label']; ?>
                                            </span>
                                        </td>
                                        
                                        <!-- 🟢 PROVIDER CELL -->
                                        <td>
                                            <?php if ($is_cash): ?>
                                                <span class="provider-cash-badge">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                    Cash
                                                </span>
                                            <?php else: ?>
                                                <div class="provider-cell">
                                                    <div class="provider-icon-badge" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                                        <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                                    </div>
                                                    <div class="provider-info">
                                                        <span class="provider-name-sm"><?php echo htmlspecialchars($provider_name); ?></span>
                                                        <?php if (!empty($provider_code)): ?>
                                                            <span class="provider-code-sm"><?php echo htmlspecialchars($provider_code); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <div class="description-cell" title="<?php echo htmlspecialchars($c['description'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($c['description'] ?? '-'); ?>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-badge amount-capital">
                                                <?php echo formatCurrency($c['amount']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="reference-cell">
                                                <?php echo htmlspecialchars($c['reference_module'] ?? '-'); ?>
                                                <?php echo !empty($c['reference_id']) ? '#' . $c['reference_id'] : ''; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="branch-cell">
                                                <span class="branch-name"><?php echo htmlspecialchars($c['branch_display_name'] ?? 'Main'); ?></span>
                                                <?php if (!empty($c['branch_display_code'])): ?>
                                                    <span class="branch-code-sm"><?php echo htmlspecialchars($c['branch_display_code']); ?></span>
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
                                                <span class="employee-name-sm"><?php echo htmlspecialchars($c['employee_name'] ?? 'N/A'); ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- 🟢 BRANCH SUBTOTAL ROW -->
                                <tr class="branch-subtotal-row" data-branch-subtotal="<?php echo $branch_id; ?>">
                                    <td colspan="6" class="text-right">
                                        <span class="subtotal-label">
                                            <i class="fas fa-calculator"></i>
                                            SUBTOTAL - <?php echo htmlspecialchars($group['branch_name']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="subtotal-value">
                                            <?php echo formatCurrency($branch_total); ?>
                                        </span>
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                                
                            <?php endforeach; ?>
                            
                            <!-- 🟢 GRAND TOTAL ROW (FIXED) -->
                            <tr class="grand-total-row totals-row">
                                <td colspan="6" class="text-right">
                                    <span class="grand-total-label">
                                        <i class="fas fa-trophy"></i>
                                        GRAND TOTAL (<?php echo number_format($summary['total_count']); ?> records)
                                    </span>
                                </td>
                                <td class="text-right">
                                    <span class="grand-total-value">
                                        <?php echo formatCurrency($summary['opening'] + $summary['additional'] + $summary['profit_alloc'] - $summary['cash_out'] + $summary['adjustment']); ?>
                                    </span>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="empty-state" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                    <p>No capital records match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>No Capital Records Found</h3>
                    <p>No capital transactions for this period (<?php echo htmlspecialchars($filter_label); ?>).</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Try changing the quick filter, date range, branch, or transaction type.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
</div>

<script>
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('capitalTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.capital-row');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResults = document.getElementById('noSearchResults');
    const tableWrapper = document.getElementById('tableWrapper');
    
    const term = searchTerm.trim();
    
    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    
    if (term.length === 0) {
        tableBody.querySelectorAll('tr').forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
        });
        rows.forEach(row => removeAllMarks(row));
        if (countBadge) { countBadge.style.display = 'none'; countBadge.textContent = '0'; }
        if (noResults) noResults.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = '';
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    const branchMatches = {};
    
    rows.forEach(row => {
        removeAllMarks(row);
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();
        const branchId = row.getAttribute('data-branch');
        
        if (searchText.includes(searchLower) || rowText.includes(searchLower)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatchesInRow(row, term);
            matchCount++;
            branchMatches[branchId] = true;
        } else {
            row.classList.add('search-hidden');
            row.classList.remove('search-match');
        }
    });
    
    tableBody.querySelectorAll('tr.branch-header-row').forEach(row => {
        const bid = row.getAttribute('data-branch-id');
        row.classList.toggle('search-hidden', !branchMatches[bid]);
    });
    
    tableBody.querySelectorAll('tr.branch-subtotal-row').forEach(row => {
        const bid = row.getAttribute('data-branch-subtotal');
        row.classList.toggle('search-hidden', !branchMatches[bid]);
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