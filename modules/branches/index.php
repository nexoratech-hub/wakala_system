<?php
// ================================================================
// FILE: modules/branches/index.php
// WAKALA FINANCIAL SYSTEM - BRANCHES AS CARDS
// WITH PERSISTENT RED FILTER CARD
// FOLLOWS TOPBAR FILTER (USES branch_id)
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

// Check permission - Only admin and super_admin can access
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// Get user data
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// BRANCH FILTER - USES branch_id (MATCHES TOPBAR)
// ============================================================
$selected_branch = 0;

// Primary: branch_id (from topbar)
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}
// Fallback: branch (for backward compatibility)
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

// No session memory - URL is source of truth
unset($_SESSION['selected_branch']);

// ============================================================
// GET BRANCH NAME FOR DISPLAY
// ============================================================
$filter_branch_name = 'All Branches';
$filter_branch_code = '';
$filter_branch_location = '';

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT id, branch_name, branch_code, location FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $filter_branch = $stmt->fetch();
    if ($filter_branch) {
        $filter_branch_name = $filter_branch['branch_name'];
        $filter_branch_code = $filter_branch['branch_code'];
        $filter_branch_location = $filter_branch['location'] ?? '';
    } else {
        // Invalid branch - reset to all
        $selected_branch = 0;
        $filter_branch_name = 'All Branches';
    }
}

// ============================================================
// GET BRANCHES WITH DATA - FILTER AT SQL LEVEL
// ============================================================
$sql = "SELECT 
            b.id,
            b.branch_code,
            b.branch_name,
            b.location,
            b.phone,
            b.email,
            b.manager_id,
            b.is_active,
            b.created_at,
            b.updated_at,
            e.full_name as manager_name,
            COUNT(DISTINCT bp.id) as provider_count,
            COUNT(DISTINCT CASE WHEN p.provider_type = 'bank' THEN bp.id END) as bank_count,
            COUNT(DISTINCT CASE WHEN p.provider_type = 'mobile_money' THEN bp.id END) as mobile_count,
            COUNT(DISTINCT emp.id) as employee_count,
            (SELECT SUM(cumm_total) FROM morning_reports WHERE branch_id = b.id AND report_date = CURDATE()) as today_float,
            (SELECT SUM(cash_balance) FROM morning_reports WHERE branch_id = b.id AND report_date = CURDATE()) as today_cash,
            GROUP_CONCAT(DISTINCT p.provider_name ORDER BY p.provider_name SEPARATOR '||') as provider_names,
            GROUP_CONCAT(DISTINCT bp.provider_code ORDER BY p.provider_name SEPARATOR '||') as provider_codes
        FROM branches b
        LEFT JOIN employees e ON b.manager_id = e.id
        LEFT JOIN branch_providers bp ON b.id = bp.branch_id AND bp.is_active = 1
        LEFT JOIN providers p ON bp.provider_id = p.id AND p.is_active = 1
        LEFT JOIN employees emp ON b.id = emp.branch_id AND emp.is_active = 1
        WHERE b.is_active = 1";

// FILTER: If a branch is selected, show ONLY that branch
if ($selected_branch > 0) {
    $sql .= " AND b.id = :selected_branch";
}

$sql .= " GROUP BY b.id ORDER BY b.branch_name ASC";

$stmt = $db->prepare($sql);

if ($selected_branch > 0) {
    $stmt->bindValue(':selected_branch', $selected_branch, PDO::PARAM_INT);
}

$stmt->execute();
$branches = $stmt->fetchAll();

$sorted_branches = $branches;
$branch_count = count($sorted_branches);

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
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-store-alt"></i> Branches</h2>
                <span class="record-count"><?php echo $branch_count; ?> branch<?php echo $branch_count != 1 ? 'es' : ''; ?></span>
            </div>
            <div class="page-header-right">
                <a href="add.php" class="btn btn-add">
                    <i class="fas fa-plus-circle"></i> Add Branch
                </a>
            </div>
        </div>

        <!-- ============================================================
        PERSISTENT RED FILTER CARD - ALWAYS VISIBLE
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Filtered Branch' : 'Showing All Branches'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($filter_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($filter_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($filter_branch_code); ?></span>
                <?php endif; ?>
                <?php if ($selected_branch > 0 && !empty($filter_branch_location)): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($filter_branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-status-stats">
                <div class="status-stat-item">
                    <span class="status-stat-number"><?php echo $branch_count; ?></span>
                    <span class="status-stat-label">Branch<?php echo $branch_count != 1 ? 'es' : ''; ?></span>
                </div>
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
        BRANCH CARDS GRID
        ============================================================ -->
        <?php if (empty($sorted_branches)): ?>
            <div class="empty-state">
                <i class="fas fa-store-alt"></i>
                <h3>No Branches Found</h3>
                <?php if ($selected_branch > 0): ?>
                    <p>The selected branch could not be found or is inactive.</p>
                    <a href="index.php" class="btn btn-add-empty">
                        <i class="fas fa-globe-africa"></i> Show All Branches
                    </a>
                <?php else: ?>
                    <p>Start by adding your first branch.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Branch
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="branches-grid">
                <?php foreach ($sorted_branches as $branch_row): 
                    $is_active = $branch_row['is_active'] ?? 1;
                    $status_class = $is_active ? 'active' : 'inactive';
                    $status_text = $is_active ? 'Active' : 'Inactive';
                    $status_icon = $is_active ? 'fa-check-circle' : 'fa-times-circle';
                    
                    $provider_count = $branch_row['provider_count'] ?? 0;
                    $employee_count = $branch_row['employee_count'] ?? 0;
                    $today_float = $branch_row['today_float'] ?? 0;
                    $today_cash = $branch_row['today_cash'] ?? 0;
                    $total_capital = $today_float + $today_cash;
                    
                    $provider_names = isset($branch_row['provider_names']) && !empty($branch_row['provider_names']) 
                        ? explode('||', $branch_row['provider_names']) 
                        : [];
                ?>
                    <div class="branch-card <?php echo $status_class; ?>" data-branch-id="<?php echo $branch_row['id']; ?>">
                        <!-- Card Header -->
                        <div class="branch-card-header">
                            <div class="branch-card-title">
                                <h3><?php echo htmlspecialchars($branch_row['branch_name']); ?></h3>
                                <span class="branch-code"><?php echo htmlspecialchars($branch_row['branch_code']); ?></span>
                            </div>
                            <div class="branch-card-status">
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Branch Location -->
                        <?php if (!empty($branch_row['location'])): ?>
                            <div class="branch-card-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($branch_row['location']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Summary Stats -->
                        <div class="branch-card-stats">
                            <div class="stat-item stat-float">
                                <div class="stat-icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Float</span>
                                    <span class="stat-value"><?php echo formatCurrency($today_float); ?></span>
                                </div>
                            </div>
                            
                            <div class="stat-item stat-cash">
                                <div class="stat-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Cash</span>
                                    <span class="stat-value"><?php echo formatCurrency($today_cash); ?></span>
                                </div>
                            </div>
                            
                            <div class="stat-item stat-capital">
                                <div class="stat-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Capital</span>
                                    <span class="stat-value"><?php echo formatCurrency($total_capital); ?></span>
                                </div>
                            </div>
                            
                            <div class="stat-item stat-employees">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Employees</span>
                                    <span class="stat-value"><?php echo $employee_count; ?></span>
                                </div>
                            </div>
                            
                            <div class="stat-item stat-providers">
                                <div class="stat-icon">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Providers</span>
                                    <span class="stat-value"><?php echo $provider_count; ?></span>
                                    <?php if (!empty($provider_names)): ?>
                                        <span class="stat-provider-list" title="<?php echo htmlspecialchars(implode(', ', $provider_names)); ?>">
                                            <?php 
                                            $display = array_slice($provider_names, 0, 3);
                                            echo htmlspecialchars(implode(', ', $display));
                                            if (count($provider_names) > 3) {
                                                echo ' +' . (count($provider_names) - 3);
                                            }
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Card Actions -->
                        <div class="branch-card-actions">
                            <a href="view.php?id=<?php echo $branch_row['id']; ?>" class="btn-action btn-view">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="providers.php?branch_id=<?php echo $branch_row['id']; ?>" class="btn-action btn-providers" data-branch-id="<?php echo $branch_row['id']; ?>">
                                <i class="fas fa-university"></i> Providers
                            </a>
                            <a href="edit.php?id=<?php echo $branch_row['id']; ?>" class="btn-action btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="delete.php?id=<?php echo $branch_row['id']; ?>" class="btn-action btn-delete" onclick="return confirmDelete(<?php echo $branch_row['id']; ?>, '<?php echo addslashes($branch_row['branch_name']); ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
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
    --branches-bg: #f3f4f6;
    --branches-text: #1F2937;
    --branches-text-secondary: #6B7280;
    --branches-text-light: #9CA3AF;
    --branches-border: #E5E7EB;
    --branches-card-bg: #FFFFFF;
    --branches-card-shadow: rgba(0,0,0,0.08);
    --branches-card-shadow-hover: rgba(0,0,0,0.15);
    --branches-hover: #F3F4F6;
    --card-header-bg: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    --card-header-text: #FFFFFF;
}

html.dark-mode {
    --branches-bg: #0f172a;
    --branches-text: #F1F5F9;
    --branches-text-secondary: #94A3B8;
    --branches-text-light: #64748B;
    --branches-border: #334155;
    --branches-card-bg: #1E293B;
    --branches-card-shadow: rgba(0,0,0,0.3);
    --branches-card-shadow-hover: rgba(0,0,0,0.5);
    --branches-hover: #2D3A4F;
    --card-header-bg: linear-gradient(135deg, #DC2626 0%, #991B1B 100%);
    --card-header-text: #FFFFFF;
}

body {
    background: var(--branches-bg) !important;
    color: var(--branches-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--branches-bg) !important; }
.main-content { background: var(--branches-bg) !important; }

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
    font-size: 24px;
    font-weight: 700;
    color: var(--branches-text);
    margin: 0;
}

.page-header-left h2 i { color: #DC2626; margin-right: 8px; }

.record-count {
    font-size: 13px;
    color: var(--branches-text-secondary);
    background: var(--branches-hover);
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
   PERSISTENT RED BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 18px 24px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.3s ease forwards;
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

.branch-status-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
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
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
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

.branch-status-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    z-index: 1;
}

.status-stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.status-stat-number {
    font-size: 22px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
}

.status-stat-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
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
   BRANCHES GRID
   ============================================================ */
.branches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
}

.branch-card {
    background: var(--branches-card-bg);
    border-radius: 12px;
    border: 1px solid var(--branches-border);
    box-shadow: 0 2px 8px var(--branches-card-shadow);
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
}

.branch-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px var(--branches-card-shadow-hover);
}

.branch-card.inactive {
    opacity: 0.7;
}

.branch-card.inactive:hover {
    opacity: 0.85;
}

/* ============================================================
   CARD HEADER - RED BACKGROUND
   ============================================================ */
.branch-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: var(--card-header-bg);
    border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    position: relative;
    overflow: hidden;
}

.branch-card-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-card-header::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 10%;
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-card-title h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--card-header-text);
    margin: 0;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.branch-card-title .branch-code {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    background: rgba(255, 255, 255, 0.15);
    padding: 2px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-card-status {
    position: relative;
    z-index: 1;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.status-badge.active {
    background: rgba(16, 185, 129, 0.25);
    color: #FFFFFF;
    border-color: rgba(16, 185, 129, 0.3);
}

.status-badge.inactive {
    background: rgba(239, 68, 68, 0.25);
    color: #FFFFFF;
    border-color: rgba(239, 68, 68, 0.3);
}

.status-badge i {
    font-size: 12px;
}

/* ============================================================
   BRANCH LOCATION
   ============================================================ */
.branch-card-location {
    padding: 10px 20px 8px 20px;
    font-size: 13px;
    color: var(--branches-text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--branches-hover);
    border-bottom: 1px solid var(--branches-border);
}

.branch-card-location i {
    font-size: 13px;
    color: var(--branches-text-light);
}

/* ============================================================
   CARD STATS
   ============================================================ */
.branch-card-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    padding: 10px 16px 12px 16px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px;
    background: var(--branches-hover);
    border-radius: 6px;
    transition: all 0.2s ease;
}

.stat-item:hover {
    background: var(--branches-border);
}

.stat-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}

.stat-float .stat-icon {
    background: #DBEAFE;
    color: #1D4ED8;
}

.stat-cash .stat-icon {
    background: #D1FAE5;
    color: #065F46;
}

.stat-capital .stat-icon {
    background: #FEF3C7;
    color: #D97706;
}

.stat-employees .stat-icon {
    background: #E0E7FF;
    color: #4338CA;
}

.stat-providers .stat-icon {
    background: #FCE7F3;
    color: #BE185D;
}

html.dark-mode .stat-float .stat-icon {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .stat-cash .stat-icon {
    background: #065F46;
    color: #34D399;
}

html.dark-mode .stat-capital .stat-icon {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .stat-employees .stat-icon {
    background: #1E2D5F;
    color: #818CF8;
}

html.dark-mode .stat-providers .stat-icon {
    background: #5F1E3A;
    color: #F472B6;
}

.stat-info {
    flex: 1;
    min-width: 0;
}

.stat-label {
    display: block;
    font-size: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--branches-text-light);
}

.stat-value {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--branches-text);
    line-height: 1.2;
}

.stat-provider-list {
    display: block;
    font-size: 8px;
    color: var(--branches-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 80px;
}

/* ============================================================
   CARD ACTIONS
   ============================================================ */
.branch-card-actions {
    display: flex;
    gap: 6px;
    padding: 12px 20px 16px 20px;
    border-top: 1px solid var(--branches-border);
    flex-wrap: wrap;
    background: var(--branches-hover);
}

.btn-action {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.btn-action:hover {
    transform: translateY(-1px);
}

.btn-view {
    background: #DBEAFE;
    color: #1D4ED8;
}

.btn-view:hover {
    background: #BFDBFE;
}

.btn-providers {
    background: #FCE7F3;
    color: #BE185D;
}

.btn-providers:hover {
    background: #FBCFE8;
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

html.dark-mode .btn-view {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .btn-view:hover {
    background: #3B82F6;
    color: #FFFFFF;
}

html.dark-mode .btn-providers {
    background: #5F1E3A;
    color: #F472B6;
}

html.dark-mode .btn-providers:hover {
    background: #BE185D;
    color: #FFFFFF;
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

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: var(--branches-card-bg);
    border-radius: 12px;
    border: 1px solid var(--branches-border);
}

.empty-state i {
    font-size: 64px;
    color: #DC2626;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 22px;
    color: var(--branches-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--branches-text-secondary);
    font-size: 15px;
    margin: 0 0 24px 0;
}

.btn-add-empty {
    background: #DC2626;
    color: white;
    padding: 12px 32px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
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
@media (max-width: 1200px) {
    .branches-grid {
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
    }
    
    .branch-status-info {
        width: 100%;
    }
    
    .branch-status-stats {
        width: 100%;
        justify-content: center;
    }
    
    .branches-grid {
        grid-template-columns: 1fr;
    }
    
    .branch-card-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .branch-card-actions {
        justify-content: center;
    }
    
    .btn-action {
        flex: 1;
        justify-content: center;
        min-width: 80px;
    }
}

@media (max-width: 480px) {
    .branch-card-stats {
        grid-template-columns: 1fr;
    }
    
    .branch-card-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .branch-card-actions {
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
        justify-content: center;
    }
    
    .branch-status-name {
        font-size: 16px;
    }
    
    .branch-status-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.branch-card {
    animation: fadeInUp 0.4s ease forwards;
}

.branch-card:nth-child(1) { animation-delay: 0.05s; }
.branch-card:nth-child(2) { animation-delay: 0.10s; }
.branch-card:nth-child(3) { animation-delay: 0.15s; }
.branch-card:nth-child(4) { animation-delay: 0.20s; }
.branch-card:nth-child(5) { animation-delay: 0.25s; }
.branch-card:nth-child(6) { animation-delay: 0.30s; }
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(id, name) {
    return confirm('Are you sure you want to delete the branch "' + name + '"? This action cannot be undone.');
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
    
    // ============================================================
    // BRANCH SELECTOR SYNC (from admin_topbar)
    // ============================================================
    var branchSelect = document.getElementById('branchFilter');
    if (branchSelect) {
        var urlParams = new URLSearchParams(window.location.search);
        var branchParam = urlParams.get('branch_id') || urlParams.get('branch') || '0';
        var currentBranch = parseInt(branchParam) || 0;
        branchSelect.value = currentBranch;
    }
});
</script>
</body>
</html>