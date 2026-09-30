<?php
// ================================================================
// FILE: modules/evening_stock/view_employee.php
// EVENING STOCK - VIEW (EMPLOYEE) - 🟢 GREEN THEME
// ✅ FIXED: Layout sahihi (haipiti nyuma ya sidebar)
// ✅ LIVE SEARCH kwenye Provider table header
// ✅ SCROLL buttons (< >) kwa table
// ✅ Highlight inafanya kazi (nmb → NMB inahighlight)
// ✅ GREEN THEME
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

$stmt = $db->prepare("SELECT branch_id, branch, full_name FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = $emp['branch_id'] ?? 0;

$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    header('Location: index_employee.php');
    exit();
}

try {
    $sql = "SELECT es.*, 
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            dr.report_number as daily_report_number,
            dr.report_date as daily_report_date
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
            WHERE es.id = ? AND es.branch_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$stock_id, $employee_branch_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stock) {
        $_SESSION['error_message'] = 'Evening stock not found or access denied.';
        header('Location: index_employee.php');
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error loading evening stock.';
    header('Location: index_employee.php');
    exit();
}

$stmt = $db->prepare("
    SELECT esp.*, 
           p.icon_class as provider_icon,
           p.color_code as provider_color
    FROM evening_stock_providers esp
    LEFT JOIN providers p ON esp.provider_id = p.id
    WHERE esp.evening_stock_id = ?
    ORDER BY esp.id ASC
");
$stmt->execute([$stock_id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_opening_float = 0;
$total_opening_cash = 0;
$total_closing_float = 0;
$total_provider_cash = 0;
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

$total_closing_cash = floatval($stock['cash_balance'] ?? 0);
$grand_total = $total_closing_float + $total_closing_cash;

$status_labels = [
    'waiting' => ['label' => 'Waiting', 'icon' => 'fa-clock', 'color' => 'orange'],
    'approved' => ['label' => 'Approved', 'icon' => 'fa-check-circle', 'color' => 'green'],
    'adjusted' => ['label' => 'Adjusted', 'icon' => 'fa-sliders-h', 'color' => 'blue'],
    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red']
];

$status_info = $status_labels[$stock['status']] ?? ['label' => $stock['status'], 'icon' => 'fa-circle', 'color' => 'gray'];

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Evening Stock</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?></span>
                <?php if (!empty($stock['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                <?php endif; ?>
                <span class="branch-status-date">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                </span>
            </div>
            <a href="index_employee.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-invoice" style="color:#059669;"></i> Evening Stock Details</h2>
                <p class="text-muted">Reference: <strong><?php echo htmlspecialchars($stock['stock_number']); ?></strong></p>
            </div>
            <div class="header-right">
                <span class="view-only-badge">
                    <i class="fas fa-eye"></i> View Only
                </span>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>

        <!-- HERO CARD -->
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
                    <span class="hmi-value"><i class="fas fa-university"></i> <?php echo formatCurrency($total_closing_float); ?></span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Branch Cash</span>
                    <span class="hmi-value"><i class="fas fa-money-bill-wave"></i> <?php echo formatCurrency($total_closing_cash); ?></span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Providers</span>
                    <span class="hmi-value"><i class="fas fa-list"></i> <?php echo count($providers); ?></span>
                </div>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-grid">
            <div class="summary-card sc-total-float">
                <div class="sc-icon sc-icon-green"><i class="fas fa-university"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Total Float</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_float); ?></span>
                    <span class="sc-sub">Provider float</span>
                </div>
            </div>
            <div class="summary-card sc-cash">
                <div class="sc-icon sc-icon-emerald"><i class="fas fa-money-bill-wave"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Branch Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_cash); ?></span>
                    <span class="sc-sub">From Daily Report</span>
                </div>
            </div>
            <div class="summary-card sc-deposits">
                <div class="sc-icon sc-icon-teal"><i class="fas fa-arrow-down"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Deposits</span>
                    <span class="sc-value text-success">+<?php echo formatCurrency($total_deposits); ?></span>
                    <span class="sc-sub">Provider deposits</span>
                </div>
            </div>
            <div class="summary-card sc-withdrawals">
                <div class="sc-icon sc-icon-red"><i class="fas fa-arrow-up"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Withdrawals</span>
                    <span class="sc-value text-danger">-<?php echo formatCurrency($total_withdrawals); ?></span>
                    <span class="sc-sub">Provider withdrawals</span>
                </div>
            </div>
        </div>

        <!-- STOCK INFO -->
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
                            <span class="code-pill"><?php echo htmlspecialchars($stock['daily_report_number']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-user"></i> Employee</span>
                    <span class="detail-value"><?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-clock"></i> Submitted</span>
                    <span class="detail-value"><?php echo date('d M Y, h:i A', strtotime($stock['submitted_at'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-tasks"></i> Status</span>
                    <span class="detail-value">
                        <span class="status-pill status-<?php echo $status_info['color']; ?>">
                            <i class="fas <?php echo $status_info['icon']; ?>"></i>
                            <?php echo $status_info['label']; ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        PROVIDERS TABLE — GREEN HEADER + LIVE SEARCH + SCROLL BUTTONS
        ============================================================ -->
        <div class="details-card">
            
            <!-- ✅ TABLE HEADER WITH SEARCH + SCROLL BUTTONS -->
            <div class="provider-table-header">
                <div class="pth-left">
                    <div class="pth-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="pth-title">
                        <h3>Provider Breakdown</h3>
                        <span class="pth-count"><?php echo count($providers); ?> Providers</span>
                    </div>
                </div>
                
                <div class="pth-right">
                    <!-- ✅ LIVE SEARCH -->
                    <div class="pth-search-wrapper">
                        <i class="fas fa-search pth-search-icon"></i>
                        <input type="text" 
                               id="providerSearchInput" 
                               class="pth-search-input" 
                               placeholder="Search provider (e.g., NMB)..."
                               oninput="onProviderSearch(this)"
                               autocomplete="off">
                        <button type="button" 
                                class="pth-search-clear" 
                                id="providerSearchClear" 
                                onclick="clearProviderSearch()" 
                                style="display:none;"
                                title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="pth-search-count" 
                              id="providerSearchCount" 
                              style="display:none;">0</span>
                    </div>
                    
                    <!-- ✅ SCROLL BUTTONS -->
                    <div class="pth-scroll-controls">
                        <button type="button" 
                                class="pth-scroll-btn" 
                                onclick="scrollProvidersTable('left')" 
                                title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button type="button" 
                                class="pth-scroll-btn" 
                                onclick="scrollProvidersTable('right')" 
                                title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- TABLE -->
            <div class="providers-table-wrapper" id="providersTableWrapper">
                <table class="providers-table" id="providersTable">
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
                    <tbody id="providersTableBody">
                        <?php $i = 1; foreach ($providers as $p): 
                            $p_total = floatval($p['closing_float']) + floatval($p['closing_cash']);
                            $search_data = strtolower(
                                ($p['provider_name'] ?? '') . ' ' . 
                                ($p['provider_code'] ?? '')
                            );
                        ?>
                            <tr class="provider-row" 
                                data-search="<?php echo htmlspecialchars($search_data); ?>"
                                data-provider-name="<?php echo htmlspecialchars($p['provider_name']); ?>">
                                <td class="row-num"><?php echo $i++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($p['provider_color'] ?? '#059669'); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <div class="provider-info-cell">
                                            <span class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            <span class="provider-code"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right"><span class="amount-readonly"><?php echo formatCurrency($p['opening_float']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly"><?php echo formatCurrency($p['opening_cash']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly text-success">+<?php echo formatCurrency($p['total_deposits']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly text-danger">-<?php echo formatCurrency($p['total_withdrawals']); ?></span></td>
                                <td class="text-right"><span class="amount-highlight"><?php echo formatCurrency($p['closing_float']); ?></span></td>
                                <td class="text-right"><span class="amount-highlight"><?php echo formatCurrency($p['closing_cash']); ?></span></td>
                                <td class="text-right"><span class="amount-total"><?php echo formatCurrency($p_total); ?></span></td>
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
            
            <!-- NO RESULTS -->
            <div class="no-provider-results" id="noProviderResults" style="display:none;">
                <i class="fas fa-search-minus"></i>
                <h3>No providers found</h3>
                <p>No providers match your search.</p>
                <button type="button" class="btn-clear-search" onclick="clearProviderSearch()">
                    <i class="fas fa-times"></i> Clear Search
                </button>
            </div>
            
        </div>

        <!-- BRANCH CASH SUMMARY (LOCKED) -->
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
                    Branch cash is locked from the Daily Report and cannot be edited.
                </div>
            </div>
        </div>

        <!-- NOTES -->
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

        <!-- BOTTOM ACTIONS -->
        <div class="bottom-actions">
            <a href="index_employee.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <button onclick="window.print()" class="btn btn-print">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
/* ============================================================
   🟢 GREEN THEME VARIABLES
   ============================================================ */
:root {
    --ev-bg: #F0FDF4;
    --ev-text: #1F2937;
    --ev-text-secondary: #6B7280;
    --ev-text-light: #9CA3AF;
    --ev-border: #D1FAE5;
    --ev-card-bg: #FFFFFF;
    --ev-input-bg: #F0FDF4;
    --ev-hover: #ECFDF5;
    --ev-shadow: rgba(5, 150, 105, 0.08);
    --ev-shadow-md: rgba(5, 150, 105, 0.18);
}
html.dark-mode {
    --ev-bg: #0A1F1A;
    --ev-text: #F9FAFB;
    --ev-text-secondary: #9CA3AF;
    --ev-text-light: #6B7280;
    --ev-border: #065F46;
    --ev-card-bg: #0F2A24;
    --ev-input-bg: #0F2A24;
    --ev-hover: #1A3D35;
    --ev-shadow: rgba(0, 0, 0, 0.4);
    --ev-shadow-md: rgba(0, 0, 0, 0.6);
}

*, *::before, *::after { box-sizing: border-box; }
html { width: 100%; overflow-x: hidden; }
body { 
    background: var(--ev-bg) !important; 
    color: var(--ev-text);
    width: 100%;
    overflow-x: hidden;
    margin: 0;
    padding: 0;
}

.main-wrapper {
    margin-left: 220px;
    width: calc(100% - 220px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--ev-bg) !important;
    transition: margin-left 0.3s ease, width 0.3s ease;
    overflow-x: hidden;
    position: relative;
}

.main-content {
    background: var(--ev-bg) !important;
    padding: 20px 24px;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

@media (max-width: 1024px) {
    .main-wrapper { margin-left: 220px; width: calc(100% - 220px); }
    .main-content { padding: 16px 18px; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 56px; }
    .main-content { padding: 16px 14px; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 50px; }
    .main-content { padding: 12px 10px; }
}

/* ============================================================
   BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(5, 150, 105, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06); border-radius: 50%;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1;
    position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.branch-status-name { font-size: 18px; font-weight: 800; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px; font-weight: 700; color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.branch-status-date {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: rgba(255, 255, 255, 0.95);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px; font-weight: 600;
}
.btn-back-card {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; transform: translateX(-3px); }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; gap: 16px; flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px; font-weight: 800;
    color: var(--ev-text); margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.header-left .text-muted { font-size: 13px; color: var(--ev-text-secondary); margin: 4px 0 0 0; }
.header-left .text-muted strong { color: #059669; font-family: 'Courier New', monospace; font-weight: 800; }

.view-only-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #065F46; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid #6EE7B7;
    text-transform: uppercase; letter-spacing: 0.8px;
}
html.dark-mode .view-only-badge {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #6EE7B7; border-color: #10B981;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
    font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
.alert i { font-size: 20px; }

/* ============================================================
   HERO CARD
   ============================================================ */
.hero-card {
    border-radius: 16px; padding: 28px 32px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center; gap: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    position: relative; overflow: hidden; color: #FFFFFF;
}
.hero-card::before {
    content: ''; position: absolute; top: -50%; right: -5%;
    width: 400px; height: 400px;
    background: rgba(255, 255, 255, 0.08); border-radius: 50%;
}
.hero-green { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.hero-blue { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.hero-purple { background: linear-gradient(135deg, #7C3AED 0%, #A855F7 100%); }
.hero-red { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.hero-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.hero-gray { background: linear-gradient(135deg, #4B5563 0%, #6B7280 100%); }
.hero-icon {
    width: 80px; height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px; color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative; z-index: 1;
}
.hero-content {
    display: flex; flex-direction: column; gap: 6px;
    position: relative; z-index: 1; min-width: 0;
}
.hero-label {
    font-size: 11px; font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.hero-amount {
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 900; color: #FFFFFF;
    font-family: 'Inter', 'Courier New', monospace;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    word-break: break-all;
}
.hero-type-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.hero-meta { display: flex; gap: 12px; position: relative; z-index: 1; flex-wrap: wrap; }
.hero-meta-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    min-width: 120px;
}
.hmi-label {
    font-size: 10px; font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase; letter-spacing: 1px;
}
.hmi-value {
    font-size: 13px; font-weight: 700;
    color: #FFFFFF;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.hmi-value i { font-size: 12px; color: #FCD34D; }

/* ============================================================
   SUMMARY CARDS
   ============================================================ */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    border-radius: 14px; padding: 18px 20px;
    border: 1.5px solid var(--ev-border);
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 2px 8px var(--ev-shadow);
    transition: all 0.3s ease;
}
.summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px var(--ev-shadow-md); }
.sc-total-float { background: linear-gradient(135deg, rgba(5, 150, 105, 0.15), rgba(4, 120, 87, 0.08)); }
.sc-cash        { background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.08)); }
.sc-deposits    { background: linear-gradient(135deg, rgba(52, 211, 153, 0.15), rgba(16, 185, 129, 0.08)); }
.sc-withdrawals { background: linear-gradient(135deg, rgba(220, 38, 38, 0.15), rgba(185, 28, 28, 0.08)); }
.sc-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0; color: #FFFFFF;
}
.sc-icon-green { background: linear-gradient(135deg, #059669, #047857); }
.sc-icon-emerald { background: linear-gradient(135deg, #10B981, #059669); }
.sc-icon-teal { background: linear-gradient(135deg, #14B8A6, #0D9488); }
.sc-icon-red { background: linear-gradient(135deg, #DC2626, #B91C1C); }
.sc-content { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.sc-label {
    font-size: 10px; font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase; letter-spacing: 1px;
}
.sc-value {
    font-size: 16px; font-weight: 900;
    color: var(--ev-text);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word;
}
.sc-sub {
    font-size: 9px; font-weight: 600;
    color: var(--ev-text-light);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.text-success { color: #059669; }
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
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.08), rgba(16, 185, 129, 0.05));
    border-bottom: 1px solid var(--ev-border);
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; flex-wrap: wrap;
}
.section-header h3 {
    font-size: 14px; font-weight: 800;
    color: var(--ev-text); margin: 0;
    display: flex; align-items: center; gap: 10px;
    text-transform: uppercase; letter-spacing: 0.8px;
}
.section-header h3 i { color: #059669; font-size: 15px; }
.section-badge {
    font-size: 10px; font-weight: 700;
    color: #065F46; background: #D1FAE5;
    padding: 4px 14px; border-radius: 12px;
    border: 1px solid #6EE7B7;
}
html.dark-mode .section-badge {
    background: #065F46; color: #6EE7B7; border-color: #10B981;
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
    display: flex; flex-direction: column; gap: 6px;
}
.detail-item:nth-child(2n) { border-right: none; }
.detail-item:nth-last-child(-n+2) { border-bottom: none; }
.detail-label {
    font-size: 11px; font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 6px;
}
.detail-label i { color: #059669; font-size: 12px; }
.detail-value {
    font-size: 15px; font-weight: 700;
    color: var(--ev-text);
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.detail-code {
    font-family: 'Courier New', monospace;
    color: #059669; font-size: 14px;
}
.code-pill {
    font-size: 11px; font-weight: 700;
    color: #065F46; background: #D1FAE5;
    padding: 3px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
    border: 1px solid #6EE7B7;
}
html.dark-mode .code-pill { background: #065F46; color: #6EE7B7; border-color: #10B981; }

.status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 10px;
    font-size: 11px; font-weight: 800;
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

/* ============================================================
   🟢 PROVIDER TABLE HEADER — LIVE SEARCH + SCROLL BUTTONS
   ============================================================ */
.provider-table-header {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.provider-table-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.pth-left {
    display: flex; align-items: center; gap: 12px;
    position: relative; z-index: 1; flex-shrink: 0;
}
.pth-icon {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    flex-shrink: 0;
}
.pth-title { display: flex; flex-direction: column; gap: 2px; }
.pth-title h3 {
    font-size: 15px; font-weight: 800; margin: 0;
    text-transform: uppercase; letter-spacing: 0.8px;
    white-space: nowrap;
}
.pth-count {
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    display: inline-flex; align-items: center; gap: 4px;
}

.pth-right {
    display: flex; align-items: center; gap: 10px;
    position: relative; z-index: 1;
    flex: 1; justify-content: flex-end;
    min-width: 0;
}

/* ✅ LIVE SEARCH BAR */
.pth-search-wrapper {
    position: relative;
    display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.98);
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    padding: 8px 14px;
    min-width: 260px;
    max-width: 340px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.pth-search-wrapper:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.4);
}
.pth-search-icon {
    color: #059669;
    font-size: 13px;
    flex-shrink: 0;
}
.pth-search-input {
    flex: 1;
    border: none; background: transparent;
    padding: 4px 0;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-weight: 500;
}
.pth-search-input::placeholder {
    color: #9CA3AF;
    font-size: 12px;
}
.pth-search-clear {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.pth-search-clear:hover {
    background: #DC2626;
    color: #FFFFFF;
}
.pth-search-count {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* ✅ SCROLL BUTTONS < > */
.pth-scroll-controls {
    display: flex; align-items: center; gap: 6px;
    flex-shrink: 0;
}
.pth-scroll-btn {
    width: 38px; height: 38px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #059669;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
    padding: 0;
    line-height: 1;
}
.pth-scroll-btn:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.pth-scroll-btn:active {
    transform: translateY(0);
}
.pth-scroll-btn i {
    font-size: 14px;
    display: block;
    line-height: 1;
}

/* ============================================================
   TABLE
   ============================================================ */
.providers-table-wrapper {
    overflow-x: auto;
    background: var(--ev-card-bg);
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.providers-table-wrapper::-webkit-scrollbar {
    height: 8px;
}
.providers-table-wrapper::-webkit-scrollbar-track {
    background: var(--ev-hover);
    border-radius: 4px;
}
.providers-table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #059669, #047857);
    border-radius: 4px;
}
.providers-table-wrapper::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #047857, #065F46);
}

.providers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}
.providers-table thead {
    background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
}
html.dark-mode .providers-table thead {
    background: linear-gradient(135deg, #065F46, #047857);
}
.providers-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 800;
    color: #065F46;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    border-bottom: 2px solid #059669;
}
html.dark-mode .providers-table thead th {
    color: #6EE7B7;
}
.providers-table thead th.text-right { text-align: right; }

.providers-table tbody tr {
    border-bottom: 1px solid var(--ev-border);
    transition: all 0.2s ease;
}
.providers-table tbody tr:hover {
    background: var(--ev-hover);
}
.providers-table tbody tr:nth-child(even) {
    background: rgba(5, 150, 105, 0.03);
}

/* ✅ SEARCH MATCH HIGHLIGHT */
.providers-table tbody tr.search-match {
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.28), rgba(252, 211, 77, 0.12)) !important;
    border-left: 4px solid #F59E0B;
    animation: searchPulse 1.5s ease;
}
.providers-table tbody tr.search-hidden {
    display: none !important;
}
.providers-table tbody tr.search-match td {
    font-weight: 700;
}
.providers-table mark {
    background: #FEF08A;
    color: #78350F;
    padding: 2px 4px;
    border-radius: 4px;
    font-weight: 900;
    border-bottom: 2px solid #F59E0B;
    animation: markPulse 1.5s ease;
}
@keyframes searchPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.005); }
}
@keyframes markPulse {
    0%, 100% { background: #FEF08A; }
    50% { background: #FDE047; }
}
html.dark-mode .providers-table mark {
    background: #78350F;
    color: #FEF08A;
    border-bottom-color: #FCD34D;
}

.providers-table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ev-text);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }

.row-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--ev-hover);
    font-size: 11px; font-weight: 700;
    color: var(--ev-text-secondary);
    border: 1px solid var(--ev-border);
}

.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon-sm {
    width: 34px; height: 34px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 13px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.provider-info-cell { display: flex; flex-direction: column; gap: 2px; }
.provider-name { font-size: 12px; font-weight: 700; color: var(--ev-text); }
.provider-code {
    font-size: 9px; font-weight: 700;
    color: #065F46; background: #D1FAE5;
    padding: 1px 6px; border-radius: 5px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
    border: 1px solid #6EE7B7;
}
html.dark-mode .provider-code { background: #065F46; color: #6EE7B7; border-color: #10B981; }

.amount-readonly {
    font-size: 12px; font-weight: 700;
    color: var(--ev-text-secondary);
    font-family: 'Courier New', monospace;
}
.amount-readonly.text-success { color: #059669; }
.amount-readonly.text-danger { color: #DC2626; }
.amount-highlight {
    font-size: 13px; font-weight: 800;
    color: #059669;
    font-family: 'Courier New', monospace;
}
html.dark-mode .amount-highlight { color: #34D399; }
.amount-total {
    font-size: 14px; font-weight: 900;
    color: #047857;
    font-family: 'Courier New', monospace;
}
html.dark-mode .amount-total { color: #34D399; }

.providers-table tfoot {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.08), rgba(16, 185, 129, 0.05));
}
.providers-table tfoot td {
    padding: 14px;
    border-top: 2px solid #059669;
}
.totals-row strong {
    font-size: 12px; letter-spacing: 1px;
    color: #047857;
    text-transform: uppercase;
}
html.dark-mode .totals-row strong { color: #6EE7B7; }
.total-value {
    font-size: 14px; font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #059669;
}
html.dark-mode .total-value { color: #34D399; }
.total-value.text-success { color: #059669; }
.total-value.text-danger { color: #DC2626; }

/* ============================================================
   NO RESULTS
   ============================================================ */
.no-provider-results {
    padding: 60px 20px;
    text-align: center;
    background: var(--ev-hover);
}
.no-provider-results i {
    font-size: 48px;
    color: var(--ev-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-provider-results h3 {
    font-size: 16px;
    color: var(--ev-text);
    margin: 0 0 6px 0;
    font-weight: 700;
}
.no-provider-results p {
    font-size: 13px;
    color: var(--ev-text-secondary);
    margin: 0 0 16px 0;
}
.btn-clear-search {
    padding: 8px 20px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #FFFFFF;
    border: none;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.25s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-clear-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
    color: #FFFFFF;
}

/* ============================================================
   BRANCH CASH SUMMARY
   ============================================================ */
.cash-summary-card {
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 2px solid #6EE7B7;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px var(--ev-shadow);
}
.csc-header {
    padding: 12px 20px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    display: flex; align-items: center; gap: 10px;
    font-size: 12px; font-weight: 800;
    color: #065F46;
    text-transform: uppercase; letter-spacing: 1px;
    border-bottom: 2px solid #6EE7B7;
}
html.dark-mode .csc-header {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #6EE7B7; border-bottom-color: #10B981;
}
.csc-header i { font-size: 16px; }
.csc-body {
    padding: 16px 20px;
    display: flex; flex-direction: column; gap: 10px;
}
.csc-item {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; flex-wrap: wrap;
}
.csc-label {
    font-size: 12px; font-weight: 700;
    color: var(--ev-text-secondary);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.csc-value {
    font-size: 20px; font-weight: 900;
    color: #047857;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .csc-value { color: #34D399; }
.csc-note {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px;
    color: var(--ev-text-secondary);
    background: var(--ev-hover);
    padding: 10px 14px; border-radius: 8px;
    border-left: 3px solid #10B981;
}
.csc-note i { color: #059669; }

/* ============================================================
   NOTES
   ============================================================ */
.notes-section { padding: 20px 24px; }
.note-block {
    background: var(--ev-hover);
    border-radius: 10px;
    border-left: 4px solid #059669;
    padding: 14px 18px;
}
.note-content {
    font-size: 14px;
    color: var(--ev-text);
    line-height: 1.6; font-weight: 500;
}

/* ============================================================
   BOTTOM ACTIONS
   ============================================================ */
.bottom-actions {
    display: flex; gap: 12px;
    justify-content: center; flex-wrap: wrap;
    padding: 20px;
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
}
.bottom-actions .btn { padding: 12px 24px; font-size: 14px; }
.btn {
    padding: 10px 20px; border-radius: 8px;
    font-weight: 600; font-size: 13px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; white-space: nowrap;
}
.btn-secondary {
    background: var(--ev-card-bg);
    color: var(--ev-text-secondary);
    border: 1.5px solid var(--ev-border);
}
.btn-secondary:hover { background: var(--ev-hover); color: var(--ev-text); }
.btn-print { 
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-print:hover { 
    transform: translateY(-2px); 
    color: white; 
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .hero-card { grid-template-columns: 1fr; text-align: center; gap: 20px; }
    .hero-icon { margin: 0 auto; }
    .hero-meta { justify-content: center; }
    .provider-table-header { flex-direction: column; align-items: stretch; }
    .pth-right { width: 100%; flex-wrap: wrap; }
    .pth-search-wrapper { min-width: 0; max-width: none; flex: 1; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .summary-grid { grid-template-columns: 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .detail-item { border-right: none !important; }
    .bottom-actions { flex-direction: column; }
    .bottom-actions .btn { width: 100%; justify-content: center; }
    
    .pth-left { width: 100%; }
    .pth-right { flex-direction: column; }
    .pth-search-wrapper { width: 100%; max-width: none; }
    .pth-scroll-controls { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .hero-icon { width: 56px; height: 56px; font-size: 24px; }
    .hero-amount { font-size: clamp(22px, 7vw, 32px); }
    .csc-value { font-size: 16px; }
    .pth-scroll-btn { width: 42px; height: 42px; font-size: 16px; }
}
</style>

<script>
// ============================================================
// 🟢 LIVE SEARCH - PROVIDER TABLE
// ============================================================
function onProviderSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.provider-row');
    const clearBtn = document.getElementById('providerSearchClear');
    const countBadge = document.getElementById('providerSearchCount');
    const noResults = document.getElementById('noProviderResults');
    const tableWrapper = document.getElementById('providersTableWrapper');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    // Empty → Reset
    if (searchTerm.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeMarks(row);
        });
        if (countBadge) {
            countBadge.style.display = 'none';
            countBadge.textContent = '0';
        }
        if (noResults) noResults.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = '';
        return;
    }
    
    let matchCount = 0;
    let firstMatch = null;
    
    rows.forEach(row => {
        removeMarks(row);
        
        const searchData = (row.getAttribute('data-search') || '').toLowerCase();
        
        if (searchData.includes(searchTerm)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatches(row, searchTerm);
            matchCount++;
            if (!firstMatch) firstMatch = row;
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
    
    // ✅ SCROLL TO FIRST MATCH
    if (firstMatch && matchCount > 0) {
        setTimeout(() => {
            firstMatch.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 100);
    }
}

// ✅ HIGHLIGHT MATCHES
function highlightMatches(row, term) {
    if (!term || term.length === 0) return;
    
    const searchLower = term.toLowerCase();
    const termLength = term.length;
    const cells = row.querySelectorAll('td');
    
    cells.forEach(cell => {
        // Skip action cells
        if (cell.querySelector('.btn-view-provider')) return;
        
        const walker = document.createTreeWalker(
            cell,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
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

// ✅ REMOVE MARKS
function removeMarks(row) {
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

// ✅ CLEAR SEARCH
function clearProviderSearch() {
    const input = document.getElementById('providerSearchInput');
    if (input) {
        input.value = '';
        onProviderSearch(input);
        input.focus();
    }
}

// ============================================================
// 🟢 SCROLL BUTTONS - PROVIDER TABLE
// ============================================================
function scrollProvidersTable(direction) {
    const wrapper = document.getElementById('providersTableWrapper');
    if (!wrapper) return;
    
    const scrollAmount = 350;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const input = document.getElementById('providerSearchInput');
        if (input && input.value.length > 0 && document.activeElement === input) {
            clearProviderSearch();
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const input = document.getElementById('providerSearchInput');
        if (input) { input.focus(); input.select(); }
    }
});

// ============================================================
// DARK MODE SYNC
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