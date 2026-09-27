<?php
// ================================================================
// FILE: modules/salaries/view_month.php
// WAKALA FINANCIAL SYSTEM - VIEW MONTH SALARIES
// BLUE THEME — SCOPED CSS — SIDEBAR SAFE
// + Checkboxes for bulk payment
// + Live search
// + Payment modal with dropdown
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/salary_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// ============================================================
// AUTO-GENERATE SALARIES
// ============================================================
checkAndGenerateSalaries($db);

// ============================================================
// GET PARAMETERS
// ============================================================
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

// Validate month
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$salary_month = $month . '-01';

// ============================================================
// HANDLE PAYMENT
// ============================================================
$success_message = '';
$error_message = '';
$payment_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'pay_salaries') {
        try {
            $salary_ids = isset($_POST['salary_ids']) ? $_POST['salary_ids'] : [];
            $payment_method = trim($_POST['payment_method'] ?? 'cash');
            $paid_from = trim($_POST['paid_from'] ?? 'cash');
            
            if (empty($salary_ids)) {
                throw new Exception('Please select at least one salary to pay.');
            }
            
            if (!in_array($payment_method, ['cash', 'bank_transfer', 'mobile_money', 'cheque'])) {
                throw new Exception('Invalid payment method.');
            }
            
            if (!in_array($paid_from, ['cash', 'capital'])) {
                throw new Exception('Invalid payment source.');
            }
            
            // Call paySalaries function
            $result = paySalaries($db, $salary_ids, $user_id, $payment_method, $paid_from);
            
            if ($result['success'] > 0) {
                $msg = "Successfully paid {$result['success']} salaries!";
                if ($result['total'] > 0) {
                    $msg .= " Total: " . formatCurrency($result['total']);
                }
                if ($result['failed'] > 0) {
                    $msg .= " ({$result['failed']} failed)";
                }
                $_SESSION['success_message'] = $msg;
            }
            
            if (!empty($result['errors'])) {
                $error_message = implode('<br>', array_map('htmlspecialchars', $result['errors']));
            }
            
            if ($result['success'] > 0) {
                $payment_result = $result;
                header('Location: view_month.php?month=' . $month . '&branch_id=' . $branch_id);
                exit();
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_name = 'All Branches';
$branch_code = '';
if ($branch_id > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
    }
}

// Get all branches for filter
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// GET SALARIES
// ============================================================
$salaries = getSalariesByMonth($db, $salary_month, $branch_id);

// Calculate totals
$total_records = count($salaries);
$total_amount = 0;
$total_paid = 0;
$total_waiting = 0;
$total_upcoming = 0;
$count_paid = 0;
$count_waiting = 0;
$count_upcoming = 0;

foreach ($salaries as $s) {
    $total_amount += floatval($s['net_pay']);
    
    if ($s['status'] === 'paid') {
        $total_paid += floatval($s['net_pay']);
        $count_paid++;
    } elseif ($s['status'] === 'waiting') {
        $total_waiting += floatval($s['net_pay']);
        $count_waiting++;
    } elseif ($s['status'] === 'upcoming') {
        $total_upcoming += floatval($s['net_pay']);
        $count_upcoming++;
    }
}

// ============================================================
// GET GENERATION LOG
// ============================================================
$stmt = $db->prepare("
    SELECT * FROM salary_generation_log
    WHERE generation_month = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$salary_month]);
$generation_log = $stmt->fetch(PDO::FETCH_ASSOC);

$month_label = date('F Y', strtotime($salary_month));

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<style id="salaries-view-month-scoped">
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

/* BOX-SIZING — Scoped to .main-wrapper ONLY (Sidebar safe) */
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
    max-width: 100%;
    width: 100%;
}
.main-wrapper .branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
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
    width: 42px;
    height: 42px;
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
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255, 255, 255, 0.15);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 600; transition: all 0.3s ease;
    white-space: nowrap;
}
.main-wrapper .btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; }

/* PAGE HEADER */
.main-wrapper .page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.main-wrapper .page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary);
}
.main-wrapper .page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
}
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.main-wrapper .btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
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
.main-wrapper .btn-secondary:hover { background: var(--bg-card); color: var(--text-primary); }

/* ALERTS */
.main-wrapper .alert {
    padding: 14px 18px; border-radius: 10px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
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

/* MONTH CARD */
.main-wrapper .month-card {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 16px;
    padding: 26px 30px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 24px;
    color: #FFFFFF;
    box-shadow: 0 8px 32px rgba(30, 64, 175, 0.25);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}
.main-wrapper .month-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
}
.main-wrapper .month-card-icon {
    width: 80px; height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    position: relative;
    z-index: 1;
}
.main-wrapper .month-card-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}
.main-wrapper .month-card-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 4px;
}
.main-wrapper .month-card-title {
    font-size: 28px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0 0 6px 0;
    letter-spacing: -0.3px;
}
.main-wrapper .month-card-subtitle {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.9);
}
.main-wrapper .month-card-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    background: rgba(255, 255, 255, 0.22);
    border: 1.5px solid rgba(255, 255, 255, 0.35);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
}

/* SUMMARY CARDS */
.main-wrapper .summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.main-wrapper .summary-card {
    position: relative;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.main-wrapper .summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}
.main-wrapper .summary-card-blue {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.main-wrapper .summary-card-blue .summary-icon {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.main-wrapper .summary-card-blue .summary-value { color: #1D4ED8; }
.main-wrapper .summary-card-green {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.main-wrapper .summary-card-green .summary-icon {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.main-wrapper .summary-card-green .summary-value { color: #047857; }
.main-wrapper .summary-card-orange {
    background: rgba(217, 119, 6, 0.08);
    border-color: rgba(217, 119, 6, 0.2);
}
.main-wrapper .summary-card-orange .summary-icon {
    background: rgba(217, 119, 6, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.main-wrapper .summary-card-orange .summary-value { color: #B45309; }
.main-wrapper .summary-card-purple {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.main-wrapper .summary-card-purple .summary-icon {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.main-wrapper .summary-card-purple .summary-value { color: #6D28D9; }
html.dark-mode .main-wrapper .summary-card-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .main-wrapper .summary-card-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .main-wrapper .summary-card-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .main-wrapper .summary-card-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .main-wrapper .summary-card-blue .summary-value { color: #60A5FA; }
html.dark-mode .main-wrapper .summary-card-green .summary-value { color: #34D399; }
html.dark-mode .main-wrapper .summary-card-orange .summary-value { color: #FBBF24; }
html.dark-mode .main-wrapper .summary-card-purple .summary-value { color: #C4B5FD; }
.main-wrapper .summary-icon {
    width: 50px; height: 50px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.main-wrapper .summary-card:hover .summary-icon { transform: scale(1.08) rotate(-4deg); }
.main-wrapper .summary-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}
.main-wrapper .summary-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .summary-value {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}
.main-wrapper .summary-sub {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}
.main-wrapper .summary-sub i { font-size: 9px; color: var(--text-light); }
.main-wrapper .summary-decoration {
    position: absolute;
    top: -30px; right: -30px;
    width: 100px; height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
}

/* FILTER BAR */
.main-wrapper .filter-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
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
    min-width: 200px;
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
.main-wrapper .filter-actions { display: flex; gap: 8px; align-items: flex-end; }

/* TABLE CONTAINER */
.main-wrapper .table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 20px;
}

/* TABLE HEADER */
.main-wrapper .table-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 16px 20px;
    color: #FFFFFF;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    overflow: hidden;
}
.main-wrapper .table-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .table-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}
.main-wrapper .table-header-left i {
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
.main-wrapper .table-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    white-space: nowrap;
}
.main-wrapper .count-badge {
    background: rgba(255,255,255,0.22);
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    white-space: nowrap;
}

/* TABLE WRAPPER */
.main-wrapper .table-wrapper {
    overflow-x: auto;
    max-width: 100%;
    width: 100%;
    -webkit-overflow-scrolling: touch;
}
.main-wrapper .table-wrapper::-webkit-scrollbar { height: 8px; }
.main-wrapper .table-wrapper::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 4px;
}
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border-radius: 4px;
}

/* DATA TABLE */
.main-wrapper .data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1000px;
}
.main-wrapper .data-table thead tr {
    background: var(--bg-table-even);
}
.main-wrapper .data-table thead th {
    padding: 14px 12px;
    text-align: left;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
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
.main-wrapper .data-table tbody tr.row-paid { background: rgba(16, 185, 129, 0.05) !important; }
.main-wrapper .data-table tbody tr.row-upcoming { background: rgba(37, 99, 235, 0.04) !important; }
.main-wrapper .data-table tbody td {
    padding: 12px;
    color: var(--text-primary);
    vertical-align: middle;
}
.main-wrapper .data-table tbody td.text-right { text-align: right; }
.main-wrapper .data-table tbody td.text-center { text-align: center; }

/* CHECKBOX */
.main-wrapper .checkbox-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
}
.main-wrapper .checkbox-wrapper input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #1e40af;
    border-radius: 4px;
}
.main-wrapper .checkbox-wrapper input[type="checkbox"]:disabled {
    cursor: not-allowed;
    opacity: 0.4;
}

/* EMPLOYEE CELL */
.main-wrapper .employee-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.main-wrapper .employee-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border: 2px solid #93c5fd;
    box-shadow: 0 2px 8px rgba(30, 64, 175, 0.25);
    object-fit: cover;
}
.main-wrapper .employee-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.main-wrapper .employee-name {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .employee-code {
    font-size: 10px;
    color: #1e40af;
    font-family: 'Courier New', monospace;
    background: #dbeafe;
    padding: 1px 6px;
    border-radius: 4px;
    align-self: flex-start;
    font-weight: 700;
}
html.dark-mode .main-wrapper .employee-code { background: #1e3a5f; color: #93c5fd; }

/* BRANCH BADGE */
.main-wrapper .branch-badge {
    display: inline-block;
    padding: 3px 10px;
    background: #f1f5f9;
    color: #475569;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid var(--border-color);
    white-space: nowrap;
}
html.dark-mode .main-wrapper .branch-badge { background: #1e293b; color: #94a3b8; }

/* AMOUNTS */
.main-wrapper .amount-cell {
    display: inline-block;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px;
    white-space: nowrap;
}
.main-wrapper .amount-base { color: #1e40af; }
.main-wrapper .amount-bonus { color: #059669; }
.main-wrapper .amount-allow { color: #0891b2; }
.main-wrapper .amount-deduct { color: #dc2626; }
.main-wrapper .amount-tax { color: #d97706; }
.main-wrapper .amount-net {
    color: #7c3aed;
    background: #ede9fe;
    padding: 4px 12px;
    border-radius: 6px;
    border: 1.5px solid #c4b5fd;
    font-size: 13px;
}
html.dark-mode .main-wrapper .amount-base { color: #60a5fa; }
html.dark-mode .main-wrapper .amount-bonus { color: #34d399; }
html.dark-mode .main-wrapper .amount-allow { color: #67e8f9; }
html.dark-mode .main-wrapper .amount-deduct { color: #fca5a5; }
html.dark-mode .main-wrapper .amount-tax { color: #fbbf24; }
html.dark-mode .main-wrapper .amount-net { color: #c4b5fd; background: #4c1d95; border-color: #8b5cf6; }

/* STATUS BADGES */
.main-wrapper .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.main-wrapper .status-paid {
    background: #D1FAE5;
    color: #065F46;
    border: 1.5px solid #6EE7B7;
}
.main-wrapper .status-waiting {
    background: #FEF3C7;
    color: #92400E;
    border: 1.5px solid #FCD34D;
}
.main-wrapper .status-upcoming {
    background: #DBEAFE;
    color: #1E40AF;
    border: 1.5px solid #93C5FD;
}
.main-wrapper .status-cancelled {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .status-paid { background: #065F46; color: #D1FAE5; }
html.dark-mode .main-wrapper .status-waiting { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .main-wrapper .status-upcoming { background: #1E3A5F; color: #93C5FD; }

/* BULK ACTIONS BAR */
.main-wrapper .bulk-actions-bar {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #93c5fd;
    border-radius: 12px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.1);
}
html.dark-mode .main-wrapper .bulk-actions-bar {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.main-wrapper .bulk-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.main-wrapper .bulk-info-icon {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #1e40af;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(30, 64, 175, 0.2);
}
html.dark-mode .main-wrapper .bulk-info-icon {
    background: #1e3a5f;
    color: #60a5fa;
}
.main-wrapper .bulk-info-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.main-wrapper .bulk-info-label {
    font-size: 11px;
    font-weight: 800;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
html.dark-mode .main-wrapper .bulk-info-label { color: #93c5fd; }
.main-wrapper .bulk-info-value {
    font-size: 22px;
    font-weight: 900;
    color: #1e40af;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
}
html.dark-mode .main-wrapper .bulk-info-value { color: #60a5fa; }
.main-wrapper .bulk-info-sub {
    font-size: 11px;
    font-weight: 600;
    color: #1e40af;
    opacity: 0.75;
}
html.dark-mode .main-wrapper .bulk-info-sub { color: #93c5fd; }

.main-wrapper .btn-pay-bulk {
    padding: 14px 28px;
    border: none;
    border-radius: 12px;
    font-weight: 800;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.35);
    letter-spacing: 0.3px;
    text-transform: uppercase;
    white-space: nowrap;
}
.main-wrapper .btn-pay-bulk:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-pay-bulk:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* EMPTY STATE */
.main-wrapper .empty-state {
    text-align: center;
    padding: 60px 20px;
}
.main-wrapper .empty-state i {
    font-size: 64px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.main-wrapper .empty-state h3 {
    font-size: 20px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.main-wrapper .empty-state p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0 0 20px 0;
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Salary Month</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="branch-indicator-right">
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Summary</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-invoice-dollar" style="color:#1E40AF;"></i> Monthly Salaries</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo htmlspecialchars($month_label); ?> •
                    <i class="fas fa-store-alt"></i>
                    <?php echo htmlspecialchars($branch_name); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="generate.php" class="btn btn-secondary">
                    <i class="fas fa-magic"></i> Generate
                </a>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message_session); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- MONTH CARD -->
        <div class="month-card">
            <div class="month-card-icon">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="month-card-content">
                <div class="month-card-label">Salary Month</div>
                <h1 class="month-card-title"><?php echo htmlspecialchars($month_label); ?></h1>
                <div class="month-card-subtitle">
                    <i class="fas fa-users"></i> <?php echo $total_records; ?> employees •
                    <i class="fas fa-store-alt"></i> <?php echo htmlspecialchars($branch_name); ?>
                </div>
            </div>
            <?php if ($generation_log): ?>
                <span class="month-card-badge">
                    <i class="fas fa-<?php echo $generation_log['generation_type'] === 'auto' ? 'robot' : 'hand-paper'; ?>"></i>
                    <?php echo ucfirst($generation_log['generation_type']); ?> Generated
                </span>
            <?php endif; ?>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards">
            <div class="summary-card summary-card-blue">
                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value"><?php echo formatCurrency($total_amount); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-users"></i>
                        <?php echo $total_records; ?> records
                    </span>
                </div>
                <div class="summary-decoration"></div>
            </div>
            
            <div class="summary-card summary-card-green">
                <div class="summary-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Paid</span>
                    <span class="summary-value"><?php echo formatCurrency($total_paid); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo $count_paid; ?> salaries
                    </span>
                </div>
                <div class="summary-decoration"></div>
            </div>
            
            <div class="summary-card summary-card-orange">
                <div class="summary-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Waiting</span>
                    <span class="summary-value"><?php echo formatCurrency($total_waiting); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo $count_waiting; ?> salaries
                    </span>
                </div>
                <div class="summary-decoration"></div>
            </div>
            
            <div class="summary-card summary-card-purple">
                <div class="summary-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Upcoming</span>
                    <span class="summary-value"><?php echo formatCurrency($total_upcoming); ?></span>
                    <span class="summary-sub">
                        <i class="fas fa-list"></i>
                        <?php echo $count_upcoming; ?> salaries
                    </span>
                </div>
                <div class="summary-decoration"></div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
                
                <div class="filter-group">
                    <label><i class="fas fa-store-alt"></i> Filter by Branch</label>
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Back to Summary
                    </a>
                </div>
            </form>
        </div>

        <?php if (count($salaries) > 0): ?>
            
            <!-- BULK ACTIONS BAR -->
            <div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
                <div class="bulk-info">
                    <div class="bulk-info-icon">
                        <i class="fas fa-check-square"></i>
                    </div>
                    <div class="bulk-info-content">
                        <span class="bulk-info-label">Selected for Payment</span>
                        <span class="bulk-info-value" id="selectedTotal">TSh 0</span>
                        <span class="bulk-info-sub">
                            <i class="fas fa-users"></i>
                            <span id="selectedCount">0</span> salaries selected
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-pay-bulk" onclick="openPaymentModal()">
                    <i class="fas fa-money-check-alt"></i> Pay Selected
                </button>
            </div>
            
            <!-- TABLE -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-header-left">
                        <i class="fas fa-list-alt"></i>
                        <h3>Salary Records</h3>
                        <span class="count-badge" id="visibleCount"><?php echo count($salaries); ?></span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <table class="data-table" id="salaryTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;" class="text-center">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                                    </div>
                                </th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th class="text-right">Base</th>
                                <th class="text-right">Bonus</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Deductions</th>
                                <th class="text-right">Tax</th>
                                <th class="text-right">Net Pay</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="salaryTableBody">
                            <?php foreach ($salaries as $s): 
                                $employee_initial = strtoupper(substr($s['employee_name'], 0, 1));
                                $is_payable = in_array($s['status'], ['waiting', 'upcoming']);
                                $row_class = '';
                                if ($s['status'] === 'paid') $row_class = 'row-paid';
                                elseif ($s['status'] === 'upcoming') $row_class = 'row-upcoming';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td class="text-center">
                                        <div class="checkbox-wrapper">
                                            <input type="checkbox" 
                                                   class="salary-checkbox"
                                                   value="<?php echo $s['id']; ?>"
                                                   data-amount="<?php echo floatval($s['net_pay']); ?>"
                                                   data-employee="<?php echo htmlspecialchars($s['employee_name']); ?>"
                                                   onchange="updateBulkActions()"
                                                   <?php echo !$is_payable ? 'disabled' : ''; ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <div class="employee-avatar"><?php echo $employee_initial; ?></div>
                                            <div class="employee-info">
                                                <span class="employee-name"><?php echo htmlspecialchars($s['employee_name']); ?></span>
                                                <span class="employee-code"><?php echo htmlspecialchars($s['employee_code'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($s['branch_display_name'] ?? $s['branch'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-base"><?php echo formatCurrency($s['base_salary']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-bonus"><?php echo formatCurrency($s['bonus']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-allow"><?php echo formatCurrency($s['allowances']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-deduct"><?php echo formatCurrency($s['deductions']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-tax"><?php echo formatCurrency($s['tax']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cell amount-net"><?php echo formatCurrency($s['net_pay']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge status-<?php echo $s['status']; ?>">
                                            <?php if ($s['status'] === 'paid'): ?>
                                                <i class="fas fa-check-circle"></i> Paid
                                            <?php elseif ($s['status'] === 'waiting'): ?>
                                                <i class="fas fa-clock"></i> Waiting
                                            <?php elseif ($s['status'] === 'upcoming'): ?>
                                                <i class="fas fa-hourglass-half"></i> Upcoming
                                            <?php else: ?>
                                                <?php echo ucfirst($s['status']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <?php else: ?>
            <div class="table-container">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Salaries Found</h3>
                    <p>No salaries for this month yet. Generate them first.</p>
                    <a href="generate.php" class="btn btn-primary">
                        <i class="fas fa-magic"></i> Generate Salaries
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
     PAYMENT MODAL
     ============================================================ -->
<div class="modal-overlay" id="paymentModal" onclick="closePaymentModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header modal-header-green">
            <div class="modal-header-left">
                <div class="modal-header-icon">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div>
                    <h3>Pay Selected Salaries</h3>
                    <p>Confirm payment details</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closePaymentModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" onsubmit="return validatePaymentForm()">
            <input type="hidden" name="action" value="pay_salaries">
            <div id="hiddenInputsContainer"></div>
            
            <div class="modal-body">
                
                <!-- Summary -->
                <div class="payment-summary">
                    <div class="payment-summary-item">
                        <span class="payment-summary-label">Salaries</span>
                        <span class="payment-summary-value" id="modalCount">0</span>
                    </div>
                    <div class="payment-summary-item highlight">
                        <span class="payment-summary-label">Total to Pay</span>
                        <span class="payment-summary-value" id="modalTotal">TSh 0</span>
                    </div>
                </div>
                
                <!-- Employees list -->
                <div class="payment-employees">
                    <div class="payment-employees-header">
                        <i class="fas fa-users"></i>
                        <span>Employees</span>
                    </div>
                    <div class="payment-employees-list" id="modalEmployeeList"></div>
                </div>
                
                <!-- Payment Method -->
                <div class="form-group">
                    <label>Payment Method <span class="required">*</span></label>
                    <div class="input-with-icon">
                        <i class="fas fa-credit-card input-icon"></i>
                        <select name="payment_method" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                </div>
                
                <!-- Paid From -->
                <div class="form-group">
                    <label>Paid From <span class="required">*</span></label>
                    <div class="input-with-icon">
                        <i class="fas fa-wallet input-icon"></i>
                        <select name="paid_from" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="capital">Capital</option>
                        </select>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closePaymentModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-pay-confirm">
                    <i class="fas fa-check"></i> Confirm Payment
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ============================================================
   MODAL — Scope-free CSS (sidebar safe)
   ============================================================ */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-y: auto;
}
.modal-overlay.show {
    display: flex;
    animation: fadeIn 0.2s ease forwards;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUpModal {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* MODAL — scoped box-sizing ONLY to .modal */
.modal,
.modal *,
.modal *::before,
.modal *::after {
    box-sizing: border-box;
}

.modal {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: slideUpModal 0.3s ease forwards;
    border: 1px solid #cbd5e1;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
html.dark-mode .modal {
    background: #1e293b;
    border-color: #334155;
}

.modal-header {
    padding: 20px 24px;
    border-radius: 16px 16px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.modal-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
}
.modal-header-green { background: linear-gradient(135deg, #059669, #10B981); }
.modal-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}
.modal-header-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.modal-header h3 { font-size: 17px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.modal-header p { font-size: 12px; margin: 0; color: rgba(255, 255, 255, 0.85); }
.modal-close {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px;
    background: #f8fafc;
    border-top: 1.5px solid #cbd5e1;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    border-radius: 0 0 16px 16px;
}
html.dark-mode .modal-footer { background: #334155; border-color: #475569; }

/* Payment summary */
.payment-summary {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 20px;
}
.payment-summary-item {
    background: #f8fafc;
    border: 2px solid #cbd5e1;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
}
html.dark-mode .payment-summary-item {
    background: #334155;
    border-color: #475569;
}
.payment-summary-item.highlight {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-color: #6ee7b7;
}
html.dark-mode .payment-summary-item.highlight {
    background: linear-gradient(135deg, #065f46 0%, #047857 100%);
    border-color: #10b981;
}
.payment-summary-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 4px;
}
html.dark-mode .payment-summary-label { color: #94a3b8; }
.payment-summary-item.highlight .payment-summary-label { color: #065f46; }
html.dark-mode .payment-summary-item.highlight .payment-summary-label { color: #d1fae5; }
.payment-summary-value {
    display: block;
    font-size: 22px;
    font-weight: 900;
    color: #1e293b;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .payment-summary-value { color: #f1f5f9; }
.payment-summary-item.highlight .payment-summary-value { color: #047857; }
html.dark-mode .payment-summary-item.highlight .payment-summary-value { color: #6ee7b7; }

/* Employees list */
.payment-employees {
    background: #f8fafc;
    border: 2px solid #cbd5e1;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    max-height: 200px;
    overflow-y: auto;
}
html.dark-mode .payment-employees {
    background: #334155;
    border-color: #475569;
}
.payment-employees-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 800;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 10px;
}
html.dark-mode .payment-employees-header { color: #60a5fa; }
.payment-employees-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.payment-employee-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: #ffffff;
    border-radius: 8px;
    font-size: 12px;
    border: 1px solid #e2e8f0;
}
html.dark-mode .payment-employee-item {
    background: #1e293b;
    border-color: #475569;
}
.payment-employee-name {
    font-weight: 700;
    color: #1e293b;
}
html.dark-mode .payment-employee-name { color: #f1f5f9; }
.payment-employee-amount {
    font-weight: 900;
    color: #059669;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .payment-employee-amount { color: #34d399; }

/* Form in modal */
.modal-body .form-group { margin-bottom: 16px; }
.modal-body .form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
html.dark-mode .modal-body .form-group label { color: #cbd5e1; }
.modal-body .form-group label .required { color: #DC2626; }
.modal-body .input-with-icon { position: relative; display: flex; align-items: center; }
.modal-body .input-icon {
    position: absolute;
    left: 14px;
    color: #64748b;
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}
.modal-body .input-with-icon .form-control { padding-left: 42px; }
.modal-body .form-control {
    padding: 11px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    background: #f8fafc;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}
html.dark-mode .modal-body .form-control {
    background: #334155;
    color: #f1f5f9;
    border-color: #475569;
}
.modal-body .form-control:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
}

/* Buttons */
.modal-footer .btn-pay-confirm {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #059669, #10B981);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.modal-footer .btn-pay-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
    color: #FFFFFF;
}
.modal-footer .btn-cancel {
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
    color: #334155;
    border: 1.5px solid #cbd5e1;
}
html.dark-mode .modal-footer .btn-cancel {
    background: #1e293b;
    color: #cbd5e1;
    border-color: #475569;
}
.modal-footer .btn-cancel:hover {
    background: #eff6ff;
    color: #1e40af;
    border-color: #3b82f6;
}
</style>

<script>
// ============================================================
// BULK SELECTION
// ============================================================
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.salary-checkbox:checked:not(:disabled)');
    const bulkBar = document.getElementById('bulkActionsBar');
    const countEl = document.getElementById('selectedCount');
    const totalEl = document.getElementById('selectedTotal');
    
    let total = 0;
    checkboxes.forEach(cb => {
        total += parseFloat(cb.dataset.amount) || 0;
    });
    
    if (checkboxes.length > 0) {
        bulkBar.style.display = 'flex';
        countEl.textContent = checkboxes.length;
        totalEl.textContent = formatMoney(total);
    } else {
        bulkBar.style.display = 'none';
    }
    
    // Update select-all checkbox state
    const allCheckboxes = document.querySelectorAll('.salary-checkbox:not(:disabled)');
    const selectAll = document.getElementById('selectAllCheckbox');
    if (selectAll) {
        selectAll.checked = checkboxes.length > 0 && checkboxes.length === allCheckboxes.length;
        selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
    }
}

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.salary-checkbox:not(:disabled)');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

// ============================================================
// PAYMENT MODAL
// ============================================================
function openPaymentModal() {
    const checkboxes = document.querySelectorAll('.salary-checkbox:checked:not(:disabled)');
    
    if (checkboxes.length === 0) {
        alert('Please select at least one salary.');
        return;
    }
    
    let total = 0;
    const employeeList = document.getElementById('modalEmployeeList');
    const hiddenContainer = document.getElementById('hiddenInputsContainer');
    
    employeeList.innerHTML = '';
    hiddenContainer.innerHTML = '';
    
    checkboxes.forEach(cb => {
        const id = cb.value;
        const amount = parseFloat(cb.dataset.amount) || 0;
        const employee = cb.dataset.employee || 'Unknown';
        
        total += amount;
        
        // Add hidden input
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'salary_ids[]';
        hidden.value = id;
        hiddenContainer.appendChild(hidden);
        
        // Add employee to list
        const item = document.createElement('div');
        item.className = 'payment-employee-item';
        item.innerHTML = `
            <span class="payment-employee-name">${escapeHtml(employee)}</span>
            <span class="payment-employee-amount">${formatMoney(amount)}</span>
        `;
        employeeList.appendChild(item);
    });
    
    document.getElementById('modalCount').textContent = checkboxes.length;
    document.getElementById('modalTotal').textContent = formatMoney(total);
    
    document.getElementById('paymentModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closePaymentModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('paymentModal').classList.remove('show');
    document.body.style.overflow = '';
}

function validatePaymentForm() {
    const count = document.getElementById('modalCount').textContent;
    const total = document.getElementById('modalTotal').textContent;
    
    const msg = `Confirm payment?\n\n` +
                `📊 Salaries: ${count}\n` +
                `💰 Total: ${total}\n\n` +
                `This will:\n` +
                `• Mark salaries as PAID\n` +
                `• Create expense records\n` +
                `• Update capital management\n` +
                `• Update daily reports\n\n` +
                `Continue?`;
    
    if (!confirm(msg)) {
        return false;
    }
    
    const submitBtn = document.querySelector('.btn-pay-confirm');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    return true;
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ============================================================
// KEYBOARD
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
    }
});

// ============================================================
// INIT
// ============================================================
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