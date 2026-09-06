<?php
// ================================================================
// FILE: modules/providers/index.php
// WAKALA FINANCIAL SYSTEM - PROVIDERS LIST
// WITH BRANCH INDICATOR AND DARK MODE SUPPORT
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
// CHECK PERMISSION - Only admin and super_admin can access
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

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

// Get branch name
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// ============================================================
// GET PROVIDERS LIST WITH BRANCH FILTER
// ============================================================
$sql = "SELECT 
            p.id,
            p.provider_code,
            p.provider_name,
            p.provider_type,
            p.category,
            p.icon_class,
            p.color_code,
            p.display_order,
            p.is_active,
            p.is_default,
            p.requires_cash_balance,
            p.created_at,
            p.updated_at,
            p.notes,
            p.branch_id,
            e.full_name as created_by_name,
            b.branch_name as branch_name
        FROM providers p
        LEFT JOIN employees e ON p.created_by = e.id
        LEFT JOIN branches b ON p.branch_id = b.id
        WHERE 1=1";

$params = [];

// Filter by branch if selected
if ($selected_branch > 0) {
    $sql .= " AND (p.branch_id = ? OR p.branch_id IS NULL)";
    $params[] = $selected_branch;
}

$sql .= " ORDER BY p.display_order ASC, p.provider_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$providers = $stmt->fetchAll();

// Count providers
$provider_count = count($providers);

// Count active providers
$active_count = 0;
$bank_count = 0;
$mobile_count = 0;
foreach ($providers as $p) {
    if ($p['is_active'] == 1) {
        $active_count++;
    }
    if ($p['provider_type'] == 'bank') {
        $bank_count++;
    } elseif ($p['provider_type'] == 'mobile_money') {
        $mobile_count++;
    }
}
$inactive_count = $provider_count - $active_count;

// ============================================================
// HANDLE SUCCESS/ERROR MESSAGES
// ============================================================
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
                <div class="branch-provider-count">
                    <i class="fas fa-university"></i>
                    <span><?php echo $provider_count; ?> Providers</span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ===== PAGE HEADER WITH ADD BUTTON ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-university"></i> Providers</h2>
                <span class="record-count"><?php echo $provider_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Provider
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                            <i class="fas fa-download"></i> Export
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu" id="exportDropdown">
                            <a href="#" onclick="exportData('csv')">
                                <i class="fas fa-file-csv"></i> Export as CSV
                            </a>
                            <a href="#" onclick="exportData('excel')">
                                <i class="fas fa-file-excel"></i> Export as Excel
                            </a>
                            <a href="#" onclick="exportData('pdf')">
                                <i class="fas fa-file-pdf"></i> Export as PDF
                            </a>
                            <a href="#" onclick="exportData('print')">
                                <i class="fas fa-print"></i> Print
                            </a>
                        </div>
                    </div>
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
        SUMMARIES CARDS
        ============================================================ -->
        <div class="summaries-grid-four">
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-university"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL PROVIDERS</div>
                    <div class="summary-value"><?php echo number_format($provider_count); ?></div>
                    <div class="summary-sub">All Providers</div>
                </div>
            </div>

            <div class="summary-card card-active">
                <div class="summary-icon"><i class="fas fa-check-circle"></i></div>
                <div class="summary-content">
                    <div class="summary-label">ACTIVE</div>
                    <div class="summary-value"><?php echo number_format($active_count); ?></div>
                    <div class="summary-sub">Currently Active</div>
                </div>
            </div>

            <div class="summary-card card-bank">
                <div class="summary-icon"><i class="fas fa-landmark"></i></div>
                <div class="summary-content">
                    <div class="summary-label">BANKS</div>
                    <div class="summary-value"><?php echo number_format($bank_count); ?></div>
                    <div class="summary-sub">Bank Providers</div>
                </div>
            </div>

            <div class="summary-card card-mobile">
                <div class="summary-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">MOBILE MONEY</div>
                    <div class="summary-value"><?php echo number_format($mobile_count); ?></div>
                    <div class="summary-sub">Mobile Money</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - PROVIDERS LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Providers</h3>
                <div class="table-actions">
                    <select id="typeFilter" class="filter-select" onchange="filterByType(this.value)">
                        <option value="">All Types</option>
                        <option value="bank">Banks</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="other">Other</option>
                    </select>
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search providers..." class="search-input">
                </div>
            </div>

            <?php if (empty($providers)): ?>
                <div class="empty-state">
                    <i class="fas fa-university"></i>
                    <h3>No Providers Found</h3>
                    <p>No providers available for this branch. Start by adding your first provider.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Provider
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="providersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Code</th>
                                <th>Provider Name</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Default</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($providers as $provider): 
                                $is_active = $provider['is_active'] ?? 1;
                                $status = $is_active ? 'Active' : 'Inactive';
                                $status_class = $is_active ? 'status-active' : 'status-inactive';
                                $status_icon = $is_active ? 'fa-check-circle' : 'fa-times-circle';
                                
                                $type_label = ucwords(str_replace('_', ' ', $provider['provider_type']));
                                $type_class = $provider['provider_type'] == 'bank' ? 'type-bank' : 
                                             ($provider['provider_type'] == 'mobile_money' ? 'type-mobile' : 'type-other');
                                
                                $is_default = $provider['is_default'] ? 'Yes' : 'No';
                                $default_icon = $provider['is_default'] ? 'fa-star' : 'fa-star-o';
                                $default_color = $provider['is_default'] ? '#F59E0B' : '#9CA3AF';
                                
                                $provider_branch = $provider['branch_name'] ?? 'Global';
                            ?>
                                <tr data-status="<?php echo $is_active; ?>" data-type="<?php echo $provider['provider_type']; ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="provider-code" style="color: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                            <?php echo htmlspecialchars($provider['provider_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="provider-name">
                                            <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>" 
                                               style="color: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>; width:20px;"></i>
                                            <?php echo htmlspecialchars($provider['provider_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="type-badge <?php echo $type_class; ?>">
                                            <?php echo $type_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="provider-category">
                                            <?php echo htmlspecialchars($provider['category'] ?? '—'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($provider_branch); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="default-badge" style="color: <?php echo $default_color; ?>;">
                                            <i class="fas <?php echo $default_icon; ?>"></i>
                                            <?php echo $is_default; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $provider['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $provider['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $provider['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirmDelete(<?php echo $provider['id']; ?>, '<?php echo addslashes($provider['provider_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES - WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES - LIGHT & DARK MODE
   ============================================================ */
:root {
    --providers-bg: #f3f4f6;
    --providers-text: #1F2937;
    --providers-text-secondary: #6B7280;
    --providers-text-light: #9CA3AF;
    --providers-border: #E5E7EB;
    --providers-card-bg: #FFFFFF;
    --providers-card-header: #FAFBFC;
    --providers-input-bg: #F9FAFB;
    --providers-hover: #F3F4F6;
    --providers-shadow: rgba(0,0,0,0.06);
    --providers-shadow-lg: rgba(0,0,0,0.12);
    --providers-dropdown-bg: #FFFFFF;
    --providers-dropdown-border: #E5E7EB;
    --providers-scrollbar: #DC2626;
    --providers-scrollbar-track: #F3F4F6;
}

html.dark-mode {
    --providers-bg: #0f172a;
    --providers-text: #F1F5F9;
    --providers-text-secondary: #94A3B8;
    --providers-text-light: #64748B;
    --providers-border: #334155;
    --providers-card-bg: #1E293B;
    --providers-card-header: #2D3A4F;
    --providers-input-bg: #334155;
    --providers-hover: #2D3A4F;
    --providers-shadow: rgba(0,0,0,0.4);
    --providers-shadow-lg: rgba(0,0,0,0.6);
    --providers-dropdown-bg: #1E293B;
    --providers-dropdown-border: #334155;
    --providers-scrollbar: #DC2626;
    --providers-scrollbar-track: #1E293B;
}

/* ============================================================
   BASE STYLES
   ============================================================ */
body {
    background: var(--providers-bg) !important;
    color: var(--providers-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--providers-bg) !important;
}

.main-content {
    background: var(--providers-bg) !important;
}

/* Scrollbar */
.main-content::-webkit-scrollbar {
    width: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: var(--providers-scrollbar-track);
}

.main-content::-webkit-scrollbar-thumb {
    background: var(--providers-scrollbar);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-thumb:hover {
    background: #8B0000;
}

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

.branch-provider-count {
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

.branch-provider-count i {
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
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--providers-text-secondary);
    background: var(--providers-hover);
    padding: 2px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
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

.alert i {
    font-size: 20px;
    flex-shrink: 0;
}

.alert span {
    flex: 1;
}

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

.alert-close:hover {
    opacity: 1;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-add {
    background: #DC2626;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
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
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
    color: white;
}

.btn-add-empty {
    background: #DC2626;
    color: white;
    padding: 12px 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-add-empty:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.4);
    color: white;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.btn-export:hover {
    background: #1D4ED8;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}

.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle i.fa-chevron-down {
    font-size: 11px;
    margin-left: 2px;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--providers-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--providers-shadow-lg);
    border: 1px solid var(--providers-dropdown-border);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
    transition: all 0.3s ease;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    text-decoration: none;
    color: var(--providers-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--providers-hover);
}

.dropdown-menu a i.fa-file-csv { color: #0B5ED7; }
.dropdown-menu a i.fa-file-excel { color: #1D7D1D; }
.dropdown-menu a i.fa-file-pdf { color: #DC2626; }
.dropdown-menu a i.fa-print { color: #6B7280; }

/* ============================================================
   SUMMARIES GRID - 4 CARDS
   ============================================================ */
.summaries-grid-four {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--providers-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--providers-shadow);
    border: 1px solid var(--providers-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--providers-shadow-lg);
}

.summary-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--providers-text-secondary);
    transition: color 0.3s ease;
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--providers-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--providers-text-light);
    font-weight: 500;
    transition: color 0.3s ease;
}

.card-total .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total { border-left: 4px solid #3B82F6; }

.card-active .summary-icon { background: #D1FAE5; color: #065F46; }
.card-active { border-left: 4px solid #10B981; }

.card-bank .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-bank { border-left: 4px solid #3B82F6; }

.card-mobile .summary-icon { background: #FEF3C7; color: #D97706; }
.card-mobile { border-left: 4px solid #F59E0B; }

html.dark-mode .card-total .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-active .summary-icon { background: #065F46; color: #34D399; }
html.dark-mode .card-bank .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-mobile .summary-icon { background: #5F3A1E; color: #FBBF24; }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--providers-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--providers-shadow);
    border: 1px solid var(--providers-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--providers-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--providers-text);
    margin: 0;
    transition: color 0.3s ease;
}

.table-header h3 i {
    color: #3B82F6;
    margin-right: 8px;
}

.table-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--providers-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--providers-input-bg);
    color: var(--providers-text);
}

.search-input::placeholder {
    color: var(--providers-text-light);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--providers-border);
    font-size: 13px;
    outline: none;
    background: var(--providers-input-bg);
    color: var(--providers-text);
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select option {
    background: var(--providers-dropdown-bg);
    color: var(--providers-text);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--providers-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--providers-hover);
}

.data-table tbody td {
    padding: 12px 16px;
    color: var(--providers-text);
    transition: color 0.3s ease;
}

/* Provider Code */
.provider-code {
    font-weight: 700;
    font-size: 13px;
}

/* Provider Name */
.provider-name {
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--providers-text);
    transition: color 0.3s ease;
}

/* Type Badge */
.type-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.type-bank {
    background: #DBEAFE;
    color: #1D4ED8;
}

.type-mobile {
    background: #FEF3C7;
    color: #D97706;
}

.type-other {
    background: #F3F4F6;
    color: #6B7280;
}

html.dark-mode .type-bank {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .type-mobile {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .type-other {
    background: #374151;
    color: #9CA3AF;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-badge i {
    margin-right: 4px;
    font-size: 11px;
}

.status-active {
    background: #D1FAE5;
    color: #065F46;
}

.status-inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .status-active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-inactive {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* Default Badge */
.default-badge {
    font-weight: 500;
    font-size: 12px;
}

/* Provider Category */
.provider-category {
    color: var(--providers-text-secondary);
    font-size: 12px;
    transition: color 0.3s ease;
}

/* Branch Badge */
.branch-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    background: var(--providers-hover);
    color: var(--providers-text-secondary);
    transition: all 0.3s ease;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 6px;
}

.btn-action {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 13px;
}

.btn-view {
    background: #DBEAFE;
    color: #1D4ED8;
}

.btn-view:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
}

.btn-edit:hover {
    background: #A7F3D0;
    color: #047857;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
}

html.dark-mode .btn-view {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .btn-view:hover {
    background: #3B82F6;
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
    padding: 60px 20px;
}

.empty-state i {
    font-size: 60px;
    color: #3B82F6;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--providers-text);
    margin: 0 0 8px 0;
    transition: color 0.3s ease;
}

.empty-state p {
    color: var(--providers-text-secondary);
    font-size: 14px;
    margin: 0 0 24px 0;
    transition: color 0.3s ease;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summaries-grid-four {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    
    .header-actions .btn-add,
    .header-actions .btn-export {
        justify-content: center;
        width: 100%;
    }
    
    .dropdown {
        width: 100%;
    }
    
    .dropdown-menu {
        width: 100%;
        right: auto;
        left: 0;
    }
    
    .summaries-grid-four {
        grid-template-columns: 1fr 1fr;
    }
    
    .table-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .table-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .search-input {
        width: 100%;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .summary-card {
        min-height: 100px;
        height: 100px;
        padding: 14px 16px;
    }
    
    .summary-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .summary-value {
        font-size: 19px;
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
    
    .branch-info {
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
    .summaries-grid-four {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .summary-card {
        padding: 12px 14px;
        min-height: 90px;
        height: 90px;
    }
    
    .summary-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .summary-value {
        font-size: 16px;
    }
    
    .summary-label {
        font-size: 9px;
    }
    
    .summary-sub {
        font-size: 9px;
    }
    
    .data-table thead th,
    .data-table tbody td {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }
    
    .btn-action {
        width: 28px;
        height: 28px;
        font-size: 11px;
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
    
    .branch-provider-count {
        font-size: 11px;
        padding: 3px 10px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.summary-card {
    animation: fadeInUp 0.4s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.05s; }
.summary-card:nth-child(2) { animation-delay: 0.10s; }
.summary-card:nth-child(3) { animation-delay: 0.15s; }
.summary-card:nth-child(4) { animation-delay: 0.20s; }

.table-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.25s;
}

.branch-indicator {
    animation: fadeInUp 0.3s ease forwards;
}
</style>

<script>
// ============================================================
// DROPDOWN TOGGLE
// ============================================================
function toggleDropdown() {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(event) {
    var dropdown = document.getElementById('exportDropdown');
    var button = document.querySelector('.dropdown-toggle');
    if (button && !button.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// ============================================================
// EXPORT FUNCTIONS
// ============================================================
function exportData(format) {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.remove('show');
    
    var table = document.getElementById('providersTable');
    if (!table) {
        alert('No data to export!');
        return;
    }
    
    var rows = table.querySelectorAll('tbody tr');
    var headers = [];
    var headerCells = table.querySelectorAll('thead th');
    
    for (var i = 0; i < headerCells.length - 1; i++) {
        headers.push(headerCells[i].textContent.trim());
    }
    
    var data = [];
    rows.forEach(function(row) {
        var rowData = [];
        var cells = row.querySelectorAll('td');
        for (var i = 0; i < cells.length - 1; i++) {
            rowData.push(cells[i].textContent.trim());
        }
        data.push(rowData);
    });
    
    if (data.length === 0) {
        alert('No data to export!');
        return;
    }
    
    if (format === 'csv') {
        exportCSV(headers, data);
    } else if (format === 'excel') {
        exportExcel(headers, data);
    } else if (format === 'pdf') {
        exportPDF(headers, data);
    } else if (format === 'print') {
        window.print();
    }
}

function exportCSV(headers, data) {
    var csv = headers.join(',') + '\n';
    data.forEach(function(row) {
        csv += row.join(',') + '\n';
    });
    
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'providers_export_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function exportExcel(headers, data) {
    var html = '<html><head><meta charset="UTF-8"><title>Providers Export</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    html += 'h1 { color: #3B82F6; }';
    html += 'table { width: 100%; border-collapse: collapse; }';
    html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
    html += '</style>';
    html += '</head><body>';
    html += '<h1>Providers Report</h1>';
    html += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
    html += '<table>';
    html += '<thead><tr>';
    headers.forEach(function(h) {
        html += '<th>' + h + '</th>';
    });
    html += '</tr></thead><tbody>';
    
    data.forEach(function(row) {
        html += '<tr>';
        row.forEach(function(cell) {
            html += '<td>' + cell + '</td>';
        });
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    html += '</body></html>';
    
    var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'providers_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function exportPDF(headers, data) {
    var printContent = '<html><head><title>Providers Export</title>';
    printContent += '<style>';
    printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    printContent += 'h1 { color: #3B82F6; }';
    printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    printContent += '</style>';
    printContent += '</head><body>';
    printContent += '<h1>Providers Report</h1>';
    printContent += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
    printContent += '<table>';
    printContent += '<thead><tr>';
    headers.forEach(function(h) {
        printContent += '<th>' + h + '</th>';
    });
    printContent += '</tr></thead><tbody>';
    
    data.forEach(function(row) {
        printContent += '<tr>';
        row.forEach(function(cell) {
            printContent += '<td>' + cell + '</td>';
        });
        printContent += '</tr>';
    });
    
    printContent += '</tbody></table>';
    printContent += '</body></html>';
    
    var printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// ============================================================
// FILTER FUNCTIONS
// ============================================================
function filterByStatus(status) {
    var rows = document.querySelectorAll('#providersTable tbody tr');
    rows.forEach(function(row) {
        var rowStatus = row.getAttribute('data-status');
        if (status === '' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterByType(type) {
    var rows = document.querySelectorAll('#providersTable tbody tr');
    rows.forEach(function(row) {
        var rowType = row.getAttribute('data-type');
        if (type === '' || rowType === type) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function confirmDelete(id, name) {
    return confirm('Are you sure you want to delete the provider "' + name + '"? This action cannot be undone.');
}

// ============================================================
// SEARCH FUNCTIONALITY & DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#providersTable tbody tr');
            
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                if (text.indexOf(filter) > -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
    
    // Sync dark mode with header button
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
    
    // Listen for dark mode changes from header
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