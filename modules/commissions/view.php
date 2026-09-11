<?php
// ================================================================
// FILE: modules/commissions/view.php
// WAKALA FINANCIAL SYSTEM - VIEW COMMISSION (SIMPLE)
// Shows ONLY the transaction details - no summary cards
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET COMMISSION ID
// ============================================================
$commission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($commission_id <= 0) {
    $_SESSION['error_message'] = 'Invalid commission selected.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET COMMISSION DATA
// ============================================================
$sql = "SELECT c.*, 
               e.full_name as employee_name, 
               b.branch_name as branch_display_name,
               b.branch_code as branch_display_code,
               b.location as branch_location
        FROM commissions c
        LEFT JOIN employees e ON c.employee_id = e.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE c.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$commission_id]);
$view_commission = $stmt->fetch();

if (!$view_commission) {
    $_SESSION['error_message'] = 'Commission not found.';
    header('Location: index.php');
    exit();
}

if ($role == 'employee' && $view_commission['employee_id'] != $user_id) {
    $_SESSION['error_message'] = 'You do not have permission to view this commission.';
    header('Location: index.php');
    exit();
}

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($view_commission['provider_data'] ?? '{}', true);

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

$total_commission = floatval($view_commission['total_commission'] ?? 0);
$other_income = floatval($view_commission['other_income'] ?? 0);
$total_business_income = floatval($view_commission['total_business_income'] ?? 0);
$allocate_to_capital = $view_commission['allocate_to_capital'] ?? 'yes';
$allocated_amount = floatval($view_commission['allocated_amount'] ?? 0);

$is_other_income_only = (empty($provider_data) && $other_income > 0);

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

$branch_qs = ($view_commission['branch_id'] > 0) ? '?branch_id=' . $view_commission['branch_id'] : '';

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PERSISTENT RED BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Commission Branch</span>
                <span class="branch-status-name">
                    <?php echo htmlspecialchars($view_commission['branch_display_name'] ?? $view_commission['branch'] ?? 'Main'); ?>
                </span>
                <?php if (!empty($view_commission['branch_display_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($view_commission['branch_display_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($view_commission['branch_location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($view_commission['branch_location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="index.php<?php echo $branch_qs; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2>
                    <i class="fas <?php echo $is_other_income_only ? 'fa-coins' : 'fa-hand-holding-usd'; ?>"></i>
                    <?php echo $is_other_income_only ? 'Other Income Details' : 'Commission Details'; ?>
                </h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($view_commission['commission_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="edit.php?id=<?php echo $commission_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="delete.php?id=<?php echo $commission_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $commission_id; ?>)">
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
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        MAIN VIEW CARD - TRANSACTION DETAILS
        ============================================================ -->
        <div class="view-card">
            
            <!-- Card Header with Big Amount -->
            <div class="view-card-top <?php echo $is_other_income_only ? 'header-other-income' : 'header-commission'; ?>">
                <div class="top-icon">
                    <i class="fas <?php echo $is_other_income_only ? 'fa-coins' : 'fa-hand-holding-usd'; ?>"></i>
                </div>
                <div class="top-info">
                    <span class="top-label"><?php echo $is_other_income_only ? 'OTHER INCOME' : 'TOTAL BUSINESS INCOME'; ?></span>
                    <span class="top-value"><?php echo formatCurrency($total_business_income); ?></span>
                    <span class="top-meta">
                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($view_commission['commission_number']); ?>
                        <span class="top-meta-sep">•</span>
                        <i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($view_commission['commission_date'])); ?>
                    </span>
                </div>
            </div>

            <!-- Card Body -->
            <div class="view-card-body">
                
                <!-- TRANSACTION INFORMATION SECTION -->
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Transaction Information
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-calendar-alt"></i> Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($view_commission['commission_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_commission['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_commission['branch_display_name'] ?? $view_commission['branch'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-clock"></i> Created</span>
                        <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($view_commission['created_at'])); ?></span>
                    </div>
                </div>
                
                <!-- FINANCIAL BREAKDOWN SECTION -->
                <div class="section-title">
                    <i class="fas fa-calculator"></i> Financial Breakdown
                </div>
                
                <div class="amounts-breakdown">
                    
                    <!-- Provider Commission (only if not other income only) -->
                    <?php if (!$is_other_income_only): ?>
                    <div class="amount-row">
                        <span class="amount-row-label">
                            <i class="fas fa-hand-holding-usd"></i>
                            Provider Commission
                        </span>
                        <span class="amount-row-value amount-commission">
                            <?php echo formatCurrency($total_commission); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Other Income (only if exists) -->
                    <?php if ($other_income > 0): ?>
                    <div class="amount-row">
                        <span class="amount-row-label">
                            <i class="fas fa-coins"></i>
                            Other Income
                        </span>
                        <span class="amount-row-value amount-other-income">
                            <?php echo formatCurrency($other_income); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Divider -->
                    <div class="amount-divider"></div>
                    
                    <!-- Total -->
                    <div class="amount-row total-row">
                        <span class="amount-row-label">
                            <i class="fas fa-chart-bar"></i>
                            Total Business Income
                        </span>
                        <span class="amount-row-value amount-total">
                            <?php echo formatCurrency($total_business_income); ?>
                        </span>
                    </div>
                    
                    <!-- Allocate to Capital -->
                    <div class="amount-row allocation-row">
                        <span class="amount-row-label">
                            <i class="fas fa-building"></i>
                            Allocate to Capital
                            <span class="allocation-badge <?php echo $allocate_to_capital == 'yes' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $allocate_to_capital == 'yes' ? 'YES' : 'NO'; ?>
                            </span>
                        </span>
                        <span class="amount-row-value <?php echo $allocate_to_capital == 'yes' ? 'amount-allocated' : 'amount-not-allocated'; ?>">
                            <?php echo formatCurrency($allocated_amount); ?>
                        </span>
                    </div>
                    
                </div>
                
                <!-- NOTES SECTION -->
                <?php if (!empty($view_commission['notes'])): ?>
                <div class="section-title">
                    <i class="fas fa-sticky-note"></i> Notes
                </div>
                
                <div class="notes-box">
                    <?php echo nl2br(htmlspecialchars($view_commission['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <!-- PROVIDERS SECTION (only if not other income only) -->
                <?php if (!$is_other_income_only && !empty($provider_data)): ?>
                <div class="section-title">
                    <i class="fas fa-university"></i> Provider Commissions
                    <span class="section-count"><?php echo count($provider_data); ?> providers</span>
                </div>
                
                <div class="table-responsive">
                    <table class="providers-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th style="text-align:right;">Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($provider_data as $provider_id => $amount): 
                                $provider = $providers[$provider_id] ?? null;
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="provider-cell">
                                            <?php if ($provider): ?>
                                                <span class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                                    <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                                </span>
                                                <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                            <?php else: ?>
                                                <span class="provider-name">Provider #<?php echo $provider_id; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="provider-code-badge">
                                            <?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="amount-cell">
                                        <span class="amount commission"><?php echo formatCurrency($amount); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right"><strong>Total Commission</strong></td>
                                <td class="amount-cell">
                                    <span class="amount commission"><?php echo formatCurrency($total_commission); ?></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
                
            </div>
        </div>

        <!-- ============================================================
        ACTION BUTTONS
        ============================================================ -->
        <div class="view-actions">
            <a href="index.php<?php echo $branch_qs; ?>" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="edit.php?id=<?php echo $commission_id; ?>" class="btn btn-edit">
                <i class="fas fa-edit"></i> Edit
            </a>
            <a href="delete.php?id=<?php echo $commission_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $commission_id; ?>)">
                <i class="fas fa-trash"></i> Delete
            </a>
        </div>

    </div>
    
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
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
}

html.dark-mode {
    --view-bg: #0f172a;
    --view-text: #F1F5F9;
    --view-text-secondary: #94A3B8;
    --view-text-light: #64748B;
    --view-border: #334155;
    --view-card-bg: #1E293B;
    --view-card-header: #374151;
    --view-hover: #2D3A4F;
    --view-shadow: rgba(0,0,0,0.3);
    --view-shadow-lg: rgba(0,0,0,0.5);
}

body {
    background: var(--view-bg) !important;
    color: var(--view-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--view-bg) !important; }

.main-content {
    background: var(--view-bg) !important;
    padding: 16px 20px !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    box-sizing: border-box;
}

/* ============================================================
   RED BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}

.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
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
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-status-name {
    font-size: 18px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-status-code {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-status-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.btn-back-card {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
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
    flex-wrap: wrap;
    gap: 12px;
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
}

.page-header-left h2 i {
    color: #10B981;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

.page-header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 9px 20px;
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
    white-space: nowrap;
}

.btn-edit {
    background: #FEF3C7;
    color: #D97706;
}

.btn-edit:hover {
    background: #FDE68A;
    transform: translateY(-1px);
}

html.dark-mode .btn-edit {
    background: #5F3A1E;
    color: #FBBF24;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    transform: translateY(-1px);
}

html.dark-mode .btn-delete {
    background: #7F1D1D;
    color: #FCA5A5;
}

.btn-back {
    background: var(--view-hover);
    color: var(--view-text-secondary);
}

.btn-back:hover {
    background: var(--view-border);
    color: var(--view-text);
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    font-size: 13px;
    animation: slideDown 0.4s ease forwards;
}

.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}

.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

html.dark-mode .alert-success {
    background: #065F46;
    color: #D1FAE5;
    border: 1px solid #047857;
}

html.dark-mode .alert-danger {
    background: #7F1D1D;
    color: #FEE2E2;
    border: 1px solid #991B1B;
}

.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }

.alert-close {
    background: transparent;
    border: none;
    font-size: 20px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}

.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   VIEW CARD
   ============================================================ */
.view-card {
    background: var(--view-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    overflow: hidden;
    margin-bottom: 16px;
    animation: fadeInUp 0.4s ease forwards;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

/* ============================================================
   VIEW CARD TOP - BIG AMOUNT HEADER
   ============================================================ */
.view-card-top {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 28px 32px;
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.view-card-top.header-commission {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
}

.view-card-top.header-other-income {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    box-shadow: 0 4px 20px rgba(124, 58, 237, 0.3);
}

.view-card-top::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.view-card-top::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 30%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.top-icon {
    width: 72px;
    height: 72px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #FFFFFF;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.top-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
    z-index: 1;
    flex: 1;
    min-width: 0;
}

.top-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
}

.top-value {
    font-size: clamp(24px, 2.5vw, 40px);
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: -0.5px;
    line-height: 1.15;
    word-break: break-all;
    overflow-wrap: anywhere;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    margin: 2px 0;
}

.top-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    flex-wrap: wrap;
    margin-top: 4px;
}

.top-meta i {
    margin-right: 2px;
}

.top-meta-sep {
    opacity: 0.5;
}

/* ============================================================
   VIEW CARD BODY
   ============================================================ */
.view-card-body {
    padding: 24px 28px;
}

/* ============================================================
   SECTION TITLE
   ============================================================ */
.section-title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--view-text-secondary);
    padding-bottom: 10px;
    margin-bottom: 14px;
    margin-top: 24px;
    border-bottom: 2px solid var(--view-border);
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title:first-child {
    margin-top: 0;
}

.section-title i {
    color: #10B981;
    font-size: 14px;
}

.section-count {
    margin-left: auto;
    font-size: 11px;
    text-transform: none;
    letter-spacing: 0;
    background: var(--view-hover);
    padding: 2px 10px;
    border-radius: 10px;
    color: var(--view-text-light);
    font-weight: 600;
}

/* ============================================================
   INFO GRID
   ============================================================ */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 16px;
    background: var(--view-hover);
    border-radius: 8px;
    border: 1px solid var(--view-border);
    min-width: 0;
}

.info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--view-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.info-label i {
    color: #10B981;
    font-size: 11px;
}

.info-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--view-text);
    word-break: break-word;
}

/* ============================================================
   AMOUNTS BREAKDOWN
   ============================================================ */
.amounts-breakdown {
    background: var(--view-hover);
    border-radius: 10px;
    padding: 4px 0;
    border: 1px solid var(--view-border);
    overflow: hidden;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    gap: 12px;
    transition: background 0.2s ease;
    flex-wrap: wrap;
}

.amount-row:hover {
    background: rgba(0, 0, 0, 0.02);
}

html.dark-mode .amount-row:hover {
    background: rgba(255, 255, 255, 0.02);
}

.amount-row-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--view-text-secondary);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.amount-row-label i {
    width: 18px;
    color: #10B981;
    font-size: 14px;
}

.amount-row-value {
    font-size: clamp(14px, 1.2vw, 18px);
    font-weight: 800;
    letter-spacing: -0.3px;
    word-break: break-all;
    overflow-wrap: anywhere;
    text-align: right;
    min-width: 0;
}

.amount-commission {
    color: #10B981;
}

.amount-other-income {
    color: #7C3AED;
}

.amount-divider {
    height: 1px;
    background: var(--view-border);
    margin: 0 20px;
}

.total-row {
    background: rgba(16, 185, 129, 0.06);
}

html.dark-mode .total-row {
    background: rgba(16, 185, 129, 0.1);
}

.total-row .amount-row-label {
    color: var(--view-text);
    font-size: 14px;
    font-weight: 700;
}

.amount-total {
    color: #059669;
    font-size: clamp(16px, 1.4vw, 22px);
}

html.dark-mode .amount-total {
    color: #34D399;
}

.allocation-row {
    background: rgba(59, 130, 246, 0.04);
    border-top: 1px dashed var(--view-border);
}

html.dark-mode .allocation-row {
    background: rgba(59, 130, 246, 0.1);
}

.amount-allocated {
    color: #1D4ED8;
}

html.dark-mode .amount-allocated {
    color: #60A5FA;
}

.amount-not-allocated {
    color: #D97706;
}

.allocation-badge {
    font-size: 9px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 8px;
    letter-spacing: 0.5px;
    margin-left: 6px;
}

.badge-success {
    background: #D1FAE5;
    color: #065F46;
}

.badge-warning {
    background: #FEF3C7;
    color: #D97706;
}

html.dark-mode .badge-success {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .badge-warning {
    background: #5F3A1E;
    color: #FBBF24;
}

/* ============================================================
   NOTES BOX
   ============================================================ */
.notes-box {
    background: #FEF3C7;
    border: 1px solid #FDE68A;
    border-left: 4px solid #F59E0B;
    padding: 16px 20px;
    border-radius: 8px;
    font-size: 14px;
    line-height: 1.7;
    color: #78350F;
    word-break: break-word;
}

html.dark-mode .notes-box {
    background: #5F3A1E;
    border-color: #92400E;
    border-left-color: #FBBF24;
    color: #FDE68A;
}

/* ============================================================
   PROVIDERS TABLE
   ============================================================ */
.table-responsive {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    border-radius: 10px;
    border: 1px solid var(--view-border);
}

.providers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.providers-table thead {
    background: #10B981;
}

.providers-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.providers-table tbody tr {
    border-bottom: 1px solid var(--view-border);
    transition: background 0.2s ease;
}

.providers-table tbody tr:hover {
    background: var(--view-hover);
}

.providers-table tbody td {
    padding: 12px 16px;
    color: var(--view-text);
}

.provider-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.provider-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    flex-shrink: 0;
}

.provider-name {
    font-weight: 500;
    color: var(--view-text);
}

.provider-code-badge {
    font-size: 11px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.12);
    padding: 2px 10px;
    border-radius: 10px;
}

.amount-cell {
    text-align: right;
    font-weight: 600;
}

.amount.commission {
    color: #10B981;
    font-weight: 700;
}

.providers-table tfoot td {
    padding: 12px 16px;
    border-top: 2px solid #10B981;
    background: rgba(16, 185, 129, 0.06);
    font-weight: 700;
}

.providers-table tfoot .text-right {
    text-align: right;
}

/* ============================================================
   VIEW ACTIONS
   ============================================================ */
.view-actions {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
    background: var(--view-card-bg);
    border-radius: 12px;
    border: 1px solid var(--view-border);
    box-shadow: 0 1px 3px var(--view-shadow);
    flex-wrap: wrap;
    animation: fadeInUp 0.5s ease forwards;
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .main-content {
        padding: 12px !important;
    }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
    }
    
    .branch-status-info {
        width: 100%;
    }
    
    .btn-back-card {
        width: 100%;
        justify-content: center;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .page-header-right {
        width: 100%;
        flex-direction: column;
    }
    
    .page-header-right .btn {
        width: 100%;
        justify-content: center;
    }
    
    .view-card-top {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px 22px;
        gap: 14px;
    }
    
    .top-icon {
        width: 60px;
        height: 60px;
        font-size: 26px;
    }
    
    .top-value {
        font-size: clamp(22px, 6vw, 30px);
    }
    
    .view-card-body {
        padding: 18px 16px;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .amount-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .amount-row-value {
        text-align: left;
        width: 100%;
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 10px !important;
    }
    
    .branch-status-name {
        font-size: 15px;
    }
    
    .branch-status-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .page-header-left h2 {
        font-size: 17px;
    }
    
    .view-card-top {
        padding: 18px 18px;
    }
    
    .top-icon {
        width: 54px;
        height: 54px;
        font-size: 22px;
    }
    
    .top-value {
        font-size: clamp(20px, 7vw, 26px);
    }
    
    .view-card-body {
        padding: 16px 14px;
    }
    
    .providers-table thead th,
    .providers-table tbody td {
        padding: 10px 12px;
        font-size: 12px;
    }
}
</style>

<script>
function confirmDelete(id) {
    return confirm('Are you sure you want to delete this commission?\n\nThis action cannot be undone.');
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
    if (successAlert) setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    
    var closeBtns = document.querySelectorAll('.alert-close');
    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
});
</script>
</body>
</html>