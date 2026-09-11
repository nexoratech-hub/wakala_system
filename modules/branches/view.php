<?php
// ================================================================
// FILE: modules/branches/view.php
// WAKALA FINANCIAL SYSTEM - VIEW BRANCH
// ✅ FIXED: URL is source of truth for branch_id
// ✅ FIXED: No session override that affects topbar
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
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET BRANCH ID FROM URL ONLY
// ============================================================
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// ✅ FIXED: DO NOT set $_SESSION['selected_branch']
// URL is the only source of truth
// ============================================================

// ============================================================
// GET BRANCH DATA
// ============================================================
$sql = "SELECT 
            b.*,
            e.full_name as manager_name
        FROM branches b
        LEFT JOIN employees e ON b.manager_id = e.id
        WHERE b.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        bp.id as branch_provider_id,
        bp.provider_code as branch_provider_code,
        bp.is_active as bp_is_active,
        p.*
    FROM branch_providers bp
    JOIN providers p ON bp.provider_id = p.id
    WHERE bp.branch_id = ?
    ORDER BY p.provider_name
");
$stmt->execute([$branch_id]);
$branch_providers = $stmt->fetchAll();

// ============================================================
// GET BRANCH STATS
// ============================================================
$stats = [
    'provider_count' => count($branch_providers),
    'employee_count' => 0,
    'patient_count' => 0,
    'today_float' => 0,
    'today_cash' => 0
];

// Employee count
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE branch_id = ? AND is_active = 1");
    $stmt->execute([$branch_id]);
    $stats['employee_count'] = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {}

// Today's float/cash
try {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(cumm_total), 0) as float_total,
            COALESCE(SUM(cash_balance), 0) as cash_total
        FROM morning_reports 
        WHERE branch_id = ? AND report_date = CURDATE()
    ");
    $stmt->execute([$branch_id]);
    $report_data = $stmt->fetch();
    $stats['today_float'] = floatval($report_data['float_total'] ?? 0);
    $stats['today_cash'] = floatval($report_data['cash_total'] ?? 0);
} catch (Exception $e) {}

$stats['total_capital'] = $stats['today_float'] + $stats['today_cash'];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-store-alt"></i> Branch Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                <span class="branch-id-badge">ID: #<?php echo $branch_id; ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php?branch=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $branch_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <!-- ============================================================
        BRANCH OVERVIEW CARD
        ============================================================ -->
        <div class="branch-overview-card <?php echo $branch['is_active'] ? 'active' : 'inactive'; ?>">
            <div class="overview-header">
                <div class="overview-left">
                    <div class="overview-icon">
                        <i class="fas fa-store-alt"></i>
                    </div>
                    <div class="overview-info">
                        <h3><?php echo htmlspecialchars($branch['branch_name']); ?></h3>
                        <div class="overview-meta">
                            <span class="meta-item">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($branch['branch_code']); ?>
                            </span>
                            <span class="status-badge <?php echo $branch['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <i class="fas <?php echo $branch['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                <?php echo $branch['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="overview-stats">
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats['provider_count']; ?></span>
                        <span class="stat-label">Providers</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats['employee_count']; ?></span>
                        <span class="stat-label">Employees</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo formatCurrency($stats['today_float']); ?></span>
                        <span class="stat-label">Float</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo formatCurrency($stats['today_cash']); ?></span>
                        <span class="stat-label">Cash</span>
                    </div>
                    <div class="stat-box stat-box-highlight">
                        <span class="stat-value"><?php echo formatCurrency($stats['total_capital']); ?></span>
                        <span class="stat-label">Capital</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        BRANCH INFORMATION
        ============================================================ -->
        <div class="view-card">
            <div class="view-card-header">
                <h3><i class="fas fa-info-circle"></i> Branch Information</h3>
            </div>
            <div class="view-card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-tag"></i> Branch Code</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-store-alt"></i> Branch Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['location'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['phone'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['email'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user-tie"></i> Manager</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['manager_name'] ?? 'Not assigned'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-calendar-alt"></i> Created</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($branch['created_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($branch['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        BRANCH PROVIDERS
        ============================================================ -->
        <div class="view-card">
            <div class="view-card-header">
                <h3><i class="fas fa-university"></i> Branch Providers</h3>
                <div class="card-header-actions">
                    <span class="provider-count"><?php echo count($branch_providers); ?> providers</span>
                    <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn-small">
                        <i class="fas fa-external-link-alt"></i> Manage
                    </a>
                </div>
            </div>
            <div class="view-card-body">
                <?php if (empty($branch_providers)): ?>
                    <div class="empty-providers">
                        <i class="fas fa-university"></i>
                        <p>No providers assigned to this branch.</p>
                        <a href="providers_add.php?branch_id=<?php echo $branch_id; ?>" class="btn-small-primary">
                            <i class="fas fa-plus-circle"></i> Add Provider
                        </a>
                    </div>
                <?php else: ?>
                    <div class="provider-grid">
                        <?php foreach ($branch_providers as $provider): ?>
                            <div class="provider-item <?php echo $provider['bp_is_active'] ? 'active' : 'inactive'; ?>">
                                <div class="provider-icon" style="background: <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?>;">
                                    <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                </div>
                                <div class="provider-info">
                                    <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                    <span class="provider-code">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($provider['branch_provider_code']); ?>
                                    </span>
                                </div>
                                <div class="provider-status-badge <?php echo $provider['bp_is_active'] ? 'active' : 'inactive'; ?>">
                                    <i class="fas <?php echo $provider['bp_is_active'] ? 'fa-check' : 'fa-times'; ?>"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        BRANCH ACTIONS
        ============================================================ -->
        <div class="view-actions">
            <a href="edit.php?id=<?php echo $branch_id; ?>" class="btn btn-edit">
                <i class="fas fa-edit"></i> Edit Branch
            </a>
            <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-providers">
                <i class="fas fa-university"></i> Manage Providers
            </a>
            <a href="delete.php?id=<?php echo $branch_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $branch_id; ?>, '<?php echo addslashes($branch['branch_name']); ?>')">
                <i class="fas fa-trash"></i> Delete Branch
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
    --view-bg: #F3F4F6;
    --view-text: #1F2937;
    --view-text-secondary: #6B7280;
    --view-text-light: #9CA3AF;
    --view-border: #E5E7EB;
    --view-card-bg: #FFFFFF;
    --view-card-header: #FAFBFC;
    --view-hover: #F3F4F6;
    --view-shadow: rgba(0,0,0,0.06);
    --view-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --view-bg: #0F172A;
    --view-text: #F9FAFB;
    --view-text-secondary: #9CA3AF;
    --view-text-light: #6B7280;
    --view-border: #334155;
    --view-card-bg: #1E293B;
    --view-card-header: #1E293B;
    --view-hover: #334155;
    --view-shadow: rgba(0,0,0,0.3);
    --view-shadow-md: rgba(0,0,0,0.4);
}

body {
    background: var(--view-bg) !important;
    color: var(--view-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--view-bg) !important; }
.main-content { background: var(--view-bg) !important; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0 4px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.page-header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--view-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
    font-weight: 600;
}

.branch-id-badge {
    font-size: 11px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.1);
    padding: 3px 12px;
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}

.page-header-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
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
    transform: translateY(-2px);
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
    transform: translateY(-2px);
}

html.dark-mode .btn-edit {
    background: #065F46;
    color: #34D399;
}

html.dark-mode .btn-edit:hover {
    background: #10B981;
    color: #FFFFFF;
}

/* ============================================================
   BRANCH OVERVIEW CARD
   ============================================================ */
.branch-overview-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
}

html.dark-mode .branch-overview-card {
    background: linear-gradient(135deg, #991B1B 0%, #7F1D1D 100%);
}

.branch-overview-card.inactive {
    background: linear-gradient(135deg, #6B7280 0%, #4B5563 100%);
    box-shadow: 0 8px 32px rgba(107, 114, 128, 0.35);
}

.branch-overview-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-overview-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.overview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.overview-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

.overview-icon {
    width: 64px;
    height: 64px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.overview-info h3 {
    font-size: 22px;
    font-weight: 700;
    color: #FFFFFF;
    margin: 0 0 6px 0;
    text-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

.overview-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.overview-meta .meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    background: rgba(255, 255, 255, 0.12);
    padding: 3px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.overview-meta .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 14px;
    border-radius: 12px;
}

.overview-meta .status-active {
    background: rgba(16, 185, 129, 0.3);
    color: #D1FAE5;
    border: 1px solid rgba(16, 185, 129, 0.4);
}

.overview-meta .status-inactive {
    background: rgba(239, 68, 68, 0.3);
    color: #FEE2E2;
    border: 1px solid rgba(239, 68, 68, 0.4);
}

.overview-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
    flex-wrap: wrap;
}

.overview-stats .stat-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 12px;
    min-width: 70px;
}

.overview-stats .stat-box:not(:last-child) {
    border-right: 1px solid rgba(255, 255, 255, 0.15);
}

.overview-stats .stat-value {
    font-size: 16px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
    white-space: nowrap;
}

.overview-stats .stat-label {
    font-size: 9px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

.overview-stats .stat-box-highlight .stat-value {
    color: #FCD34D;
    font-size: 18px;
}

/* ============================================================
   VIEW CARDS
   ============================================================ */
.view-card {
    background: var(--view-card-bg);
    border-radius: 12px;
    border: 1px solid var(--view-border);
    box-shadow: 0 1px 3px var(--view-shadow);
    margin-bottom: 20px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.view-card:hover {
    box-shadow: 0 4px 12px var(--view-shadow-md);
}

.view-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
    flex-wrap: wrap;
    gap: 10px;
}

.view-card-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.view-card-header h3 i {
    color: #3B82F6;
}

.card-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.provider-count {
    font-size: 12px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.1);
    padding: 3px 12px;
    border-radius: 12px;
}

.btn-small {
    background: var(--view-hover);
    color: var(--view-text-secondary);
    padding: 5px 14px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    border: 1px solid var(--view-border);
}

.btn-small:hover {
    background: var(--view-border);
    color: var(--view-text);
}

.view-card-body {
    padding: 20px 22px;
}

/* ============================================================
   INFO GRID
   ============================================================ */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px 24px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-bottom: 12px;
    border-bottom: 1px dashed var(--view-border);
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
    color: #3B82F6;
    font-size: 11px;
}

.info-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--view-text);
    word-break: break-word;
}

/* ============================================================
   PROVIDER GRID
   ============================================================ */
.provider-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.provider-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--view-hover);
    border-radius: 10px;
    border: 1px solid var(--view-border);
    transition: all 0.3s ease;
    position: relative;
}

.provider-item:hover {
    border-color: #3B82F6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.provider-item.inactive {
    opacity: 0.6;
}

.provider-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    flex-shrink: 0;
}

.provider-info {
    flex: 1;
    min-width: 0;
}

.provider-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--view-text);
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.provider-code {
    font-size: 10px;
    font-weight: 500;
    color: var(--view-text-light);
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

.provider-status-badge {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    flex-shrink: 0;
}

.provider-status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.provider-status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .provider-status-badge.active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .provider-status-badge.inactive {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* ============================================================
   EMPTY PROVIDERS
   ============================================================ */
.empty-providers {
    text-align: center;
    padding: 40px 20px;
}

.empty-providers i {
    font-size: 48px;
    color: var(--view-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}

.empty-providers p {
    color: var(--view-text-secondary);
    font-size: 14px;
    margin: 0 0 16px 0;
}

.btn-small-primary {
    background: #3B82F6;
    color: #FFFFFF;
    padding: 8px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-small-primary:hover {
    background: #2563EB;
    color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

/* ============================================================
   VIEW ACTIONS
   ============================================================ */
.view-actions {
    display: flex;
    gap: 12px;
    padding: 20px;
    background: var(--view-card-bg);
    border-radius: 12px;
    border: 1px solid var(--view-border);
    box-shadow: 0 1px 3px var(--view-shadow);
    flex-wrap: wrap;
}

.btn {
    padding: 10px 22px;
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

.btn:hover {
    transform: translateY(-2px);
}

.btn-providers {
    background: #FCE7F3;
    color: #BE185D;
}

.btn-providers:hover {
    background: #FBCFE8;
    box-shadow: 0 4px 12px rgba(190, 24, 93, 0.2);
}

html.dark-mode .btn-providers {
    background: #5F1E3A;
    color: #F472B6;
}

html.dark-mode .btn-providers:hover {
    background: #BE185D;
    color: #FFFFFF;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
    margin-left: auto;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
}

html.dark-mode .btn-delete {
    background: #7F1D1D;
    color: #FCA5A5;
}

html.dark-mode .btn-delete:hover {
    background: #DC2626;
    color: #FFFFFF;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .overview-stats {
        flex-wrap: wrap;
        gap: 8px;
        padding: 10px 14px;
    }
    
    .overview-stats .stat-box {
        padding: 0 8px;
        min-width: 60px;
    }
    
    .overview-stats .stat-value {
        font-size: 14px;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .page-header-right {
        width: 100%;
    }
    
    .page-header-right .btn-back,
    .page-header-right .btn-edit {
        flex: 1;
        justify-content: center;
    }
    
    .overview-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .overview-stats {
        width: 100%;
        justify-content: center;
    }
    
    .overview-stats .stat-box {
        border-right: none !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 6px 12px;
        width: calc(50% - 4px);
        flex-direction: row;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
    }
    
    .overview-stats .stat-box:last-child {
        border-bottom: none;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .provider-grid {
        grid-template-columns: 1fr;
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .btn-delete {
        margin-left: 0;
    }
    
    .overview-info h3 {
        font-size: 18px;
    }
    
    .overview-icon {
        width: 52px;
        height: 52px;
        font-size: 22px;
    }
}

@media (max-width: 480px) {
    .page-header-left h2 {
        font-size: 18px;
    }
    
    .page-subtitle {
        font-size: 11px;
        padding: 2px 10px;
    }
    
    .branch-id-badge {
        font-size: 10px;
    }
    
    .overview-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .overview-info h3 {
        font-size: 16px;
    }
    
    .view-card-header {
        padding: 12px 16px;
    }
    
    .view-card-body {
        padding: 16px 14px;
    }
    
    .provider-item {
        padding: 10px 12px;
    }
    
    .provider-icon {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

.branch-overview-card,
.view-card,
.view-actions {
    animation: fadeInUp 0.4s ease forwards;
}

.view-card:nth-child(2) { animation-delay: 0.05s; }
.view-card:nth-child(3) { animation-delay: 0.1s; }
.view-actions { animation-delay: 0.15s; }
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(id, name) {
    return confirm('Are you sure you want to delete the branch "' + name + '"?\n\nThis action cannot be undone.');
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
    
    // Console log for debug
    console.log('=== VIEW.PHP ===');
    console.log('Branch ID: <?php echo $branch_id; ?>');
    console.log('Branch Name: <?php echo htmlspecialchars($branch['branch_name']); ?>');
    console.log('Providers: <?php echo count($branch_providers); ?>');
});
</script>
</body>
</html>