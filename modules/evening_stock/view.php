<?php
// ================================================================
// FILE: modules/evening_stock/view.php
// EVENING STOCK - VIEW DETAILS (ADMIN) - BLUE THEME
// ✅ Shows full evening stock details
// ✅ Provider breakdown table
// ✅ Edit / Delete / Approve buttons
// ✅ Print / Export
// ✅ BLUE THEME (consistent with index.php, add.php, edit.php)
// ✅ ALL INSTRUCTIONS IN ENGLISH
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    $_SESSION['error_message'] = 'Invalid stock ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// HANDLE STATUS UPDATE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $new_status = $_POST['status'] ?? '';
        $allowed = ['waiting', 'approved', 'adjusted', 'rejected'];
        
        if (!in_array($new_status, $allowed)) {
            throw new Exception('Invalid status.');
        }
        
        $stmt = $db->prepare("UPDATE evening_stocks SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $stock_id]);
        
        logActivity($user_id, 'Update Evening Stock Status', 'Evening Stock', $stock_id, '', 'Status changed to ' . $new_status);
        
        $_SESSION['success_message'] = 'Status updated to ' . ucfirst($new_status) . ' successfully!';
        header('Location: view.php?id=' . $stock_id);
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ============================================================
// GET STOCK DATA
// ============================================================
try {
    $sql = "SELECT es.*, 
            e.full_name as employee_name,
            e.email as employee_email,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            b.phone as branch_phone,
            dr.report_number as daily_report_number,
            dr.report_date as daily_report_date
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
            WHERE es.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$stock_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $stock = null;
}

if (!$stock) {
    $_SESSION['error_message'] = 'Evening stock not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDERS
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT esp.*, 
               p.icon_class as provider_icon,
               p.color_code as provider_color,
               p.provider_type
        FROM evening_stock_providers esp
        LEFT JOIN providers p ON esp.provider_id = p.id
        WHERE esp.evening_stock_id = ?
        ORDER BY esp.id ASC
    ");
    $stmt->execute([$stock_id]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $providers = [];
}

// ============================================================
// CALCULATE TOTALS
// ✅ Float from providers
// ✅ Cash from evening_stocks.cash_balance (BRANCH CASH)
// ✅ Grand Total = float + cash
// ============================================================
$total_opening_float = 0;
$total_opening_cash = 0;
$total_closing_float = 0;
$total_provider_cash = 0;  // Provider cash (kwa display kwenye table)
$total_deposits = 0;
$total_withdrawals = 0;

foreach ($providers as $p) {
    $total_opening_float += floatval($p['opening_float']);
    $total_opening_cash += floatval($p['opening_cash']);
    $total_closing_float += floatval($p['closing_float']);
    $total_provider_cash += floatval($p['closing_cash']);
    $total_deposits += floatval($p['total_deposits']);
    $total_withdrawals += floatval($p['total_withdrawals']);
}

// ✅ Cash ya branch inatoka evening_stocks.cash_balance (SIO providers)
$total_closing_cash = floatval($stock['cash_balance'] ?? 0);

// ✅ Grand Total = float + branch cash
$grand_total = $total_closing_float + $total_closing_cash;

// Status labels
$status_labels = [
    'waiting' => ['label' => 'Waiting', 'icon' => 'fa-clock', 'color' => 'orange'],
    'approved' => ['label' => 'Approved', 'icon' => 'fa-check-circle', 'color' => 'green'],
    'adjusted' => ['label' => 'Adjusted', 'icon' => 'fa-sliders-h', 'color' => 'blue'],
    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red']
];

$status_info = $status_labels[$stock['status']] ?? ['label' => $stock['status'], 'icon' => 'fa-circle', 'color' => 'gray'];

// Success/error messages
$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if (isset($error_message) && empty($error_message)) {
    $error_message = '';
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH CARD — BLUE
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Evening Stock For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?></span>
                <?php if (!empty($stock['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                <?php endif; ?>
                <span class="branch-status-date">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                </span>
            </div>
            <a href="index.php?branch=<?php echo $stock['branch_id']; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-invoice" style="color:#2563EB;"></i> Evening Stock Details</h2>
                <p class="text-muted">
                    Reference: <strong><?php echo htmlspecialchars($stock['stock_number']); ?></strong>
                </p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $stock_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="delete.php?id=<?php echo $stock_id; ?>" 
                   class="btn btn-delete" 
                   onclick="return confirmDelete('<?php echo addslashes($stock['stock_number']); ?>')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
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

        <!-- ============================================================
        HERO CARD — Grand Total
        ============================================================ -->
        <div class="hero-card hero-<?php echo $status_info['color']; ?>">
            <div class="hero-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="hero-content">
                <span class="hero-label">Grand Total</span>
                <span class="hero-amount"><?php echo formatCurrency($grand_total); ?></span>
                <span class="hero-type">
                    <span class="hero-type-badge">
                        <i class="fas <?php echo $status_info['icon']; ?>"></i>
                        <?php echo $status_info['label']; ?>
                    </span>
                </span>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <span class="hmi-label">Total Float</span>
                    <span class="hmi-value">
                        <i class="fas fa-university"></i>
                        <?php echo formatCurrency($total_closing_float); ?>
                    </span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Branch Cash</span>
                    <span class="hmi-value">
                        <i class="fas fa-money-bill-wave"></i>
                        <?php echo formatCurrency($total_closing_cash); ?>
                    </span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Providers</span>
                    <span class="hmi-value">
                        <i class="fas fa-list"></i>
                        <?php echo count($providers); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        STATUS UPDATE BAR
        ============================================================ -->
        <div class="status-update-bar">
            <div class="sub-left">
                <i class="fas fa-tasks"></i>
                <span class="sub-label">Update Status:</span>
                <span class="sub-current">
                    Current: <strong class="status-current status-<?php echo $status_info['color']; ?>">
                        <i class="fas <?php echo $status_info['icon']; ?>"></i>
                        <?php echo $status_info['label']; ?>
                    </strong>
                </span>
            </div>
            <form method="POST" action="" class="sub-form">
                <input type="hidden" name="action" value="update_status">
                <div class="sub-buttons">
                    <button type="submit" name="status" value="waiting" class="btn-status btn-waiting" <?php echo $stock['status'] == 'waiting' ? 'disabled' : ''; ?>>
                        <i class="fas fa-clock"></i> Waiting
                    </button>
                    <button type="submit" name="status" value="approved" class="btn-status btn-approved" <?php echo $stock['status'] == 'approved' ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle"></i> Approve
                    </button>
                    <button type="submit" name="status" value="adjusted" class="btn-status btn-adjusted" <?php echo $stock['status'] == 'adjusted' ? 'disabled' : ''; ?>>
                        <i class="fas fa-sliders-h"></i> Adjust
                    </button>
                    <button type="submit" name="status" value="rejected" class="btn-status btn-rejected" <?php echo $stock['status'] == 'rejected' ? 'disabled' : ''; ?>>
                        <i class="fas fa-times-circle"></i> Reject
                    </button>
                </div>
            </form>
        </div>

        <!-- ============================================================
        SUMMARY GRID — SEMI-TRANSPARENT COLORS
        ============================================================ -->
        <div class="summary-grid">
            <div class="summary-card sc-total-float">
                <div class="sc-icon sc-icon-blue">
                    <i class="fas fa-university"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Float</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_float); ?></span>
                    <span class="sc-sub">Provider float</span>
                </div>
            </div>
            
            <div class="summary-card sc-cash">
                <div class="sc-icon sc-icon-teal">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Branch Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_cash); ?></span>
                    <span class="sc-sub">From Daily Report</span>
                </div>
            </div>
            
            <div class="summary-card sc-deposits">
                <div class="sc-icon sc-icon-green">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Deposits</span>
                    <span class="sc-value text-success">+<?php echo formatCurrency($total_deposits); ?></span>
                    <span class="sc-sub">Provider deposits</span>
                </div>
            </div>
            
            <div class="summary-card sc-withdrawals">
                <div class="sc-icon sc-icon-red">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Withdrawals</span>
                    <span class="sc-value text-danger">-<?php echo formatCurrency($total_withdrawals); ?></span>
                    <span class="sc-sub">Provider withdrawals</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        STOCK INFORMATION
        ============================================================ -->
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-info-circle"></i> Stock Information</h3>
            </div>
            
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Stock Number</span>
                    <span class="detail-value detail-code"><?php echo htmlspecialchars($stock['stock_number']); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-calendar"></i> Stock Date</span>
                    <span class="detail-value"><?php echo date('l, d M Y', strtotime($stock['stock_date'])); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-store-alt"></i> Branch</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?>
                        <?php if (!empty($stock['branch_code'])): ?>
                            <span class="code-pill"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-clipboard-check"></i> Daily Report</span>
                    <span class="detail-value">
                        <?php if (!empty($stock['daily_report_number'])): ?>
                            <a href="../daily_report/view.php?id=<?php echo $stock['daily_report_id']; ?>" class="capital-link">
                                <?php echo htmlspecialchars($stock['daily_report_number']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-clock"></i> Submitted At</span>
                    <span class="detail-value">
                        <?php echo date('d M Y, h:i A', strtotime($stock['submitted_at'])); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-sync-alt"></i> Last Updated</span>
                    <span class="detail-value">
                        <?php echo date('d M Y, h:i A', strtotime($stock['updated_at'])); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        PROVIDERS BREAKDOWN
        ============================================================ -->
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-university"></i> Provider Breakdown</h3>
                <span class="section-badge"><?php echo count($providers); ?> Providers</span>
            </div>
            
            <div class="providers-table-wrapper">
                <table class="providers-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Provider</th>
                            <th class="text-right">Opening Float</th>
                            <th class="text-right">Opening Cash</th>
                            <th class="text-right">Deposits</th>
                            <th class="text-right">Withdrawals</th>
                            <th class="text-right">Closing Float</th>
                            <th class="text-right">Closing Cash</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($providers as $p): 
                            $p_total = floatval($p['closing_float']) + floatval($p['closing_cash']);
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($p['provider_color'] ?? '#2563EB'); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <div class="provider-info-cell">
                                            <span class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            <span class="provider-code"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <span class="amount-readonly"><?php echo formatCurrency($p['opening_float']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-readonly"><?php echo formatCurrency($p['opening_cash']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-readonly text-success">+<?php echo formatCurrency($p['total_deposits']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-readonly text-danger">-<?php echo formatCurrency($p['total_withdrawals']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-highlight"><?php echo formatCurrency($p['closing_float']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-highlight"><?php echo formatCurrency($p['closing_cash']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-total"><?php echo formatCurrency($p_total); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="2" class="text-right"><strong>TOTALS</strong></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_opening_float); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_opening_cash); ?></span></td>
                            <td class="text-right"><span class="total-value text-success">+<?php echo formatCurrency($total_deposits); ?></span></td>
                            <td class="text-right"><span class="total-value text-danger">-<?php echo formatCurrency($total_withdrawals); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_closing_float); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_provider_cash); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_closing_float + $total_provider_cash); ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ============================================================
        BRANCH CASH SUMMARY (LOCKED FROM DAILY REPORT)
        ============================================================ -->
        <div class="cash-summary-card">
            <div class="csc-header">
                <i class="fas fa-lock"></i>
                <span>Branch Cash (Locked from Daily Report)</span>
            </div>
            <div class="csc-body">
                <div class="csc-item">
                    <span class="csc-label">Branch Cash Balance</span>
                    <span class="csc-value"><?php echo formatCurrency($total_closing_cash); ?></span>
                </div>
                <div class="csc-note">
                    <i class="fas fa-info-circle"></i>
                    Branch cash is locked from the Daily Report and cannot be edited here.
                </div>
            </div>
        </div>

        <!-- ============================================================
        NOTES
        ============================================================ -->
        <?php if (!empty($stock['notes'])): ?>
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-sticky-note"></i> Notes</h3>
            </div>
            <div class="notes-section">
                <div class="note-block">
                    <div class="note-content"><?php echo nl2br(htmlspecialchars($stock['notes'])); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        AUDIT INFORMATION
        ============================================================ -->
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-clipboard-check"></i> Audit Information</h3>
            </div>
            <div class="audit-grid">
                <div class="audit-item">
                    <div class="audit-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="audit-content">
                        <span class="audit-label">Created By</span>
                        <span class="audit-value"><?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?></span>
                        <?php if (!empty($stock['employee_code'])): ?>
                            <span class="audit-sub"><?php echo htmlspecialchars($stock['employee_code']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="audit-item">
                    <div class="audit-icon"><i class="fas fa-clock"></i></div>
                    <div class="audit-content">
                        <span class="audit-label">Submitted At</span>
                        <span class="audit-value"><?php echo date('d M Y', strtotime($stock['submitted_at'])); ?></span>
                        <span class="audit-sub"><?php echo date('h:i A', strtotime($stock['submitted_at'])); ?></span>
                    </div>
                </div>
                <div class="audit-item">
                    <div class="audit-icon"><i class="fas fa-sync-alt"></i></div>
                    <div class="audit-content">
                        <span class="audit-label">Last Updated</span>
                        <span class="audit-value"><?php echo date('d M Y', strtotime($stock['updated_at'])); ?></span>
                        <span class="audit-sub"><?php echo date('h:i A', strtotime($stock['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        BOTTOM ACTIONS
        ============================================================ -->
        <div class="bottom-actions">
            <a href="index.php?branch=<?php echo $stock['branch_id']; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="edit.php?id=<?php echo $stock_id; ?>" class="btn btn-edit">
                <i class="fas fa-edit"></i> Edit Stock
            </a>
            <a href="print.php?id=<?php echo $stock_id; ?>" target="_blank" class="btn btn-print">
                <i class="fas fa-print"></i> Print
            </a>
            <a href="delete.php?id=<?php echo $stock_id; ?>" 
               class="btn btn-delete" 
               onclick="return confirmDelete('<?php echo addslashes($stock['stock_number']); ?>')">
                <i class="fas fa-trash"></i> Delete
            </a>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   CSS VARIABLES — BLUE THEME
   ============================================================ */
:root {
    --ev-bg: #F3F4F6;
    --ev-text: #1F2937;
    --ev-text-secondary: #6B7280;
    --ev-text-light: #9CA3AF;
    --ev-border: #E5E7EB;
    --ev-card-bg: #FFFFFF;
    --ev-input-bg: #F9FAFB;
    --ev-hover: #F3F4F6;
    --ev-shadow: rgba(0,0,0,0.06);
    --ev-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --ev-bg: #0F172A;
    --ev-text: #F9FAFB;
    --ev-text-secondary: #9CA3AF;
    --ev-text-light: #6B7280;
    --ev-border: #334155;
    --ev-card-bg: #1E293B;
    --ev-input-bg: #334155;
    --ev-hover: #334155;
    --ev-shadow: rgba(0,0,0,0.3);
    --ev-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
body { background: var(--ev-bg) !important; color: var(--ev-text); }
.main-wrapper { background: var(--ev-bg) !important; }
.main-content { background: var(--ev-bg) !important; padding: 16px 20px !important; }

/* ============================================================
   BRANCH STATUS CARD — BLUE
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(37, 99, 235, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}
.branch-status-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.branch-status-date {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    font-weight: 600;
}
.btn-back-card {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}
.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
    transform: translateX(-3px);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 16px;
    flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px;
    font-weight: 800;
    color: var(--ev-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted {
    font-size: 13px;
    color: var(--ev-text-secondary);
    margin: 4px 0 0 0;
}
.header-left .text-muted strong {
    color: #2563EB;
    font-family: 'Courier New', monospace;
    font-weight: 800;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-edit { background: #F59E0B; color: white; }
.btn-edit:hover { background: #D97706; transform: translateY(-2px); color: white; box-shadow: 0 4px 12px rgba(245,158,11,0.4); }
.btn-delete { background: #DC2626; color: white; }
.btn-delete:hover { background: #B91C1C; transform: translateY(-2px); color: white; box-shadow: 0 4px 12px rgba(220,38,38,0.4); }
.btn-print { background: #3B82F6; color: white; }
.btn-print:hover { background: #2563EB; transform: translateY(-2px); color: white; box-shadow: 0 4px 12px rgba(59,130,246,0.4); }
.btn-secondary {
    background: var(--ev-card-bg);
    color: var(--ev-text-secondary);
    border: 1.5px solid var(--ev-border);
}
.btn-secondary:hover { background: var(--ev-hover); color: var(--ev-text); }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

/* ============================================================
   HERO CARD
   ============================================================ */
.hero-card {
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}
.hero-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 400px; height: 400px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.hero-blue { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.hero-green { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.hero-purple { background: linear-gradient(135deg, #7C3AED 0%, #A855F7 100%); }
.hero-red { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.hero-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.hero-gray { background: linear-gradient(135deg, #4B5563 0%, #6B7280 100%); }

.hero-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative;
    z-index: 1;
}
.hero-content {
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    z-index: 1;
    min-width: 0;
}
.hero-label {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
.hero-amount {
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 900;
    color: #FFFFFF;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    word-break: break-all;
}
.hero-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.hero-meta {
    display: flex;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.hero-meta-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    min-width: 120px;
}
.hmi-label {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.hmi-value {
    font-size: 13px;
    font-weight: 700;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.hmi-value i { font-size: 12px; color: #FCD34D; }

/* ============================================================
   STATUS UPDATE BAR
   ============================================================ */
.status-update-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 16px 22px;
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.sub-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.sub-left > i {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.sub-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.sub-current {
    font-size: 13px;
    color: var(--ev-text-secondary);
}
.status-current {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.status-orange { background: #FEF3C7; color: #92400E; }
.status-green { background: #D1FAE5; color: #065F46; }
.status-blue { background: #DBEAFE; color: #1D4ED8; }
.status-red { background: #FEE2E2; color: #991B1B; }
html.dark-mode .status-orange { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .status-green { background: #065F46; color: #34D399; }
html.dark-mode .status-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .status-red { background: #7F1D1D; color: #FCA5A5; }

.sub-form { flex: 0 0 auto; }
.sub-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-status {
    padding: 8px 16px;
    border-radius: 8px;
    border: 1.5px solid;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.25s ease;
    white-space: nowrap;
    background: transparent;
}
.btn-waiting { color: #D97706; border-color: #FCD34D; }
.btn-waiting:hover:not(:disabled) { background: #FEF3C7; }
.btn-approved { color: #059669; border-color: #6EE7B7; }
.btn-approved:hover:not(:disabled) { background: #D1FAE5; }
.btn-adjusted { color: #2563EB; border-color: #93C5FD; }
.btn-adjusted:hover:not(:disabled) { background: #DBEAFE; }
.btn-rejected { color: #DC2626; border-color: #FCA5A5; }
.btn-rejected:hover:not(:disabled) { background: #FEE2E2; }
.btn-status:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: var(--ev-hover);
}

/* ============================================================
   SUMMARY GRID — SEMI-TRANSPARENT COLORS
   ============================================================ */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: var(--ev-card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--ev-border);
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--ev-shadow);
    transition: all 0.3s ease;
    min-width: 0;
}
.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--ev-shadow-md);
}

/* ✅ Semi-transparent color accents */
.sc-total-float  { background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(37, 99, 235, 0.08)); }
.sc-cash         { background: linear-gradient(135deg, rgba(20, 184, 166, 0.15), rgba(13, 148, 136, 0.08)); }
.sc-deposits     { background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.08)); }
.sc-withdrawals  { background: linear-gradient(135deg, rgba(220, 38, 38, 0.15), rgba(185, 28, 28, 0.08)); }

.sc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    color: #FFFFFF;
}
.sc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); }
.sc-icon-green { background: linear-gradient(135deg, #10B981, #059669); }
.sc-icon-teal { background: linear-gradient(135deg, #14B8A6, #0D9488); }
.sc-icon-red { background: linear-gradient(135deg, #DC2626, #B91C1C); }
.sc-content { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.sc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.sc-value {
    font-size: 16px;
    font-weight: 900;
    color: var(--ev-text);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word;
}
.sc-sub {
    font-size: 9px;
    font-weight: 600;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.text-success { color: #10B981; }
.text-danger { color: #DC2626; }
.text-muted { color: var(--ev-text-light); }

/* ============================================================
   DETAILS CARD
   ============================================================ */
.details-card {
    background: var(--ev-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.section-header {
    padding: 16px 24px;
    background: var(--ev-hover);
    border-bottom: 1px solid var(--ev-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--ev-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i { color: #2563EB; font-size: 15px; }
.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    background: var(--ev-card-bg);
    padding: 4px 14px;
    border-radius: 12px;
    text-transform: uppercase;
    border: 1px solid var(--ev-border);
}

/* ============================================================
   DETAILS GRID
   ============================================================ */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
}
.detail-item {
    padding: 16px 24px;
    border-bottom: 1px solid var(--ev-border);
    border-right: 1px solid var(--ev-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.detail-item:nth-child(2n) { border-right: none; }
.detail-item:nth-last-child(-n+2) { border-bottom: none; }
.detail-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.detail-label i { color: #2563EB; font-size: 12px; }
.detail-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--ev-text);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.detail-code {
    font-family: 'Courier New', monospace;
    color: #2563EB;
    font-size: 14px;
}
.code-pill {
    font-size: 11px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 3px 10px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .code-pill { background: #1E3A5F; color: #60A5FA; }
.capital-link {
    font-weight: 700;
    color: #3B82F6;
    text-decoration: none;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}
.capital-link:hover { text-decoration: underline; }

/* ============================================================
   PROVIDERS TABLE — BLUE HEADER
   ============================================================ */
.providers-table-wrapper {
    overflow-x: auto;
    background: var(--ev-card-bg);
}
.providers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}
.providers-table thead {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
}
.providers-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody tr {
    border-bottom: 1px solid var(--ev-border);
    transition: background 0.2s ease;
}
.providers-table tbody tr:hover { background: var(--ev-hover); }
.providers-table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ev-text);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }

.provider-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.provider-icon-sm {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.provider-info-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.provider-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--ev-text);
    white-space: nowrap;
}
.provider-code {
    font-size: 9px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 1px 6px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .provider-code { background: #1E3A5F; color: #60A5FA; }

.amount-readonly {
    font-size: 12px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    font-family: 'Courier New', monospace;
}
.amount-readonly.text-success { color: #10B981; }
.amount-readonly.text-danger { color: #DC2626; }
.amount-highlight {
    font-size: 13px;
    font-weight: 800;
    color: #2563EB;
    font-family: 'Courier New', monospace;
}
html.dark-mode .amount-highlight { color: #60A5FA; }
.amount-total {
    font-size: 14px;
    font-weight: 900;
    color: #059669;
    font-family: 'Courier New', monospace;
}
html.dark-mode .amount-total { color: #34D399; }

.providers-table tfoot {
    background: var(--ev-hover);
}
.providers-table tfoot td {
    padding: 14px;
    border-top: 2px solid var(--ev-border);
}
.totals-row strong {
    font-size: 12px;
    letter-spacing: 1px;
    color: #2563EB;
    text-transform: uppercase;
}
html.dark-mode .totals-row strong { color: #60A5FA; }
.total-value {
    font-size: 14px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #2563EB;
}
html.dark-mode .total-value { color: #60A5FA; }
.total-value.text-success { color: #10B981; }
.total-value.text-danger { color: #DC2626; }

/* ============================================================
   BRANCH CASH SUMMARY CARD
   ============================================================ */
.cash-summary-card {
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 2px solid #FCD34D;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px var(--ev-shadow);
}
.csc-header {
    padding: 12px 20px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    font-weight: 800;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 2px solid #FCD34D;
}
html.dark-mode .csc-header {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FCD34D;
    border-bottom-color: #F59E0B;
}
.csc-header i { font-size: 16px; }
.csc-body {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.csc-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.csc-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.csc-value {
    font-size: 20px;
    font-weight: 900;
    color: #D97706;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .csc-value { color: #FCD34D; }
.csc-note {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--ev-text-secondary);
    background: var(--ev-hover);
    padding: 10px 14px;
    border-radius: 8px;
    border-left: 3px solid #FCD34D;
}
.csc-note i { color: #F59E0B; }

/* ============================================================
   NOTES
   ============================================================ */
.notes-section { padding: 20px 24px; }
.note-block {
    background: var(--ev-hover);
    border-radius: 10px;
    border-left: 4px solid #2563EB;
    padding: 14px 18px;
}
.note-content {
    font-size: 14px;
    color: var(--ev-text);
    line-height: 1.6;
    font-weight: 500;
}

/* ============================================================
   AUDIT GRID
   ============================================================ */
.audit-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
.audit-item {
    padding: 20px 24px;
    border-right: 1px solid var(--ev-border);
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.audit-item:last-child { border-right: none; }
.audit-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .audit-icon {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #60A5FA;
    border-color: #3B82F6;
}
.audit-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.audit-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.audit-value {
    font-size: 14px;
    font-weight: 800;
    color: var(--ev-text);
}
.audit-sub {
    font-size: 11px;
    font-weight: 600;
    color: var(--ev-text-secondary);
    font-family: 'Courier New', monospace;
}

/* ============================================================
   BOTTOM ACTIONS
   ============================================================ */
.bottom-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    padding: 20px;
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
}
.bottom-actions .btn { padding: 12px 24px; font-size: 14px; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .audit-grid { grid-template-columns: 1fr; }
    .audit-item { border-right: none; border-bottom: 1px solid var(--ev-border); }
    .audit-item:last-child { border-bottom: none; }
}
@media (max-width: 1024px) {
    .hero-card {
        grid-template-columns: 1fr;
        text-align: center;
        gap: 20px;
    }
    .hero-icon { margin: 0 auto; }
    .hero-meta { justify-content: center; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; padding: 14px 18px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    .hero-card { padding: 20px 22px; }
    .hero-icon { width: 64px; height: 64px; font-size: 28px; }
    .summary-grid { grid-template-columns: 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .detail-item { border-right: none !important; }
    .status-update-bar { flex-direction: column; align-items: stretch; }
    .sub-buttons { width: 100%; }
    .btn-status { flex: 1; justify-content: center; }
    .bottom-actions { flex-direction: column; }
    .bottom-actions .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .hero-icon { width: 56px; height: 56px; font-size: 24px; }
    .hero-amount { font-size: clamp(22px, 7vw, 32px); }
    .sc-value { font-size: 14px; }
    .csc-value { font-size: 16px; }
}
</style>

<script>
function confirmDelete(reference) {
    return confirm(
        'Are you sure you want to DELETE this evening stock?\n\n' +
        'Reference: ' + reference + '\n\n' +
        'This action cannot be undone.'
    );
}

document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() { if (successAlert.parentElement) successAlert.remove(); }, 400);
        }, 5000);
    }
});
</script>

</body>
</html>