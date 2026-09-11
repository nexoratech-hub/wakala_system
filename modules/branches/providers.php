<?php
// ================================================================
// FILE: modules/branches/providers.php
// WAKALA FINANCIAL SYSTEM - BRANCH PROVIDERS MANAGEMENT
// WITH FLOAT AVAILABLE DISPLAY
// FIXED: Uses 'branch_id' consistently with topbar
// ================================================================

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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================================
// GET BRANCH ID - USES branch_id FIRST (MATCHES TOPBAR)
// ============================================================
$branch_id = 0;

// Primary: branch_id
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $branch_id = intval($_GET['branch_id']);
}
// Fallback: branch (for backward compatibility)
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0' && intval($_GET['branch']) > 0) {
    $branch_id = intval($_GET['branch']);
}
// No branch selected - redirect to index
else {
    $_SESSION['error_message'] = 'No branch selected. Please select a branch first.';
    header('Location: index.php');
    exit();
}

// Validate
if ($branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch selected.';
    header('Location: index.php');
    exit();
}

// ============================================================
// NO SESSION FORCING - URL IS SOURCE OF TRUTH
// ============================================================
unset($_SESSION['selected_branch']);
unset($_SESSION['providers_branch_id']);

// ============================================================
// GET BRANCH DETAILS
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found or inactive.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH PROVIDERS WITH FLOAT AVAILABLE
// ============================================================
$sql = "SELECT 
            bp.id,
            bp.branch_id,
            bp.provider_id,
            bp.provider_code,
            bp.is_active,
            bp.created_at,
            p.provider_name,
            p.provider_code as main_code,
            p.provider_type,
            p.icon_class,
            p.color_code,
            COALESCE((
                SELECT SUM(mrp.float_balance) 
                FROM morning_reports mr
                JOIN morning_report_providers mrp ON mr.id = mrp.report_id
                WHERE mr.branch_id = bp.branch_id 
                AND mrp.provider_id = bp.provider_id
                AND mr.report_date = CURDATE()
            ), 0) as float_balance,
            COALESCE((
                SELECT SUM(amount) FROM transactions 
                WHERE branch_id = bp.branch_id 
                AND provider_id = bp.provider_id 
                AND transaction_type = 'deposit' 
                AND status = 'approved' 
                AND DATE(transaction_date) = CURDATE()
            ), 0) as today_deposits,
            COALESCE((
                SELECT SUM(amount) FROM transactions 
                WHERE branch_id = bp.branch_id 
                AND provider_id = bp.provider_id 
                AND transaction_type = 'withdrawal' 
                AND status = 'approved' 
                AND DATE(transaction_date) = CURDATE()
            ), 0) as today_withdrawals,
            COALESCE((
                SELECT SUM(cumm_total) FROM morning_reports 
                WHERE branch_id = bp.branch_id 
                AND report_date = CURDATE()
            ), 0) as branch_float,
            COALESCE((
                SELECT SUM(cash_balance) FROM morning_reports 
                WHERE branch_id = bp.branch_id 
                AND report_date = CURDATE()
            ), 0) as branch_cash
        FROM branch_providers bp
        JOIN providers p ON bp.provider_id = p.id
        WHERE bp.branch_id = ?
        ORDER BY p.provider_name, bp.provider_code";

$stmt = $db->prepare($sql);
$stmt->execute([$branch_id]);
$branch_providers = $stmt->fetchAll();

// Calculate totals
$total_float = 0;
$total_cash = 0;
$total_capital = 0;

foreach ($branch_providers as $provider) {
    $total_float += floatval($provider['branch_float'] ?? 0);
    $total_cash += floatval($provider['branch_cash'] ?? 0);
}
$total_capital = $total_float + $total_cash;

// Get all active providers for add modal
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
$stmt->execute();
$all_providers = $stmt->fetchAll();

// Get already assigned providers for this branch
$stmt = $db->prepare("SELECT provider_id, provider_code FROM branch_providers WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$assigned = $stmt->fetchAll();
$assigned_provider_ids = array_column($assigned, 'provider_id');

// Handle success/error messages
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
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
        <div class="branch-indicator-card">
            <div class="branch-card-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-card-info">
                <span class="branch-card-label">Branch Providers</span>
                <span class="branch-card-name"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                <span class="branch-card-code"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                <?php if (!empty($branch['location'])): ?>
                    <span class="branch-card-location">
                        <i class="fas fa-map-marker-alt"></i> 
                        <?php echo htmlspecialchars($branch['location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-card-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($branch_providers); ?></span>
                    <span class="stat-label">Providers</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo formatCurrency($total_float); ?></span>
                    <span class="stat-label">Float</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo formatCurrency($total_cash); ?></span>
                    <span class="stat-label">Cash</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo formatCurrency($total_capital); ?></span>
                    <span class="stat-label">Capital</span>
                </div>
            </div>
            <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Branches</span>
            </a>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-university"></i> Branch Providers</h2>
                <span class="record-count"><?php echo count($branch_providers); ?> providers</span>
            </div>
            <div class="page-header-right">
                <a href="providers_add.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-add">
                    <i class="fas fa-plus-circle"></i> Add Provider
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
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
        PROVIDERS CARDS GRID
        ============================================================ -->
        <?php if (empty($branch_providers)): ?>
            <div class="empty-state">
                <i class="fas fa-university"></i>
                <h3>No Providers Assigned</h3>
                <p>This branch doesn't have any providers yet.</p>
                <a href="providers_add.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-add-empty">
                    <i class="fas fa-plus-circle"></i> Add Provider
                </a>
            </div>
        <?php else: ?>
            <div class="providers-grid">
                <?php foreach ($branch_providers as $provider): 
                    $is_active = $provider['is_active'] ?? 1;
                    $status_class = $is_active ? 'active' : 'inactive';
                    $status_text = $is_active ? 'Active' : 'Inactive';
                    $status_icon = $is_active ? 'fa-check-circle' : 'fa-times-circle';
                    
                    $float_balance = floatval($provider['float_balance'] ?? 0);
                    $today_deposits = floatval($provider['today_deposits'] ?? 0);
                    $today_withdrawals = floatval($provider['today_withdrawals'] ?? 0);
                    
                    // Check if provider has amounts (can't delete if has amounts)
                    $has_amount = ($today_deposits > 0 || $today_withdrawals > 0 || $float_balance > 0);
                    
                    $type_icon = $provider['provider_type'] == 'mobile_money' ? 'fa-mobile-alt' : 'fa-university';
                    $type_label = ucfirst(str_replace('_', ' ', $provider['provider_type'] ?? 'Bank'));
                    $color = $provider['color_code'] ?? '#0B5ED7';
                    $icon = $provider['icon_class'] ?? 'fas fa-university';
                ?>
                    <div class="provider-card <?php echo $status_class; ?> <?php echo $has_amount ? 'has-amount' : ''; ?>">
                        <!-- Card Header -->
                        <div class="provider-card-header">
                            <div class="provider-card-title">
                                <div class="provider-icon" style="background: <?php echo $color; ?>;">
                                    <i class="<?php echo $icon; ?>"></i>
                                </div>
                                <div>
                                    <h3><?php echo htmlspecialchars($provider['provider_name']); ?></h3>
                                    <span class="provider-code-display">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($provider['provider_code']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="provider-card-status">
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo $status_text; ?>
                                </span>
                                <span class="provider-type-badge">
                                    <i class="fas <?php echo $type_icon; ?>"></i>
                                    <?php echo $type_label; ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Today's Amounts -->
                        <div class="provider-card-amounts">
                            <div class="amount-item amount-float">
                                <span class="amount-label">Float Available</span>
                                <span class="amount-value"><?php echo formatCurrency($float_balance); ?></span>
                            </div>
                            <div class="amount-divider"></div>
                            <div class="amount-item amount-deposit">
                                <span class="amount-label">Deposits</span>
                                <span class="amount-value"><?php echo formatCurrency($today_deposits); ?></span>
                            </div>
                            <div class="amount-divider"></div>
                            <div class="amount-item amount-withdrawal">
                                <span class="amount-label">Withdrawals</span>
                                <span class="amount-value"><?php echo formatCurrency($today_withdrawals); ?></span>
                            </div>
                            <?php if ($has_amount): ?>
                                <div class="amount-has-badge">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Has balance
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Card Actions -->
                        <div class="provider-card-actions">
                            <a href="providers_edit.php?id=<?php echo $provider['id']; ?>&branch_id=<?php echo $branch_id; ?>" class="btn-action btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php if ($has_amount): ?>
                                <button class="btn-action btn-delete disabled" title="Cannot delete - provider has balance or transactions" disabled>
                                    <i class="fas fa-lock"></i> Delete
                                </button>
                            <?php else: ?>
                                <a href="providers_delete.php?id=<?php echo $provider['id']; ?>&branch_id=<?php echo $branch_id; ?>" class="btn-action btn-delete" onclick="return confirmDelete('<?php echo addslashes($provider['provider_name']); ?>', '<?php echo addslashes($provider['provider_code']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
    --providers-bg: #f3f4f6;
    --providers-text: #1F2937;
    --providers-text-secondary: #6B7280;
    --providers-text-light: #9CA3AF;
    --providers-border: #E5E7EB;
    --providers-card-bg: #FFFFFF;
    --providers-card-shadow: rgba(0,0,0,0.08);
    --providers-card-shadow-hover: rgba(0,0,0,0.15);
    --providers-hover: #F3F4F6;
}

html.dark-mode {
    --providers-bg: #0f172a;
    --providers-text: #F1F5F9;
    --providers-text-secondary: #94A3B8;
    --providers-text-light: #64748B;
    --providers-border: #334155;
    --providers-card-bg: #1E293B;
    --providers-card-shadow: rgba(0,0,0,0.3);
    --providers-card-shadow-hover: rgba(0,0,0,0.5);
    --providers-hover: #2D3A4F;
}

body {
    background: var(--providers-bg) !important;
    color: var(--providers-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--providers-bg) !important; }
.main-content { background: var(--providers-bg) !important; }

/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 18px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}

.branch-indicator-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
}

.branch-indicator-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
}

.branch-card-icon {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    z-index: 1;
}

.branch-card-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.branch-card-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-card-name {
    font-size: 18px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-card-code {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    padding: 2px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-card-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.branch-card-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-card-stats .stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.branch-card-stats .stat-number {
    font-size: 18px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
}

.branch-card-stats .stat-label {
    font-size: 9px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.5);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-divider {
    width: 1px;
    height: 30px;
    background: rgba(255, 255, 255, 0.15);
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
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--providers-text);
    margin: 0;
}

.page-header-left h2 i { color: #DC2626; margin-right: 8px; }

.record-count {
    font-size: 13px;
    color: var(--providers-text-secondary);
    background: var(--providers-hover);
    padding: 3px 14px;
    border-radius: 12px;
}

.btn-add {
    background: #DC2626;
    color: white;
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-add:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.35);
    color: white;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    position: relative;
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
    transition: opacity 0.2s;
}

.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 16px;
}

.provider-card {
    background: var(--providers-card-bg);
    border-radius: 12px;
    border: 1px solid var(--providers-border);
    box-shadow: 0 2px 8px var(--providers-card-shadow);
    transition: all 0.3s ease;
    overflow: hidden;
}

.provider-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px var(--providers-card-shadow-hover);
}

.provider-card.inactive {
    opacity: 0.7;
}

.provider-card.has-amount {
    border-left: 4px solid #F59E0B;
}

.provider-card.has-amount.inactive {
    border-left-color: #6B7280;
}

/* Provider Card Header */
.provider-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 16px 18px 12px 18px;
    border-bottom: 1px solid var(--providers-border);
}

.provider-card-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.provider-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    flex-shrink: 0;
}

.provider-card-title h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--providers-text);
    margin: 0;
}

.provider-code-display {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.1);
    padding: 1px 10px;
    border-radius: 10px;
}

.provider-card-status {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .status-badge.active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-badge.inactive {
    background: #7F1D1D;
    color: #FEE2E2;
}

.provider-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 500;
    color: var(--providers-text-secondary);
    background: var(--providers-hover);
    padding: 1px 10px;
    border-radius: 10px;
}

/* Provider Amounts */
.provider-card-amounts {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    background: var(--providers-hover);
    border-bottom: 1px solid var(--providers-border);
    flex-wrap: wrap;
}

.amount-item {
    display: flex;
    flex-direction: column;
}

.amount-label {
    font-size: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--providers-text-light);
}

.amount-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--providers-text);
}

.amount-float .amount-value { color: #3B82F6; }
.amount-deposit .amount-value { color: #059669; }
.amount-withdrawal .amount-value { color: #DC2626; }

.amount-divider {
    width: 1px;
    height: 30px;
    background: var(--providers-border);
}

.amount-has-badge {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 600;
    color: #D97706;
    background: #FEF3C7;
    padding: 2px 12px;
    border-radius: 12px;
}

html.dark-mode .amount-has-badge {
    background: #5F3A1E;
    color: #FBBF24;
}

/* Provider Card Actions */
.provider-card-actions {
    display: flex;
    gap: 6px;
    padding: 12px 18px 16px 18px;
    border-top: 1px solid var(--providers-border);
    flex-wrap: wrap;
}

.btn-action {
    padding: 6px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-1px);
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
}

.btn-edit:hover {
    background: #A7F3D0;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
}

.btn-delete.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-delete.disabled:hover {
    transform: none;
}

html.dark-mode .btn-edit {
    background: #065F46;
    color: #34D399;
}

html.dark-mode .btn-edit:hover {
    background: #10B981;
    color: #FFFFFF;
}

html.dark-mode .btn-delete {
    background: #7F1D1D;
    color: #FCA5A5;
}

html.dark-mode .btn-delete:hover {
    background: #DC2626;
    color: #FFFFFF;
}

html.dark-mode .btn-delete.disabled {
    background: #374151;
    color: #6B7280;
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--providers-card-bg);
    border-radius: 12px;
    border: 1px solid var(--providers-border);
}

.empty-state i {
    font-size: 60px;
    color: #DC2626;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--providers-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--providers-text-secondary);
    font-size: 14px;
    margin: 0 0 24px 0;
}

.btn-add-empty {
    background: #DC2626;
    color: white;
    padding: 12px 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-add-empty:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(220,38,38,0.4);
    color: white;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .branch-indicator-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
    }
    
    .branch-card-stats {
        margin-left: 0;
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
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
    
    .providers-grid {
        grid-template-columns: 1fr;
    }
    
    .provider-card-header {
        flex-direction: column;
        gap: 8px;
    }
    
    .provider-card-status {
        flex-direction: row;
        align-items: center;
        gap: 6px;
    }
    
    .provider-card-amounts {
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .amount-has-badge {
        margin-left: 0;
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .branch-card-stats .stat-number {
        font-size: 14px;
    }
    
    .branch-card-name {
        font-size: 15px;
    }
    
    .provider-card-title h3 {
        font-size: 14px;
    }
    
    .provider-card-actions {
        flex-direction: column;
    }
    
    .provider-card-actions .btn-action {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(name, code) {
    return confirm('Are you sure you want to delete "' + name + '" with code "' + code + '" from this branch?\n\nNote: This provider has no balance or transactions and can be safely deleted.');
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
    
    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    }
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    }
});
</script>
</body>
</html>