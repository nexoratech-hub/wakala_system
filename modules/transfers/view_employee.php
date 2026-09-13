<?php
// ================================================================
// FILE: modules/transfers/view_employee.php
// WAKALA FINANCIAL SYSTEM - VIEW TRANSFER (Employee)
// Print button → redirects to receipt page
// NO BRANCH SELECTOR IN TOPBAR
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

// ============================================================
// GET TRANSFER ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid transfer.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET EMPLOYEE BRANCH
// ============================================================
$stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp || $emp['branch_id'] <= 0) {
    $_SESSION['error_message'] = 'You are not assigned to any branch.';
    header('Location: ../dashboard/employee.php');
    exit();
}

$employee_branch_id = intval($emp['branch_id']);

// ============================================================
// GET TRANSFER DETAILS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        t.*,
        p.icon_class,
        p.color_code,
        p.provider_type,
        p.provider_name as provider_full_name,
        e.full_name as employee_name,
        e.employee_id as employee_code,
        e.email as employee_email,
        e.phone as employee_phone,
        e.profile_pic as employee_avatar,
        e.role as employee_role,
        b.branch_name as branch_display_name,
        b.branch_code as branch_display_code,
        b.location as branch_location,
        b.phone as branch_phone,
        b.email as branch_email
    FROM transfers t
    LEFT JOIN providers p ON t.provider_id = p.id
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN branches b ON t.branch_id = b.id
    WHERE t.id = ? AND t.branch_id = ?
");
$stmt->execute([$id, $employee_branch_id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    $_SESSION['error_message'] = 'Transfer not found or you do not have permission to view it.';
    header('Location: index_employee.php');
    exit();
}

$is_cash_to_float = $transfer['transfer_type'] === 'cash_to_float';
$type_label = $is_cash_to_float ? 'Cash → Float' : 'Float → Cash';
$type_class = $is_cash_to_float ? 'type-cash-to-float' : 'type-float-to-cash';
$type_desc = $is_cash_to_float 
    ? 'Money moved from Cash into Provider Float' 
    : 'Money moved from Provider Float into Cash';

$employee_avatar = $transfer['employee_avatar'] ?? '';
$employee_initial = strtoupper(substr($transfer['employee_name'] ?? 'N', 0, 1));

$float_before = floatval($transfer['before_float']);
$float_after = floatval($transfer['after_float']);
$float_change = $float_after - $float_before;

$cash_before = floatval($transfer['before_cash']);
$cash_after = floatval($transfer['after_cash']);
$cash_change = $cash_after - $cash_before;

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
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
     HIDE BRANCH SELECTOR FROM TOPBAR (EMPLOYEE VIEW)
     ============================================================ -->
<style id="employee-topbar-fix">
    .topbar .branch-selector,
    .topbar .topbar-branch,
    .topbar .branch-dropdown,
    .topbar .topbar-branch-selector,
    .topbar .branch-switcher,
    .topbar .branch-select-wrapper,
    .topbar .header-branch-selector,
    .topbar .branch-filter,
    .topbar .branch-filter-wrapper,
    .topbar .branch-picker,
    .topbar .navbar-branch,
    .topbar .branch-select,
    .topbar .branch-choice,
    .topbar .topbar-branch-wrapper,
    .topbar select[name="branch_id"],
    .topbar select[name="branch"],
    .topbar #branchSelector,
    .topbar #branchSwitcher,
    .topbar .topbar-right .branch-selector,
    .topbar .topbar-right .branch-dropdown,
    .topbar .topbar-right select[name="branch_id"],
    header.topbar .branch-selector,
    header.topbar .branch-dropdown,
    header.topbar select[name="branch_id"],
    .admin-topbar .branch-selector,
    .admin-topbar .topbar-branch,
    .admin-topbar .branch-dropdown,
    .admin-topbar .branch-switcher,
    .admin-topbar select[name="branch_id"],
    nav.topbar .branch-selector,
    nav.topbar .branch-dropdown,
    .main-header .branch-selector,
    .main-header .branch-dropdown,
    .dashboard-header .branch-selector,
    .header .branch-selector {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        height: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name">
                        <?php echo htmlspecialchars($transfer['branch_display_name'] ?? 'N/A'); ?>
                    </span>
                    <?php if (!empty($transfer['branch_display_code'])): ?>
                        <span class="branch-indicator-code">
                            <?php echo htmlspecialchars($transfer['branch_display_code']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($transfer['branch_location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($transfer['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <a href="index_employee.php" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Transfers</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas fa-exchange-alt" style="color:#7C3AED;"></i>
                    Transfer Details
                </h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                </p>
            </div>
        </div>

        <!-- ALERTS -->
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

        <!-- MAIN TRANSFER CARD -->
        <div class="transfer-main-card <?php echo $type_class; ?>">
            <div class="tmc-icon">
                <i class="fas fa-arrow-<?php echo $is_cash_to_float ? 'right' : 'left'; ?>"></i>
            </div>
            <div class="tmc-content">
                <div class="tmc-label"><?php echo $type_label; ?></div>
                <div class="tmc-amount"><?php echo formatCurrency($transfer['amount']); ?></div>
                <div class="tmc-desc"><?php echo $type_desc; ?></div>
            </div>
            <div class="tmc-badge">
                <span class="tmc-status">
                    <i class="fas fa-check-circle"></i>
                    <?php echo ucfirst($transfer['status'] ?? 'Completed'); ?>
                </span>
            </div>
        </div>

        <!-- DETAILS GRID -->
        <div class="details-grid">
            
            <!-- TRANSFER INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon" style="background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h3>Transfer Information</h3>
                            <p>Basic transfer details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-hashtag"></i> Transfer Number</span>
                        <span class="info-value">
                            <span class="transfer-number-badge"><?php echo htmlspecialchars($transfer['transfer_number']); ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-exchange-alt"></i> Transfer Type</span>
                        <span class="info-value">
                            <span class="type-badge-detail <?php echo $type_class; ?>">
                                <i class="fas fa-arrow-<?php echo $is_cash_to_float ? 'right' : 'left'; ?>"></i>
                                <?php echo $type_label; ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-calendar"></i> Transfer Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($transfer['transfer_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-clock"></i> Transfer Time</span>
                        <span class="info-value"><?php echo date('H:i:s', strtotime($transfer['transfer_time'] ?? $transfer['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar-plus"></i> Created</span>
                        <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($transfer['created_at'])); ?></span>
                    </div>
                    <?php if (!empty($transfer['reference_number'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-tag"></i> Reference</span>
                        <span class="info-value">
                            <span class="reference-badge"><?php echo htmlspecialchars($transfer['reference_number']); ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-toggle-on"></i> Status</span>
                        <span class="info-value">
                            <span class="status-badge-detail status-<?php echo $transfer['status'] ?? 'completed'; ?>">
                                <i class="fas fa-check-circle"></i>
                                <?php echo ucfirst($transfer['status'] ?? 'Completed'); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- PROVIDER INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon" style="background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);">
                            <i class="fas fa-university"></i>
                        </div>
                        <div>
                            <h3>Provider Information</h3>
                            <p>Provider involved in transfer</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="provider-display-box">
                        <div class="provider-display-icon" style="background: <?php echo $transfer['color_code'] ?? '#0B5ED7'; ?>;">
                            <i class="<?php echo $transfer['icon_class'] ?? 'fas fa-university'; ?>"></i>
                        </div>
                        <div class="provider-display-info">
                            <span class="provider-display-name"><?php echo htmlspecialchars($transfer['provider_name'] ?? 'N/A'); ?></span>
                            <span class="provider-display-code"><?php echo htmlspecialchars($transfer['provider_code'] ?? '-'); ?></span>
                            <span class="provider-display-type">
                                <i class="fas fa-<?php echo ($transfer['provider_type'] ?? 'bank') == 'mobile_money' ? 'mobile-alt' : 'university'; ?>"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $transfer['provider_type'] ?? 'Bank')); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="float-detail-box">
                        <div class="float-detail-header"><i class="fas fa-arrow-up"></i> Float BEFORE Transfer</div>
                        <div class="float-detail-value float-before-value"><?php echo formatCurrency($float_before); ?></div>
                    </div>
                    
                    <div class="float-change-arrow">
                        <i class="fas fa-arrow-down"></i>
                        <span class="float-change-amount <?php echo $float_change >= 0 ? 'change-positive' : 'change-negative'; ?>">
                            <?php echo $float_change >= 0 ? '+' : ''; ?>
                            <?php echo formatCurrency(abs($float_change)); ?>
                        </span>
                    </div>
                    
                    <div class="float-detail-box">
                        <div class="float-detail-header"><i class="fas fa-arrow-down"></i> Float AFTER Transfer</div>
                        <div class="float-detail-value float-after-value"><?php echo formatCurrency($float_after); ?></div>
                    </div>
                </div>
            </div>

            <!-- EMPLOYEE INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon" style="background: linear-gradient(135deg, #059669 0%, #10B981 100%);">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div>
                            <h3>Employee Information</h3>
                            <p>Who performed this transfer</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="employee-display-box">
                        <?php if ($employee_avatar && file_exists('../../' . $employee_avatar)): ?>
                            <img src="../../<?php echo htmlspecialchars($employee_avatar); ?>" 
                                 alt="<?php echo htmlspecialchars($transfer['employee_name']); ?>"
                                 class="employee-display-img"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="employee-display-avatar" style="display:none;"><?php echo $employee_initial; ?></div>
                        <?php else: ?>
                            <div class="employee-display-avatar"><?php echo $employee_initial; ?></div>
                        <?php endif; ?>
                        <div class="employee-display-info">
                            <span class="employee-display-name"><?php echo htmlspecialchars($transfer['employee_name'] ?? 'N/A'); ?></span>
                            <span class="employee-display-code"><?php echo htmlspecialchars($transfer['employee_code'] ?? '-'); ?></span>
                            <span class="employee-display-role">
                                <i class="fas fa-user-shield"></i>
                                <?php echo ucfirst($transfer['employee_role'] ?? 'Employee'); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if (!empty($transfer['employee_email'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($transfer['employee_email']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($transfer['employee_phone'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($transfer['employee_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FINANCIAL SUMMARY -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon" style="background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%);">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div>
                            <h3>Financial Summary</h3>
                            <p>Financial impact of transfer</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="financial-grid">
                        <div class="financial-item">
                            <div class="financial-item-icon financial-icon-float"><i class="fas fa-coins"></i></div>
                            <div class="financial-item-info">
                                <span class="financial-item-label">Float Change</span>
                                <span class="financial-item-value <?php echo $float_change >= 0 ? 'value-positive' : 'value-negative'; ?>">
                                    <?php echo $float_change >= 0 ? '+' : ''; ?>
                                    <?php echo formatCurrency(abs($float_change)); ?>
                                </span>
                                <span class="financial-item-sub"><?php echo $float_change >= 0 ? 'Increased' : 'Decreased'; ?></span>
                            </div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-item-icon financial-icon-cash"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="financial-item-info">
                                <span class="financial-item-label">Cash Change</span>
                                <span class="financial-item-value <?php echo $cash_change >= 0 ? 'value-positive' : 'value-negative'; ?>">
                                    <?php echo $cash_change >= 0 ? '+' : ''; ?>
                                    <?php echo formatCurrency(abs($cash_change)); ?>
                                </span>
                                <span class="financial-item-sub"><?php echo $cash_change >= 0 ? 'Increased' : 'Decreased'; ?></span>
                            </div>
                        </div>
                        <div class="financial-item financial-item-highlight">
                            <div class="financial-item-icon financial-icon-amount"><i class="fas fa-exchange-alt"></i></div>
                            <div class="financial-item-info">
                                <span class="financial-item-label">Transfer Amount</span>
                                <span class="financial-item-value value-amount"><?php echo formatCurrency($transfer['amount']); ?></span>
                                <span class="financial-item-sub">Total transferred</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="cash-before-after">
                        <div class="cba-item">
                            <span class="cba-label">Cash Before</span>
                            <span class="cba-value"><?php echo formatCurrency($cash_before); ?></span>
                        </div>
                        <div class="cba-arrow"><i class="fas fa-arrow-right"></i></div>
                        <div class="cba-item">
                            <span class="cba-label">Cash After</span>
                            <span class="cba-value cba-value-after"><?php echo formatCurrency($cash_after); ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- DESCRIPTION & NOTES -->
        <?php if (!empty($transfer['description']) || !empty($transfer['notes'])): ?>
        <div class="description-card">
            <div class="description-card-header">
                <i class="fas fa-sticky-note"></i>
                <h3>Description & Notes</h3>
            </div>
            <div class="description-card-body">
                <?php if (!empty($transfer['description'])): ?>
                    <div class="description-item">
                        <span class="description-label"><i class="fas fa-align-left"></i> Description</span>
                        <p class="description-text"><?php echo nl2br(htmlspecialchars($transfer['description'])); ?></p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($transfer['notes'])): ?>
                    <div class="description-item">
                        <span class="description-label"><i class="fas fa-comment-alt"></i> Notes</span>
                        <p class="description-text"><?php echo nl2br(htmlspecialchars($transfer['notes'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACTION BUTTONS -->
        <div class="actions-card">
            <a href="index_employee.php" class="btn btn-back-large">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Transfers</span>
            </a>
            
            <a href="receipt_employee.php?id=<?php echo $id; ?>" class="btn btn-print-large">
                <i class="fas fa-print"></i>
                <span>Print Receipt</span>
            </a>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important; padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}

html.dark-mode {
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

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper { background: var(--bg-body) !important; }
.main-content { background: var(--bg-body) !important; }

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px; background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.75;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 700; font-size: 16px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;
}
.branch-indicator-code {
    font-size: 11px; font-weight: 600; color: #FFFFFF;
    padding: 3px 12px; background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 12px;
    color: rgba(255,255,255,0.85); padding: 4px 12px;
    background: rgba(255, 255, 255, 0.08); border-radius: 12px; white-space: nowrap;
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255, 255, 255, 0.12);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.22); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace; font-weight: 600;
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close {
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* MAIN TRANSFER CARD */
.transfer-main-card {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 28px 32px;
    border-radius: 16px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    color: #FFFFFF;
}
.transfer-main-card.type-cash-to-float {
    background: linear-gradient(135deg, #059669 0%, #10B981 50%, #34D399 100%);
}
.transfer-main-card.type-float-to-cash {
    background: linear-gradient(135deg, #D97706 0%, #F59E0B 50%, #FBBF24 100%);
}
.transfer-main-card::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%; pointer-events: none;
}
.transfer-main-card::after {
    content: ''; position: absolute;
    bottom: -60%; left: 20%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.tmc-icon {
    width: 88px; height: 88px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #FFFFFF; flex-shrink: 0;
    backdrop-filter: blur(8px);
    border: 2px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
}
.tmc-content {
    flex: 1;
    display: flex; flex-direction: column; gap: 6px;
    min-width: 0; position: relative; z-index: 1;
}
.tmc-label {
    font-size: 13px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.9);
}
.tmc-amount {
    font-size: clamp(28px, 3vw, 44px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px; line-height: 1.1;
    color: #FFFFFF; word-break: break-all;
    overflow-wrap: anywhere;
    text-shadow: 0 3px 12px rgba(0, 0, 0, 0.2);
}
.tmc-desc {
    font-size: 13px; font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
}
.tmc-badge { position: relative; z-index: 1; flex-shrink: 0; }
.tmc-status {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 20px;
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1px;
    border: 1.5px solid rgba(255, 255, 255, 0.35);
    backdrop-filter: blur(8px);
}

/* DETAILS GRID */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px; margin-bottom: 16px;
}
.detail-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; min-width: 0;
}
.detail-card:hover {
    box-shadow: 0 8px 24px var(--shadow-hover);
    transform: translateY(-2px);
}
.detail-card-header {
    padding: 16px 20px;
    background: var(--bg-table-even);
    border-bottom: 1.5px solid var(--border-color);
}
.detail-card-header-left { display: flex; align-items: center; gap: 14px; }
.detail-card-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.detail-card-header h3 {
    font-size: 15px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 2px 0;
}
.detail-card-header p {
    font-size: 11px; color: var(--text-muted);
    margin: 0; font-weight: 500;
}
.detail-card-body { padding: 18px 20px; }

.info-row {
    display: flex; justify-content: space-between;
    align-items: center; padding: 11px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 12px; flex-wrap: wrap;
}
.info-row:last-child { border-bottom: none; }
.info-label {
    font-size: 12px; font-weight: 600;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.info-label i {
    color: #7C3AED; font-size: 12px;
    width: 14px; text-align: center;
}
.info-value {
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    text-align: right; word-break: break-word;
}
.transfer-number-badge {
    display: inline-block; padding: 4px 12px;
    background: #EDE9FE; color: #7C3AED;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px; font-weight: 700;
    letter-spacing: 0.5px;
}
html.dark-mode .transfer-number-badge { background: #4C1D95; color: #DDD6FE; }
.type-badge-detail {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
    white-space: nowrap;
}
.type-badge-detail.type-cash-to-float {
    background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0;
}
.type-badge-detail.type-float-to-cash {
    background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A;
}
html.dark-mode .type-badge-detail.type-cash-to-float {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .type-badge-detail.type-float-to-cash {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.reference-badge {
    display: inline-block; padding: 4px 12px;
    background: #DBEAFE; color: #1D4ED8;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px; font-weight: 700;
}
html.dark-mode .reference-badge { background: #1E3A5F; color: #60A5FA; }
.status-badge-detail {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.status-badge-detail.status-completed {
    background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0;
}
.status-badge-detail.status-cancelled {
    background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA;
}
.status-badge-detail.status-reversed {
    background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A;
}
html.dark-mode .status-badge-detail.status-completed {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .status-badge-detail.status-cancelled {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
html.dark-mode .status-badge-detail.status-reversed {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}

/* PROVIDER DISPLAY BOX */
.provider-display-box {
    display: flex; align-items: center; gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px; margin-bottom: 16px;
}
html.dark-mode .provider-display-box {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.provider-display-icon {
    width: 56px; height: 56px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 22px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.provider-display-info {
    display: flex; flex-direction: column; gap: 4px;
    min-width: 0; flex: 1;
}
.provider-display-name {
    font-size: 16px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.provider-display-code {
    display: inline-block; padding: 2px 10px;
    background: #1D4ED8; color: #FFFFFF;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 700;
    align-self: flex-start; letter-spacing: 0.5px;
}
.provider-display-type {
    font-size: 11px; color: var(--text-muted);
    font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.provider-display-type i { color: #2563EB; }

.float-detail-box {
    padding: 14px 16px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-input);
}
.float-detail-header {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.float-detail-header i { color: #3B82F6; font-size: 11px; }
.float-detail-value {
    font-size: clamp(16px, 1.5vw, 22px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; word-break: break-all;
    overflow-wrap: anywhere;
}
.float-before-value { color: #1D4ED8; }
.float-after-value { color: #059669; }
html.dark-mode .float-before-value { color: #60A5FA; }
html.dark-mode .float-after-value { color: #34D399; }
.float-change-arrow {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 10px 0; position: relative;
}
.float-change-arrow::before,
.float-change-arrow::after {
    content: ''; flex: 1; height: 2px;
    background: linear-gradient(90deg, transparent, #C4B5FD, transparent);
}
.float-change-arrow i { color: #7C3AED; font-size: 14px; }
.float-change-amount {
    font-size: 13px; font-weight: 800;
    padding: 4px 14px; border-radius: 20px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.change-positive { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
.change-negative { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .change-positive {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .change-negative {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* EMPLOYEE DISPLAY BOX */
.employee-display-box {
    display: flex; align-items: center; gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 2px solid #A7F3D0;
    border-radius: 12px; margin-bottom: 16px;
}
html.dark-mode .employee-display-box {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-color: #10B981;
}
.employee-display-img {
    width: 64px; height: 64px;
    border-radius: 50%; object-fit: cover;
    border: 3px solid #10B981;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.employee-display-avatar {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 24px;
    flex-shrink: 0;
    border: 3px solid #10B981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.employee-display-info {
    display: flex; flex-direction: column; gap: 4px;
    min-width: 0; flex: 1;
}
.employee-display-name {
    font-size: 16px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.employee-display-code {
    display: inline-block; padding: 2px 10px;
    background: #059669; color: #FFFFFF;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 700;
    align-self: flex-start; letter-spacing: 0.5px;
}
.employee-display-role {
    font-size: 11px; color: var(--text-muted);
    font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.employee-display-role i { color: #059669; }

/* FINANCIAL SUMMARY */
.financial-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 12px; margin-bottom: 16px;
}
.financial-item {
    display: flex; flex-direction: column;
    align-items: center; gap: 10px;
    padding: 16px 14px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 12px; text-align: center;
    transition: all 0.3s ease; min-width: 0;
}
.financial-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px var(--shadow-hover);
}
.financial-item-highlight {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border-color: #C4B5FD;
}
html.dark-mode .financial-item-highlight {
    background: linear-gradient(135deg, #4C1D95 0%, #6D28D9 100%);
    border-color: #8B5CF6;
}
.financial-item-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
}
.financial-icon-float {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #1D4ED8; border: 1.5px solid #93C5FD;
}
.financial-icon-cash {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669; border: 1.5px solid #6EE7B7;
}
.financial-icon-amount {
    background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%);
    color: #7C3AED; border: 1.5px solid #C4B5FD;
}
html.dark-mode .financial-icon-float {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .financial-icon-cash {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .financial-icon-amount {
    background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6;
}
.financial-item-info {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; width: 100%;
}
.financial-item-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.financial-item-value {
    font-size: clamp(14px, 1.2vw, 18px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2;
    word-break: break-all; overflow-wrap: anywhere;
}
.value-positive { color: #059669; }
.value-negative { color: #DC2626; }
.value-amount { color: #7C3AED; }
html.dark-mode .value-positive { color: #34D399; }
html.dark-mode .value-negative { color: #FCA5A5; }
html.dark-mode .value-amount { color: #DDD6FE; }
.financial-item-sub {
    font-size: 10px; font-weight: 600;
    color: var(--text-light);
}

.cash-before-after {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 14px 18px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
}
html.dark-mode .cash-before-after {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.cba-item {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1;
}
.cba-label {
    font-size: 10px; font-weight: 700;
    color: #1E40AF;
    text-transform: uppercase; letter-spacing: 0.8px;
}
html.dark-mode .cba-label { color: #93C5FD; }
.cba-value {
    font-size: clamp(13px, 1.1vw, 17px);
    font-weight: 900; color: #1D4ED8;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all; overflow-wrap: anywhere;
}
.cba-value-after { color: #059669; }
html.dark-mode .cba-value { color: #60A5FA; }
html.dark-mode .cba-value-after { color: #34D399; }
.cba-arrow {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #FFFFFF; color: #1D4ED8;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
html.dark-mode .cba-arrow { background: #0F172A; color: #60A5FA; }

/* DESCRIPTION CARD */
.description-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 16px;
}
.description-card-header {
    padding: 14px 20px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border-bottom: 1.5px solid #FCD34D;
    display: flex; align-items: center; gap: 10px;
}
html.dark-mode .description-card-header {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #D97706;
}
.description-card-header i {
    font-size: 18px; color: #D97706;
}
html.dark-mode .description-card-header i { color: #FBBF24; }
.description-card-header h3 {
    font-size: 15px; font-weight: 800;
    color: #78350F; margin: 0;
}
html.dark-mode .description-card-header h3 { color: #FDE68A; }
.description-card-body {
    padding: 18px 20px;
    display: flex; flex-direction: column; gap: 16px;
}
.description-item {
    display: flex; flex-direction: column; gap: 8px;
}
.description-label {
    font-size: 11px; font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 6px;
}
.description-label i { color: #D97706; font-size: 11px; }
.description-text {
    font-size: 14px; line-height: 1.7;
    color: var(--text-primary); margin: 0;
    padding: 14px 18px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    word-break: break-word;
}

/* ACTIONS CARD */
.actions-card {
    display: flex; gap: 12px;
    padding: 20px 24px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap;
}
.btn-back-large, .btn-print-large {
    padding: 12px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    display: inline-flex; align-items: center; gap: 8px;
    cursor: pointer; text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    flex: 1;
    justify-content: center;
    min-width: 160px;
    white-space: nowrap;
}
.btn-back-large {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-back-large:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
    transform: translateY(-2px);
}
.btn-print-large {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}
.btn-print-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.5);
    color: #FFFFFF;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .details-grid { grid-template-columns: 1fr; }
    .financial-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    
    .branch-indicator {
        flex-direction: column;
        align-items: flex-start;
        padding: 14px 16px;
    }
    .btn-back-card {
        width: 100%;
        justify-content: center;
    }
    
    .transfer-main-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 22px 20px;
    }
    .tmc-icon {
        width: 72px; height: 72px;
        font-size: 32px;
    }
    .tmc-amount { font-size: 28px; }
    .tmc-badge { width: 100%; }
    .tmc-status { width: 100%; justify-content: center; }
    
    .details-grid { grid-template-columns: 1fr; gap: 12px; }
    .financial-grid { grid-template-columns: 1fr; }
    
    .cash-before-after {
        flex-direction: column;
        gap: 8px;
    }
    .cba-arrow { transform: rotate(90deg); }
    .cba-item { width: 100%; text-align: center; }
    
    .provider-display-box,
    .employee-display-box {
        flex-direction: column;
        text-align: center;
    }
    .provider-display-code,
    .employee-display-code,
    .provider-display-type,
    .employee-display-role {
        align-self: center;
    }
    
    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    .info-value { text-align: left; width: 100%; }
    
    .actions-card { flex-direction: column; }
    .actions-card .btn-back-large,
    .actions-card .btn-print-large { width: 100%; }
}

@media (max-width: 480px) {
    .tmc-amount { font-size: 24px; }
    .tmc-icon { width: 64px; height: 64px; font-size: 26px; }
    .detail-card-header { padding: 14px 16px; }
    .detail-card-icon { width: 42px; height: 42px; font-size: 18px; }
    .detail-card-body { padding: 14px 16px; }
    .provider-display-icon { width: 48px; height: 48px; font-size: 20px; }
    .employee-display-img,
    .employee-display-avatar { width: 56px; height: 56px; font-size: 22px; }
    .financial-item { padding: 14px 12px; }
    .financial-item-icon { width: 40px; height: 40px; font-size: 16px; }
}
</style>

<!-- ============================================================
     EXTRA: HIDE BRANCH SELECTOR FROM TOPBAR (Backup)
     ============================================================ -->
<style id="employee-hide-branch-selector">
    .topbar .branch-selector,
    .topbar .topbar-branch,
    .topbar .branch-dropdown,
    .topbar .topbar-branch-selector,
    .topbar .branch-switcher,
    .topbar .branch-select-wrapper,
    .topbar .header-branch-selector,
    .topbar .branch-filter,
    .topbar .branch-filter-wrapper,
    .topbar .branch-picker,
    .topbar .navbar-branch,
    .topbar .branch-select,
    .topbar .branch-choice,
    .topbar .topbar-branch-wrapper,
    .topbar select[name="branch_id"],
    .topbar select[name="branch"],
    .topbar #branchSelector,
    .topbar #branchSwitcher,
    .topbar .topbar-right .branch-selector,
    .topbar .topbar-right .branch-dropdown,
    .topbar .topbar-right select[name="branch_id"],
    header.topbar .branch-selector,
    header.topbar .branch-dropdown,
    header.topbar select[name="branch_id"],
    .admin-topbar .branch-selector,
    .admin-topbar .topbar-branch,
    .admin-topbar .branch-dropdown,
    .admin-topbar .branch-switcher,
    .admin-topbar select[name="branch_id"],
    nav.topbar .branch-selector,
    nav.topbar .branch-dropdown,
    .main-header .branch-selector,
    .main-header .branch-dropdown,
    .dashboard-header .branch-selector,
    .header .branch-selector {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        height: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
</style>

<script>
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