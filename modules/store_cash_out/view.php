<?php
// ================================================================
// FILE: modules/store_cash_out/view.php
// WAKALA FINANCIAL SYSTEM - VIEW STORE CASH OUT
// 🔴 RED THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ View single cash out record with details
// ✅ Print / Export PDF
// ✅ Admin actions (Approve / Reject / Delete)
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
// GET CASHOUT ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid cash out ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET CASHOUT RECORD
// ============================================================
try {
    $sql = "
        SELECT 
            sco.*,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code,
            b.location as branch_location,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            e.position as employee_position
        FROM store_cash_out sco
        LEFT JOIN branches b ON sco.branch_id = b.id
        LEFT JOIN employees e ON sco.employee_id = e.id
        WHERE sco.id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $cashout = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cashout) {
        $_SESSION['error_message'] = 'Cash out record not found.';
        header('Location: index.php');
        exit();
    }

    // Employee anaweza kuona record yake tu
    if (!$is_admin && $cashout['employee_id'] != $user_id) {
        $_SESSION['error_message'] = 'You do not have permission to view this record.';
        header('Location: index.php');
        exit();
    }

} catch (PDOException $e) {
    error_log("View cashout error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to load cash out record.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET RELATED CAPITAL MANAGEMENT ENTRY
// ============================================================
$capital_entry = null;
try {
    $cap_stmt = $db->prepare("
        SELECT * FROM capital_management 
        WHERE reference_module = 'store_cash_out' 
        AND reference_id = ?
        LIMIT 1
    ");
    $cap_stmt->execute([$id]);
    $capital_entry = $cap_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Capital entry error: " . $e->getMessage());
}

// ============================================================
// STATUS INFO
// ============================================================
$status = $cashout['status'] ?? 'pending';
$status_icons = [
    'pending' => 'clock',
    'approved' => 'check-circle',
    'rejected' => 'times-circle',
    'cancelled' => 'ban'
];
$status_labels = [
    'pending' => 'Pending Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled'
];
$status_icon = $status_icons[$status] ?? 'question-circle';
$status_label = $status_labels[$status] ?? ucfirst($status);

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_header.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_sidebar.php';
include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_topbar.php';
?>

<style id="cashout-view-scoped">
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
.main-wrapper .btn-success {
    background: linear-gradient(135deg, #059669, #10B981);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.main-wrapper .btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
    color: #FFFFFF;
}
.main-wrapper .btn-danger {
    background: linear-gradient(135deg, #991B1B, #7F1D1D);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(153, 27, 27, 0.3);
}
.main-wrapper .btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(153, 27, 27, 0.5);
    color: #FFFFFF;
}

/* ALERTS */
.main-wrapper .alert {
    padding: 14px 18px; border-radius: 10px; margin-bottom: 16px;
    display: flex; align-items: flex-start; gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
    font-size: 13px; font-weight: 500;
}
.main-wrapper .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
html.dark-mode .main-wrapper .alert-success { background: #065F46; color: #D1FAE5; }
.main-wrapper .alert i { font-size: 20px; flex-shrink: 0; margin-top: 1px; }

/* VIEW CONTAINER */
.main-wrapper .view-container {
    max-width: 1000px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* MAIN CARD */
.main-wrapper .view-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* CARD HEADER */
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
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .view-card-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
    min-width: 0;
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
.main-wrapper .view-card-title {
    flex: 1;
    min-width: 0;
}
.main-wrapper .view-card-title h3 {
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 4px 0;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}
.main-wrapper .view-card-title p {
    font-size: 12px;
    margin: 0;
    opacity: 0.9;
    font-weight: 500;
}
.main-wrapper .view-card-header-right {
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}
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

/* CARD BODY */
.main-wrapper .view-card-body {
    padding: 28px 24px;
}

/* AMOUNT DISPLAY */
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
    content: ''; position: absolute;
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
    position: relative;
    z-index: 1;
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
    position: relative;
    z-index: 1;
}
html.dark-mode .main-wrapper .amount-value { 
    color: #FCA5A5; 
    text-shadow: 0 2px 8px rgba(252, 165, 165, 0.2);
}
.main-wrapper .amount-in-words {
    font-size: 13px;
    font-weight: 700;
    color: #B91C1C;
    font-style: italic;
    text-transform: capitalize;
    position: relative;
    z-index: 1;
}
html.dark-mode .main-wrapper .amount-in-words { color: #FECACA; }

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
.main-wrapper .info-item-full {
    grid-column: 1 / -1;
}
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
.main-wrapper .info-label i {
    color: #DC2626;
    font-size: 11px;
}
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

/* EMPLOYEE INFO */
.main-wrapper .employee-info-box {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
}
.main-wrapper .employee-avatar-lg {
    width: 56px; height: 56px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border: 3px solid #FCA5A5;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
}
.main-wrapper .employee-info-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.main-wrapper .employee-info-name {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.main-wrapper .employee-info-code {
    font-size: 11px;
    font-weight: 700;
    color: #DC2626;
    font-family: 'Courier New', monospace;
    background: #FEE2E2;
    padding: 2px 10px;
    border-radius: 6px;
    align-self: flex-start;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .main-wrapper .employee-info-code {
    background: #7F1D1D; color: #FCA5A5;
}

/* CAPITAL LINK BOX */
.main-wrapper .capital-link-box {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 1.5px solid #93C5FD;
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
}
html.dark-mode .main-wrapper .capital-link-box {
    background: linear-gradient(135deg, #1E3A5F 0%, #1e293b 100%);
    border-color: #3B82F6;
}
.main-wrapper .capital-link-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: #3B82F6;
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}
.main-wrapper .capital-link-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.main-wrapper .capital-link-title {
    font-size: 11px;
    font-weight: 800;
    color: #1D4ED8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
html.dark-mode .main-wrapper .capital-link-title { color: #60A5FA; }
.main-wrapper .capital-link-value {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    font-family: 'Courier New', monospace;
}
html.dark-mode .main-wrapper .capital-link-value { color: #93C5FD; }
.main-wrapper .capital-link-arrow {
    color: #3B82F6;
    font-size: 16px;
    flex-shrink: 0;
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

/* TIMELINE */
.main-wrapper .timeline-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.main-wrapper .timeline-header {
    padding: 16px 20px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 10px;
}
.main-wrapper .timeline-header i {
    color: #DC2626;
    font-size: 18px;
}
.main-wrapper .timeline-header h4 {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.main-wrapper .timeline-body {
    padding: 20px 24px;
}
.main-wrapper .timeline-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    position: relative;
}
.main-wrapper .timeline-list::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 8px;
    bottom: 8px;
    width: 2px;
    background: var(--border-color);
}
.main-wrapper .timeline-item {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    position: relative;
    z-index: 1;
}
.main-wrapper .timeline-dot {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-card);
    border: 2px solid #DC2626;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #DC2626;
    flex-shrink: 0;
}
.main-wrapper .timeline-dot.success {
    border-color: #059669;
    color: #059669;
}
.main-wrapper .timeline-dot.danger {
    border-color: #DC2626;
    color: #DC2626;
}
.main-wrapper .timeline-dot.warning {
    border-color: #D97706;
    color: #D97706;
}
.main-wrapper .timeline-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-top: 4px;
}
.main-wrapper .timeline-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
}
.main-wrapper .timeline-desc {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
}
.main-wrapper .timeline-time {
    font-size: 11px;
    color: var(--text-light);
    font-weight: 600;
    font-family: 'Courier New', monospace;
}

/* ACTION BAR */
.main-wrapper .action-bar {
    display: flex;
    gap: 10px;
    justify-content: center;
    padding: 20px 0;
    flex-wrap: wrap;
}

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
    .main-wrapper .employee-avatar-lg { width: 48px; height: 48px; font-size: 18px; }
    .main-wrapper .action-bar { flex-direction: column; }
    .main-wrapper .action-bar .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-wrapper .amount-value { font-size: 24px; }
    .main-wrapper .status-badge-lg { padding: 6px 12px; font-size: 10px; }
    .main-wrapper .view-card-header-left { gap: 10px; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">View Cash Out</span>
                    <span class="branch-indicator-name">
                        <?php echo htmlspecialchars($cashout['branch_display_name'] ?? 'Main'); ?>
                    </span>
                    <?php if (!empty($cashout['branch_display_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($cashout['branch_display_code']); ?></span>
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
                <h2><i class="fas fa-eye" style="color:#DC2626;"></i> View Cash Out</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($cashout['cashout_number']); ?>
                    •
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($cashout['cashout_date'])); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="print.php?id=<?php echo $cashout['id']; ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="index.php" class="btn btn-secondary">
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
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="view-card-title">
                            <h3><?php echo htmlspecialchars($cashout['cashout_number']); ?></h3>
                            <p>Store Cash Out Record</p>
                        </div>
                    </div>
                    <div class="view-card-header-right">
                        <span class="status-badge-lg">
                            <i class="fas fa-<?php echo $status_icon; ?>"></i>
                            <?php echo $status_label; ?>
                        </span>
                    </div>
                </div>

                <!-- CARD BODY -->
                <div class="view-card-body">
                    
                    <!-- AMOUNT DISPLAY -->
                    <div class="amount-display">
                        <span class="amount-label">Total Amount</span>
                        <div class="amount-value">
                            <?php echo formatCurrency($cashout['amount']); ?>
                        </div>
                        <div class="amount-in-words" id="amountInWords"></div>
                    </div>
                    
                    <!-- EMPLOYEE INFO -->
                    <div class="employee-info-box">
                        <?php 
                        $emp_avatar = $cashout['employee_avatar'] ?? '';
                        $emp_initial = strtoupper(substr($cashout['employee_name'] ?? 'N', 0, 1));
                        ?>
                        <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                            <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                 alt="" class="employee-avatar-lg">
                        <?php else: ?>
                            <div class="employee-avatar-lg"><?php echo $emp_initial; ?></div>
                        <?php endif; ?>
                        <div class="employee-info-text">
                            <span class="employee-info-name">
                                <?php echo htmlspecialchars($cashout['employee_name'] ?? 'N/A'); ?>
                            </span>
                            <span class="employee-info-code">
                                <?php echo htmlspecialchars($cashout['employee_code'] ?? 'N/A'); ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- INFO GRID -->
                    <div class="info-grid">
                        
                        <!-- Date -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-calendar-day"></i>
                                Cash Out Date
                            </span>
                            <span class="info-value">
                                <?php echo date('l, d F Y', strtotime($cashout['cashout_date'])); ?>
                            </span>
                        </div>
                        
                        <!-- Branch -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-store-alt"></i>
                                Branch
                            </span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($cashout['branch_display_name'] ?? 'Main'); ?>
                                <?php if (!empty($cashout['branch_display_code'])): ?>
                                    (<?php echo htmlspecialchars($cashout['branch_display_code']); ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <!-- Taken By -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-user"></i>
                                Taken By
                            </span>
                            <span class="info-value <?php echo empty($cashout['taken_by']) ? 'empty' : ''; ?>">
                                <?php echo !empty($cashout['taken_by']) 
                                    ? htmlspecialchars($cashout['taken_by']) 
                                    : 'Not specified'; ?>
                            </span>
                        </div>
                        
                        <!-- Status -->
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-info-circle"></i>
                                Status
                            </span>
                            <span class="info-value">
                                <?php echo $status_label; ?>
                            </span>
                        </div>
                        
                        <!-- Reason (Full Width) -->
                        <div class="info-item info-item-full">
                            <span class="info-label">
                                <i class="fas fa-comment-alt"></i>
                                Reason
                            </span>
                            <span class="info-value <?php echo empty($cashout['reason']) ? 'empty' : ''; ?>">
                                <?php echo !empty($cashout['reason']) 
                                    ? htmlspecialchars($cashout['reason']) 
                                    : 'No reason provided'; ?>
                            </span>
                        </div>
                        
                        <!-- Description / Notes (Full Width) -->
                        <?php if (!empty($cashout['description'])): ?>
                        <div class="info-item info-item-full">
                            <span class="info-label">
                                <i class="fas fa-align-left"></i>
                                Notes
                            </span>
                            <span class="info-value">
                                <?php echo nl2br(htmlspecialchars($cashout['description'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Approved By -->
                        <?php if (!empty($cashout['approved_by'])): ?>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-check-circle"></i>
                                Approved By
                            </span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($cashout['approved_by']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Approved Date -->
                        <?php if (!empty($cashout['approved_date'])): ?>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-calendar-check"></i>
                                Approved Date
                            </span>
                            <span class="info-value">
                                <?php echo date('d M Y', strtotime($cashout['approved_date'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                    
                    <!-- CAPITAL MANAGEMENT LINK -->
                    <?php if ($capital_entry): ?>
                    <div class="capital-link-box">
                        <div class="capital-link-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="capital-link-content">
                            <span class="capital-link-title">
                                Linked Capital Entry
                            </span>
                            <span class="capital-link-value">
                                <?php echo htmlspecialchars($capital_entry['capital_number']); ?>
                                — 
                                <?php echo formatCurrency($capital_entry['amount']); ?>
                            </span>
                        </div>
                        <i class="fas fa-arrow-right capital-link-arrow"></i>
                    </div>
                    <?php endif; ?>
                    
                </div>
                
                <!-- CARD FOOTER -->
                <div class="view-card-footer">
                    <div class="footer-meta">
                        <div class="footer-meta-item">
                            <i class="fas fa-clock"></i>
                            <strong>Created:</strong>
                            <?php echo date('d M Y, H:i', strtotime($cashout['created_at'])); ?>
                        </div>
                        <?php if (!empty($cashout['updated_at']) && $cashout['updated_at'] != $cashout['created_at']): ?>
                        <div class="footer-meta-item">
                            <i class="fas fa-edit"></i>
                            <strong>Updated:</strong>
                            <?php echo date('d M Y, H:i', strtotime($cashout['updated_at'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($is_admin): ?>
                    <div class="action-bar">
                        <?php if ($status === 'pending'): ?>
                            <a href="approve.php?id=<?php echo $cashout['id']; ?>&action=approve" 
                               class="btn btn-success"
                               onclick="return confirm('Approve this cash out?')">
                                <i class="fas fa-check"></i> Approve
                            </a>
                            <a href="approve.php?id=<?php echo $cashout['id']; ?>&action=reject" 
                               class="btn btn-danger"
                               onclick="return confirm('Reject this cash out?')">
                                <i class="fas fa-times"></i> Reject
                            </a>
                        <?php endif; ?>
                        <a href="delete.php?id=<?php echo $cashout['id']; ?>" 
                           class="btn btn-danger"
                           onclick="return confirm('Are you sure you want to delete this record? This action cannot be undone.')">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
            </div>
            
            <!-- TIMELINE CARD -->
            <div class="timeline-card">
                <div class="timeline-header">
                    <i class="fas fa-history"></i>
                    <h4>Record Timeline</h4>
                </div>
                <div class="timeline-body">
                    <div class="timeline-list">
                        
                        <!-- Created -->
                        <div class="timeline-item">
                            <div class="timeline-dot warning">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="timeline-content">
                                <span class="timeline-title">Record Created</span>
                                <span class="timeline-desc">
                                    Created by <?php echo htmlspecialchars($cashout['employee_name'] ?? 'System'); ?>
                                </span>
                                <span class="timeline-time">
                                    <?php echo date('d M Y, H:i:s', strtotime($cashout['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Approved -->
                        <?php if ($status === 'approved' && !empty($cashout['approved_by'])): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot success">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="timeline-content">
                                <span class="timeline-title">Approved</span>
                                <span class="timeline-desc">
                                    Approved by <?php echo htmlspecialchars($cashout['approved_by']); ?>
                                </span>
                                <span class="timeline-time">
                                    <?php echo date('d M Y', strtotime($cashout['approved_date'])); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Rejected -->
                        <?php if ($status === 'rejected'): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot danger">
                                <i class="fas fa-times"></i>
                            </div>
                            <div class="timeline-content">
                                <span class="timeline-title">Rejected</span>
                                <span class="timeline-desc">
                                    Record was rejected
                                </span>
                                <span class="timeline-time">
                                    <?php echo date('d M Y, H:i', strtotime($cashout['updated_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Pending -->
                        <?php if ($status === 'pending'): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="timeline-content">
                                <span class="timeline-title">Awaiting Approval</span>
                                <span class="timeline-desc">
                                    This record is pending administrator approval
                                </span>
                                <span class="timeline-time">Current Status</span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                </div>
            </div>
            
        </div>
        
    </div>
    <?php include_once '../../includes/' . ($is_admin ? 'admin' : 'employee') . '_footer.php'; ?>
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
        if (n >= 100) {
            const hundreds = Math.floor(n / 100);
            result += ones[hundreds] + ' mia ';
            n %= 100;
        }
        if (n >= 20) {
            const t = Math.floor(n / 10);
            result += tens[t] + ' ';
            n %= 10;
        }
        if (n > 0) {
            result += ones[n] + ' ';
        }
        return result;
    }
    
    function convert(n) {
        if (n === 0) return '';
        let result = '';
        let scaleIndex = 0;
        
        while (n > 0) {
            const chunk = n % 1000;
            if (chunk > 0) {
                let chunkWords = convertHundreds(chunk);
                if (scales[scaleIndex]) {
                    chunkWords += ' ' + scales[scaleIndex];
                }
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
    // Onyesha amount kwa maneno
    const amountInWords = document.getElementById('amountInWords');
    const amount = <?php echo floatval($cashout['amount']); ?>;
    
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
    
    // ============================================================
    // DARK MODE
    // ============================================================
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