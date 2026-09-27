<?php
// ================================================================
// FILE: modules/salaries/settings.php
// WAKALA FINANCIAL SYSTEM - SALARY SETTINGS
// BLUE THEME — SCOPED CSS — SIDEBAR SAFE
// + Live Search (progressive highlight)
// + Table Scroll Buttons (< >)
// + Bulk Update: AMOUNTS (TSh) with money format
// + Beautiful Edit Modal + Bulk Modal
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
// HANDLE UPDATE
// ============================================================
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_salary') {
        try {
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $base_salary = floatval(str_replace(',', '', $_POST['base_salary'] ?? 0));
            $default_bonus = floatval(str_replace(',', '', $_POST['default_bonus'] ?? 0));
            $default_allowances = floatval(str_replace(',', '', $_POST['default_allowances'] ?? 0));
            $default_deductions = floatval(str_replace(',', '', $_POST['default_deductions'] ?? 0));
            $default_tax = floatval(str_replace(',', '', $_POST['default_tax'] ?? 0));
            
            if ($employee_id <= 0) throw new Exception('Invalid employee.');
            if ($base_salary < 0) throw new Exception('Base salary cannot be negative.');
            
            $stmt = $db->prepare("SELECT full_name, base_salary FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$emp) throw new Exception('Employee not found.');
            
            $stmt = $db->prepare("
                UPDATE employees 
                SET base_salary = ?,
                    default_bonus = ?,
                    default_allowances = ?,
                    default_deductions = ?,
                    default_tax = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $base_salary,
                $default_bonus,
                $default_allowances,
                $default_deductions,
                $default_tax,
                $employee_id
            ]);
            
            $synced = 0;
            if (function_exists('syncUnpaidSalariesForEmployee')) {
                $synced = syncUnpaidSalariesForEmployee($db, $employee_id);
            }
            
            logActivity(
                $user_id,
                'Update Salary Settings',
                'Salaries',
                $employee_id,
                'Base: ' . number_format($emp['base_salary'], 0),
                $emp['full_name'] . ' - New base: ' . number_format($base_salary, 0)
            );
            
            $msg = 'Salary settings updated for ' . $emp['full_name'] . '!';
            if ($synced > 0) {
                $msg .= ' (' . $synced . ' unpaid salary record(s) synced)';
            }
            $_SESSION['success_message'] = $msg;
            header('Location: settings.php');
            exit();
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'bulk_update') {
        try {
            $bonus_amount = floatval(str_replace(',', '', $_POST['bonus_amount'] ?? 0));
            $allowances_amount = floatval(str_replace(',', '', $_POST['allowances_amount'] ?? 0));
            $deductions_amount = floatval(str_replace(',', '', $_POST['deductions_amount'] ?? 0));
            $tax_amount = floatval(str_replace(',', '', $_POST['tax_amount'] ?? 0));
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if ($bonus_amount < 0) throw new Exception('Bonus cannot be negative.');
            if ($allowances_amount < 0) throw new Exception('Allowances cannot be negative.');
            if ($deductions_amount < 0) throw new Exception('Deductions cannot be negative.');
            if ($tax_amount < 0) throw new Exception('Tax cannot be negative.');
            
            $sql = "SELECT id, full_name, base_salary FROM employees 
                    WHERE is_active = 1 AND employment_status = 'active' AND base_salary > 0";
            $params = [];
            if ($branch_id > 0) {
                $sql .= " AND branch_id = ?";
                $params[] = $branch_id;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($employees)) throw new Exception('No active employees found.');
            
            $db->beginTransaction();
            $updated = 0;
            
            foreach ($employees as $emp) {
                $stmt = $db->prepare("
                    UPDATE employees 
                    SET default_bonus = ?,
                        default_allowances = ?,
                        default_deductions = ?,
                        default_tax = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $bonus_amount,
                    $allowances_amount,
                    $deductions_amount,
                    $tax_amount,
                    $emp['id']
                ]);
                $updated++;
            }
            
            $synced = 0;
            if (function_exists('syncAllUnpaidSalaries')) {
                $synced = syncAllUnpaidSalaries($db, $branch_id);
            }
            
            $db->commit();
            
            logActivity(
                $user_id,
                'Bulk Update Salary Settings',
                'Salaries',
                null,
                '',
                "Updated {$updated} employees: Bonus=" . number_format($bonus_amount, 0) 
                . ", Allowances=" . number_format($allowances_amount, 0)
                . ", Deductions=" . number_format($deductions_amount, 0)
                . ", Tax=" . number_format($tax_amount, 0)
            );
            
            $msg = "Bulk update successful! {$updated} employees updated.";
            if ($synced > 0) {
                $msg .= " {$synced} unpaid salary record(s) synced.";
            }
            $_SESSION['success_message'] = $msg;
            header('Location: settings.php');
            exit();
            
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = $e->getMessage();
        }
    }
}

// ============================================================
// GET EMPLOYEES
// ============================================================
$filter_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

$sql = "
    SELECT 
        e.id, e.employee_id, e.full_name, e.email, e.phone,
        e.position, e.branch, e.branch_id, e.profile_pic, e.role,
        e.base_salary,
        COALESCE(e.default_bonus, 0) as default_bonus,
        COALESCE(e.default_allowances, 0) as default_allowances,
        COALESCE(e.default_deductions, 0) as default_deductions,
        COALESCE(e.default_tax, 0) as default_tax,
        b.branch_name as branch_display_name,
        b.branch_code as branch_display_code
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.is_active = 1 
    AND e.employment_status = 'active'
    AND e.role IN ('employee', 'admin', 'super_admin')
";
$params = [];

if ($filter_branch > 0) {
    $sql .= " AND e.branch_id = ?";
    $params[] = $filter_branch;
}

$sql .= " ORDER BY e.branch_id, e.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get branches
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_base = 0;
$total_bonus = 0;
$total_allowances = 0;
$total_deductions = 0;
$total_tax = 0;
$total_net = 0;

foreach ($employees as $emp) {
    $total_base += floatval($emp['base_salary']);
    $total_bonus += floatval($emp['default_bonus']);
    $total_allowances += floatval($emp['default_allowances']);
    $total_deductions += floatval($emp['default_deductions']);
    $total_tax += floatval($emp['default_tax']);
    
    $gross = floatval($emp['base_salary']) + floatval($emp['default_bonus']) + floatval($emp['default_allowances']);
    $net = $gross - floatval($emp['default_tax']) - floatval($emp['default_deductions']);
    $total_net += $net;
}

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<style id="salaries-settings-scoped">
/* ============================================================
   SCOPED VARIABLES — only inside .main-wrapper
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

/* ============================================================
   BOX-SIZING — ONLY inside .main-wrapper
   (Sidebar haipo affected kabisa!)
   ============================================================ */
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
.main-wrapper .btn-bulk {
    background: linear-gradient(135deg, #D97706, #F59E0B);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
}
.main-wrapper .btn-bulk:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(217, 119, 6, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-reset {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.main-wrapper .btn-reset:hover { background: var(--bg-card); color: var(--text-primary); }

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

/* INFO BANNER */
.main-wrapper .info-banner {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #93c5fd; border-radius: 12px;
    padding: 16px 20px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 14px;
}
html.dark-mode .main-wrapper .info-banner {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.main-wrapper .info-banner-icon {
    width: 50px; height: 50px; border-radius: 50%;
    background: rgba(30, 64, 175, 0.15);
    color: #1e40af; display: flex; align-items: center;
    justify-content: center; font-size: 22px; flex-shrink: 0;
    border: 2px solid rgba(30, 64, 175, 0.3);
}
html.dark-mode .main-wrapper .info-banner-icon {
    background: rgba(96, 165, 250, 0.25); color: #93c5fd;
}
.main-wrapper .info-banner-content h4 {
    font-size: 14px; font-weight: 800; margin: 0 0 3px 0;
    color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;
}
html.dark-mode .main-wrapper .info-banner-content h4 { color: #93c5fd; }
.main-wrapper .info-banner-content p {
    font-size: 12px; margin: 0; color: var(--text-muted); line-height: 1.5;
}

/* SUMMARY CARDS */
.main-wrapper .summary-cards-soft {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.main-wrapper .summary-card-soft {
    position: relative; border-radius: 14px; padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0; overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.main-wrapper .summary-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}
.main-wrapper .summary-card-soft-green {
    background: rgba(5, 150, 105, 0.08); border-color: rgba(5, 150, 105, 0.2);
}
.main-wrapper .summary-card-soft-green .summary-icon-soft {
    background: rgba(5, 150, 105, 0.15); color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.main-wrapper .summary-card-soft-green .summary-value-soft { color: #047857; }
.main-wrapper .summary-card-soft-orange {
    background: rgba(217, 119, 6, 0.08); border-color: rgba(217, 119, 6, 0.2);
}
.main-wrapper .summary-card-soft-orange .summary-icon-soft {
    background: rgba(217, 119, 6, 0.15); color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.main-wrapper .summary-card-soft-orange .summary-value-soft { color: #B45309; }
.main-wrapper .summary-card-soft-blue {
    background: rgba(37, 99, 235, 0.08); border-color: rgba(37, 99, 235, 0.2);
}
.main-wrapper .summary-card-soft-blue .summary-icon-soft {
    background: rgba(37, 99, 235, 0.15); color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.main-wrapper .summary-card-soft-blue .summary-value-soft { color: #1D4ED8; }
.main-wrapper .summary-card-soft-cyan {
    background: rgba(8, 145, 178, 0.08); border-color: rgba(8, 145, 178, 0.2);
}
.main-wrapper .summary-card-soft-cyan .summary-icon-soft {
    background: rgba(8, 145, 178, 0.15); color: #0891b2;
    border: 1.5px solid rgba(8, 145, 178, 0.3);
}
.main-wrapper .summary-card-soft-cyan .summary-value-soft { color: #0e7490; }
html.dark-mode .main-wrapper .summary-card-soft-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .main-wrapper .summary-card-soft-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .main-wrapper .summary-card-soft-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .main-wrapper .summary-card-soft-cyan { background: rgba(8, 145, 178, 0.15); border-color: rgba(8, 145, 178, 0.3); }
html.dark-mode .main-wrapper .summary-card-soft-green .summary-value-soft { color: #34D399; }
html.dark-mode .main-wrapper .summary-card-soft-orange .summary-value-soft { color: #FBBF24; }
html.dark-mode .main-wrapper .summary-card-soft-blue .summary-value-soft { color: #60A5FA; }
html.dark-mode .main-wrapper .summary-card-soft-cyan .summary-value-soft { color: #67e8f9; }
.main-wrapper .summary-icon-soft {
    width: 50px; height: 50px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    transition: all 0.3s ease;
}
.main-wrapper .summary-card-soft:hover .summary-icon-soft { transform: scale(1.08) rotate(-4deg); }
.main-wrapper .summary-info-soft {
    display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px;
}
.main-wrapper .summary-label-soft {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.main-wrapper .summary-value-soft {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2; word-break: break-word;
}
.main-wrapper .summary-sub-soft {
    font-size: 10px; font-weight: 600; color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;
}
.main-wrapper .summary-sub-soft i { font-size: 9px; color: var(--text-light); }
.main-wrapper .summary-decoration-soft {
    position: absolute; top: -30px; right: -30px;
    width: 100px; height: 100px; border-radius: 50%;
    background: rgba(255, 255, 255, 0.15); pointer-events: none;
}

/* FILTER BAR */
.main-wrapper .filter-bar {
    background: var(--bg-card); border-radius: 12px;
    padding: 14px 18px; margin-bottom: 16px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    max-width: 100%;
}
.main-wrapper .filter-form {
    display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
}
.main-wrapper .filter-group {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 200px; flex: 1;
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
    outline: none; border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.main-wrapper .filter-actions { display: flex; gap: 8px; }

/* TABLE CONTAINER */
.main-wrapper .table-container {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color); overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    max-width: 100% !important; width: 100% !important;
}

/* TABLE HEADER */
.main-wrapper .table-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 16px 20px; display: flex;
    justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.main-wrapper .table-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%; width: 200px; height: 200px;
    background: rgba(255,255,255,0.08); border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .table-header-left {
    display: flex; align-items: center; gap: 12px;
    position: relative; z-index: 1; flex-shrink: 0;
}
.main-wrapper .table-header-left i {
    font-size: 22px; background: rgba(255,255,255,0.18);
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
    flex-shrink: 0;
}
.main-wrapper .table-header h3 {
    font-size: 16px; font-weight: 800; margin: 0; white-space: nowrap;
}
.main-wrapper .count-badge {
    background: rgba(255,255,255,0.22); padding: 4px 12px;
    border-radius: 10px; font-size: 11px; font-weight: 800;
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
    min-width: 260px; max-width: 320px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
html.dark-mode .main-wrapper .table-search-live {
    background: rgba(30, 41, 59, 0.98);
    border-color: rgba(59, 130, 246, 0.4);
}
.main-wrapper .table-search-live:focus-within {
    background: #FFFFFF; border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
}
html.dark-mode .main-wrapper .table-search-live:focus-within {
    background: #1e293b; border-color: #fbbf24;
}
.main-wrapper .table-search-live > i {
    color: #1e40af; font-size: 13px; flex-shrink: 0;
}
html.dark-mode .main-wrapper .table-search-live > i { color: #60a5fa; }
.main-wrapper .table-search-live input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1e293b; outline: none; min-width: 0; font-weight: 500;
}
html.dark-mode .main-wrapper .table-search-live input { color: #f1f5f9; }
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
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.main-wrapper .scroll-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}
.main-wrapper .scroll-btn i { font-size: 14px; display: block; line-height: 1; }
html.dark-mode .main-wrapper .scroll-btn {
    background: #1e3a5f; color: #93c5fd; border-color: #3b82f6;
}
html.dark-mode .main-wrapper .scroll-btn:hover {
    background: #FCD34D; color: #78350F; border-color: #FCD34D;
}

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
.main-wrapper .table-wrapper::-webkit-scrollbar-thumb:hover { background: #1e3a8a; }

/* DATA TABLE */
.main-wrapper .data-table {
    width: 100%; border-collapse: collapse;
    font-size: 13px; min-width: 1100px; max-width: none;
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
.main-wrapper .data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.main-wrapper .data-table tbody tr:hover { background: var(--bg-table-hover); }
.main-wrapper .data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.main-wrapper .data-table tbody td {
    padding: 12px; color: var(--text-primary); vertical-align: middle;
}
.main-wrapper .data-table tbody td.text-right { text-align: right; }

/* SEARCH HIGHLIGHT */
.main-wrapper .data-table tbody tr.search-match {
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important;
    border-left: 4px solid #F59E0B;
}
.main-wrapper .data-table tbody tr.search-hidden { display: none !important; }
.main-wrapper .data-table mark {
    background: #FEF08A; color: #78350F;
    padding: 1px 3px; border-radius: 3px;
    font-weight: 800;
    box-shadow: 0 1px 3px rgba(252, 211, 77, 0.5);
}
html.dark-mode .main-wrapper .data-table mark {
    background: #FCD34D; color: #78350F;
}

/* EMPLOYEE CELL */
.main-wrapper .row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--bg-input); font-size: 11px; font-weight: 800;
    color: var(--text-secondary); border: 1.5px solid var(--border-color);
}
.main-wrapper .employee-cell { display: flex; align-items: center; gap: 10px; }
.main-wrapper .employee-avatar,
.main-wrapper .employee-avatar-img {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 14px; color: #FFFFFF;
    flex-shrink: 0; background: linear-gradient(135deg, #1e40af, #2563eb);
    border: 2px solid #93c5fd;
    box-shadow: 0 2px 8px rgba(30, 64, 175, 0.25);
    object-fit: cover;
}
.main-wrapper .employee-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.main-wrapper .employee-name {
    font-size: 13px; font-weight: 800; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.main-wrapper .employee-code {
    font-size: 10px; color: #1e40af; font-family: 'Courier New', monospace;
    background: #dbeafe; padding: 1px 6px; border-radius: 4px;
    align-self: flex-start; font-weight: 700;
}
html.dark-mode .main-wrapper .employee-code { background: #1e3a5f; color: #93c5fd; }

.main-wrapper .branch-badge {
    display: inline-block; padding: 3px 10px;
    background: #f1f5f9; color: #475569;
    border-radius: 6px; font-size: 11px; font-weight: 700;
    border: 1px solid var(--border-color); white-space: nowrap;
}
html.dark-mode .main-wrapper .branch-badge { background: #1e293b; color: #94a3b8; }

.main-wrapper .amount-base,
.main-wrapper .amount-bonus,
.main-wrapper .amount-allow,
.main-wrapper .amount-deduct,
.main-wrapper .amount-tax,
.main-wrapper .amount-net {
    display: inline-block; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px; white-space: nowrap;
}
.main-wrapper .amount-base { color: #1e40af; }
.main-wrapper .amount-bonus { color: #059669; }
.main-wrapper .amount-allow { color: #0891b2; }
.main-wrapper .amount-deduct { color: #dc2626; }
.main-wrapper .amount-tax { color: #d97706; }
.main-wrapper .amount-net {
    color: #7c3aed; background: #ede9fe;
    padding: 3px 10px; border-radius: 6px;
    border: 1px solid #c4b5fd;
}
html.dark-mode .main-wrapper .amount-base { color: #60a5fa; }
html.dark-mode .main-wrapper .amount-bonus { color: #34d399; }
html.dark-mode .main-wrapper .amount-allow { color: #67e8f9; }
html.dark-mode .main-wrapper .amount-deduct { color: #fca5a5; }
html.dark-mode .main-wrapper .amount-tax { color: #fbbf24; }
html.dark-mode .main-wrapper .amount-net { color: #c4b5fd; background: #4c1d95; border-color: #8b5cf6; }

.main-wrapper .table-actions { display: flex; gap: 6px; justify-content: center; }
.main-wrapper .btn-action {
    padding: 6px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 5px;
    cursor: pointer; text-decoration: none;
    transition: all 0.2s ease; white-space: nowrap; border: none;
}
.main-wrapper .btn-edit {
    background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe;
}
.main-wrapper .btn-edit:hover {
    background: #1d4ed8; color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
}
html.dark-mode .main-wrapper .btn-edit { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
html.dark-mode .main-wrapper .btn-edit:hover { background: #2563eb; color: #FFFFFF; }

.main-wrapper .no-search-results {
    padding: 40px 20px; text-align: center;
    background: var(--bg-table-even);
}
.main-wrapper .no-search-results i {
    font-size: 48px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 12px;
}
.main-wrapper .no-search-results p {
    font-size: 14px; color: var(--text-muted); margin: 0;
}

.main-wrapper .empty-state { text-align: center; padding: 60px 20px; }
.main-wrapper .empty-state i {
    font-size: 64px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 16px;
}
.main-wrapper .empty-state h3 {
    font-size: 20px; color: var(--text-primary); margin: 0 0 8px 0;
}
.main-wrapper .empty-state p { color: var(--text-muted); font-size: 14px; margin: 0; }

/* ============================================================
   MODALS
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
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes slideUpModal {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* MODAL BOX-SIZING — scoped to .modal only (SAFE for sidebar) */
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
    max-width: 620px;
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
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
}
.modal-header-blue { background: linear-gradient(135deg, #1e40af, #2563eb); }
.modal-header-orange { background: linear-gradient(135deg, #D97706, #F59E0B); }
.modal-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}
.modal-header-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.modal-header h3 {
    font-size: 17px;
    font-weight: 800;
    margin: 0 0 2px 0;
    color: #FFFFFF;
}
.modal-header p {
    font-size: 12px;
    margin: 0;
    color: rgba(255, 255, 255, 0.85);
}
.modal-close {
    width: 36px;
    height: 36px;
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
html.dark-mode .modal-footer {
    background: #334155;
    border-color: #475569;
}

/* Form fields inside modal */
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
.modal-body .form-group label i { color: #1e40af; margin-right: 4px; }
.modal-body .input-with-icon {
    position: relative;
    display: flex;
    align-items: center;
}
.modal-body .input-icon {
    position: absolute;
    left: 14px;
    color: #64748b;
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}
.modal-body .input-icon.deduct-icon { color: #dc2626; }
.modal-body .input-with-icon .form-control { padding-left: 42px; }
.modal-body .form-control {
    padding: 10px 14px;
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
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.modal-body .form-control.money-field {
    font-family: 'Inter', 'Courier New', monospace;
    font-weight: 800;
    text-align: right;
    padding-right: 16px;
    letter-spacing: 0.5px;
    font-size: 15px;
}
.modal-body .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

/* ============================================================
   NET PREVIEW — Beautiful (Inside modal)
   ============================================================ */
.modal .net-preview {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #93c5fd;
    border-radius: 12px;
    padding: 16px 18px;
    margin-top: 8px;
}
html.dark-mode .modal .net-preview {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.modal .net-preview-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 800;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 12px;
}
html.dark-mode .modal .net-preview-header { color: #93c5fd; }
.modal .net-preview-header i { color: #1e40af; }
html.dark-mode .modal .net-preview-header i { color: #60a5fa; }
.modal .net-preview-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1.2fr;
    gap: 12px;
}
.modal .net-preview-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.8);
    border-radius: 8px;
    border: 1px solid rgba(147, 197, 253, 0.5);
    min-width: 0;
}
html.dark-mode .modal .net-preview-item {
    background: rgba(15, 23, 42, 0.5);
    border-color: rgba(59, 130, 246, 0.4);
}
.modal .net-preview-item.net-preview-highlight {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border-color: #1e40af;
}
.modal .net-label {
    font-size: 10px;
    font-weight: 700;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
html.dark-mode .modal .net-label { color: #93c5fd; }
.modal .net-preview-highlight .net-label { color: rgba(255,255,255,0.85); }
.modal .net-value {
    font-size: 14px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    line-height: 1.2;
}
.modal .net-gross { color: #1d4ed8; }
.modal .net-deduct { color: #dc2626; }
.modal .net-final { color: #FFFFFF; font-size: 16px; }
html.dark-mode .modal .net-gross { color: #60a5fa; }
html.dark-mode .modal .net-deduct { color: #fca5a5; }

/* ============================================================
   BULK MODAL — WARNING BANNER
   ============================================================ */
.modal .warning-banner {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #78350f;
    border: 2px solid #fcd34d;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    font-size: 13px;
    line-height: 1.55;
    position: relative;
    overflow: hidden;
}
.modal .warning-banner::before {
    content: '';
    position: absolute;
    top: -30px;
    right: -30px;
    width: 100px;
    height: 100px;
    background: rgba(217, 119, 6, 0.1);
    border-radius: 50%;
}
html.dark-mode .modal .warning-banner {
    background: linear-gradient(135deg, #5f3a1e 0%, #78350f 100%);
    color: #fde68a;
    border-color: #d97706;
}
.modal .warning-banner > i {
    font-size: 22px;
    color: #d97706;
    flex-shrink: 0;
    margin-top: 2px;
    position: relative;
    z-index: 1;
}
html.dark-mode .modal .warning-banner > i { color: #fbbf24; }
.modal .warning-banner strong {
    display: block;
    margin-bottom: 4px;
    font-size: 14px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    z-index: 1;
}
.modal .warning-banner > div { position: relative; z-index: 1; }

/* Section Title inside modal */
.modal .section-title {
    font-size: 13px;
    font-weight: 800;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 22px 0 12px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid #cbd5e1;
    display: flex;
    align-items: center;
    gap: 8px;
}
html.dark-mode .modal .section-title {
    color: #f1f5f9;
    border-color: #475569;
}
.modal .section-title i {
    color: #1e40af;
    font-size: 14px;
}

/* ============================================================
   EXAMPLE BOX — BEAUTIFUL (Inside modal)
   ============================================================ */
.modal .example-box {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 50%, #bfdbfe 100%);
    border: 2px solid #93c5fd;
    border-radius: 14px;
    padding: 20px 22px;
    margin-top: 20px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    font-size: 13px;
    line-height: 1.6;
    color: #1e3a8a;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.1);
}
.modal .example-box::before {
    content: '';
    position: absolute;
    top: -50px;
    right: -50px;
    width: 150px;
    height: 150px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.12) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
html.dark-mode .modal .example-box {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
    color: #dbeafe;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.2);
}
.modal .example-box > i {
    font-size: 30px;
    color: #f59e0b;
    flex-shrink: 0;
    margin-top: 2px;
    filter: drop-shadow(0 2px 6px rgba(245, 158, 11, 0.4));
    position: relative;
    z-index: 1;
}
.modal .example-box > div {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}
.modal .example-box > div > strong {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
html.dark-mode .modal .example-box > div > strong { color: #93c5fd; }
.modal .example-box strong {
    color: #1e40af;
    font-weight: 800;
}
html.dark-mode .modal .example-box strong { color: #93c5fd; }

.modal .example-box ul {
    margin: 14px 0;
    padding-left: 0;
    list-style: none;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    position: relative;
    z-index: 1;
}
.modal .example-box li {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px;
    font-weight: 700;
    margin: 0;
    padding: 12px 14px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 10px;
    border-left: 4px solid #3b82f6;
    color: #1e40af;
    transition: all 0.25s ease;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    line-height: 1.4;
}
html.dark-mode .modal .example-box li {
    background: rgba(15, 23, 42, 0.6);
    color: #93c5fd;
    border-left-color: #60a5fa;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.modal .example-box li:hover {
    background: #FFFFFF;
    transform: translateX(4px);
    border-left-color: #2563eb;
    box-shadow: 0 4px 14px rgba(59, 130, 246, 0.2);
}
html.dark-mode .modal .example-box li:hover {
    background: rgba(15, 23, 42, 0.9);
}
.modal .example-box li strong {
    color: #059669;
    font-weight: 900;
    font-size: 13px;
    white-space: nowrap;
}
html.dark-mode .modal .example-box li strong { color: #34d399; }

/* Example Result — GREEN BOX */
.modal .example-result {
    margin-top: 16px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 2px solid #6ee7b7;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    position: relative;
    z-index: 1;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.18);
}
html.dark-mode .modal .example-result {
    background: linear-gradient(135deg, #065f46 0%, #047857 100%);
    border-color: #10b981;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
}
.modal .example-result-label {
    font-size: 12px;
    font-weight: 800;
    color: #065f46;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 8px;
}
html.dark-mode .modal .example-result-label { color: #d1fae5; }
.modal .example-result-label i {
    color: #059669;
    font-size: 18px;
}
html.dark-mode .modal .example-result-label i { color: #34d399; }
.modal .example-result-value {
    font-size: 22px;
    font-weight: 900;
    color: #047857;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    text-shadow: 0 1px 2px rgba(4, 120, 87, 0.15);
    white-space: nowrap;
}
html.dark-mode .modal .example-result-value { color: #6ee7b7; text-shadow: none; }

/* Modal footer buttons */
.modal-footer .btn-save {
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
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.modal-footer .btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
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
.modal-footer .btn-bulk-submit {
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
    background: linear-gradient(135deg, #D97706, #F59E0B);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
}
.modal-footer .btn-bulk-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(217, 119, 6, 0.5);
    color: #FFFFFF;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .main-wrapper .summary-cards-soft { grid-template-columns: repeat(2, 1fr); }
    .modal .net-preview-grid { grid-template-columns: 1fr 1fr; }
    .modal .net-preview-item.net-preview-highlight { grid-column: 1 / -1; }
    .main-wrapper .data-table { min-width: 1000px; }
    .main-wrapper .table-search-live { min-width: 220px; max-width: 280px; }
}
@media (max-width: 1024px) {
    .main-wrapper .data-table { min-width: 950px; }
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
    .main-wrapper .btn-back-card { width: 100%; justify-content: center; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .header-right .btn { flex: 1; justify-content: center; }
    .main-wrapper .summary-cards-soft { grid-template-columns: 1fr; }
    .main-wrapper .info-banner { flex-direction: column; text-align: center; }
    .main-wrapper .filter-form { flex-direction: column; }
    .main-wrapper .filter-group { min-width: 100%; }
    .main-wrapper .filter-actions { width: 100%; }
    .main-wrapper .filter-actions .btn { flex: 1; justify-content: center; }
    .modal-body .form-row { grid-template-columns: 1fr; }
    .modal .net-preview-grid { grid-template-columns: 1fr; }
    .modal .example-box { flex-direction: column; align-items: center; text-align: center; }
    .modal .example-box ul { grid-template-columns: 1fr; }
    .modal .example-result { flex-direction: column; text-align: center; }
    .modal { max-width: 95vw; max-height: 95vh; }
    .modal-body { padding: 18px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn-save,
    .modal-footer .btn-cancel,
    .modal-footer .btn-bulk-submit { width: 100%; justify-content: center; }
    .main-wrapper .data-table { min-width: 850px; }
    .main-wrapper .table-header-left h3 { font-size: 14px; }
}
@media (max-width: 480px) {
    .main-wrapper .main-content { padding: 10px !important; }
    .main-wrapper .summary-value-soft { font-size: 16px; }
    .main-wrapper .summary-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .main-wrapper .employee-name { max-width: 140px; }
    .main-wrapper .data-table { min-width: 800px; }
    .main-wrapper .table-header { padding: 14px 16px; }
    .main-wrapper .table-header h3 { font-size: 13px; }
    .main-wrapper .table-header-left i { width: 36px; height: 36px; font-size: 16px; }
    .main-wrapper .table-search-live { width: 100%; }
    .main-wrapper .table-scroll-buttons { width: 100%; justify-content: center; }
    .main-wrapper .scroll-btn { width: 42px; height: 42px; font-size: 16px; }
    .modal .example-box { padding: 16px 18px; }
    .modal .example-box li { padding: 10px 12px; font-size: 11px; }
    .modal .example-result-value { font-size: 18px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Salary Settings</span>
                    <span class="branch-indicator-name"><?php echo count($employees); ?> Employees</span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <a href="index.php" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Salaries</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-cog" style="color:#1E40AF;"></i> Salary Settings</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Configure salary templates for each employee
                </p>
            </div>
            <div class="header-right">
                <button type="button" class="btn btn-bulk" onclick="openBulkModal()">
                    <i class="fas fa-layer-group"></i> Bulk Update
                </button>
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
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- INFO BANNER -->
        <div class="info-banner">
            <div class="info-banner-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="info-banner-content">
                <h4>How It Works</h4>
                <p>
                    Salaries are auto-generated every month using these templates. 
                    Set once, and the system will use these values for every month automatically.
                </p>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards-soft">
            <div class="summary-card-soft summary-card-soft-blue">
                <div class="summary-icon-soft">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Base Salary</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($total_base); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-users"></i>
                        <?php echo count($employees); ?> employees
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-green">
                <div class="summary-icon-soft">
                    <i class="fas fa-gift"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Bonus + Allowances</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($total_bonus + $total_allowances); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-plus-circle"></i>
                        Monthly additions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-orange">
                <div class="summary-icon-soft">
                    <i class="fas fa-minus-circle"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Deductions + Tax</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($total_deductions + $total_tax); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-receipt"></i>
                        Monthly reductions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-cyan">
                <div class="summary-icon-soft">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Net Pay</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($total_net); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-calculator"></i>
                        Monthly payable
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-store-alt"></i> Filter by Branch</label>
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($filter_branch > 0): ?>
                    <a href="settings.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- EMPLOYEE SALARY TABLE -->
        <div class="table-container">
            
            <div class="table-header">
                <div class="table-header-left">
                    <i class="fas fa-users"></i>
                    <h3>Employee Salary Templates</h3>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($employees); ?></span>
                </div>
                
                <div class="table-header-right">
                    
                    <!-- LIVE SEARCH -->
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search employee..."
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
                    
                    <!-- SCROLL BUTTONS -->
                    <div class="table-scroll-buttons">
                        <button type="button" 
                                class="scroll-btn" 
                                onclick="scrollTable('left')" 
                                title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button type="button" 
                                class="scroll-btn" 
                                onclick="scrollTable('right')" 
                                title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (count($employees) > 0): ?>
                <div class="table-wrapper" id="tableWrapper">
                    <table class="data-table" id="salaryTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th class="text-right">Base Salary</th>
                                <th class="text-right">Bonus</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Deductions</th>
                                <th class="text-right">Tax</th>
                                <th class="text-right">Net Pay</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salaryTableBody">
                            <?php $i = 1; foreach ($employees as $emp): 
                                $emp_initial = strtoupper(substr($emp['full_name'], 0, 1));
                                $emp_avatar = $emp['profile_pic'] ?? '';
                                
                                $gross = floatval($emp['base_salary']) + floatval($emp['default_bonus']) + floatval($emp['default_allowances']);
                                $net = $gross - floatval($emp['default_tax']) - floatval($emp['default_deductions']);
                                
                                $search_text = strtolower(
                                    $emp['full_name'] . ' ' . 
                                    $emp['employee_id'] . ' ' . 
                                    $emp['email'] . ' ' . 
                                    ($emp['branch_display_name'] ?? $emp['branch'] ?? '')
                                );
                            ?>
                                <tr data-search-text="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="employee-cell">
                                            <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                                                <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                                     alt="" class="employee-avatar-img"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="employee-avatar" style="display:none;"><?php echo $emp_initial; ?></div>
                                            <?php else: ?>
                                                <div class="employee-avatar"><?php echo $emp_initial; ?></div>
                                            <?php endif; ?>
                                            <div class="employee-info">
                                                <span class="employee-name"><?php echo htmlspecialchars($emp['full_name']); ?></span>
                                                <span class="employee-code"><?php echo htmlspecialchars($emp['employee_id']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($emp['branch_display_name'] ?? $emp['branch'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-base"><?php echo formatCurrency($emp['base_salary']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-bonus"><?php echo formatCurrency($emp['default_bonus']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-allow"><?php echo formatCurrency($emp['default_allowances']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-deduct"><?php echo formatCurrency($emp['default_deductions']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-tax"><?php echo formatCurrency($emp['default_tax']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-net"><?php echo formatCurrency($net); ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button type="button" class="btn-action btn-edit" 
                                                    onclick='openEditModal(<?php echo json_encode([
                                                        "id" => $emp["id"],
                                                        "name" => $emp["full_name"],
                                                        "employee_id" => $emp["employee_id"],
                                                        "base_salary" => floatval($emp["base_salary"]),
                                                        "default_bonus" => floatval($emp["default_bonus"]),
                                                        "default_allowances" => floatval($emp["default_allowances"]),
                                                        "default_deductions" => floatval($emp["default_deductions"]),
                                                        "default_tax" => floatval($emp["default_tax"])
                                                    ]); ?>)'>
                                                <i class="fas fa-edit"></i>
                                                <span>Edit</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="no-search-results" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No employees match your search</p>
                    <button type="button" class="btn-action btn-edit" 
                            onclick="clearLiveSearch()" 
                            style="margin-top: 12px;">
                        <i class="fas fa-times"></i>
                        <span>Clear Search</span>
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h3>No Employees Found</h3>
                    <p>No employees matching your filter criteria.</p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
     EDIT MODAL (Employee)
     ============================================================ -->
<div class="modal-overlay" id="editModal" onclick="closeEditModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header modal-header-blue">
            <div class="modal-header-left">
                <div class="modal-header-icon">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div>
                    <h3>Edit Salary Settings</h3>
                    <p id="editModalSubtitle">Loading...</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeEditModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" onsubmit="return validateEditForm()">
            <input type="hidden" name="action" value="update_salary">
            <input type="hidden" name="employee_id" id="editEmployeeId">
            
            <div class="modal-body">
                
                <div class="form-group">
                    <label>Base Salary (TSh) <span class="required">*</span></label>
                    <div class="input-with-icon">
                        <i class="fas fa-wallet input-icon"></i>
                        <input type="text" name="base_salary" id="editBaseSalary" 
                               class="form-control money-field" required
                               placeholder="0"
                               oninput="formatMoneyInput(this); updateNetPreview();">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bonus (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-gift input-icon"></i>
                            <input type="text" name="default_bonus" id="editBonus" 
                                   class="form-control money-field"
                                   placeholder="0"
                                   oninput="formatMoneyInput(this); updateNetPreview();">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Allowances (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-hand-holding-usd input-icon"></i>
                            <input type="text" name="default_allowances" id="editAllowances" 
                                   class="form-control money-field"
                                   placeholder="0"
                                   oninput="formatMoneyInput(this); updateNetPreview();">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Deductions (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-minus-circle input-icon deduct-icon"></i>
                            <input type="text" name="default_deductions" id="editDeductions" 
                                   class="form-control money-field"
                                   placeholder="0"
                                   oninput="formatMoneyInput(this); updateNetPreview();">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Tax (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-percent input-icon deduct-icon"></i>
                            <input type="text" name="default_tax" id="editTax" 
                                   class="form-control money-field"
                                   placeholder="0"
                                   oninput="formatMoneyInput(this); updateNetPreview();">
                        </div>
                    </div>
                </div>
                
                <!-- NET PREVIEW -->
                <div class="net-preview" id="netPreview">
                    <div class="net-preview-header">
                        <i class="fas fa-calculator"></i>
                        <span>Net Pay Preview</span>
                    </div>
                    <div class="net-preview-grid">
                        <div class="net-preview-item">
                            <span class="net-label">Gross</span>
                            <span class="net-value net-gross" id="previewGross">TSh 0</span>
                        </div>
                        <div class="net-preview-item">
                            <span class="net-label">Deductions</span>
                            <span class="net-value net-deduct" id="previewDeduct">TSh 0</span>
                        </div>
                        <div class="net-preview-item net-preview-highlight">
                            <span class="net-label">NET PAY</span>
                            <span class="net-value net-final" id="previewNet">TSh 0</span>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     BULK UPDATE MODAL
     ============================================================ -->
<div class="modal-overlay" id="bulkModal" onclick="closeBulkModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header modal-header-orange">
            <div class="modal-header-left">
                <div class="modal-header-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <h3>Bulk Update Salary Settings</h3>
                    <p>Apply fixed amounts to all employees</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeBulkModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" onsubmit="return validateBulkForm()">
            <input type="hidden" name="action" value="bulk_update">
            
            <div class="modal-body">
                
                <div class="warning-banner">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Warning</strong>
                        This will update salary settings for ALL filtered employees. 
                        Existing individual settings will be overwritten.
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-store-alt"></i> Apply to Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>">
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <h4 class="section-title">
                    <i class="fas fa-money-bill-wave"></i>
                    Enter Fixed Amounts (TSh)
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bonus (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-gift input-icon"></i>
                            <input type="text" 
                                   name="bonus_amount" 
                                   id="bulkBonus"
                                   class="form-control money-field" 
                                   placeholder="0"
                                   oninput="formatMoneyInput(this);">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Allowances (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-hand-holding-usd input-icon"></i>
                            <input type="text" 
                                   name="allowances_amount" 
                                   id="bulkAllowances"
                                   class="form-control money-field" 
                                   placeholder="0"
                                   oninput="formatMoneyInput(this);">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Deductions (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-minus-circle input-icon deduct-icon"></i>
                            <input type="text" 
                                   name="deductions_amount" 
                                   id="bulkDeductions"
                                   class="form-control money-field" 
                                   placeholder="0"
                                   oninput="formatMoneyInput(this);">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Tax (TSh)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-percent input-icon deduct-icon"></i>
                            <input type="text" 
                                   name="tax_amount" 
                                   id="bulkTax"
                                   class="form-control money-field" 
                                   placeholder="0"
                                   oninput="formatMoneyInput(this);">
                        </div>
                    </div>
                </div>
                
                <!-- BULK EXAMPLE -->
                <div class="example-box">
                    <i class="fas fa-lightbulb"></i>
                    <div style="flex: 1;">
                        <strong>Example</strong>
                        If an employee has base salary <strong>300,000 TSh</strong> and you set:
                        
                        <ul>
                            <li>Bonus <strong>30,000 TSh</strong></li>
                            <li>Allowances <strong>15,000 TSh</strong></li>
                            <li>Deductions <strong>9,000 TSh</strong></li>
                            <li>Tax <strong>0 TSh</strong></li>
                        </ul>
                        
                        <div class="example-result">
                            <span class="example-result-label">
                                <i class="fas fa-calculator"></i>
                                Net Pay
                            </span>
                            <span class="example-result-value">336,000 TSh</span>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeBulkModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-bulk-submit">
                    <i class="fas fa-layer-group"></i> Apply to All
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================================
// MONEY FORMAT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldLength = input.value.length;
    let value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    const newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

function formatMoneyInputValue(num) {
    if (!num || num == 0) return '';
    return Number(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

// ============================================================
// LIVE SEARCH — Filter + Progressive Highlight
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('salaryTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResults = document.getElementById('noSearchResults');
    const tableWrapper = document.getElementById('tableWrapper');
    
    const term = searchTerm.trim();
    
    if (clearBtn) {
        clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    }
    
    if (term.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeAllMarks(row);
        });
        if (countBadge) {
            countBadge.style.display = 'none';
            countBadge.textContent = '0';
        }
        if (noResults) noResults.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = '';
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    
    rows.forEach(row => {
        removeAllMarks(row);
        
        const searchText = (row.getAttribute('data-search-text') || '').toLowerCase();
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
    
    if (noResults) {
        noResults.style.display = matchCount === 0 ? 'block' : 'none';
    }
    if (tableWrapper) {
        tableWrapper.style.display = matchCount === 0 ? 'none' : '';
    }
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
        if (cell.querySelector('.btn-edit') || cell.querySelector('button')) return;
        if (cell.querySelector('img') && cell.textContent.trim() === '') return;
        
        const walker = document.createTreeWalker(
            cell,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }
        
        textNodes.forEach(textNode => {
            const text = textNode.textContent;
            const lowerText = text.toLowerCase();
            
            if (!lowerText.includes(searchLower)) return;
            
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let index = lowerText.indexOf(searchLower);
            
            while (index !== -1) {
                if (index > lastIndex) {
                    fragment.appendChild(
                        document.createTextNode(text.substring(lastIndex, index))
                    );
                }
                
                const mark = document.createElement('mark');
                mark.textContent = text.substring(index, index + termLength);
                fragment.appendChild(mark);
                
                lastIndex = index + termLength;
                index = lowerText.indexOf(searchLower, lastIndex);
            }
            
            if (lastIndex < text.length) {
                fragment.appendChild(
                    document.createTextNode(text.substring(lastIndex))
                );
            }
            
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    });
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) {
        input.value = '';
        performLiveSearch('');
        input.focus();
    }
}

// ============================================================
// TABLE SCROLL BUTTONS
// ============================================================
function scrollTable(direction) {
    const wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    
    const scrollAmount = 350;
    const currentScroll = wrapper.scrollLeft;
    const targetScroll = direction === 'left' 
        ? currentScroll - scrollAmount 
        : currentScroll + scrollAmount;
    
    wrapper.scrollTo({
        left: targetScroll,
        behavior: 'smooth'
    });
}

// ============================================================
// EDIT MODAL
// ============================================================
function openEditModal(data) {
    document.getElementById('editEmployeeId').value = data.id;
    document.getElementById('editModalSubtitle').textContent = data.name + ' • ' + data.employee_id;
    document.getElementById('editBaseSalary').value = formatMoneyInputValue(data.base_salary);
    document.getElementById('editBonus').value = formatMoneyInputValue(data.default_bonus);
    document.getElementById('editAllowances').value = formatMoneyInputValue(data.default_allowances);
    document.getElementById('editDeductions').value = formatMoneyInputValue(data.default_deductions);
    document.getElementById('editTax').value = formatMoneyInputValue(data.default_tax);
    
    updateNetPreview();
    document.getElementById('editModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = '';
}

function updateNetPreview() {
    const base = parseMoney(document.getElementById('editBaseSalary').value);
    const bonus = parseMoney(document.getElementById('editBonus').value);
    const allow = parseMoney(document.getElementById('editAllowances').value);
    const deduct = parseMoney(document.getElementById('editDeductions').value);
    const tax = parseMoney(document.getElementById('editTax').value);
    
    const gross = base + bonus + allow;
    const totalDeduct = deduct + tax;
    const net = gross - totalDeduct;
    
    document.getElementById('previewGross').textContent = formatMoney(gross);
    document.getElementById('previewDeduct').textContent = formatMoney(totalDeduct);
    document.getElementById('previewNet').textContent = formatMoney(net);
}

function validateEditForm() {
    const base = parseMoney(document.getElementById('editBaseSalary').value);
    if (base <= 0) {
        alert('Please enter a valid base salary.');
        return false;
    }
    return true;
}

// ============================================================
// BULK MODAL
// ============================================================
function openBulkModal() {
    document.getElementById('bulkModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeBulkModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('bulkModal').classList.remove('show');
    document.body.style.overflow = '';
}

function validateBulkForm() {
    const bonus = parseMoney(document.getElementById('bulkBonus').value);
    const allow = parseMoney(document.getElementById('bulkAllowances').value);
    const deduct = parseMoney(document.getElementById('bulkDeductions').value);
    const tax = parseMoney(document.getElementById('bulkTax').value);
    
    if (bonus < 0 || allow < 0 || deduct < 0 || tax < 0) {
        alert('All amounts must be 0 or greater.');
        return false;
    }
    
    if (bonus === 0 && allow === 0 && deduct === 0 && tax === 0) {
        alert('Please enter at least one amount to apply.');
        return false;
    }
    
    let msg = 'This will apply these amounts to ALL filtered employees:\n\n';
    if (bonus > 0) msg += '  • Bonus: ' + formatMoney(bonus) + '\n';
    if (allow > 0) msg += '  • Allowances: ' + formatMoney(allow) + '\n';
    if (deduct > 0) msg += '  • Deductions: ' + formatMoney(deduct) + '\n';
    if (tax > 0) msg += '  • Tax: ' + formatMoney(tax) + '\n';
    msg += '\nExisting individual settings will be overwritten.\n\nContinue?';
    
    if (!confirm(msg)) {
        return false;
    }
    
    return true;
}

// ============================================================
// KEYBOARD
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
        closeBulkModal();
        
        const searchInput = document.getElementById('liveSearchInput');
        if (searchInput && searchInput.value.length > 0) {
            clearLiveSearch();
        }
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.getElementById('liveSearchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
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