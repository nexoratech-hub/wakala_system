<?php
// ================================================================
// FILE: modules/morning_report/view.php
// WAKALA FINANCIAL SYSTEM - VIEW MORNING REPORT
// WITH BRANCH INDICATOR CARD
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET USER'S BRANCH
// ============================================================
$selected_branch = isset($_SESSION['user_branch_id']) ? intval($_SESSION['user_branch_id']) : 0;
if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
        $_SESSION['user_branch_id'] = $selected_branch;
    }
}

// ============================================================
// GET BRANCHES FOR FILTER
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// Get branch name for display
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            $branch_code = $b['branch_code'] ?? '';
            $branch_location = $b['location'] ?? '';
            break;
        }
    }
}

// ============================================================
// GET REPORT ID
// ============================================================
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT DATA
// ============================================================
$sql = "SELECT 
            mr.*,
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code,
            b.location as branch_location
        FROM morning_reports mr
        LEFT JOIN employees e ON mr.employee_id = e.id
        LEFT JOIN branches b ON mr.branch_id = b.id
        WHERE mr.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$report_id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only view their own reports
if ($role == 'employee' && $report['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($report['provider_data'] ?? '{}', true);

// Get provider details
$providers = [];
if (!empty($provider_data)) {
    $provider_ids = array_keys($provider_data);
    $placeholders = implode(',', array_fill(0, count($provider_ids), '?'));
    $stmt = $db->prepare("SELECT id, provider_name, provider_code, icon_class, color_code FROM providers WHERE id IN ($placeholders)");
    $stmt->execute($provider_ids);
    $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($providers_list as $p) {
        $providers[$p['id']] = $p;
    }
}

$total_float = $report['cumm_total'] ?? 0;
$cash_balance = $report['cash_balance'] ?? 0;
$grand_total = $total_float + $cash_balance;

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Current Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($branch_location): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch_location); ?></span>
                    </div>
                <?php endif; ?>
                <div class="branch-report-count">
                    <i class="fas fa-sun"></i>
                    <span><?php echo htmlspecialchars($report['report_number']); ?></span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-sun"></i> Morning Report Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($report['report_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php<?php echo $report['branch_id'] > 0 ? '?branch=' . $report['branch_id'] : ''; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $report_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="print.php?id=<?php echo $report_id; ?>" class="btn btn-print" target="_blank">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- ============================================================
        REPORT DETAILS
        ============================================================ -->
        <div class="report-container">
            
            <!-- Report Header -->
            <div class="report-header">
                <div class="report-title">
                    <h3>Morning Report</h3>
                    <span class="report-number">#<?php echo htmlspecialchars($report['report_number']); ?></span>
                </div>
                <div class="report-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                </div>
            </div>
            
            <!-- Report Info Grid -->
            <div class="report-info-grid">
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-user"></i> Employee</div>
                    <div class="info-value"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-store-alt"></i> Branch</div>
                    <div class="info-value"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-clock"></i> Submitted</div>
                    <div class="info-value"><?php echo date('d M Y H:i:s', strtotime($report['submitted_at'])); ?></div>
                </div>
                <?php if ($report['notes']): ?>
                <div class="info-card full-width">
                    <div class="info-label"><i class="fas fa-sticky-note"></i> Notes</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($report['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Providers Table -->
            <div class="providers-table-container">
                <h4><i class="fas fa-university"></i> Provider Balances</h4>
                <div class="table-responsive">
                    <table class="providers-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($provider_data)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">No provider data available</td>
                                </tr>
                            <?php else: 
                                $counter = 1;
                                foreach ($provider_data as $provider_id => $amount): 
                                    $provider = $providers[$provider_id] ?? null;
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <?php if ($provider): ?>
                                            <span class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>; display:inline-block;width:24px;height:24px;border-radius:50%;text-align:center;line-height:24px;color:white;font-size:11px;margin-right:8px;">
                                                <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                            </span>
                                            <?php echo htmlspecialchars($provider['provider_name']); ?>
                                        <?php else: ?>
                                            Provider #<?php echo $provider_id; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?></td>
                                    <td class="amount"><?php echo formatCurrency($amount); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right"><strong>Total Float</strong></td>
                                <td class="amount total"><?php echo formatCurrency($total_float); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-right"><strong>Cash Balance</strong></td>
                                <td class="amount cash"><?php echo formatCurrency($cash_balance); ?></td>
                            </tr>
                            <tr class="grand-total">
                                <td colspan="3" class="text-right"><strong>Grand Total</strong></td>
                                <td class="amount grand-total"><?php echo formatCurrency($grand_total); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="report-actions">
                <a href="edit.php?id=<?php echo $report_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Report
                </a>
                <a href="print.php?id=<?php echo $report_id; ?>" class="btn btn-print" target="_blank">
                    <i class="fas fa-print"></i> Print Report
                </a>
                <button onclick="deleteReport(<?php echo $report_id; ?>)" class="btn btn-delete">
                    <i class="fas fa-trash"></i> Delete Report
                </button>
            </div>
            
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES
============================================================ -->
<style>
/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 24px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
}

.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 30%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 13px;
    color: #FFFFFF;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-icon-wrapper {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.branch-indicator-label {
    font-size: 11px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-indicator-code {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.6;
    color: #FFFFFF;
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    opacity: 0.8;
    color: #FFFFFF;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location i {
    font-size: 12px;
}

.branch-report-count {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #FFFFFF;
    padding: 4px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-report-count i {
    font-size: 13px;
}

.branch-indicator-right {
    position: relative;
    z-index: 1;
}

.branch-indicator-right .date-display {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}

.branch-indicator-right .date-display i {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
}

/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --view-bg: #f3f4f6;
    --view-text: #1F2937;
    --view-text-secondary: #6B7280;
    --view-text-light: #9CA3AF;
    --view-border: #E5E7EB;
    --view-card-bg: #FFFFFF;
    --view-card-header: #FAFBFC;
    --view-hover: #F3F4F6;
    --view-shadow: rgba(0,0,0,0.06);
    --view-shadow-lg: rgba(0,0,0,0.12);
    --view-success: #D1FAE5;
    --view-success-text: #065F46;
    --view-scrollbar: #DC2626;
    --view-scrollbar-track: #F3F4F6;
}

html.dark-mode {
    --view-bg: #0f172a;
    --view-text: #F1F5F9;
    --view-text-secondary: #94A3B8;
    --view-text-light: #64748B;
    --view-border: #334155;
    --view-card-bg: #1E293B;
    --view-card-header: #2D3A4F;
    --view-hover: #2D3A4F;
    --view-shadow: rgba(0,0,0,0.4);
    --view-shadow-lg: rgba(0,0,0,0.6);
    --view-success: #065F46;
    --view-success-text: #D1FAE5;
    --view-scrollbar: #DC2626;
    --view-scrollbar-track: #1E293B;
}

body {
    background: var(--view-bg) !important;
    color: var(--view-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--view-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--view-bg) !important;
    transition: background 0.3s ease;
}

/* Scrollbar */
.main-content::-webkit-scrollbar {
    width: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: var(--view-scrollbar-track);
}

.main-content::-webkit-scrollbar-thumb {
    background: var(--view-scrollbar);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-thumb:hover {
    background: #8B0000;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 0 4px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--view-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #F59E0B;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.btn-back {
    background: var(--view-hover);
    color: var(--view-text-secondary);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--view-border);
    color: var(--view-text);
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-edit:hover {
    background: #A7F3D0;
    color: #047857;
}

.btn-print {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-print:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

/* ============================================================
   REPORT CONTAINER
   ============================================================ */
.report-container {
    background: var(--view-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

/* ============================================================
   REPORT HEADER
   ============================================================ */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.report-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-title h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0;
}

.report-number {
    font-size: 13px;
    font-weight: 600;
    color: #F59E0B;
    background: rgba(245,158,11,0.1);
    padding: 2px 12px;
    border-radius: 12px;
}

.report-date {
    font-size: 14px;
    color: var(--view-text-secondary);
}

.report-date i {
    color: #F59E0B;
    margin-right: 6px;
}

/* ============================================================
   REPORT INFO GRID
   ============================================================ */
.report-info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.info-card {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-card.full-width {
    grid-column: span 3;
}

.info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--view-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
}

.info-label i {
    margin-right: 4px;
}

.info-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--view-text);
}

/* ============================================================
   PROVIDERS TABLE
   ============================================================ */
.providers-table-container {
    padding: 20px 24px;
}

.providers-table-container h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0 0 12px 0;
}

.providers-table-container h4 i {
    color: #F59E0B;
    margin-right: 8px;
}

.providers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.providers-table thead th {
    background: #F59E0B;
    color: #FFFFFF;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
}

.providers-table thead th:first-child {
    border-radius: 6px 0 0 0;
}

.providers-table thead th:last-child {
    border-radius: 0 6px 0 0;
}

.providers-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--view-border);
    color: var(--view-text);
    transition: border-color 0.3s ease, color 0.3s ease;
}

.providers-table tbody tr:hover {
    background: var(--view-hover);
}

.providers-table tfoot td {
    padding: 10px 14px;
    border-top: 1px solid var(--view-border);
    font-weight: 600;
}

.providers-table .amount {
    text-align: right;
    font-weight: 600;
}

.providers-table .amount.total {
    color: #1D4ED8;
}

.providers-table .amount.cash {
    color: #059669;
}

.providers-table .amount.grand-total {
    color: #10B981;
    font-size: 16px;
}

.providers-table .text-right {
    text-align: right;
}

.providers-table .text-center {
    text-align: center;
}

.providers-table .grand-total {
    background: var(--view-success);
}

.providers-table .grand-total td {
    border-top: 2px solid #10B981;
}

/* ============================================================
   REPORT ACTIONS
   ============================================================ */
.report-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--view-border);
    background: var(--view-card-header);
    transition: all 0.3s ease;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
}

.btn-edit:hover {
    background: #A7F3D0;
    color: #047857;
}

.btn-print {
    background: #DBEAFE;
    color: #1D4ED8;
}

.btn-print:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .report-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .report-info-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .info-card.full-width {
        grid-column: span 2;
    }
    
    .report-actions {
        flex-direction: column;
    }
    
    .report-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .branch-indicator {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
        padding: 16px 18px;
    }
    
    .branch-indicator-left {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .branch-indicator-right {
        width: 100%;
    }
    
    .branch-indicator-right .date-display {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .report-info-grid {
        grid-template-columns: 1fr;
    }
    
    .info-card.full-width {
        grid-column: span 1;
    }
    
    .report-header {
        padding: 14px 16px;
    }
    
    .report-info-grid {
        padding: 14px 16px;
    }
    
    .providers-table-container {
        padding: 14px 16px;
    }
    
    .report-actions {
        padding: 14px 16px;
    }
    
    .branch-indicator-name {
        font-size: 14px;
    }
    
    .branch-location {
        font-size: 11px;
        padding: 3px 10px;
    }
    
    .branch-indicator-code {
        font-size: 10px;
    }
    
    .branch-icon-wrapper {
        width: 38px;
        height: 38px;
        font-size: 17px;
    }
}
</style>

<script>
function deleteReport(id) {
    if (confirm('Are you sure you want to delete this morning report? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) {
            html.classList.add('dark-mode');
        } else {
            html.classList.remove('dark-mode');
        }
    }
    
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
});
</script>

</body>
</html>