<?php
// ================================================================
// FILE: modules/capital_management/view.php
// VIEW CAPITAL TRANSACTION DETAILS
// ✅ FIXED: Dark mode uses html.dark-mode (consistent with topbar)
// ✅ FIXED: No session override
// ✅ NEW: Better UI with provider info display
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET TRANSACTION DATA
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT cm.*, 
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            p.provider_name,
            p.provider_type,
            p.icon_class as provider_icon,
            p.color_code as provider_color,
            bp.provider_code as branch_provider_code
        FROM capital_management cm
        LEFT JOIN employees e ON cm.employee_id = e.id
        LEFT JOIN branches b ON cm.branch_id = b.id
        LEFT JOIN providers p ON cm.reference_id = p.id AND cm.reference_module = 'provider'
        LEFT JOIN branch_providers bp ON bp.branch_id = cm.branch_id AND bp.provider_id = p.id
        WHERE cm.id = ?
    ");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transaction: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$transaction) {
    header('Location: index.php');
    exit();
}

// ============================================================
// TYPE LABELS
// ============================================================
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Capital Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

$type_info = $type_labels[$transaction['transaction_type']] ?? [
    'label' => $transaction['transaction_type'],
    'icon' => 'fa-circle',
    'color' => 'gray'
];

// Determine amount sign
$is_outgoing = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']);
$amount_sign = $is_outgoing ? '-' : '+';
$amount_class = $is_outgoing ? 'text-danger' : 'text-success';

// Determine source
$is_float = ($transaction['reference_module'] === 'provider' && !empty($transaction['provider_name']));
$source_label = $is_float ? 'Float (Provider)' : 'Cash (Manual)';
$source_icon = $is_float ? 'fa-university' : 'fa-money-bill-wave';
$source_class = $is_float ? 'source-float' : 'source-cash';

// Get branch parameter for navigation
$branch_param = $transaction['branch_id'] ?? 0;

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH CARD
        ============================================================ -->
        <div class="branch-card">
            <i class="fas fa-store-alt"></i>
            <span class="branch-label">Branch:</span>
            <span class="branch-name"><?php echo htmlspecialchars($transaction['branch_name'] ?? 'Main'); ?></span>
            <?php if (!empty($transaction['branch_code'])): ?>
                <span class="branch-code-badge"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
            <?php endif; ?>
            <?php if (!empty($transaction['branch_location'])): ?>
                <span class="branch-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($transaction['branch_location']); ?>
                </span>
            <?php endif; ?>
            <a href="index.php?branch=<?php echo $branch_param; ?>" class="branch-back-btn">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-invoice" style="color:#bb0404;"></i> Capital Transaction Details</h2>
                <p class="text-muted">View complete transaction information</p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="#" class="btn btn-info" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="index.php?branch=<?php echo $branch_param; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Close
                </a>
            </div>
        </div>

        <!-- ============================================================
        MAIN TRANSACTION CARD
        ============================================================ -->
        <div class="transaction-card">
            
            <!-- Card Header with Number and Amount -->
            <div class="transaction-card-header">
                <div class="header-left-section">
                    <div class="transaction-icon <?php echo $is_outgoing ? 'icon-outgoing' : 'icon-incoming'; ?>">
                        <i class="fas <?php echo $type_info['icon']; ?>"></i>
                    </div>
                    <div class="transaction-title">
                        <span class="transaction-label">Transaction Number</span>
                        <span class="transaction-number"><?php echo htmlspecialchars($transaction['capital_number']); ?></span>
                        <span class="type-badge type-<?php echo $type_info['color']; ?>">
                            <i class="fas <?php echo $type_info['icon']; ?>"></i>
                            <?php echo $type_info['label']; ?>
                        </span>
                    </div>
                </div>
                <div class="header-right-section">
                    <span class="amount-label"><?php echo $is_outgoing ? 'Amount Out' : 'Amount In'; ?></span>
                    <span class="amount-value <?php echo $amount_class; ?>">
                        <?php echo $amount_sign; ?> <?php echo formatCurrency($transaction['amount']); ?>
                    </span>
                </div>
            </div>

            <!-- ============================================================
            SOURCE INDICATOR (Float or Cash)
            ============================================================ -->
            <div class="source-indicator <?php echo $source_class; ?>">
                <div class="source-icon">
                    <i class="fas <?php echo $source_icon; ?>"></i>
                </div>
                <div class="source-info">
                    <span class="source-label">Source</span>
                    <span class="source-value"><?php echo $source_label; ?></span>
                </div>
                <?php if ($is_float && !empty($transaction['provider_name'])): ?>
                    <div class="provider-display">
                        <div class="provider-icon" style="background: <?php echo htmlspecialchars($transaction['provider_color'] ?? '#0B5ED7'); ?>;">
                            <i class="<?php echo htmlspecialchars($transaction['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                        </div>
                        <div class="provider-info">
                            <span class="provider-name"><?php echo htmlspecialchars($transaction['provider_name']); ?></span>
                            <span class="provider-code-display">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($transaction['branch_provider_code'] ?? 'N/A'); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================================
            DETAILS GRID
            ============================================================ -->
            <div class="details-grid">
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar-alt"></i> Transaction Date
                    </span>
                    <span class="detail-value">
                        <?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-store-alt"></i> Branch
                    </span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($transaction['branch_name'] ?? 'Main'); ?>
                        <?php if (!empty($transaction['branch_code'])): ?>
                            <span class="badge-inline"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-user-tie"></i> Created By
                    </span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?>
                        <?php if (!empty($transaction['employee_code'])): ?>
                            <span class="badge-inline"><?php echo htmlspecialchars($transaction['employee_code']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-money-bill-wave"></i> Amount
                    </span>
                    <span class="detail-value <?php echo $amount_class; ?> font-bold">
                        <?php echo $amount_sign; ?> <?php echo formatCurrency($transaction['amount']); ?>
                    </span>
                </div>

            </div>

            <!-- ============================================================
            DESCRIPTION & NOTES
            ============================================================ -->
            <div class="description-section">
                <div class="description-item">
                    <span class="description-label">
                        <i class="fas fa-align-left"></i> Description
                    </span>
                    <div class="description-content">
                        <?php echo nl2br(htmlspecialchars($transaction['description'] ?? 'N/A')); ?>
                    </div>
                </div>

                <?php if (!empty($transaction['notes'])): ?>
                    <div class="description-item">
                        <span class="description-label">
                            <i class="fas fa-sticky-note"></i> Notes
                        </span>
                        <div class="description-content">
                            <?php echo nl2br(htmlspecialchars($transaction['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================================
            TIMESTAMPS
            ============================================================ -->
            <div class="timestamps-section">
                <div class="timestamp-item">
                    <i class="fas fa-plus-circle"></i>
                    <div>
                        <span class="timestamp-label">Created</span>
                        <span class="timestamp-value">
                            <?php echo date('d M Y H:i:s', strtotime($transaction['created_at'])); ?>
                        </span>
                    </div>
                </div>
                <div class="timestamp-item">
                    <i class="fas fa-edit"></i>
                    <div>
                        <span class="timestamp-label">Last Updated</span>
                        <span class="timestamp-value">
                            <?php echo date('d M Y H:i:s', strtotime($transaction['updated_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================================
        ACTION BUTTONS (Bottom)
        ============================================================ -->
        <div class="action-bar">
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning-large">
                <i class="fas fa-edit"></i> Edit Transaction
            </a>
            <a href="index.php?branch=<?php echo $branch_param; ?>" class="btn btn-secondary-large">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <button onclick="confirmDelete(<?php echo $id; ?>)" class="btn btn-danger-large">
                <i class="fas fa-trash"></i> Delete Transaction
            </button>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --cm-bg: #F3F4F6;
    --cm-text: #1F2937;
    --cm-text-secondary: #6B7280;
    --cm-text-light: #9CA3AF;
    --cm-border: #E5E7EB;
    --cm-card-bg: #FFFFFF;
    --cm-card-header: #FAFBFC;
    --cm-input-bg: #F9FAFB;
    --cm-hover: #F3F4F6;
    --cm-shadow: rgba(0,0,0,0.06);
}

html.dark-mode {
    --cm-bg: #0F172A;
    --cm-text: #F9FAFB;
    --cm-text-secondary: #9CA3AF;
    --cm-text-light: #6B7280;
    --cm-border: #334155;
    --cm-card-bg: #1E293B;
    --cm-card-header: #1E293B;
    --cm-input-bg: #334155;
    --cm-hover: #334155;
    --cm-shadow: rgba(0,0,0,0.3);
}

body {
    background: var(--cm-bg) !important;
    color: var(--cm-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--cm-bg) !important; }
.main-content { background: var(--cm-bg) !important; }

/* ============================================================
   BRANCH CARD
   ============================================================ */
.branch-card {
    background: #bb0404;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.3);
    flex-wrap: wrap;
}
.branch-card i { font-size: 16px; }
.branch-card .branch-label { font-weight: 500; font-size: 13px; opacity: 0.9; }
.branch-card .branch-name { font-weight: 700; font-size: 15px; }
.branch-card .branch-code-badge {
    background: rgba(255,255,255,0.15);
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}
.branch-card .branch-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    opacity: 0.8;
}
.branch-card .branch-back-btn {
    margin-left: auto;
    color: #ffffff;
    text-decoration: none;
    font-size: 12px;
    padding: 5px 14px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.branch-card .branch-back-btn:hover {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0;
}
.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--cm-text-secondary);
    margin: 4px 0 0 0;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 9px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-warning {
    background: #F59E0B;
    color: #FFFFFF;
}
.btn-warning:hover {
    background: #D97706;
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
}
.btn-info {
    background: #3B82F6;
    color: #FFFFFF;
}
.btn-info:hover {
    background: #2563EB;
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59,130,246,0.3);
}
.btn-secondary {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-secondary:hover {
    background: var(--cm-border);
    color: var(--cm-text);
}

/* ============================================================
   TRANSACTION CARD
   ============================================================ */
.transaction-card {
    background: var(--cm-card-bg);
    border-radius: 14px;
    border: 1px solid var(--cm-border);
    box-shadow: 0 2px 8px var(--cm-shadow);
    margin-bottom: 20px;
    overflow: hidden;
    animation: fadeInUp 0.4s ease;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   CARD HEADER
   ============================================================ */
.transaction-card-header {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    padding: 20px 26px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.transaction-card-header::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
    pointer-events: none;
}
.transaction-card-header::after {
    content: '';
    position: absolute;
    bottom: -80%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
    pointer-events: none;
}

.header-left-section {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.transaction-icon {
    width: 58px;
    height: 58px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #FFFFFF;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.2);
}
.icon-incoming { background: rgba(16, 185, 129, 0.3); }
.icon-outgoing { background: rgba(239, 68, 68, 0.3); }

.transaction-title {
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
    z-index: 1;
}
.transaction-label {
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.7);
}
.transaction-number {
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    width: fit-content;
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.type-blue { background: rgba(59, 130, 246, 0.25); color: #BFDBFE; border: 1px solid rgba(59, 130, 246, 0.3); }
.type-green { background: rgba(16, 185, 129, 0.25); color: #A7F3D0; border: 1px solid rgba(16, 185, 129, 0.3); }
.type-purple { background: rgba(139, 92, 246, 0.25); color: #DDD6FE; border: 1px solid rgba(139, 92, 246, 0.3); }
.type-red { background: rgba(239, 68, 68, 0.25); color: #FECACA; border: 1px solid rgba(239, 68, 68, 0.3); }
.type-orange { background: rgba(245, 158, 11, 0.25); color: #FDE68A; border: 1px solid rgba(245, 158, 11, 0.3); }
.type-gray { background: rgba(107, 114, 128, 0.25); color: #E5E7EB; border: 1px solid rgba(107, 114, 128, 0.3); }

.header-right-section {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    position: relative;
    z-index: 1;
}
.amount-label {
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.7);
}
.amount-value {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.15);
}
.amount-value.text-success { color: #6EE7B7; }
.amount-value.text-danger { color: #FCA5A5; }

/* ============================================================
   SOURCE INDICATOR
   ============================================================ */
.source-indicator {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 26px;
    border-bottom: 1px solid var(--cm-border);
    background: var(--cm-card-header);
    flex-wrap: wrap;
}
.source-indicator.source-float {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.08), transparent);
    border-left: 4px solid #3B82F6;
}
.source-indicator.source-cash {
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.08), transparent);
    border-left: 4px solid #10B981;
}

.source-icon {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 18px;
    flex-shrink: 0;
}
.source-float .source-icon { background: linear-gradient(135deg, #3B82F6, #2563EB); }
.source-cash .source-icon { background: linear-gradient(135deg, #10B981, #059669); }

.source-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.source-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--cm-text-light);
}
.source-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--cm-text);
}

.provider-display {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 14px;
    background: var(--cm-card-bg);
    border-radius: 20px;
    border: 1px solid var(--cm-border);
    margin-left: auto;
    box-shadow: 0 1px 3px var(--cm-shadow);
}

.provider-display .provider-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
}

.provider-info {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.provider-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--cm-text);
    line-height: 1.2;
}
.provider-code-display {
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    color: #3B82F6;
    font-weight: 600;
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
    padding: 18px 26px;
    border-bottom: 1px solid var(--cm-border);
    border-right: 1px solid var(--cm-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: background 0.2s ease;
}
.detail-item:hover {
    background: var(--cm-hover);
}
.detail-item:nth-child(2n) {
    border-right: none;
}
.detail-item:nth-last-child(-n+2) {
    border-bottom: none;
}

.detail-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cm-text-light);
}
.detail-label i {
    color: #3B82F6;
    font-size: 12px;
}
.detail-value {
    font-size: 15px;
    font-weight: 600;
    color: var(--cm-text);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.badge-inline {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.font-bold { font-weight: 800; font-size: 16px; }
.text-success { color: #10B981; }
.text-danger { color: #DC2626; }

/* ============================================================
   DESCRIPTION SECTION
   ============================================================ */
.description-section {
    padding: 20px 26px;
    border-bottom: 1px solid var(--cm-border);
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.description-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.description-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cm-text-light);
}
.description-label i { color: #3B82F6; font-size: 12px; }
.description-content {
    font-size: 14px;
    line-height: 1.6;
    color: var(--cm-text);
    background: var(--cm-hover);
    padding: 14px 18px;
    border-radius: 8px;
    border-left: 3px solid #3B82F6;
}

/* ============================================================
   TIMESTAMPS SECTION
   ============================================================ */
.timestamps-section {
    display: flex;
    justify-content: space-around;
    padding: 16px 26px;
    background: var(--cm-card-header);
    flex-wrap: wrap;
    gap: 16px;
}
.timestamp-item {
    display: flex;
    align-items: center;
    gap: 12px;
}
.timestamp-item i {
    font-size: 20px;
    color: var(--cm-text-light);
    opacity: 0.6;
}
.timestamp-item .timestamp-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cm-text-light);
}
.timestamp-item .timestamp-value {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--cm-text);
    margin-top: 2px;
}

/* ============================================================
   ACTION BAR
   ============================================================ */
.action-bar {
    display: flex;
    gap: 12px;
    padding: 20px;
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
    box-shadow: 0 1px 3px var(--cm-shadow);
    flex-wrap: wrap;
}
.btn-warning-large {
    background: #F59E0B;
    color: #FFFFFF;
    padding: 12px 24px;
    flex: 1;
    justify-content: center;
    font-size: 14px;
}
.btn-warning-large:hover {
    background: #D97706;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
}
.btn-secondary-large {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
    padding: 12px 24px;
    flex: 1;
    justify-content: center;
    font-size: 14px;
}
.btn-secondary-large:hover {
    background: var(--cm-border);
    color: var(--cm-text);
}
.btn-danger-large {
    background: #DC2626;
    color: #FFFFFF;
    padding: 12px 24px;
    flex: 1;
    justify-content: center;
    font-size: 14px;
    border: none;
    cursor: pointer;
    border-radius: 8px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-danger-large:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .header-right {
        width: 100%;
    }
    .header-right .btn {
        flex: 1;
        justify-content: center;
    }
    .transaction-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    .header-right-section {
        align-items: flex-start;
        width: 100%;
    }
    .amount-value { font-size: 24px; }
    .source-indicator {
        flex-direction: column;
        align-items: flex-start;
    }
    .provider-display {
        margin-left: 0;
        width: 100%;
    }
    .details-grid {
        grid-template-columns: 1fr;
    }
    .detail-item {
        border-right: none !important;
        border-bottom: 1px solid var(--cm-border) !important;
    }
    .detail-item:last-child {
        border-bottom: none !important;
    }
    .timestamps-section {
        flex-direction: column;
        align-items: flex-start;
    }
    .action-bar {
        flex-direction: column;
    }
    .action-bar .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .transaction-number { font-size: 16px; }
    .amount-value { font-size: 20px; }
    .transaction-icon { width: 48px; height: 48px; font-size: 20px; }
    .detail-value { font-size: 14px; }
}

/* ============================================================
   PRINT STYLES
   ============================================================ */
@media print {
    .branch-card .branch-back-btn,
    .page-header .header-right,
    .action-bar {
        display: none !important;
    }
    .transaction-card {
        box-shadow: none;
        border: 1px solid #ccc;
    }
    .transaction-card-header {
        background: #1E40AF !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body {
        background: #ffffff !important;
    }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this capital transaction?\n\nThis action cannot be undone.')) {
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
    
    console.log('=== CAPITAL VIEW.PHP ===');
    console.log('Transaction ID: <?php echo $id; ?>');
    console.log('Number: <?php echo htmlspecialchars($transaction['capital_number']); ?>');
    console.log('Type: <?php echo $transaction['transaction_type']; ?>');
    console.log('Amount: <?php echo $transaction['amount']; ?>');
    console.log('Source: <?php echo $is_float ? "Float" : "Cash"; ?>');
});
</script>

</body>
</html>