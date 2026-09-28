<?php
// ================================================================
// FILE: modules/daily_report/view_my_transaction.php
// WAKALA FINANCIAL SYSTEM - VIEW MY TRANSACTION (EMPLOYEE)
// 🔴 RED THEME — SCOPED CSS
// ✅ View single transaction ya employee
// ✅ Employee anaweza kuona yake TU
// ✅ Inaonyesha jina la employee + profile
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

if ($role !== 'employee') {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET TRANSACTION ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid transaction ID.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = $employee['branch_id'] ?? 0;

// ============================================================
// GET TRANSACTION (LAZIMA IWE YA EMPLOYEE HII NA BRANCH HII)
// ============================================================
try {
    $sql = "
        SELECT 
            t.*,
            p.provider_name,
            p.icon_class,
            p.color_code,
            p.provider_type,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code,
            b.location as branch_location,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            e.position as employee_position,
            e.phone as employee_phone,
            e.email as employee_email
        FROM transactions t
        LEFT JOIN providers p ON t.provider_id = p.id
        LEFT JOIN branches b ON t.branch_id = b.id
        LEFT JOIN employees e ON t.employee_id = e.id
        WHERE t.id = ? 
          AND t.employee_id = ? 
          AND t.branch_id = ?
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id, $user_id, $employee_branch_id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$txn) {
        $_SESSION['error_message'] = 'Transaction not found or you do not have permission to view it.';
        header('Location: index_employee.php');
        exit();
    }

} catch (PDOException $e) {
    error_log("View transaction error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to load transaction.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// STATUS INFO
// ============================================================
$is_deposit = ($txn['transaction_type'] === 'deposit');
$status = $txn['status'] ?? 'approved';

$status_colors = [
    'pending' => ['bg' => '#FEF3C7', 'color' => '#B45309', 'icon' => 'clock', 'label' => 'Pending'],
    'approved' => ['bg' => '#DCFCE7', 'color' => '#15803D', 'icon' => 'check-circle', 'label' => 'Approved'],
    'rejected' => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'icon' => 'times-circle', 'label' => 'Rejected'],
    'cancelled' => ['bg' => '#E5E7EB', 'color' => '#4B5563', 'icon' => 'ban', 'label' => 'Cancelled'],
];
$sc = $status_colors[$status] ?? $status_colors['approved'];

// Employee info
$emp_avatar = $txn['employee_avatar'] ?? '';
$emp_initial = strtoupper(substr($txn['employee_name'] ?? 'N', 0, 1));
$emp_name = $txn['employee_name'] ?? 'N/A';
$emp_code = $txn['employee_code'] ?? 'N/A';
$emp_position = $txn['employee_position'] ?? '';
$emp_phone = $txn['employee_phone'] ?? '';
$emp_email = $txn['employee_email'] ?? '';

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<style id="view-txn-scoped">
/* ============================================================
   VARIABLES
   ============================================================ */
.main-wrapper {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
    --red-lighter: #FEE2E2;
    --red-accent: #FCA5A5;
}
html.dark-mode .main-wrapper {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after {
    box-sizing: border-box;
}
html { width: 100%; overflow-x: hidden; }
body { width: 100%; overflow-x: hidden; margin: 0; padding: 0; }

.main-wrapper {
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--bg-body);
    transition: margin-left 0.3s ease, width 0.3s ease;
    overflow-x: hidden;
    position: relative;
}
.main-content {
    padding: 20px 24px;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}
@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 44px; width: 100%; }
    .main-content { padding: 12px 10px; width: 100%; }
}

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   BRANCH INDICATOR
   ============================================================ */
.main-wrapper .branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
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
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .date-display {
    font-size: 13px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center;
    gap: 6px; font-weight: 600;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
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
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

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
.main-wrapper .btn-secondary:hover { background: var(--bg-card); color: var(--text-primary); }

/* ============================================================
   VIEW CONTAINER
   ============================================================ */
.main-wrapper .view-container {
    max-width: 900px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* ============================================================
   MAIN CARD
   ============================================================ */
.main-wrapper .view-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.main-wrapper .view-card-header {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    padding: 20px 24px;
    color: #FFFFFF;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    position: relative;
    overflow: hidden;
}
.main-wrapper .view-card-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .view-card-header-left {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1; min-width: 0;
}
.main-wrapper .view-card-icon {
    width: 56px; height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    flex-shrink: 0;
}
.main-wrapper .view-card-title { flex: 1; min-width: 0; }
.main-wrapper .view-card-title h3 {
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 4px 0;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}
.main-wrapper .view-card-title p { font-size: 12px; margin: 0; opacity: 0.9; font-weight: 500; }
.main-wrapper .view-card-header-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .status-badge-lg {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    background: rgba(255, 255, 255, 0.22);
    border: 2px solid rgba(255, 255, 255, 0.4);
    backdrop-filter: blur(8px);
    white-space: nowrap;
}
.main-wrapper .status-badge-lg i { font-size: 16px; }

/* ============================================================
   CARD BODY
   ============================================================ */
.main-wrapper .view-card-body { padding: 28px 24px; }

/* ============================================================
   ✅ EMPLOYEE INFO BOX (NEW)
   ============================================================ */
.main-wrapper .employee-info-box {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border: 2px solid #FCA5A5;
    border-radius: 14px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
html.dark-mode .main-wrapper .employee-info-box {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 100%);
    border-color: #DC2626;
}
.main-wrapper .employee-info-box::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 200px; height: 200px;
    background: rgba(220, 38, 38, 0.05);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .employee-avatar-xl {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border: 3px solid #FCA5A5;
    object-fit: cover;
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
    position: relative;
    z-index: 1;
}
.main-wrapper .employee-info-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    z-index: 1;
}
.main-wrapper .employee-info-name {
    font-size: 20px;
    font-weight: 900;
    color: #991B1B;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
html.dark-mode .main-wrapper .employee-info-name { color: #FCA5A5; }
.main-wrapper .employee-info-name i {
    font-size: 14px;
    color: #DC2626;
    opacity: 0.7;
}
.main-wrapper .employee-info-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.main-wrapper .employee-info-code {
    font-size: 11px;
    font-weight: 800;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    background: #DC2626;
    padding: 3px 12px;
    border-radius: 6px;
    letter-spacing: 0.5px;
    border: 1.5px solid #B91C1C;
}
.main-wrapper .employee-info-role {
    font-size: 11px;
    font-weight: 700;
    color: #991B1B;
    background: #FFFFFF;
    padding: 3px 12px;
    border-radius: 6px;
    border: 1.5px solid #FCA5A5;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
html.dark-mode .main-wrapper .employee-info-role {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.main-wrapper .employee-info-contact {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 11px;
    font-weight: 600;
    color: #991B1B;
    margin-top: 2px;
}
html.dark-mode .main-wrapper .employee-info-contact { color: #FCA5A5; }
.main-wrapper .employee-info-contact span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.main-wrapper .employee-info-contact i { font-size: 10px; opacity: 0.7; }

/* ============================================================
   AMOUNT DISPLAY
   ============================================================ */
.main-wrapper .amount-display {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border: 2px solid #FCA5A5;
    border-radius: 14px;
    padding: 24px;
    text-align: center;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
html.dark-mode .main-wrapper .amount-display {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 100%);
    border-color: #DC2626;
}
.main-wrapper .amount-display::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 200px; height: 200px;
    background: rgba(220, 38, 38, 0.05);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .amount-label {
    font-size: 11px;
    font-weight: 800;
    color: #991B1B;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
    display: block;
    position: relative; z-index: 1;
}
html.dark-mode .main-wrapper .amount-label { color: #FCA5A5; }
.main-wrapper .amount-value {
    font-size: 42px;
    font-weight: 900;
    color: #DC2626;
    font-family: 'Courier New', monospace;
    letter-spacing: -1px;
    line-height: 1;
    margin-bottom: 8px;
    text-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);
    word-break: break-all;
    position: relative; z-index: 1;
}
html.dark-mode .main-wrapper .amount-value { color: #FCA5A5; }
.main-wrapper .amount-in-words {
    font-size: 13px;
    font-weight: 700;
    color: #B91C1C;
    font-style: italic;
    text-transform: capitalize;
    position: relative; z-index: 1;
}
html.dark-mode .main-wrapper .amount-in-words { color: #FECACA; }

/* TYPE BADGE */
.main-wrapper .type-badge-display {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 20px;
}
.main-wrapper .type-badge-display.deposit {
    background: linear-gradient(135deg, #DCFCE7, #BBF7D0);
    color: #15803D;
    border: 2px solid #86EFAC;
}
.main-wrapper .type-badge-display.withdrawal {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border: 2px solid #FCA5A5;
}
html.dark-mode .main-wrapper .type-badge-display.deposit {
    background: #14532D; color: #4ADE80; border-color: #16A34A;
}
html.dark-mode .main-wrapper .type-badge-display.withdrawal {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.main-wrapper .type-badge-display i { font-size: 16px; }

/* INFO GRID */
.main-wrapper .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.main-wrapper .info-item {
    background: var(--bg-input);
    border-radius: 12px;
    padding: 16px 18px;
    border: 1.5px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.25s ease;
    min-width: 0;
}
.main-wrapper .info-item:hover {
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
}
.main-wrapper .info-item-full { grid-column: 1 / -1; }
.main-wrapper .info-label {
    font-size: 10px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.main-wrapper .info-label i { color: #DC2626; font-size: 11px; }
.main-wrapper .info-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    word-break: break-word;
    line-height: 1.4;
}
.main-wrapper .info-value.empty {
    color: var(--text-light);
    font-style: italic;
    font-weight: 500;
}
.main-wrapper .info-value.mono {
    font-family: 'Courier New', monospace;
    letter-spacing: 0.3px;
}

/* PROVIDER BOX */
.main-wrapper .provider-info-box {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    margin-bottom: 20px;
}
html.dark-mode .main-wrapper .provider-info-box {
    background: linear-gradient(135deg, #1E3A5F 0%, #1e293b 100%);
    border-color: #3B82F6;
}
.main-wrapper .provider-avatar-lg {
    width: 56px; height: 56px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    border: 3px solid rgba(255, 255, 255, 0.4);
}
.main-wrapper .provider-info-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.main-wrapper .provider-info-name {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
html.dark-mode .main-wrapper .provider-info-name { color: #93C5FD; }
.main-wrapper .provider-info-code {
    font-size: 11px;
    font-weight: 700;
    color: #1D4ED8;
    font-family: 'Courier New', monospace;
    background: #FFFFFF;
    padding: 3px 12px;
    border-radius: 6px;
    align-self: flex-start;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .main-wrapper .provider-info-code {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}

/* CARD FOOTER */
.main-wrapper .view-card-footer {
    padding: 16px 24px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.main-wrapper .footer-meta {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.main-wrapper .footer-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.main-wrapper .footer-meta-item i {
    color: #DC2626;
    font-size: 11px;
}
.main-wrapper .footer-meta-item strong {
    color: var(--text-secondary);
    font-weight: 700;
}

/* NOTES BOX */
.main-wrapper .notes-box {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border: 1.5px solid #FCD34D;
    border-radius: 12px;
    padding: 14px 18px;
    margin-top: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
html.dark-mode .main-wrapper .notes-box {
    background: linear-gradient(135deg, #5F3A1E 0%, #7F1D1D 100%);
    border-color: #D97706;
}
.main-wrapper .notes-box-icon {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: #D97706;
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.main-wrapper .notes-box-content {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    color: #92400E;
    line-height: 1.5;
}
html.dark-mode .main-wrapper .notes-box-content { color: #FBBF24; }

/* ALERT */
.main-wrapper .alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
    font-size: 13px;
    font-weight: 500;
}
.main-wrapper .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
html.dark-mode .main-wrapper .alert-success { background: #065F46; color: #D1FAE5; }
.main-wrapper .alert i { font-size: 20px; flex-shrink: 0; }

/* RESPONSIVE */
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .main-wrapper .branch-indicator { flex-direction: column; align-items: flex-start; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .header-right .btn { flex: 1; justify-content: center; }
    .main-wrapper .info-grid { grid-template-columns: 1fr; }
    .main-wrapper .amount-value { font-size: 30px; }
    .main-wrapper .amount-display { padding: 20px 16px; }
    .main-wrapper .view-card-body { padding: 20px 16px; }
    .main-wrapper .view-card-header { padding: 16px 18px; }
    .main-wrapper .view-card-icon { width: 46px; height: 46px; font-size: 22px; }
    .main-wrapper .view-card-title h3 { font-size: 16px; }
    .main-wrapper .provider-avatar-lg { width: 48px; height: 48px; font-size: 18px; }
    .main-wrapper .employee-avatar-xl { width: 60px; height: 60px; font-size: 22px; }
    .main-wrapper .employee-info-name { font-size: 17px; }
}
@media (max-width: 480px) {
    .main-wrapper .amount-value { font-size: 24px; }
    .main-wrapper .status-badge-lg { padding: 6px 12px; font-size: 10px; }
    .main-wrapper .view-card-header-left { gap: 10px; }
    .main-wrapper .view-card-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .view-card-header-right { width: 100%; }
    .main-wrapper .status-badge-lg { width: 100%; justify-content: center; }
    .main-wrapper .employee-info-box { flex-direction: column; align-items: flex-start; }
    .main-wrapper .employee-info-name { font-size: 16px; }
    .main-wrapper .employee-avatar-xl { width: 52px; height: 52px; font-size: 20px; }
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
                    <span class="branch-indicator-label">My Transaction</span>
                    <span class="branch-indicator-name">
                        <?php echo htmlspecialchars($txn['branch_display_name'] ?? 'Main Branch'); ?>
                    </span>
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
                <h2><i class="fas fa-eye" style="color:#DC2626;"></i> Transaction Details</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($txn['transaction_number']); ?>
                    •
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y, H:i', strtotime($txn['created_at'] ?? $txn['transaction_date'])); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="index_employee.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- SUCCESS MESSAGE -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- VIEW CONTAINER -->
        <div class="view-container">
            
            <!-- MAIN CARD -->
            <div class="view-card">
                
                <!-- CARD HEADER -->
                <div class="view-card-header">
                    <div class="view-card-header-left">
                        <div class="view-card-icon">
                            <i class="fas fa-<?php echo $is_deposit ? 'arrow-down' : 'arrow-up'; ?>"></i>
                        </div>
                        <div class="view-card-title">
                            <h3><?php echo htmlspecialchars($txn['transaction_number']); ?></h3>
                            <p>Transaction Record</p>
                        </div>
                    </div>
                    <div class="view-card-header-right">
                        <span class="status-badge-lg">
                            <i class="fas fa-<?php echo $sc['icon']; ?>"></i>
                            <?php echo $sc['label']; ?>
                        </span>
                    </div>
                </div>

                <!-- CARD BODY -->
                <div class="view-card-body">
                    
                    <!-- ✅ EMPLOYEE INFO BOX (NEW) -->
                    <div class="employee-info-box">
                        <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                            <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                 alt="<?php echo htmlspecialchars($emp_name); ?>" 
                                 class="employee-avatar-xl">
                        <?php else: ?>
                            <div class="employee-avatar-xl"><?php echo $emp_initial; ?></div>
                        <?php endif; ?>
                        <div class="employee-info-content">
                            <div class="employee-info-name">
                                <i class="fas fa-user-circle"></i>
                                <?php echo htmlspecialchars($emp_name); ?>
                            </div>
                            <div class="employee-info-meta">
                                <span class="employee-info-code">
                                    <i class="fas fa-id-badge"></i>
                                    <?php echo htmlspecialchars($emp_code); ?>
                                </span>
                                <?php if (!empty($emp_position)): ?>
                                    <span class="employee-info-role">
                                        <?php echo htmlspecialchars($emp_position); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($emp_phone) || !empty($emp_email)): ?>
                            <div class="employee-info-contact">
                                <?php if (!empty($emp_phone)): ?>
                                    <span>
                                        <i class="fas fa-phone"></i>
                                        <?php echo htmlspecialchars($emp_phone); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($emp_email)): ?>
                                    <span>
                                        <i class="fas fa-envelope"></i>
                                        <?php echo htmlspecialchars($emp_email); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- TYPE BADGE -->
                    <div style="text-align: center;">
                        <span class="type-badge-display <?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                            <i class="fas fa-<?php echo $is_deposit ? 'arrow-down' : 'arrow-up'; ?>"></i>
                            <?php echo $is_deposit ? 'Deposit' : 'Withdrawal'; ?>
                        </span>
                    </div>
                    
                    <!-- AMOUNT DISPLAY -->
                    <div class="amount-display">
                        <span class="amount-label">Transaction Amount</span>
                        <div class="amount-value">
                            <?php echo $is_deposit ? '+' : '-'; ?><?php echo formatCurrency($txn['amount']); ?>
                        </div>
                        <div class="amount-in-words" id="amountInWords"></div>
                    </div>
                    
                    <!-- PROVIDER INFO -->
                    <?php if (!empty($txn['provider_name'])): ?>
                    <div class="provider-info-box">
                        <div class="provider-avatar-lg" style="background: <?php echo htmlspecialchars($txn['color_code'] ?? '#0B5ED7'); ?>;">
                            <i class="<?php echo htmlspecialchars($txn['icon_class'] ?? 'fas fa-university'); ?>"></i>
                        </div>
                        <div class="provider-info-text">
                            <span class="provider-info-name"><?php echo htmlspecialchars($txn['provider_name']); ?></span>
                            <span class="provider-info-code">
                                <?php echo htmlspecialchars($txn['provider_code'] ?? 'N/A'); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- INFO GRID -->
                    <div class="info-grid">
                        
                        <!-- Transaction Number -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-hashtag"></i>
                                Transaction Number
                            </span>
                            <span class="info-value mono">
                                <?php echo htmlspecialchars($txn['transaction_number']); ?>
                            </span>
                        </div>
                        
                        <!-- Transaction Date -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-calendar-day"></i>
                                Transaction Date
                            </span>
                            <span class="info-value">
                                <?php echo date('l, d F Y', strtotime($txn['transaction_date'])); ?>
                            </span>
                        </div>
                        
                        <!-- Transaction Time -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-clock"></i>
                                Time
                            </span>
                            <span class="info-value mono">
                                <?php echo date('h:i A', strtotime($txn['transaction_time'] ?? $txn['created_at'])); ?>
                            </span>
                        </div>
                        
                        <!-- Provider Code -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-barcode"></i>
                                Provider Code
                            </span>
                            <span class="info-value mono">
                                <?php echo htmlspecialchars($txn['provider_code'] ?? 'N/A'); ?>
                            </span>
                        </div>
                        
                        <!-- Reference Number (Full Width) -->
                        <div class="info-item info-item-full">
                            <span class="info-label">
                                <i class="fas fa-bookmark"></i>
                                Reference Number
                            </span>
                            <span class="info-value mono <?php echo empty($txn['reference_number']) ? 'empty' : ''; ?>">
                                <?php echo !empty($txn['reference_number']) 
                                    ? htmlspecialchars($txn['reference_number']) 
                                    : 'No reference number'; ?>
                            </span>
                        </div>
                        
                        <!-- Description (Full Width) -->
                        <div class="info-item info-item-full">
                            <span class="info-label">
                                <i class="fas fa-align-left"></i>
                                Description
                            </span>
                            <span class="info-value <?php echo empty($txn['description']) ? 'empty' : ''; ?>">
                                <?php echo !empty($txn['description']) 
                                    ? nl2br(htmlspecialchars($txn['description'])) 
                                    : 'No description'; ?>
                            </span>
                        </div>
                        
                        <!-- Notes (Full Width) -->
                        <?php if (!empty($txn['notes'])): ?>
                        <div class="info-item info-item-full">
                            <span class="info-label">
                                <i class="fas fa-sticky-note"></i>
                                Notes
                            </span>
                            <span class="info-value">
                                <?php echo nl2br(htmlspecialchars($txn['notes'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                    
                    <!-- NOTES BOX -->
                    <?php if (!empty($txn['notes'])): ?>
                    <div class="notes-box">
                        <div class="notes-box-icon">
                            <i class="fas fa-info"></i>
                        </div>
                        <div class="notes-box-content">
                            <?php echo nl2br(htmlspecialchars($txn['notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                </div>
                
                <!-- CARD FOOTER -->
                <div class="view-card-footer">
                    <div class="footer-meta">
                        <div class="footer-meta-item">
                            <i class="fas fa-clock"></i>
                            <strong>Created:</strong>
                            <?php echo date('d M Y, H:i:s', strtotime($txn['created_at'] ?? $txn['transaction_date'])); ?>
                        </div>
                        <?php if (!empty($txn['updated_at']) && $txn['updated_at'] != $txn['created_at']): ?>
                        <div class="footer-meta-item">
                            <i class="fas fa-edit"></i>
                            <strong>Updated:</strong>
                            <?php echo date('d M Y, H:i:s', strtotime($txn['updated_at'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
            
        </div>
        
    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<script>
// ============================================================
// 💰 NUMBER TO WORDS (Kiswahili)
// ============================================================
function numberToWords(num) {
    if (num === 0) return 'sifuri';
    const ones = ['', 'moja', 'mbili', 'tatu', 'nne', 'tano', 'sita', 'saba', 'nane', 'tisa'];
    const tens = ['', '', 'ishirini', 'thelathini', 'arobaini', 'hamsini', 'sitini', 'sabini', 'themanini', 'tisini'];
    const scales = ['', 'elfu', 'milioni', 'bilioni', 'trilioni'];
    
    function convertHundreds(n) {
        let result = '';
        if (n >= 100) { result += ones[Math.floor(n / 100)] + ' mia '; n %= 100; }
        if (n >= 20) { result += tens[Math.floor(n / 10)] + ' '; n %= 10; }
        if (n > 0) { result += ones[n] + ' '; }
        return result;
    }
    function convert(n) {
        if (n === 0) return '';
        let result = '', scaleIndex = 0;
        while (n > 0) {
            const chunk = n % 1000;
            if (chunk > 0) {
                let chunkWords = convertHundreds(chunk);
                if (scales[scaleIndex]) chunkWords += ' ' + scales[scaleIndex];
                result = chunkWords + (result ? ' na ' + result : '');
            }
            n = Math.floor(n / 1000);
            scaleIndex++;
        }
        return result.trim();
    }
    return convert(Math.floor(num)) + ' tu';
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Amount in words
    const amountInWords = document.getElementById('amountInWords');
    const amount = <?php echo floatval($txn['amount']); ?>;
    if (amountInWords && amount > 0) {
        amountInWords.textContent = numberToWords(amount);
    }
    
    // Auto-hide success message
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 5000);
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