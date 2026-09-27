<?php
// ================================================================
// FILE: modules/salaries/generate.php
// WAKALA FINANCIAL SYSTEM - MANUAL SALARY GENERATION
// BLUE THEME — SCOPED CSS — SIDEBAR SAFE
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
// AUTO-GENERATE SALARIES (invisible)
// ============================================================
checkAndGenerateSalaries($db);

// ============================================================
// HANDLE MANUAL GENERATION
// ============================================================
$success_message = '';
$error_message = '';
$generated_summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'generate') {
        try {
            $salary_month = trim($_POST['salary_month'] ?? '');
            $status = trim($_POST['status'] ?? 'upcoming');
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // Validate salary_month
            if (!preg_match('/^\d{4}-\d{2}$/', $salary_month)) {
                throw new Exception('Invalid month format. Use YYYY-MM.');
            }
            
            $salary_month_date = $salary_month . '-01';
            
            // Validate status
            if (!in_array($status, ['upcoming', 'waiting'])) {
                throw new Exception('Invalid status.');
            }
            
            // Check kama mwezi huu umeshagenerate
            $stmt = $db->prepare("
                SELECT total_employees, total_amount, created_at
                FROM salary_generation_log 
                WHERE generation_month = ?
                LIMIT 1
            ");
            $stmt->execute([$salary_month_date]);
            $existing_log = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_log && $existing_log['total_employees'] > 0) {
                throw new Exception(
                    'Salaries for ' . date('F Y', strtotime($salary_month_date)) . 
                    ' have already been generated (' . $existing_log['total_employees'] . 
                    ' employees, ' . formatCurrency($existing_log['total_amount']) . ').'
                );
            }
            
            // Get employees kwa filter
            $sql = "
                SELECT 
                    e.id, e.full_name, e.branch, e.branch_id, e.base_salary,
                    COALESCE(e.default_bonus, 0) as default_bonus,
                    COALESCE(e.default_allowances, 0) as default_allowances,
                    COALESCE(e.default_deductions, 0) as default_deductions,
                    COALESCE(e.default_tax, 0) as default_tax
                FROM employees e
                WHERE e.is_active = 1 
                AND e.employment_status = 'active'
                AND e.base_salary > 0
            ";
            $params = [];
            
            if ($branch_id > 0) {
                $sql .= " AND e.branch_id = ?";
                $params[] = $branch_id;
            }
            
            $sql .= " ORDER BY e.branch_id, e.full_name";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($employees)) {
                throw new Exception('No active employees found with base salary > 0.');
            }
            
            // ============================================================
            // GENERATE
            // ============================================================
            $db->beginTransaction();
            $generated_count = 0;
            $skipped_count = 0;
            $total_amount = 0;
            $generated_list = [];
            
            foreach ($employees as $emp) {
                // Check kama salary ya mwezi huu ipo tayari
                $check = $db->prepare("
                    SELECT id FROM employee_salaries 
                    WHERE employee_id = ? AND salary_month = ?
                    LIMIT 1
                ");
                $check->execute([$emp['id'], $salary_month_date]);
                if ($check->fetch()) {
                    $skipped_count++;
                    continue;
                }
                
                // Generate salary number
                $salary_number = 'SAL-' 
                               . date('Ymd', strtotime($salary_month_date)) 
                               . '-' 
                               . str_pad($emp['id'], 5, '0', STR_PAD_LEFT);
                
                // Insert salary
                $stmt = $db->prepare("
                    INSERT INTO employee_salaries 
                    (salary_number, employee_id, branch, branch_id, salary_month,
                     base_salary, bonus, allowances, tax, deductions,
                     description, status, is_auto_generated, generated_at, 
                     payment_date, paid_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), 
                            CURDATE(), NULL, NOW())
                ");
                $stmt->execute([
                    $salary_number,
                    $emp['id'],
                    $emp['branch'] ?? 'Main',
                    $emp['branch_id'],
                    $salary_month_date,
                    floatval($emp['base_salary']),
                    floatval($emp['default_bonus']),
                    floatval($emp['default_allowances']),
                    floatval($emp['default_tax']),
                    floatval($emp['default_deductions']),
                    'Manual-generated salary for ' . date('F Y', strtotime($salary_month_date)),
                    $status
                ]);
                
                $new_id = $db->lastInsertId();
                
                // Get net pay
                $check_np = $db->prepare("SELECT net_pay FROM employee_salaries WHERE id = ?");
                $check_np->execute([$new_id]);
                $np = $check_np->fetch(PDO::FETCH_ASSOC);
                $net_pay = floatval($np['net_pay'] ?? 0);
                
                $total_amount += $net_pay;
                $generated_count++;
                
                $generated_list[] = [
                    'name' => $emp['full_name'],
                    'employee_id' => $emp['branch'],
                    'base' => floatval($emp['base_salary']),
                    'net' => $net_pay
                ];
            }
            
            // Log generation
            if ($generated_count > 0) {
                $stmt = $db->prepare("
                    INSERT INTO salary_generation_log 
                    (generation_month, branch_id, total_employees, total_amount, 
                     generation_type, generated_by, created_at)
                    VALUES (?, ?, ?, ?, 'manual', ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        total_employees = total_employees + VALUES(total_employees),
                        total_amount = total_amount + VALUES(total_amount),
                        generation_type = 'manual',
                        generated_by = VALUES(generated_by)
                ");
                $stmt->execute([
                    $salary_month_date,
                    $branch_id > 0 ? $branch_id : null,
                    $generated_count,
                    $total_amount,
                    $user_id
                ]);
            }
            
            // Log activity
            logActivity(
                $user_id,
                'Manual Generate Salaries',
                'Salaries',
                null,
                '',
                "Generated {$generated_count} salaries for " . 
                date('F Y', strtotime($salary_month_date)) . 
                " (status: {$status}, total: " . number_format($total_amount, 0) . ")"
            );
            
            $db->commit();
            
            $success_message = "Successfully generated {$generated_count} salaries for " 
                             . date('F Y', strtotime($salary_month_date)) . "!";
            if ($skipped_count > 0) {
                $success_message .= " {$skipped_count} employees skipped (already had salary).";
            }
            
            $generated_summary = [
                'month' => date('F Y', strtotime($salary_month_date)),
                'status' => $status,
                'generated' => $generated_count,
                'skipped' => $skipped_count,
                'total_amount' => $total_amount,
                'employees' => $generated_list
            ];
            
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = $e->getMessage();
        }
    }
}

// ============================================================
// GET DATA FOR FORM
// ============================================================

// Branches
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preview: employees ambao watagenerate
$preview_branch = isset($_GET['preview_branch']) ? intval($_GET['preview_branch']) : 0;

$sql = "
    SELECT 
        e.id, e.employee_id, e.full_name, e.branch, e.branch_id,
        e.base_salary,
        COALESCE(e.default_bonus, 0) as default_bonus,
        COALESCE(e.default_allowances, 0) as default_allowances,
        COALESCE(e.default_deductions, 0) as default_deductions,
        COALESCE(e.default_tax, 0) as default_tax,
        b.branch_name as branch_display_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.is_active = 1 
    AND e.employment_status = 'active'
    AND e.base_salary > 0
";
$params = [];

if ($preview_branch > 0) {
    $sql .= " AND e.branch_id = ?";
    $params[] = $preview_branch;
}

$sql .= " ORDER BY e.branch_id, e.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$preview_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate preview totals
$preview_total_base = 0;
$preview_total_net = 0;
foreach ($preview_employees as $emp) {
    $gross = floatval($emp['base_salary']) 
           + floatval($emp['default_bonus']) 
           + floatval($emp['default_allowances']);
    $net = $gross - floatval($emp['default_tax']) - floatval($emp['default_deductions']);
    $preview_total_base += floatval($emp['base_salary']);
    $preview_total_net += $net;
}

// Recent generation logs
$stmt = $db->prepare("
    SELECT 
        sgl.*,
        b.branch_name as branch_display_name,
        e.full_name as generated_by_name
    FROM salary_generation_log sgl
    LEFT JOIN branches b ON sgl.branch_id = b.id
    LEFT JOIN employees e ON sgl.generated_by = e.id
    ORDER BY sgl.created_at DESC
    LIMIT 10
");
$stmt->execute();
$generation_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default month (next month)
$default_month = date('Y-m', strtotime('+1 month'));

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<style id="salaries-generate-scoped">
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
   BOX-SIZING — Scoped ONLY inside .main-wrapper
   (Sidebar haipo affected!)
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

/* GENERATE FORM CARD */
.main-wrapper .generate-form-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 20px;
}
.main-wrapper .generate-form-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 18px 24px;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    overflow: hidden;
}
.main-wrapper .generate-form-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
}
.main-wrapper .generate-form-header-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    position: relative;
    z-index: 1;
}
.main-wrapper .generate-form-header-content {
    position: relative;
    z-index: 1;
    flex: 1;
    min-width: 0;
}
.main-wrapper .generate-form-header h3 {
    font-size: 17px;
    font-weight: 800;
    margin: 0 0 3px 0;
    color: #FFFFFF;
}
.main-wrapper .generate-form-header p {
    font-size: 12px;
    margin: 0;
    color: rgba(255, 255, 255, 0.85);
}
.main-wrapper .generate-form-body {
    padding: 24px;
}

/* FORM FIELDS */
.main-wrapper .form-group { margin-bottom: 18px; }
.main-wrapper .form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.main-wrapper .form-group label .required { color: #DC2626; }
.main-wrapper .form-group label i { color: #1e40af; margin-right: 4px; }

.main-wrapper .form-control {
    padding: 12px 16px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
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
    background: var(--bg-card);
}
.main-wrapper .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* STATUS OPTIONS */
.main-wrapper .status-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 6px;
}
.main-wrapper .status-option {
    position: relative;
    cursor: pointer;
}
.main-wrapper .status-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.main-wrapper .status-option-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    transition: all 0.25s ease;
    min-width: 0;
}
.main-wrapper .status-option-card:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
}
.main-wrapper .status-option input[type="radio"]:checked + .status-option-card {
    border-color: #1e40af;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.2);
}
html.dark-mode .main-wrapper .status-option input[type="radio"]:checked + .status-option-card {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
}
.main-wrapper .status-option-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.main-wrapper .status-icon-upcoming {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #2563EB;
    border: 1.5px solid #93C5FD;
}
.main-wrapper .status-icon-waiting {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    color: #D97706;
    border: 1.5px solid #FCD34D;
}
html.dark-mode .main-wrapper .status-icon-upcoming {
    background: #1e3a5f; color: #60a5fa; border-color: #3b82f6;
}
html.dark-mode .main-wrapper .status-icon-waiting {
    background: #5f3a1e; color: #fbbf24; border-color: #d97706;
}
.main-wrapper .status-option-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
}
.main-wrapper .status-option-title {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
}
.main-wrapper .status-option-desc {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
}

/* PREVIEW BOX */
.main-wrapper .preview-box {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 2px solid #93c5fd;
    border-radius: 12px;
    padding: 16px 20px;
    margin-top: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
html.dark-mode .main-wrapper .preview-box {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.main-wrapper .preview-box-icon {
    width: 44px;
    height: 44px;
    background: rgba(30, 64, 175, 0.15);
    color: #1e40af;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 2px solid rgba(30, 64, 175, 0.3);
}
html.dark-mode .main-wrapper .preview-box-icon {
    background: rgba(96, 165, 250, 0.25);
    color: #93c5fd;
}
.main-wrapper .preview-box-content {
    flex: 1;
    min-width: 0;
}
.main-wrapper .preview-box-title {
    font-size: 12px;
    font-weight: 800;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 4px;
}
html.dark-mode .main-wrapper .preview-box-title { color: #93c5fd; }
.main-wrapper .preview-box-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.main-wrapper .preview-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.main-wrapper .preview-stat-label {
    font-size: 10px;
    font-weight: 700;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
html.dark-mode .main-wrapper .preview-stat-label { color: #93c5fd; }
.main-wrapper .preview-stat-value {
    font-size: 16px;
    font-weight: 900;
    color: #1e3a8a;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .main-wrapper .preview-stat-value { color: #dbeafe; }

/* FORM ACTIONS */
.main-wrapper .form-actions {
    display: flex;
    gap: 12px;
    padding-top: 20px;
    margin-top: 20px;
    border-top: 1.5px solid var(--border-color);
    flex-wrap: wrap;
    justify-content: flex-end;
}
.main-wrapper .btn-generate {
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
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.35);
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.main-wrapper .btn-generate:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(30, 64, 175, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-generate:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* RESULT CARD */
.main-wrapper .result-card {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 2px solid #6ee7b7;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.15);
}
html.dark-mode .main-wrapper .result-card {
    background: linear-gradient(135deg, #065f46 0%, #047857 100%);
    border-color: #10b981;
}
.main-wrapper .result-card::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 180px; height: 180px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .result-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}
.main-wrapper .result-header-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #059669;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.main-wrapper .result-header-content {
    flex: 1;
    min-width: 0;
}
.main-wrapper .result-header h3 {
    font-size: 20px;
    font-weight: 900;
    color: #065F46;
    margin: 0 0 3px 0;
}
html.dark-mode .main-wrapper .result-header h3 { color: #d1fae5; }
.main-wrapper .result-header p {
    font-size: 13px;
    color: #047857;
    margin: 0;
}
html.dark-mode .main-wrapper .result-header p { color: #6ee7b7; }

.main-wrapper .result-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    position: relative;
    z-index: 1;
}
.main-wrapper .result-stat {
    background: rgba(255, 255, 255, 0.7);
    border: 1.5px solid rgba(16, 185, 129, 0.3);
    border-radius: 10px;
    padding: 14px 16px;
    text-align: center;
}
html.dark-mode .main-wrapper .result-stat {
    background: rgba(15, 23, 42, 0.4);
    border-color: rgba(52, 211, 153, 0.3);
}
.main-wrapper .result-stat-label {
    font-size: 11px;
    font-weight: 800;
    color: #047857;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 4px;
}
html.dark-mode .main-wrapper .result-stat-label { color: #6ee7b7; }
.main-wrapper .result-stat-value {
    font-size: 22px;
    font-weight: 900;
    color: #065F46;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .main-wrapper .result-stat-value { color: #d1fae5; }

/* LOGS TABLE */
.main-wrapper .logs-section {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 20px;
}
.main-wrapper .logs-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 16px 22px;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    overflow: hidden;
}
.main-wrapper .logs-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
}
.main-wrapper .logs-header i {
    font-size: 22px;
    background: rgba(255, 255, 255, 0.18);
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.25);
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.main-wrapper .logs-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    position: relative;
    z-index: 1;
}
.main-wrapper .logs-table-wrapper {
    overflow-x: auto;
    max-width: 100%;
}
.main-wrapper .logs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 700px;
}
.main-wrapper .logs-table thead tr {
    background: var(--bg-table-even);
}
.main-wrapper .logs-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.main-wrapper .logs-table thead th.text-right { text-align: right; }
.main-wrapper .logs-table thead th.text-center { text-align: center; }
.main-wrapper .logs-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.main-wrapper .logs-table tbody tr:hover { background: var(--bg-table-hover); }
.main-wrapper .logs-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.main-wrapper .logs-table tbody td {
    padding: 12px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.main-wrapper .logs-table tbody td.text-right { text-align: right; }
.main-wrapper .logs-table tbody td.text-center { text-align: center; }
.main-wrapper .log-month {
    font-weight: 800;
    color: #1e40af;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .main-wrapper .log-month { color: #60a5fa; }
.main-wrapper .log-count {
    display: inline-block;
    padding: 3px 10px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 6px;
    font-weight: 800;
    font-size: 11px;
    border: 1.5px solid #BFDBFE;
}
html.dark-mode .main-wrapper .log-count {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
.main-wrapper .log-amount {
    font-weight: 800;
    color: #059669;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .main-wrapper .log-amount { color: #34D399; }
.main-wrapper .log-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.main-wrapper .log-type-auto {
    background: #DBEAFE;
    color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
.main-wrapper .log-type-manual {
    background: #FEF3C7;
    color: #D97706;
    border: 1.5px solid #FCD34D;
}
html.dark-mode .main-wrapper .log-type-auto {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .main-wrapper .log-type-manual {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.main-wrapper .empty-logs {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
}
.main-wrapper .empty-logs i {
    font-size: 48px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.main-wrapper .empty-logs p {
    font-size: 13px;
    margin: 0;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .main-wrapper .form-row { grid-template-columns: 1fr; }
    .main-wrapper .result-stats { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .main-wrapper .branch-indicator { flex-direction: column; align-items: flex-start; }
    .main-wrapper .btn-back-card { width: 100%; justify-content: center; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .header-right .btn { flex: 1; justify-content: center; }
    .main-wrapper .info-banner { flex-direction: column; text-align: center; }
    .main-wrapper .status-options { grid-template-columns: 1fr; }
    .main-wrapper .form-actions { flex-direction: column; }
    .main-wrapper .form-actions .btn-generate,
    .main-wrapper .form-actions .btn-secondary {
        width: 100%;
        justify-content: center;
    }
    .main-wrapper .result-stats { grid-template-columns: 1fr; }
    .main-wrapper .preview-box-stats { gap: 12px; }
}
@media (max-width: 480px) {
    .main-wrapper .generate-form-body { padding: 18px; }
    .main-wrapper .generate-form-header { padding: 14px 18px; }
    .main-wrapper .generate-form-header h3 { font-size: 15px; }
    .main-wrapper .generate-form-header-icon { width: 40px; height: 40px; font-size: 18px; }
    .main-wrapper .result-card { padding: 18px; }
    .main-wrapper .result-header h3 { font-size: 16px; }
    .main-wrapper .result-header-icon { width: 48px; height: 48px; font-size: 22px; }
    .main-wrapper .result-stat-value { font-size: 18px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-magic"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Manual Generation</span>
                    <span class="branch-indicator-name">Salary Generator</span>
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
                <h2><i class="fas fa-magic" style="color:#1E40AF;"></i> Manual Salary Generation</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Generate salaries for a specific month (backup for auto-generation)
                </p>
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
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- RESULT CARD (kama generation imefanikiwa) -->
        <?php if ($generated_summary): ?>
            <div class="result-card">
                <div class="result-header">
                    <div class="result-header-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="result-header-content">
                        <h3>Successfully Generated!</h3>
                        <p><?php echo htmlspecialchars($generated_summary['month']); ?> • Status: <?php echo ucfirst($generated_summary['status']); ?></p>
                    </div>
                </div>
                
                <div class="result-stats">
                    <div class="result-stat">
                        <div class="result-stat-label">Generated</div>
                        <div class="result-stat-value"><?php echo $generated_summary['generated']; ?></div>
                    </div>
                    <div class="result-stat">
                        <div class="result-stat-label">Skipped</div>
                        <div class="result-stat-value"><?php echo $generated_summary['skipped']; ?></div>
                    </div>
                    <div class="result-stat">
                        <div class="result-stat-label">Total Amount</div>
                        <div class="result-stat-value"><?php echo formatCurrency($generated_summary['total_amount']); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- INFO BANNER -->
        <div class="info-banner">
            <div class="info-banner-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="info-banner-content">
                <h4>How Manual Generation Works</h4>
                <p>
                    <strong>Auto-generation</strong> runs daily (Day 21-27: Upcoming, Day 28+: Waiting). 
                    Use this page to manually generate salaries for any month. Already-generated salaries will be skipped.
                </p>
            </div>
        </div>

        <!-- GENERATE FORM -->
        <div class="generate-form-card">
            <div class="generate-form-header">
                <div class="generate-form-header-icon">
                    <i class="fas fa-magic"></i>
                </div>
                <div class="generate-form-header-content">
                    <h3>Generate Salaries</h3>
                    <p>Select month, status, and branch to generate salaries</p>
                </div>
            </div>
            
            <form method="POST" action="" onsubmit="return validateGenerateForm()">
                <input type="hidden" name="action" value="generate">
                
                <div class="generate-form-body">
                    
                    <!-- Month + Branch -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-calendar-alt"></i>
                                Salary Month <span class="required">*</span>
                            </label>
                            <input type="month" 
                                   name="salary_month" 
                                   id="salaryMonth"
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($default_month); ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-store-alt"></i>
                                Branch
                            </label>
                            <select name="branch_id" 
                                    id="branchId"
                                    class="form-control"
                                    onchange="updatePreview()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($b['branch_name']); ?>"
                                            <?php echo $preview_branch == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Status -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-toggle-on"></i>
                            Salary Status <span class="required">*</span>
                        </label>
                        <div class="status-options">
                            <label class="status-option">
                                <input type="radio" 
                                       name="status" 
                                       value="upcoming" 
                                       checked>
                                <div class="status-option-card">
                                    <div class="status-option-icon status-icon-upcoming">
                                        <i class="fas fa-hourglass-half"></i>
                                    </div>
                                    <div class="status-option-info">
                                        <span class="status-option-title">Upcoming</span>
                                        <span class="status-option-desc">Not yet payable</span>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="status-option">
                                <input type="radio" 
                                       name="status" 
                                       value="waiting">
                                <div class="status-option-card">
                                    <div class="status-option-icon status-icon-waiting">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="status-option-info">
                                        <span class="status-option-title">Waiting</span>
                                        <span class="status-option-desc">Ready to pay</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Preview Box -->
                    <div class="preview-box">
                        <div class="preview-box-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="preview-box-content">
                            <div class="preview-box-title">Preview</div>
                            <div class="preview-box-stats">
                                <div class="preview-stat">
                                    <span class="preview-stat-label">Employees</span>
                                    <span class="preview-stat-value" id="previewCount">
                                        <?php echo count($preview_employees); ?>
                                    </span>
                                </div>
                                <div class="preview-stat">
                                    <span class="preview-stat-label">Total Base</span>
                                    <span class="preview-stat-value" id="previewBase">
                                        <?php echo formatCurrency($preview_total_base); ?>
                                    </span>
                                </div>
                                <div class="preview-stat">
                                    <span class="preview-stat-label">Total Net Pay</span>
                                    <span class="preview-stat-value" id="previewNet">
                                        <?php echo formatCurrency($preview_total_net); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn-generate" id="generateBtn">
                            <i class="fas fa-magic"></i> Generate Salaries
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- RECENT LOGS -->
        <div class="logs-section">
            <div class="logs-header">
                <i class="fas fa-history"></i>
                <h3>Recent Generation Logs</h3>
            </div>
            
            <?php if (count($generation_logs) > 0): ?>
                <div class="logs-table-wrapper">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Branch</th>
                                <th class="text-center">Employees</th>
                                <th class="text-right">Total Amount</th>
                                <th class="text-center">Type</th>
                                <th>Generated By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($generation_logs as $log): 
                                $is_auto = $log['generation_type'] === 'auto';
                            ?>
                                <tr>
                                    <td>
                                        <span class="log-month">
                                            <?php echo date('M Y', strtotime($log['generation_month'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($log['branch_display_name'] ?? 'All Branches'); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="log-count"><?php echo intval($log['total_employees']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="log-amount">
                                            <?php echo formatCurrency($log['total_amount']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="log-type-badge log-type-<?php echo $is_auto ? 'auto' : 'manual'; ?>">
                                            <i class="fas fa-<?php echo $is_auto ? 'robot' : 'hand-paper'; ?>"></i>
                                            <?php echo ucfirst($log['generation_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($log['generated_by_name'] ?? ($is_auto ? 'System' : 'N/A')); ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 11px; color: var(--text-muted);">
                                            <?php echo date('d M Y H:i', strtotime($log['created_at'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-logs">
                    <i class="fas fa-history"></i>
                    <p>No generation logs yet. Generate salaries to see history.</p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<script>
// ============================================================
// PREVIEW UPDATE (kwa branch selection)
// ============================================================
function updatePreview() {
    const branchSelect = document.getElementById('branchId');
    const branchId = branchSelect.value;
    
    // Reload page with preview_branch param (simple approach)
    const url = new URL(window.location.href);
    if (branchId > 0) {
        url.searchParams.set('preview_branch', branchId);
    } else {
        url.searchParams.delete('preview_branch');
    }
    window.location.href = url.toString();
}

// ============================================================
// FORM VALIDATION
// ============================================================
function validateGenerateForm() {
    const month = document.getElementById('salaryMonth').value;
    const branchSelect = document.getElementById('branchId');
    const branchName = branchSelect.options[branchSelect.selectedIndex].text;
    const status = document.querySelector('input[name="status"]:checked').value;
    const count = <?php echo count($preview_employees); ?>;
    
    if (!month) {
        alert('Please select a month.');
        return false;
    }
    
    if (count === 0) {
        alert('No employees to generate for the selected branch.');
        return false;
    }
    
    // Confirm
    const monthLabel = new Date(month + '-01').toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
    const statusLabel = status === 'upcoming' ? 'Upcoming' : 'Waiting';
    
    let msg = 'Are you sure you want to generate salaries?\n\n';
    msg += '📅 Month: ' + monthLabel + '\n';
    msg += '🏢 Branch: ' + branchName + '\n';
    msg += '📌 Status: ' + statusLabel + '\n';
    msg += '👥 Employees: ' + count + '\n';
    msg += '\nThis will create salary records for these employees.\n';
    msg += 'Already-generated salaries will be skipped.\n';
    msg += '\nContinue?';
    
    if (!confirm(msg)) {
        return false;
    }
    
    // Disable button
    const btn = document.getElementById('generateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 10000);
    }
    
    // Dark mode sync
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