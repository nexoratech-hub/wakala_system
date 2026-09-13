<?php
// ================================================================
// FILE: modules/capital_management/view.php
// WAKALA FINANCIAL SYSTEM - VIEW CAPITAL TRANSACTION
// ✅ Shows full transaction details
// ✅ Provider info (if float transaction)
// ✅ Employee who created it
// ✅ Action buttons: Edit | Delete | Back
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET TRANSACTION ID
// ============================================================
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transaction_id <= 0) {
    $_SESSION['error_message'] = 'Invalid transaction ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET TRANSACTION DATA
// ============================================================
try {
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            e.email as employee_email,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            p.provider_name,
            p.provider_code as main_provider_code,
            p.icon_class as provider_icon,
            p.color_code as provider_color,
            p.provider_type,
            bp.provider_code as branch_provider_code
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            LEFT JOIN providers p ON cm.reference_id = p.id AND cm.reference_module = 'provider'
            LEFT JOIN branch_providers bp ON bp.branch_id = cm.branch_id AND bp.provider_id = p.id
            WHERE cm.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $transaction = null;
}

if (!$transaction) {
    $_SESSION['error_message'] = 'Transaction not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// PARSE DATA
// ============================================================
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

$type_info = $type_labels[$transaction['transaction_type']] ?? [
    'label' => ucfirst(str_replace('_', ' ', $transaction['transaction_type'])),
    'icon' => 'fa-circle',
    'color' => 'gray'
];

$is_outgoing = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']);
$is_float = ($transaction['reference_module'] === 'provider' && !empty($transaction['provider_name']));

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

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Branch</span>
                <span class="branch-status-name">
                    <?php echo htmlspecialchars($transaction['branch_name'] ?? 'N/A'); ?>
                </span>
                <?php if (!empty($transaction['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($transaction['branch_location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($transaction['branch_location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="index.php?branch=<?php echo $transaction['branch_id']; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas fa-file-invoice" style="color:#bb0404;"></i>
                    Capital Transaction Details
                </h2>
                <p class="text-muted">
                    Reference: <strong><?php echo htmlspecialchars($transaction['capital_number']); ?></strong>
                </p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $transaction_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="delete.php?id=<?php echo $transaction_id; ?>" 
                   class="btn btn-delete" 
                   onclick="return confirmDelete('<?php echo addslashes($transaction['capital_number']); ?>')">
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
        HERO CARD - AMOUNT
        ============================================================ -->
        <div class="hero-card hero-<?php echo $type_info['color']; ?>">
            <div class="hero-icon">
                <i class="fas <?php echo $type_info['icon']; ?>"></i>
            </div>
            <div class="hero-content">
                <span class="hero-label">
                    <?php echo $is_outgoing ? 'Total Out' : 'Total In'; ?>
                </span>
                <span class="hero-amount">
                    <?php echo $is_outgoing ? '-' : '+'; ?>
                    <?php echo formatCurrency($transaction['amount']); ?>
                </span>
                <span class="hero-type">
                    <span class="hero-type-badge type-<?php echo $type_info['color']; ?>">
                        <i class="fas <?php echo $type_info['icon']; ?>"></i>
                        <?php echo $type_info['label']; ?>
                    </span>
                </span>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <span class="hmi-label">Date</span>
                    <span class="hmi-value">
                        <i class="fas fa-calendar"></i>
                        <?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?>
                    </span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Reference</span>
                    <span class="hmi-value hmi-code">
                        <?php echo htmlspecialchars($transaction['capital_number']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS
        ============================================================ -->
        <div class="summary-grid">
            <!-- TRANSACTION TYPE -->
            <div class="summary-card">
                <div class="sc-icon sc-icon-<?php echo $type_info['color']; ?>">
                    <i class="fas <?php echo $type_info['icon']; ?>"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Transaction Type</span>
                    <span class="sc-value"><?php echo $type_info['label']; ?></span>
                </div>
            </div>
            
            <!-- SOURCE -->
            <div class="summary-card">
                <div class="sc-icon sc-icon-<?php echo $is_float ? 'purple' : 'teal'; ?>">
                    <i class="fas <?php echo $is_float ? 'fa-university' : 'fa-money-bill-wave'; ?>"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Source</span>
                    <span class="sc-value">
                        <?php echo $is_float ? htmlspecialchars($transaction['provider_name']) : 'Branch Cash'; ?>
                    </span>
                </div>
            </div>
            
            <!-- DATE -->
            <div class="summary-card">
                <div class="sc-icon sc-icon-blue">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Transaction Date</span>
                    <span class="sc-value"><?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?></span>
                </div>
            </div>
            
            <!-- EMPLOYEE -->
            <div class="summary-card">
                <div class="sc-icon sc-icon-orange">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Created By</span>
                    <span class="sc-value"><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        MAIN DETAILS CARD
        ============================================================ -->
        <div class="details-card">
            
            <!-- Transaction Information -->
            <div class="section-header">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    Transaction Information
                </h3>
            </div>
            
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-hashtag"></i> Reference Number
                    </span>
                    <span class="detail-value detail-code">
                        <?php echo htmlspecialchars($transaction['capital_number']); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar"></i> Transaction Date
                    </span>
                    <span class="detail-value">
                        <?php echo date('l, d M Y', strtotime($transaction['transaction_date'])); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-tag"></i> Transaction Type
                    </span>
                    <span class="detail-value">
                        <span class="type-badge-inline type-<?php echo $type_info['color']; ?>">
                            <i class="fas <?php echo $type_info['icon']; ?>"></i>
                            <?php echo $type_info['label']; ?>
                        </span>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-arrow-<?php echo $is_outgoing ? 'up' : 'down'; ?>"></i> Direction
                    </span>
                    <span class="detail-value <?php echo $is_outgoing ? 'text-danger' : 'text-success'; ?>">
                        <strong><?php echo $is_outgoing ? 'OUTGOING' : 'INCOMING'; ?></strong>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-store-alt"></i> Branch
                    </span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($transaction['branch_name'] ?? 'N/A'); ?>
                        <?php if (!empty($transaction['branch_code'])): ?>
                            <span class="code-pill"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-database"></i> Reference Module
                    </span>
                    <span class="detail-value">
                        <?php 
                        if ($is_float) {
                            echo 'Provider Float';
                        } elseif ($transaction['reference_module'] === 'cash') {
                            echo 'Branch Cash';
                        } else {
                            echo htmlspecialchars(ucfirst($transaction['reference_module'] ?? 'Manual'));
                        }
                        ?>
                    </span>
                </div>
            </div>
            
            <!-- ============================================================
            PROVIDER INFO (if applicable)
            ============================================================ -->
            <?php if ($is_float): ?>
            <div class="section-header">
                <h3>
                    <i class="fas fa-university"></i>
                    Provider Information
                </h3>
            </div>
            
            <div class="provider-card">
                <div class="provider-icon-lg" style="background: <?php echo htmlspecialchars($transaction['provider_color'] ?? '#0B5ED7'); ?>;">
                    <i class="<?php echo htmlspecialchars($transaction['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                </div>
                <div class="provider-info-lg">
                    <span class="provider-name-lg"><?php echo htmlspecialchars($transaction['provider_name']); ?></span>
                    <div class="provider-meta-lg">
                        <span class="code-pill">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($transaction['branch_provider_code'] ?? $transaction['main_provider_code'] ?? 'N/A'); ?>
                        </span>
                        <?php if (!empty($transaction['provider_type'])): ?>
                            <span class="type-pill">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $transaction['provider_type']))); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================================
            AMOUNT BREAKDOWN
            ============================================================ -->
            <div class="section-header">
                <h3>
                    <i class="fas fa-calculator"></i>
                    Amount Details
                </h3>
            </div>
            
            <div class="amount-breakdown">
                <div class="amount-row <?php echo $is_outgoing ? 'amount-out' : 'amount-in'; ?>">
                    <div class="amount-label">
                        <i class="fas fa-arrow-<?php echo $is_outgoing ? 'up' : 'down'; ?>"></i>
                        <span><?php echo $type_info['label']; ?> Amount</span>
                    </div>
                    <div class="amount-value">
                        <?php echo $is_outgoing ? '-' : '+'; ?>
                        <?php echo formatCurrency($transaction['amount']); ?>
                    </div>
                </div>
                
                <?php if (!empty($transaction['previous_balance']) && !empty($transaction['new_balance'])): ?>
                <div class="amount-row">
                    <div class="amount-label">
                        <i class="fas fa-history"></i>
                        <span>Previous Balance</span>
                    </div>
                    <div class="amount-value">
                        <?php echo formatCurrency($transaction['previous_balance']); ?>
                    </div>
                </div>
                
                <div class="amount-row amount-total">
                    <div class="amount-label">
                        <i class="fas fa-wallet"></i>
                        <span>New Balance</span>
                    </div>
                    <div class="amount-value">
                        <?php echo formatCurrency($transaction['new_balance']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ============================================================
            DESCRIPTION & NOTES
            ============================================================ -->
            <?php if (!empty($transaction['description']) || !empty($transaction['notes'])): ?>
            <div class="section-header">
                <h3>
                    <i class="fas fa-sticky-note"></i>
                    Description & Notes
                </h3>
            </div>
            
            <div class="notes-section">
                <?php if (!empty($transaction['description'])): ?>
                <div class="note-block">
                    <div class="note-label">
                        <i class="fas fa-align-left"></i>
                        Description
                    </div>
                    <div class="note-content">
                        <?php echo nl2br(htmlspecialchars($transaction['description'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($transaction['notes'])): ?>
                <div class="note-block">
                    <div class="note-label">
                        <i class="fas fa-comment-alt"></i>
                        Notes
                    </div>
                    <div class="note-content">
                        <?php echo nl2br(htmlspecialchars($transaction['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- ============================================================
            AUDIT INFORMATION
            ============================================================ -->
            <div class="section-header">
                <h3>
                    <i class="fas fa-clipboard-check"></i>
                    Audit Information
                </h3>
            </div>
            
            <div class="audit-grid">
                <div class="audit-item">
                    <div class="audit-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="audit-content">
                        <span class="audit-label">Created By</span>
                        <span class="audit-value"><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></span>
                        <?php if (!empty($transaction['employee_code'])): ?>
                            <span class="audit-sub"><?php echo htmlspecialchars($transaction['employee_code']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="audit-item">
                    <div class="audit-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="audit-content">
                        <span class="audit-label">Created At</span>
                        <span class="audit-value">
                            <?php echo date('d M Y', strtotime($transaction['created_at'])); ?>
                        </span>
                        <span class="audit-sub"><?php echo date('h:i A', strtotime($transaction['created_at'])); ?></span>
                    </div>
                </div>
                
                <?php if (!empty($transaction['updated_at'])): ?>
                <div class="audit-item">
                    <div class="audit-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div class="audit-content">
                        <span class="audit-label">Last Updated</span>
                        <span class="audit-value">
                            <?php echo date('d M Y', strtotime($transaction['updated_at'])); ?>
                        </span>
                        <span class="audit-sub"><?php echo date('h:i A', strtotime($transaction['updated_at'])); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        </div>

        <!-- ============================================================
        BOTTOM ACTIONS
        ============================================================ -->
        <div class="bottom-actions">
            <a href="index.php?branch=<?php echo $transaction['branch_id']; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="edit.php?id=<?php echo $transaction_id; ?>" class="btn btn-edit">
                <i class="fas fa-edit"></i> Edit Transaction
            </a>
            <a href="delete.php?id=<?php echo $transaction_id; ?>" 
               class="btn btn-delete" 
               onclick="return confirmDelete('<?php echo addslashes($transaction['capital_number']); ?>')">
                <i class="fas fa-trash"></i> Delete
            </a>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --cv-bg: #F3F4F6;
    --cv-text: #1F2937;
    --cv-text-secondary: #6B7280;
    --cv-text-light: #9CA3AF;
    --cv-border: #E5E7EB;
    --cv-card-bg: #FFFFFF;
    --cv-card-header: #FAFBFC;
    --cv-input-bg: #F9FAFB;
    --cv-hover: #F3F4F6;
    --cv-shadow: rgba(0,0,0,0.06);
    --cv-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --cv-bg: #0F172A;
    --cv-text: #F9FAFB;
    --cv-text-secondary: #9CA3AF;
    --cv-text-light: #6B7280;
    --cv-border: #334155;
    --cv-card-bg: #1E293B;
    --cv-card-header: #1E293B;
    --cv-input-bg: #334155;
    --cv-hover: #334155;
    --cv-shadow: rgba(0,0,0,0.3);
    --cv-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--cv-bg) !important;
    color: var(--cv-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--cv-bg) !important; overflow-x: hidden !important; }
.main-content { 
    background: var(--cv-bg) !important; 
    overflow-x: hidden !important; 
    max-width: 100% !important; 
    padding: 16px 20px !important; 
}

/* ============================================================
   BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
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
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    letter-spacing: 0.8px;
    font-family: 'Courier New', monospace;
}
.branch-status-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
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
    color: var(--cv-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted {
    font-size: 13px;
    color: var(--cv-text-secondary);
    margin: 4px 0 0 0;
}
.header-left .text-muted strong {
    color: #DC2626;
    font-family: 'Courier New', monospace;
    font-weight: 800;
}
.header-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

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
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-edit { background: #F59E0B; color: white; }
.btn-edit:hover { background: #D97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); color: white; }
.btn-delete { background: #DC2626; color: white; }
.btn-delete:hover { background: #B91C1C; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4); color: white; }
.btn-secondary {
    background: var(--cv-card-bg);
    color: var(--cv-text-secondary);
    border: 1px solid var(--cv-border);
}
.btn-secondary:hover { background: var(--cv-hover); color: var(--cv-text); }

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
    animation: slideDown 0.4s ease forwards;
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
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

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
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    position: relative;
    z-index: 1;
    backdrop-filter: blur(8px);
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
    line-height: 1.1;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    word-break: break-all;
}
.hero-type {
    margin-top: 4px;
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
    backdrop-filter: blur(8px);
}
.hero-meta {
    display: flex;
    gap: 20px;
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
    backdrop-filter: blur(8px);
    min-width: 140px;
}
.hmi-label {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.hmi-value {
    font-size: 14px;
    font-weight: 700;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.hmi-value i { font-size: 12px; color: #FCD34D; }
.hmi-code {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    letter-spacing: 0.5px;
}

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
    background: var(--cv-card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--cv-border);
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--cv-shadow);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.summary-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    background: #3B82F6;
}
.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--cv-shadow-md);
}
.sc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.sc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.sc-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.sc-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }
.sc-icon-red { background: linear-gradient(135deg, #DC2626, #B91C1C); color: #FFFFFF; }
.sc-icon-orange { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFFFFF; }
.sc-icon-teal { background: linear-gradient(135deg, #14B8A6, #0D9488); color: #FFFFFF; }
.sc-icon-gray { background: linear-gradient(135deg, #6B7280, #4B5563); color: #FFFFFF; }

.sc-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
    min-width: 0;
}
.sc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--cv-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sc-value {
    font-size: 14px;
    font-weight: 800;
    color: var(--cv-text);
    word-break: break-word;
    line-height: 1.2;
}

/* ============================================================
   DETAILS CARD
   ============================================================ */
.details-card {
    background: var(--cv-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--cv-border);
    box-shadow: 0 2px 8px var(--cv-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.section-header {
    padding: 16px 24px;
    background: var(--cv-card-header);
    border-bottom: 1px solid var(--cv-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--cv-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i {
    color: #DC2626;
    font-size: 15px;
}

/* ============================================================
   DETAILS GRID
   ============================================================ */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
    padding: 0;
}
.detail-item {
    padding: 16px 24px;
    border-bottom: 1px solid var(--cv-border);
    border-right: 1px solid var(--cv-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.detail-item:nth-child(2n) { border-right: none; }
.detail-item:nth-last-child(-n+2) { border-bottom: none; }

.detail-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--cv-text-light);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.detail-label i {
    color: #3B82F6;
    font-size: 12px;
}
.detail-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--cv-text);
    word-break: break-word;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.detail-code {
    font-family: 'Courier New', monospace;
    color: #DC2626;
    font-size: 14px;
    letter-spacing: 0.5px;
}
.code-pill {
    font-size: 11px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 3px 10px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}
html.dark-mode .code-pill { background: #1E3A5F; color: #60A5FA; }
.type-pill {
    font-size: 11px;
    font-weight: 700;
    color: #7C3AED;
    background: #EDE9FE;
    padding: 3px 10px;
    border-radius: 8px;
}
html.dark-mode .type-pill { background: #4C1D95; color: #C4B5FD; }

.type-badge-inline {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.type-badge-inline.type-blue { background: #DBEAFE; color: #1D4ED8; }
.type-badge-inline.type-green { background: #D1FAE5; color: #065F46; }
.type-badge-inline.type-purple { background: #EDE9FE; color: #6D28D9; }
.type-badge-inline.type-red { background: #FEE2E2; color: #991B1B; }
.type-badge-inline.type-orange { background: #FEF3C7; color: #92400E; }
.type-badge-inline.type-gray { background: #F3F4F6; color: #374151; }
html.dark-mode .type-badge-inline.type-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .type-badge-inline.type-green { background: #065F46; color: #34D399; }
html.dark-mode .type-badge-inline.type-purple { background: #2D1B5F; color: #A78BFA; }
html.dark-mode .type-badge-inline.type-red { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .type-badge-inline.type-orange { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .type-badge-inline.type-gray { background: #374151; color: #9CA3AF; }

/* ============================================================
   PROVIDER CARD
   ============================================================ */
.provider-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-radius: 12px;
    margin: 20px 24px;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .provider-card {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.provider-icon-lg {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}
.provider-info-lg {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.provider-name-lg {
    font-size: 20px;
    font-weight: 800;
    color: var(--cv-text);
    letter-spacing: 0.3px;
}
.provider-meta-lg {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* ============================================================
   AMOUNT BREAKDOWN
   ============================================================ */
.amount-breakdown {
    margin: 20px 24px;
    background: var(--cv-hover);
    border-radius: 12px;
    border: 1.5px solid var(--cv-border);
    overflow: hidden;
}
.amount-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    gap: 16px;
    border-bottom: 1px solid var(--cv-border);
    flex-wrap: wrap;
}
.amount-row:last-child { border-bottom: none; }
.amount-row.amount-in {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.02));
}
.amount-row.amount-out {
    background: linear-gradient(135deg, rgba(220, 38, 38, 0.08), rgba(220, 38, 38, 0.02));
}
.amount-row.amount-total {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(59, 130, 246, 0.02));
    border-top: 2px solid #3B82F6;
}
.amount-label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.amount-label i {
    font-size: 14px;
    color: #3B82F6;
    width: 20px;
    text-align: center;
}
.amount-row.amount-in .amount-label i { color: #10B981; }
.amount-row.amount-out .amount-label i { color: #DC2626; }
.amount-row.amount-total .amount-label i { color: #3B82F6; }
.amount-value {
    font-size: clamp(16px, 1.5vw, 22px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}
.amount-row.amount-in .amount-value { color: #059669; }
.amount-row.amount-out .amount-value { color: #DC2626; }
.amount-row.amount-total .amount-value { color: #1D4ED8; }
html.dark-mode .amount-row.amount-in .amount-value { color: #34D399; }
html.dark-mode .amount-row.amount-out .amount-value { color: #FCA5A5; }
html.dark-mode .amount-row.amount-total .amount-value { color: #60A5FA; }

/* ============================================================
   NOTES SECTION
   ============================================================ */
.notes-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px 24px;
}
.note-block {
    background: var(--cv-hover);
    border-radius: 10px;
    border-left: 4px solid #3B82F6;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.note-label {
    font-size: 11px;
    font-weight: 800;
    color: #3B82F6;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.note-content {
    font-size: 14px;
    color: var(--cv-text);
    line-height: 1.6;
    word-break: break-word;
    font-weight: 500;
}

/* ============================================================
   AUDIT GRID
   ============================================================ */
.audit-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    padding: 0;
}
.audit-item {
    padding: 20px 24px;
    border-right: 1px solid var(--cv-border);
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
    color: var(--cv-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.audit-value {
    font-size: 14px;
    font-weight: 800;
    color: var(--cv-text);
    word-break: break-word;
}
.audit-sub {
    font-size: 11px;
    font-weight: 600;
    color: var(--cv-text-secondary);
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
    background: var(--cv-card-bg);
    border-radius: 12px;
    border: 1.5px solid var(--cv-border);
    box-shadow: 0 2px 8px var(--cv-shadow);
}
.bottom-actions .btn {
    padding: 12px 24px;
    font-size: 14px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .audit-grid { grid-template-columns: 1fr; }
    .audit-item { border-right: none; border-bottom: 1px solid var(--cv-border); }
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
    .hero-meta-item { align-items: center; text-align: center; }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 18px;
    }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .hero-card { padding: 20px 22px; }
    .hero-icon { width: 64px; height: 64px; font-size: 28px; }
    .hero-amount { font-size: clamp(24px, 7vw, 34px); }
    .hero-meta { width: 100%; }
    .hero-meta-item { flex: 1; min-width: 0; }
    
    .summary-grid { grid-template-columns: 1fr; }
    .sc-value { font-size: 13px; }
    
    .details-grid { grid-template-columns: 1fr; }
    .detail-item { border-right: none !important; border-bottom: 1px solid var(--cv-border); }
    .detail-item:last-child { border-bottom: none; }
    
    .provider-card { flex-direction: column; text-align: center; padding: 18px; }
    .provider-meta-lg { justify-content: center; }
    
    .amount-row { flex-direction: column; align-items: flex-start; gap: 8px; }
    .amount-value { width: 100%; text-align: right; }
    
    .audit-item { flex-direction: column; align-items: flex-start; }
    
    .bottom-actions { flex-direction: column; }
    .bottom-actions .btn { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .header-left h2 { font-size: 18px; }
    .hero-icon { width: 56px; height: 56px; font-size: 24px; }
    .hero-meta-item { padding: 10px 12px; }
    .hmi-value { font-size: 12px; }
    .section-header h3 { font-size: 13px; }
    .detail-value { font-size: 14px; }
    .provider-name-lg { font-size: 16px; }
    .amount-value { font-size: 16px; }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(reference) {
    return confirm(
        'Are you sure you want to DELETE this capital transaction?\n\n' +
        'Reference: ' + reference + '\n\n' +
        'WARNING: This will reverse the capital entry from daily reports.\n' +
        'The branch capital will be adjusted by this amount.\n\n' +
        'This action cannot be undone.'
    );
}

// ============================================================
// DARK MODE
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
    
    // Auto-hide alerts
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
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.transition = 'opacity 0.4s ease';
            errorAlert.style.opacity = '0';
            setTimeout(function() {
                if (errorAlert.parentElement) errorAlert.remove();
            }, 400);
        }, 8000);
    }
});
</script>

</body>
</html>