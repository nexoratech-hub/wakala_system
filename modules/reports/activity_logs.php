<?php
// ================================================================
// FILE: modules/reports/activity_logs.php
// WAKALA FINANCIAL SYSTEM - ACTIVITY LOGS REPORT
// 🔵 BLUE THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ Quick Filters: All, Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom
// ✅ Delete Single + Delete All buttons
// ✅ CSRF Token + Admin check
// ✅ Toast notifications
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
// 🔥 QUICK FILTER LOGIC (DEFAULT = ALL TIME)
// ============================================================
$quick = isset($_GET['quick']) ? trim($_GET['quick']) : '';
$today = date('Y-m-d');

$from_date = '2000-01-01';
$to_date = $today;
$filter_label = 'All Time';
$quick = $quick !== '' ? $quick : 'all';

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
            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $today;
            $filter_label = 'Custom Range';
            break;
        default:
            $from_date = '2000-01-01'; $to_date = $today; $filter_label = 'All Time'; $quick = 'all';
    }
}

$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$selected_module = isset($_GET['module']) ? trim($_GET['module']) : '';
$selected_action = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) $from_date = '2000-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) $to_date = $today;

if (!$is_admin) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_branch = intval($emp['branch_id'] ?? 0);
}

// ============================================================
// CSRF TOKEN
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// HANDLE DELETE ACTIONS (AJAX)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
    
    // Only admin can delete
    if (!$is_admin) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    try {
        if ($_POST['action'] === 'delete_single' && !empty($_POST['log_id'])) {
            $log_id = intval($_POST['log_id']);
            
            // Get log details before delete
            $stmt = $db->prepare("SELECT action, module FROM activity_logs WHERE id = ?");
            $stmt->execute([$log_id]);
            $log_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$log_info) {
                echo json_encode(['success' => false, 'message' => 'Log not found']);
                exit();
            }
            
            $stmt = $db->prepare("DELETE FROM activity_logs WHERE id = ?");
            $stmt->execute([$log_id]);
            
            // Log the deletion itself (audit trail)
            try {
                $stmt = $db->prepare("
                    INSERT INTO activity_logs 
                    (employee_id, action, module, record_id, old_value, new_value, ip_address, user_agent, branch_id, created_at)
                    VALUES (?, 'Delete Activity Log', 'Activity Logs', ?, ?, NULL, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user_id,
                    $log_id,
                    'Deleted: ' . $log_info['action'] . ' (' . $log_info['module'] . ')',
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    $selected_branch ?: null
                ]);
            } catch (Exception $e) { /* ignore */ }
            
            echo json_encode([
                'success' => true,
                'message' => 'Log deleted successfully',
                'log_id' => $log_id
            ]);
            exit();
            
        } elseif ($_POST['action'] === 'delete_all') {
            $del_from = $_POST['from_date'] ?? $from_date;
            $del_to = $_POST['to_date'] ?? $to_date;
            $del_module = $_POST['module'] ?? '';
            $del_action = $_POST['action_type'] ?? '';
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $del_from)) $del_from = $from_date;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $del_to)) $del_to = $to_date;
            
            // Build WHERE
            $where = "DATE(created_at) BETWEEN ? AND ?";
            $params = [$del_from, $del_to];
            
            if (!empty($del_module)) {
                $where .= " AND module = ?";
                $params[] = $del_module;
            }
            if (!empty($del_action)) {
                $where .= " AND action = ?";
                $params[] = $del_action;
            }
            
            // Count first
            $stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE $where");
            $stmt->execute($params);
            $count = intval($stmt->fetchColumn());
            
            if ($count == 0) {
                echo json_encode(['success' => false, 'message' => 'No logs to delete']);
                exit();
            }
            
            // Delete
            $stmt = $db->prepare("DELETE FROM activity_logs WHERE $where");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
            
            // Log the mass deletion
            try {
                $stmt = $db->prepare("
                    INSERT INTO activity_logs 
                    (employee_id, action, module, record_id, old_value, new_value, ip_address, user_agent, branch_id, created_at)
                    VALUES (?, 'Delete All Activity Logs', 'Activity Logs', NULL, ?, NULL, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user_id,
                    'Deleted ' . $deleted . ' logs (' . $del_from . ' to ' . $del_to . ')',
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    $selected_branch ?: null
                ]);
            } catch (Exception $e) { /* ignore */ }
            
            echo json_encode([
                'success' => true,
                'message' => $deleted . ' logs deleted successfully',
                'deleted_count' => $deleted
            ]);
            exit();
        }
    } catch (PDOException $e) {
        error_log("Delete log error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
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
// GET UNIQUE MODULES
// ============================================================
$modules_list = [];
try {
    $stmt = $db->prepare("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC");
    $stmt->execute();
    $modules_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $modules_list = [];
}

// ============================================================
// GET ACTIVITY LOGS
// ============================================================
$logs = [];
$summary = [
    'total_count' => 0,
    'login_count' => 0,
    'logout_count' => 0,
    'add_count' => 0,
    'edit_count' => 0,
    'delete_count' => 0,
    'other_count' => 0,
];

try {
    $sql = "
        SELECT 
            al.*,
            e.full_name AS employee_name,
            e.employee_id AS employee_code,
            e.profile_pic AS employee_avatar,
            b.branch_name AS branch_display_name,
            b.branch_code AS branch_display_code
        FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        LEFT JOIN branches b ON al.branch_id = b.id
        WHERE DATE(al.created_at) BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];

    if (!empty($selected_module)) {
        $sql .= " AND al.module = ?";
        $params[] = $selected_module;
    }

    if (!empty($selected_action)) {
        $sql .= " AND al.action = ?";
        $params[] = $selected_action;
    }

    $sql .= " ORDER BY al.created_at DESC, al.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as $log) {
        $summary['total_count']++;
        $action_lower = strtolower($log['action'] ?? '');
        
        if (strpos($action_lower, 'login') !== false) {
            $summary['login_count']++;
        } elseif (strpos($action_lower, 'logout') !== false) {
            $summary['logout_count']++;
        } elseif (strpos($action_lower, 'add') !== false || strpos($action_lower, 'create') !== false || strpos($action_lower, 'generate') !== false) {
            $summary['add_count']++;
        } elseif (strpos($action_lower, 'edit') !== false || strpos($action_lower, 'update') !== false) {
            $summary['edit_count']++;
        } elseif (strpos($action_lower, 'delete') !== false || strpos($action_lower, 'remove') !== false) {
            $summary['delete_count']++;
        } else {
            $summary['other_count']++;
        }
    }

    // Module breakdown
    $sql_modules = "
        SELECT 
            al.module,
            COUNT(*) AS count
        FROM activity_logs al
        WHERE DATE(al.created_at) BETWEEN ? AND ?
    ";
    $params_modules = [$from_date, $to_date];

    if (!empty($selected_module)) {
        $sql_modules .= " AND al.module = ?";
        $params_modules[] = $selected_module;
    }

    $sql_modules .= " AND al.module IS NOT NULL AND al.module != '' 
                      GROUP BY al.module ORDER BY count DESC LIMIT 8";

    $stmt = $db->prepare($sql_modules);
    $stmt->execute($params_modules);
    $module_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check total in DB
    $check_stmt = $db->prepare("SELECT COUNT(*) as total FROM activity_logs");
    $check_stmt->execute();
    $total_in_db = intval($check_stmt->fetch()['total'] ?? 0);

} catch (PDOException $e) {
    error_log("Activity logs error: " . $e->getMessage());
    $logs = [];
    $module_breakdown = [];
    $total_in_db = 0;
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

<style id="activity-logs-scoped">
/* ============================================================
   🔵 BLUE THEME VARIABLES
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
    
    --blue-primary: #1e40af;
    --blue-dark: #1e3a8a;
    --blue-mid: #2563eb;
    --blue-light: #3b82f6;
    --blue-lighter: #dbeafe;
    --blue-lightest: #eff6ff;
    --blue-accent: #93c5fd;
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
    --blue-lighter: #1e3a5f;
    --blue-lightest: #1e293b;
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
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
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
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
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
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.main-wrapper .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-secondary {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.main-wrapper .btn-secondary:hover {
    background: var(--blue-lighter);
    color: var(--blue-dark);
    border-color: var(--blue-accent);
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
.main-wrapper .quick-filters-label i { color: #1e40af; font-size: 12px; }
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
    background: var(--blue-lighter);
    border-color: var(--blue-accent);
    color: var(--blue-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(30, 64, 175, 0.15);
}
.main-wrapper .quick-filter-btn.active {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border-color: #1e3a8a; color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.4);
    transform: translateY(-2px);
}
.main-wrapper .quick-filter-btn.active i { color: #FCD34D; }

/* NOTICE BOX */
.main-wrapper .notice-box {
    background: #FEF3C7;
    border: 1.5px solid #FCD34D;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    font-weight: 600;
    color: #92400E;
}
.main-wrapper .notice-box i {
    color: #D97706;
    font-size: 16px;
    flex-shrink: 0;
}
.main-wrapper .notice-box a {
    color: #1e40af;
    font-weight: 900;
    text-decoration: underline;
}
html.dark-mode .main-wrapper .notice-box {
    background: #5F3A1E;
    border-color: #D97706;
    color: #FBBF24;
}

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
    box-shadow: 0 12px 28px rgba(30, 64, 175, 0.15);
}
.main-wrapper .summary-card-blue {
    background: rgba(30, 64, 175, 0.08); border-color: rgba(30, 64, 175, 0.2);
}
.main-wrapper .summary-card-blue .summary-icon {
    background: rgba(30, 64, 175, 0.15); color: #1e40af;
    border: 1.5px solid rgba(30, 64, 175, 0.3);
}
.main-wrapper .summary-card-blue .summary-value { color: #1e3a8a; }

.main-wrapper .summary-card-purple {
    background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2);
}
.main-wrapper .summary-card-purple .summary-icon {
    background: rgba(124, 58, 237, 0.15); color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.main-wrapper .summary-card-purple .summary-value { color: #6D28D9; }

.main-wrapper .summary-card-orange {
    background: rgba(217, 119, 6, 0.08); border-color: rgba(217, 119, 6, 0.2);
}
.main-wrapper .summary-card-orange .summary-icon {
    background: rgba(217, 119, 6, 0.15); color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.main-wrapper .summary-card-orange .summary-value { color: #B45309; }

.main-wrapper .summary-card-red {
    background: rgba(220, 38, 38, 0.08); border-color: rgba(220, 38, 38, 0.2);
}
.main-wrapper .summary-card-red .summary-icon {
    background: rgba(220, 38, 38, 0.15); color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.main-wrapper .summary-card-red .summary-value { color: #B91C1C; }

html.dark-mode .main-wrapper .summary-card-blue { background: rgba(30, 64, 175, 0.15); border-color: rgba(30, 64, 175, 0.3); }
html.dark-mode .main-wrapper .summary-card-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .main-wrapper .summary-card-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .main-wrapper .summary-card-red { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .main-wrapper .summary-card-blue .summary-value { color: #60A5FA; }
html.dark-mode .main-wrapper .summary-card-purple .summary-value { color: #C4B5FD; }
html.dark-mode .main-wrapper .summary-card-orange .summary-value { color: #FBBF24; }
html.dark-mode .main-wrapper .summary-card-red .summary-value { color: #FCA5A5; }

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
    font-size: 22px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2; word-break: break-word;
}
.main-wrapper .summary-sub {
    font-size: 10px; font-weight: 600; color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;
    flex-wrap: wrap;
}

/* MODULE BREAKDOWN */
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
.main-wrapper .category-section-title i { color: #1e40af; }
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
    border-color: #3b82f6;
    box-shadow: 0 4px 14px rgba(59, 130, 246, 0.15);
}
.main-wrapper .category-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #1e40af;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .main-wrapper .category-icon {
    background: #1e3a5f; color: #60A5FA; border-color: #3b82f6;
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
    color: #1e40af;
    font-family: 'Inter', 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .main-wrapper .category-amount { color: #60A5FA; }

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
.main-wrapper .filter-group label i { color: #1e40af; }
.main-wrapper .form-control {
    padding: 10px 14px; border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 13px;
    color: var(--text-primary); background: var(--bg-input);
    transition: all 0.3s ease; font-family: 'Inter', sans-serif;
    width: 100%;
}
.main-wrapper .form-control:focus {
    outline: none; border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
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
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
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

/* DELETE ALL BUTTON */
.main-wrapper .btn-delete-all {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.25s ease;
    border: 1.5px solid #FCA5A5;
    background: #FEE2E2;
    color: #991B1B;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    flex-shrink: 0;
}
.main-wrapper .btn-delete-all:hover {
    background: #DC2626;
    border-color: #DC2626;
    color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}
.main-wrapper .btn-delete-all:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.main-wrapper .btn-delete-all i { font-size: 12px; }
html.dark-mode .main-wrapper .btn-delete-all {
    background: #7F1D1D;
    border-color: #DC2626;
    color: #FCA5A5;
}
html.dark-mode .main-wrapper .btn-delete-all:hover {
    background: #DC2626;
    color: #FFFFFF;
}

/* SEARCH */
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
    color: #1e40af; font-size: 13px; flex-shrink: 0;
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
    background: #DBEAFE; color: #1e40af; border: none;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; font-size: 10px;
    transition: all 0.2s ease; flex-shrink: 0;
}
.main-wrapper .table-search-live .search-clear-btn:hover {
    background: #1e40af; color: #FFFFFF;
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
    color: #1e40af; cursor: pointer;
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
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border-radius: 4px;
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
.main-wrapper .log-row.deleting {
    opacity: 0.4;
    background: #FEE2E2 !important;
    pointer-events: none;
    transition: all 0.3s ease;
}
html.dark-mode .main-wrapper .log-row.deleting {
    background: #7F1D1D !important;
}

.main-wrapper .row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--blue-lighter); font-size: 11px; font-weight: 800;
    color: var(--blue-dark); border: 1.5px solid var(--blue-accent);
}
html.dark-mode .main-wrapper .row-number {
    background: #1e3a5f; color: #93c5fd; border-color: #3b82f6;
}

.main-wrapper .datetime-cell {
    display: inline-flex; flex-direction: column; gap: 2px;
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary); white-space: nowrap;
}
.main-wrapper .datetime-main {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px; font-weight: 800;
    color: #1e40af;
}
html.dark-mode .main-wrapper .datetime-main { color: #93c5fd; }
.main-wrapper .datetime-time {
    font-size: 10px;
    color: var(--text-muted);
    font-family: 'Courier New', monospace;
}

.main-wrapper .action-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 11px; font-weight: 800;
    letter-spacing: 0.3px;
    white-space: nowrap;
    border: 1.5px solid;
}
.main-wrapper .action-login {
    background: #DBEAFE; color: #1E40AF; border-color: #93C5FD;
}
.main-wrapper .action-logout {
    background: #F3F4F6; color: #6B7280; border-color: #D1D5DB;
}
.main-wrapper .action-add {
    background: #DCFCE7; color: #15803D; border-color: #86EFAC;
}
.main-wrapper .action-edit {
    background: #FEF3C7; color: #B45309; border-color: #FCD34D;
}
.main-wrapper .action-delete {
    background: #FEE2E2; color: #991B1B; border-color: #FCA5A5;
}
.main-wrapper .action-other {
    background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD;
}
html.dark-mode .main-wrapper .action-login { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .main-wrapper .action-logout { background: #374151; color: #D1D5DB; border-color: #6B7280; }
html.dark-mode .main-wrapper .action-add { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .main-wrapper .action-edit { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .main-wrapper .action-delete { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .main-wrapper .action-other { background: #4C1D95; color: #C4B5FD; border-color: #8B5CF6; }

.main-wrapper .module-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
    background: #DBEAFE; color: #1e40af;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .main-wrapper .module-badge {
    background: #1e3a5f; color: #60A5FA; border-color: #3b82f6;
}

.main-wrapper .detail-cell {
    font-size: 11px;
    color: var(--text-secondary);
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.4;
}

.main-wrapper .employee-cell {
    display: flex; align-items: center; gap: 8px;
    min-width: 0;
}
.main-wrapper .employee-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 12px; color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border: 2px solid #93c5fd;
    object-fit: cover;
}
.main-wrapper .employee-name-sm {
    font-size: 12px; font-weight: 700;
    color: var(--text-primary);
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
    color: #1e40af; font-family: 'Courier New', monospace;
    background: #DBEAFE; padding: 1px 6px;
    border-radius: 4px; align-self: flex-start;
}
html.dark-mode .main-wrapper .branch-code-sm {
    background: #1e3a5f; color: #93c5fd;
}

.main-wrapper .ip-cell {
    font-family: 'Courier New', monospace;
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--bg-input);
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    white-space: nowrap;
}

/* DELETE ROW BUTTON */
.main-wrapper .btn-delete-row {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1.5px solid #FCA5A5;
    background: #FEE2E2;
    color: #991B1B;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.2s ease;
    padding: 0;
}
.main-wrapper .btn-delete-row:hover {
    background: #DC2626;
    border-color: #DC2626;
    color: #FFFFFF;
    transform: scale(1.1);
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.3);
}
.main-wrapper .btn-delete-row:active {
    transform: scale(0.95);
}
.main-wrapper .btn-delete-row i { display: block; line-height: 1; }
html.dark-mode .main-wrapper .btn-delete-row {
    background: #7F1D1D;
    border-color: #DC2626;
    color: #FCA5A5;
}
html.dark-mode .main-wrapper .btn-delete-row:hover {
    background: #DC2626;
    color: #FFFFFF;
}

/* TOTALS ROW */
.main-wrapper .data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%) !important;
    border-top: 4px solid #1e40af !important;
    border-bottom: 4px solid #1e40af !important;
}
html.dark-mode .main-wrapper .data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%) !important;
}
.main-wrapper .data-table tbody tr.totals-row td {
    padding: 16px 12px !important;
    font-weight: 900 !important;
    color: var(--text-primary);
    font-size: 13px !important;
}
.main-wrapper .grand-total-label {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #1e3a8a;
}
html.dark-mode .main-wrapper .grand-total-label { color: #93c5fd; }
.main-wrapper .grand-total-label i { color: #1e40af; font-size: 18px; }
html.dark-mode .main-wrapper .grand-total-label i { color: #60A5FA; }
.main-wrapper .grand-total-value {
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-size: 18px;
    font-weight: 900;
    color: #FFFFFF;
    background: linear-gradient(135deg, #1e40af, #2563eb);
    padding: 8px 20px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.4);
    white-space: nowrap;
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
.main-wrapper .empty-state a {
    color: #1e40af;
    font-weight: 900;
    text-decoration: underline;
}

/* TOAST */
.main-wrapper .toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 14px 22px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #FFFFFF;
    z-index: 99999;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    animation: slideInRight 0.3s ease;
    max-width: 400px;
    font-family: 'Inter', sans-serif;
}
.main-wrapper .toast-success { background: linear-gradient(135deg, #059669, #10B981); }
.main-wrapper .toast-error { background: linear-gradient(135deg, #DC2626, #EF4444); }
.main-wrapper .toast-info { background: linear-gradient(135deg, #1e40af, #2563eb); }
.main-wrapper .toast i { font-size: 18px; flex-shrink: 0; }

@keyframes slideInRight {
    from { opacity: 0; transform: translateX(100px); }
    to { opacity: 1; transform: translateX(0); }
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
    .main-wrapper .summary-value { font-size: 18px; }
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
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Activity Logs Report</span>
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
                <h2><i class="fas fa-clipboard-list" style="color:#1E40AF;"></i> Activity Logs Report</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($from_date)); ?> — <?php echo date('d M Y', strtotime($to_date)); ?>
                    •
                    <i class="fas fa-filter"></i>
                    <?php echo htmlspecialchars($filter_label); ?>
                    •
                    <i class="fas fa-list"></i>
                    <?php echo number_format($summary['total_count']); ?> logs
                </p>
            </div>
            <div class="header-right">
                <a href="export.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&type=activity_logs&format=pdf" 
                   class="btn btn-primary" target="_blank">
                    <i class="fas fa-file-pdf"></i> Export
                </a>
                <a href="index.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>" 
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
                    'module' => $selected_module,
                    'action_type' => $selected_action,
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

        <!-- NOTICE -->
        <?php if ($summary['total_count'] === 0 && $total_in_db > 0): ?>
            <div class="notice-box">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
                    <strong>Notice:</strong> Kuna <strong><?php echo number_format($total_in_db); ?></strong> activity logs kwenye database, lakini hazionekani kwa filter ya sasa (<strong><?php echo htmlspecialchars($filter_label); ?></strong>).
                    <?php if (!empty($selected_module)): ?>
                        Module filter: <strong><?php echo htmlspecialchars($selected_module); ?></strong>.
                    <?php endif; ?>
                    <?php if (!empty($selected_action)): ?>
                        Action filter: <strong><?php echo htmlspecialchars($selected_action); ?></strong>.
                    <?php endif; ?>
                    Bonyeza <a href="?">hapa</a> kuona zote (All Time).
                </span>
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards">
            <div class="summary-card summary-card-blue">
                <div class="summary-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Logs</span>
                    <span class="summary-value"><?php echo number_format($summary['total_count']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        All activities
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-purple">
                <div class="summary-icon">
                    <i class="fas fa-sign-in-alt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Logins</span>
                    <span class="summary-value"><?php echo number_format($summary['login_count']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-sign-out-alt"></i>
                        <?php echo number_format($summary['logout_count']); ?> logouts
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-orange">
                <div class="summary-icon">
                    <i class="fas fa-edit"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Data Changes</span>
                    <span class="summary-value"><?php echo number_format($summary['add_count'] + $summary['edit_count']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-plus"></i>
                        <?php echo number_format($summary['add_count']); ?> adds
                        ·
                        <i class="fas fa-pen"></i>
                        <?php echo number_format($summary['edit_count']); ?> edits
                    </span>
                </div>
            </div>
            
            <div class="summary-card summary-card-red">
                <div class="summary-icon">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Deletes</span>
                    <span class="summary-value"><?php echo number_format($summary['delete_count']); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-circle"></i>
                        <?php echo number_format($summary['other_count']); ?> other
                    </span>
                </div>
            </div>
        </div>

        <!-- MODULE BREAKDOWN -->
        <?php if (count($module_breakdown) > 0): ?>
        <div class="category-section">
            <div class="category-section-header">
                <div class="category-section-title">
                    <i class="fas fa-th-large"></i>
                    Module Activity Breakdown
                </div>
                <span style="font-size: 11px; font-weight: 800; background: #DBEAFE; color: #1E40AF; padding: 4px 12px; border-radius: 8px; border: 1.5px solid #93C5FD;">
                    <?php echo count($module_breakdown); ?> modules
                </span>
            </div>
            <div class="category-grid">
                <?php foreach ($module_breakdown as $mod): ?>
                    <a href="?quick=<?php echo htmlspecialchars($quick); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&module=<?php echo urlencode($mod['module']); ?>" 
                       class="category-card-mini" style="text-decoration: none;">
                        <div class="category-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($mod['module']); ?></span>
                            <span class="category-count"><?php echo number_format($mod['count']); ?> activities</span>
                        </div>
                        <span class="category-amount"><?php echo number_format($mod['count']); ?></span>
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
                
                <div class="filter-group">
                    <label><i class="fas fa-cube"></i> Module</label>
                    <select name="module" class="form-control">
                        <option value="">All Modules</option>
                        <?php foreach ($modules_list as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $selected_module === $m ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Action Type</label>
                    <select name="action_type" class="form-control">
                        <option value="">All Actions</option>
                        <option value="Login" <?php echo $selected_action === 'Login' ? 'selected' : ''; ?>>Login</option>
                        <option value="Logout" <?php echo $selected_action === 'Logout' ? 'selected' : ''; ?>>Logout</option>
                        <option value="Add Morning Report" <?php echo $selected_action === 'Add Morning Report' ? 'selected' : ''; ?>>Add Morning Report</option>
                        <option value="Edit Morning Report" <?php echo $selected_action === 'Edit Morning Report' ? 'selected' : ''; ?>>Edit Morning Report</option>
                        <option value="Delete Morning Report" <?php echo $selected_action === 'Delete Morning Report' ? 'selected' : ''; ?>>Delete Morning Report</option>
                        <option value="Add Deposit" <?php echo $selected_action === 'Add Deposit' ? 'selected' : ''; ?>>Add Deposit</option>
                        <option value="Add Withdrawal" <?php echo $selected_action === 'Add Withdrawal' ? 'selected' : ''; ?>>Add Withdrawal</option>
                        <option value="Add Transfer" <?php echo $selected_action === 'Add Transfer' ? 'selected' : ''; ?>>Add Transfer</option>
                        <option value="Update Profile" <?php echo $selected_action === 'Update Profile' ? 'selected' : ''; ?>>Update Profile</option>
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
                    <h3>Activity Logs</h3>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($logs); ?></span>
                </div>
                
                <div class="table-header-right">
                    <!-- DELETE ALL BUTTON -->
                    <?php if ($is_admin && count($logs) > 0): ?>
                        <button type="button" 
                                class="btn-delete-all" 
                                id="deleteAllBtn"
                                onclick="deleteAllLogs()"
                                title="Delete all filtered logs">
                            <i class="fas fa-trash-alt"></i>
                            Delete All (<?php echo count($logs); ?>)
                        </button>
                    <?php endif; ?>
                    
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search logs..."
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
            
            <?php if (count($logs) > 0): ?>
                <div class="table-wrapper" id="tableWrapper">
                    <table class="data-table" id="logsTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date & Time</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Details</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>IP Address</th>
                                <?php if ($is_admin): ?>
                                    <th style="width: 80px;" class="text-center">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <?php $i = 1; foreach ($logs as $log): 
                                $log_datetime = strtotime($log['created_at']);
                                $log_date = date('d M Y', $log_datetime);
                                $log_time = date('H:i:s', $log_datetime);
                                $emp_initial = strtoupper(substr($log['employee_name'] ?? 'S', 0, 1));
                                $emp_avatar = $log['employee_avatar'] ?? '';
                                $action = $log['action'] ?? 'Unknown';
                                $action_lower = strtolower($action);
                                
                                if (strpos($action_lower, 'login') !== false) {
                                    $action_class = 'action-login';
                                    $action_icon = 'sign-in-alt';
                                } elseif (strpos($action_lower, 'logout') !== false) {
                                    $action_class = 'action-logout';
                                    $action_icon = 'sign-out-alt';
                                } elseif (strpos($action_lower, 'delete') !== false || strpos($action_lower, 'remove') !== false) {
                                    $action_class = 'action-delete';
                                    $action_icon = 'trash';
                                } elseif (strpos($action_lower, 'edit') !== false || strpos($action_lower, 'update') !== false) {
                                    $action_class = 'action-edit';
                                    $action_icon = 'pen';
                                } elseif (strpos($action_lower, 'add') !== false || strpos($action_lower, 'create') !== false || strpos($action_lower, 'generate') !== false) {
                                    $action_class = 'action-add';
                                    $action_icon = 'plus';
                                } else {
                                    $action_class = 'action-other';
                                    $action_icon = 'circle';
                                }
                                
                                $detail_text = '';
                                if (!empty($log['new_value'])) {
                                    $detail_text = $log['new_value'];
                                } elseif (!empty($log['old_value'])) {
                                    $detail_text = $log['old_value'];
                                } else {
                                    $detail_text = '-';
                                }
                                
                                if (strlen($detail_text) > 120) {
                                    $detail_text = substr($detail_text, 0, 120) . '...';
                                }
                                
                                $search_text = strtolower(
                                    ($log['action'] ?? '') . ' ' . 
                                    ($log['module'] ?? '') . ' ' . 
                                    ($log['new_value'] ?? '') . ' ' . 
                                    ($log['old_value'] ?? '') . ' ' . 
                                    ($log['employee_name'] ?? '') . ' ' . 
                                    ($log['branch_display_name'] ?? '') . ' ' . 
                                    ($log['ip_address'] ?? '') . ' ' .
                                    $log_date . ' ' . $log_time
                                );
                            ?>
                                <tr class="log-row" id="log-row-<?php echo $log['id']; ?>" data-log-id="<?php echo $log['id']; ?>" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="datetime-cell">
                                            <span class="datetime-main"><?php echo $log_date; ?></span>
                                            <span class="datetime-time"><?php echo $log_time; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="action-badge <?php echo $action_class; ?>">
                                            <i class="fas fa-<?php echo $action_icon; ?>"></i>
                                            <?php echo htmlspecialchars($action); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="module-badge">
                                            <i class="fas fa-cube"></i>
                                            <?php echo htmlspecialchars($log['module'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="detail-cell" title="<?php echo htmlspecialchars($detail_text); ?>">
                                            <?php echo htmlspecialchars($detail_text); ?>
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
                                            <span class="employee-name-sm"><?php echo htmlspecialchars($log['employee_name'] ?? 'System'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['branch_display_name'])): ?>
                                            <div class="branch-cell">
                                                <span class="branch-name"><?php echo htmlspecialchars($log['branch_display_name']); ?></span>
                                                <?php if (!empty($log['branch_display_code'])): ?>
                                                    <span class="branch-code-sm"><?php echo htmlspecialchars($log['branch_display_code']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ip-cell">
                                            <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <?php if ($is_admin): ?>
                                        <td class="text-center">
                                            <button type="button" 
                                                    class="btn-delete-row" 
                                                    onclick="deleteLog(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars(addslashes($action)); ?>')"
                                                    title="Delete this log">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="totals-row">
                                <td colspan="<?php echo $is_admin ? 8 : 7; ?>" style="text-align:right;">
                                    <span class="grand-total-label">
                                        <i class="fas fa-trophy"></i>
                                        TOTAL LOGS (<?php echo number_format($summary['total_count']); ?> records)
                                    </span>
                                </td>
                                <td class="text-right">
                                    <span class="grand-total-value">
                                        <?php echo number_format($summary['total_count']); ?>
                                    </span>
                                </td>
                                <?php if ($is_admin): ?>
                                    <td></td>
                                <?php endif; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="empty-state" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                    <p>No logs match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Activity Logs Found</h3>
                    <p>No activity logs for this period (<?php echo htmlspecialchars($filter_label); ?>).</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Bonyeza <a href="?">hapa</a> kuona logs zote (All Time), au badilisha filters.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
</div>

<script>
// ============================================================
// CSRF TOKEN
// ============================================================
const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
const FROM_DATE = '<?php echo $from_date; ?>';
const TO_DATE = '<?php echo $to_date; ?>';
const SELECTED_MODULE = '<?php echo addslashes($selected_module); ?>';
const SELECTED_ACTION = '<?php echo addslashes($selected_action); ?>';

// ============================================================
// DELETE SINGLE LOG
// ============================================================
function deleteLog(logId, actionName) {
    if (!confirm('Delete this log?\n\n"' + actionName + '"\n\nThis action cannot be undone.')) {
        return;
    }
    
    const row = document.getElementById('log-row-' + logId);
    if (row) row.classList.add('deleting');
    
    const formData = new FormData();
    formData.append('action', 'delete_single');
    formData.append('log_id', logId);
    formData.append('csrf_token', CSRF_TOKEN);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => row.remove(), 300);
            }
            
            updateTotalCount();
        } else {
            showToast(data.message || 'Failed to delete log', 'error');
            if (row) row.classList.remove('deleting');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error. Please try again.', 'error');
        if (row) row.classList.remove('deleting');
    });
}

// ============================================================
// DELETE ALL LOGS
// ============================================================
function deleteAllLogs() {
    const count = document.querySelectorAll('#logsTableBody tr.log-row').length;
    
    if (count === 0) {
        showToast('No logs to delete', 'info');
        return;
    }
    
    if (!confirm('DELETE ALL ' + count + ' LOGS?\n\nThis action CANNOT be undone!\n\nAre you sure?')) {
        return;
    }
    
    if (!confirm('Are you ABSOLUTELY sure? This will permanently delete ' + count + ' activity logs.')) {
        return;
    }
    
    const btn = document.getElementById('deleteAllBtn');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_all');
    formData.append('from_date', FROM_DATE);
    formData.append('to_date', TO_DATE);
    formData.append('module', SELECTED_MODULE);
    formData.append('action_type', SELECTED_ACTION);
    formData.append('csrf_token', CSRF_TOKEN);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            
            const rows = document.querySelectorAll('#logsTableBody tr.log-row');
            rows.forEach((row, i) => {
                setTimeout(() => {
                    row.style.transition = 'all 0.3s ease';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-20px)';
                    setTimeout(() => row.remove(), 300);
                }, i * 20);
            });
            
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Failed to delete logs', 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error. Please try again.', 'error');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
}

// ============================================================
// UPDATE TOTAL COUNT
// ============================================================
function updateTotalCount() {
    const badge = document.getElementById('totalCountBadge');
    if (badge) {
        const count = document.querySelectorAll('#logsTableBody tr.log-row').length;
        badge.textContent = count;
    }
}

// ============================================================
// TOAST NOTIFICATION
// ============================================================
function showToast(message, type) {
    type = type || 'info';
    const icons = {
        'success': 'fa-check-circle',
        'error': 'fa-exclamation-circle',
        'info': 'fa-info-circle'
    };
    
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i><span>' + message + '</span>';
    
    document.querySelector('.main-wrapper').appendChild(toast);
    
    setTimeout(() => {
        toast.style.transition = 'all 0.4s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100px)';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

// ============================================================
// LIVE SEARCH
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('logsTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.log-row');
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

// ============================================================
// TABLE SCROLL
// ============================================================
function scrollTable(direction) {
    const wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({
        left: direction === 'left' ? -350 : 350,
        behavior: 'smooth'
    });
}

// ============================================================
// KEYBOARD
// ============================================================
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

// ============================================================
// INIT
// ============================================================
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