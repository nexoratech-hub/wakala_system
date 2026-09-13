<?php
// ================================================================
// FILE: modules/branches/index.php
// WAKALA FINANCIAL SYSTEM - BRANCHES AS CARDS
// RED THEME + COMPACT SEARCH + < > SCROLL + MODERN CARDS
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

$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

unset($_SESSION['selected_branch']);

// Search
$search = trim($_GET['search'] ?? '');

// ============================================================
// BRANCH NAME FOR DISPLAY
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
        $selected_branch = 0;
        $filter_branch_name = 'All Branches';
    }
}

// ============================================================
// FETCH BRANCHES
// ============================================================
$sql = "SELECT 
            b.id, b.branch_code, b.branch_name, b.location, b.phone, b.email,
            b.manager_id, b.is_active, b.created_at, b.updated_at,
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

$params = [];

if ($selected_branch > 0) {
    $sql .= " AND b.id = ?";
    $params[] = $selected_branch;
}

if ($search !== '') {
    $sql .= " AND (b.branch_name LIKE ? OR b.branch_code LIKE ? OR b.location LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " GROUP BY b.id ORDER BY b.branch_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$branches = $stmt->fetchAll();

$sorted_branches = $branches;
$branch_count = count($sorted_branches);

// Stats
$total_active   = 0;
$total_inactive = 0;
$total_employees = 0;
$total_providers = 0;
foreach ($sorted_branches as $b) {
    if (intval($b['is_active'] ?? 1) === 1) $total_active++; else $total_inactive++;
    $total_employees += intval($b['employee_count'] ?? 0);
    $total_providers += intval($b['provider_count'] ?? 0);
}

// Flash
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

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-store-alt"></i> Branches</h2>
                <p class="text-muted"><?php echo $branch_count; ?> branch<?php echo $branch_count !== 1 ? 'es' : ''; ?> found</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-add">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Branch</span>
                </a>
            </div>
        </div>

        <!-- FILTER BRANCH CARD (persistent) -->
        <div class="filter-card">
            <div class="filter-card-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="filter-card-info">
                <span class="filter-card-label">
                    <?php echo $selected_branch > 0 ? 'Filtered Branch' : 'Showing All Branches'; ?>
                </span>
                <div class="filter-card-title-row">
                    <span class="filter-card-name"><?php echo htmlspecialchars($filter_branch_name); ?></span>
                    <?php if ($selected_branch > 0 && !empty($filter_branch_code)): ?>
                        <span class="filter-card-code"><?php echo htmlspecialchars($filter_branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($selected_branch > 0 && !empty($filter_branch_location)): ?>
                    <span class="filter-card-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($filter_branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="filter-card-actions">
                <?php if ($selected_branch > 0): ?>
                    <a href="index.php" class="btn-filter-clear">
                        <i class="fas fa-times"></i>
                        <span>Show All</span>
                    </a>
                <?php endif; ?>
                <div class="filter-card-stat">
                    <span class="filter-card-stat-num"><?php echo $branch_count; ?></span>
                    <span class="filter-card-stat-label">Branch<?php echo $branch_count !== 1 ? 'es' : ''; ?></span>
                </div>
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

        <!-- STATS CARDS (compact) -->
        <div class="stats-grid">
            <div class="stat-card stat-red">
                <div class="stat-card-icon"><i class="fas fa-store-alt"></i></div>
                <div class="stat-card-info">
                    <span class="stat-card-label">Total</span>
                    <span class="stat-card-value"><?php echo number_format($branch_count); ?></span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-card-info">
                    <span class="stat-card-label">Active</span>
                    <span class="stat-card-value"><?php echo number_format($total_active); ?></span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                <div class="stat-card-info">
                    <span class="stat-card-label">Employees</span>
                    <span class="stat-card-value"><?php echo number_format($total_employees); ?></span>
                </div>
            </div>
            <div class="stat-card stat-purple">
                <div class="stat-card-icon"><i class="fas fa-university"></i></div>
                <div class="stat-card-info">
                    <span class="stat-card-label">Providers</span>
                    <span class="stat-card-value"><?php echo number_format($total_providers); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        RED HEADER BAR (search + < > scroll)
        ============================================================ -->
        <div class="branches-toolbar">
            <div class="branches-toolbar-inner">

                <!-- LEFT: Compact Search -->
                <div class="toolbar-search">
                    <i class="fas fa-search toolbar-search-icon"></i>
                    <input type="text"
                           id="branchSearch"
                           class="toolbar-search-input"
                           placeholder="Search branch..."
                           value="<?php echo htmlspecialchars($search); ?>"
                           oninput="onBranchSearch(this)">
                    <button type="button" class="toolbar-search-clear"
                            id="branchSearchClear"
                            onclick="clearBranchSearch()"
                            style="<?php echo $search !== '' ? '' : 'display:none;'; ?>">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- CENTER: < > scroll -->
                <div class="toolbar-scroll-center">
                    <button type="button" class="toolbar-scroll-btn" onclick="scrollBranches('left')" title="Scroll Left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="toolbar-scroll-label">
                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                    </span>
                    <button type="button" class="toolbar-scroll-btn" onclick="scrollBranches('right')" title="Scroll Right">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <!-- RIGHT: Count -->
                <div class="toolbar-count">
                    <i class="fas fa-store-alt"></i>
                    <strong><?php echo $branch_count; ?></strong>
                </div>

            </div>
        </div>

        <!-- ============================================================
        BRANCHES GRID
        ============================================================ -->
        <?php if (empty($sorted_branches)): ?>
            <div class="empty-state">
                <i class="fas fa-store-slash"></i>
                <h3>No Branches Found</h3>
                <?php if ($selected_branch > 0): ?>
                    <p>The selected branch could not be found or is inactive.</p>
                    <a href="index.php" class="btn-add-empty">
                        <i class="fas fa-globe-africa"></i> Show All Branches
                    </a>
                <?php else: ?>
                    <p>Start by adding your first branch.</p>
                    <a href="add.php" class="btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Branch
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="branches-wrapper" id="branchesWrapper">
                <div class="branches-grid" id="branchesGrid">
                    <?php foreach ($sorted_branches as $branch_row):
                        $is_active = $branch_row['is_active'] ?? 1;
                        $status_class = $is_active ? 'active' : 'inactive';
                        $status_text = $is_active ? 'Active' : 'Inactive';
                        $status_icon = $is_active ? 'fa-check-circle' : 'fa-times-circle';

                        $provider_count = intval($branch_row['provider_count'] ?? 0);
                        $employee_count = intval($branch_row['employee_count'] ?? 0);
                        $today_float = floatval($branch_row['today_float'] ?? 0);
                        $today_cash  = floatval($branch_row['today_cash'] ?? 0);
                        $total_capital = $today_float + $today_cash;

                        $provider_names = !empty($branch_row['provider_names'])
                            ? explode('||', $branch_row['provider_names'])
                            : [];

                        $search_data = strtolower(
                            ($branch_row['branch_name'] ?? '') . ' ' .
                            ($branch_row['branch_code'] ?? '') . ' ' .
                            ($branch_row['location'] ?? '')
                        );
                    ?>
                    <div class="branch-card <?php echo $status_class; ?>"
                         data-search="<?php echo htmlspecialchars($search_data); ?>">

                        <!-- Card Header (red) -->
                        <div class="branch-card-header">
                            <div class="branch-card-header-left">
                                <div class="branch-card-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="branch-card-header-text">
                                    <h3><?php echo htmlspecialchars($branch_row['branch_name']); ?></h3>
                                    <div class="branch-card-meta">
                                        <?php if (!empty($branch_row['branch_code'])): ?>
                                            <span class="branch-code-chip"><?php echo htmlspecialchars($branch_row['branch_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="status-pill <?php echo $status_class; ?>">
                                <i class="fas <?php echo $status_icon; ?>"></i>
                                <?php echo $status_text; ?>
                            </span>
                        </div>

                        <!-- Location strip -->
                        <?php if (!empty($branch_row['location'])): ?>
                        <div class="branch-card-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo htmlspecialchars($branch_row['location']); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Stats mini grid -->
                        <div class="branch-card-stats">
                            <div class="mini-stat">
                                <div class="mini-stat-icon icon-blue">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <div class="mini-stat-info">
                                    <span class="mini-stat-label">Float</span>
                                    <span class="mini-stat-value"><?php echo formatCurrency($today_float); ?></span>
                                </div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-icon icon-green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="mini-stat-info">
                                    <span class="mini-stat-label">Cash</span>
                                    <span class="mini-stat-value"><?php echo formatCurrency($today_cash); ?></span>
                                </div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-icon icon-purple">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="mini-stat-info">
                                    <span class="mini-stat-label">Capital</span>
                                    <span class="mini-stat-value"><?php echo formatCurrency($total_capital); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Counts row: Employees + Providers -->
                        <div class="branch-card-counts">
                            <div class="count-box count-emp">
                                <i class="fas fa-users"></i>
                                <span class="count-num"><?php echo number_format($employee_count); ?></span>
                                <span class="count-label">Employee<?php echo $employee_count !== 1 ? 's' : ''; ?></span>
                            </div>
                            <div class="count-box count-prov">
                                <i class="fas fa-university"></i>
                                <span class="count-num"><?php echo number_format($provider_count); ?></span>
                                <span class="count-label">Provider<?php echo $provider_count !== 1 ? 's' : ''; ?></span>
                            </div>
                        </div>

                        <!-- Provider chips (max 3) -->
                        <?php if (!empty($provider_names)): ?>
                        <div class="branch-card-chips">
                            <?php 
                            $shown = array_slice($provider_names, 0, 3);
                            foreach ($shown as $pname): ?>
                                <span class="provider-chip">
                                    <i class="fas fa-circle"></i>
                                    <?php echo htmlspecialchars($pname); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($provider_names) > 3): ?>
                                <span class="provider-chip provider-chip-more">
                                    +<?php echo count($provider_names) - 3; ?> more
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Footer actions -->
                        <div class="branch-card-footer">
                            <a href="view.php?id=<?php echo $branch_row['id']; ?>" class="btn-card-action action-view">
                                <i class="fas fa-eye"></i>
                                <span>View</span>
                            </a>
                            <a href="providers.php?branch_id=<?php echo $branch_row['id']; ?>" class="btn-card-action action-providers">
                                <i class="fas fa-university"></i>
                                <span>Providers</span>
                            </a>
                            <a href="edit.php?id=<?php echo $branch_row['id']; ?>" class="btn-card-action action-edit">
                                <i class="fas fa-edit"></i>
                                <span>Edit</span>
                            </a>
                            <a href="delete.php?id=<?php echo $branch_row['id']; ?>"
                               class="btn-card-action action-delete"
                               onclick="return confirmDelete(<?php echo $branch_row['id']; ?>, '<?php echo addslashes($branch_row['branch_name']); ?>')">
                                <i class="fas fa-trash"></i>
                                <span>Delete</span>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- No search results -->
                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No branches match your search</p>
                    <button type="button" class="btn-clear-search" onclick="clearBranchSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>
        <?php endif; ?>

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
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }
.header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn-add {
    padding: 11px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    display: inline-flex; align-items: center; gap: 8px;
    cursor: pointer; text-decoration: none;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}

/* FILTER CARD */
.filter-card {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 14px;
    position: relative; overflow: hidden;
}
.filter-card::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.filter-card-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF; flex-shrink: 0;
    border: 1px solid rgba(255, 255, 255, 0.15);
    position: relative; z-index: 1;
}
.filter-card-info {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1; position: relative; z-index: 1;
}
.filter-card-label {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.filter-card-title-row {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.filter-card-name {
    font-size: 18px; font-weight: 800;
    color: #FFFFFF;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.filter-card-code {
    font-size: 11px; font-weight: 700;
    color: rgba(255, 255, 255, 0.9);
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
}
.filter-card-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.85);
}
.filter-card-actions {
    display: flex; align-items: center; gap: 10px;
    position: relative; z-index: 1;
}
.btn-filter-clear {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
}
.btn-filter-clear:hover {
    background: rgba(255, 255, 255, 0.3);
    color: #FFFFFF;
    transform: translateY(-1px);
}
.filter-card-stat {
    display: flex; flex-direction: column; align-items: center;
    padding: 8px 18px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.filter-card-stat-num {
    font-size: 20px; font-weight: 800;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    line-height: 1.1;
}
.filter-card-stat-label {
    font-size: 9px; font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase; letter-spacing: 0.5px;
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin-bottom: 16px;
}
.stat-card {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.25s ease;
    min-width: 0;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: var(--red-primary);
}
.stat-card-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0; color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(0,0,0,0.12);
}
.stat-red    .stat-card-icon { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.stat-green  .stat-card-icon { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.stat-blue   .stat-card-icon { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.stat-purple .stat-card-icon { background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%); }
.stat-card-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.stat-card-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--text-muted);
}
.stat-card-value {
    font-size: 20px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    line-height: 1.15;
    word-break: break-all;
}

/* ============================================================
   RED TOOLBAR (search + < > scroll)
   ============================================================ */
.branches-toolbar {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px 12px 0 0;
    padding: 12px 18px;
    position: relative;
    overflow: hidden;
}
.branches-toolbar::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.07);
    border-radius: 50%; pointer-events: none;
}
.branches-toolbar-inner {
    display: flex; align-items: center;
    gap: 12px; flex-wrap: wrap;
    position: relative; z-index: 1;
    justify-content: space-between;
}

/* LEFT: Compact Search */
.toolbar-search {
    position: relative;
    display: flex; align-items: center;
    flex: 0 1 240px;
    max-width: 240px; min-width: 180px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 8px;
    padding: 0 10px;
    height: 36px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.2s ease;
    border: 2px solid transparent;
}
.toolbar-search:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 3px 14px rgba(252, 211, 77, 0.5);
}
html.dark-mode .toolbar-search { background: rgba(30, 41, 59, 0.98); }
.toolbar-search-icon {
    color: #DC2626;
    font-size: 12px;
    flex-shrink: 0;
    margin-right: 8px;
}
.toolbar-search-input {
    flex: 1; border: none; background: transparent;
    padding: 0; outline: none;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: #1f2937; min-width: 0;
}
html.dark-mode .toolbar-search-input { color: #f1f5f9; }
.toolbar-search-input::placeholder { color: #9ca3af; font-size: 11px; }
.toolbar-search-clear {
    width: 18px; height: 18px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px; transition: all 0.2s ease;
    flex-shrink: 0; margin-left: 6px;
}
.toolbar-search-clear:hover { background: #DC2626; color: #FFFFFF; transform: scale(1.1); }

/* CENTER: < > scroll */
.toolbar-scroll-center {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; flex: 1; min-width: 0; padding: 0 8px;
}
.toolbar-scroll-btn {
    width: 34px; height: 34px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF; color: #DC2626;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0; padding: 0; line-height: 1;
}
.toolbar-scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.toolbar-scroll-btn i { font-size: 12px; display: block; line-height: 1; }
.toolbar-scroll-label {
    font-size: 10px; font-weight: 800;
    color: #FCD34D; text-transform: uppercase;
    letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.toolbar-scroll-label i { font-size: 10px; color: #FCD34D; }

/* RIGHT: Count */
.toolbar-count {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border-radius: 10px;
    font-size: 12px; font-weight: 700;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    white-space: nowrap;
    flex-shrink: 0;
}
.toolbar-count i { color: #FCD34D; font-size: 12px; }
.toolbar-count strong { font-size: 14px; font-weight: 900; }

/* BRANCHES WRAPPER */
.branches-wrapper {
    background: var(--bg-card);
    border-radius: 0 0 14px 14px;
    border: 1.5px solid var(--border-color);
    border-top: none;
    padding: 20px;
    overflow-x: auto;
    scroll-behavior: smooth;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.branches-wrapper::-webkit-scrollbar { height: 8px; }
.branches-wrapper::-webkit-scrollbar-track { background: var(--bg-input); border-radius: 4px; }
.branches-wrapper::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.branches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
    min-width: min-content;
}

/* BRANCH CARD */
.branch-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.branch-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
    border-color: #FCA5A5;
}
.branch-card.inactive { opacity: 0.88; }
.branch-card.hidden-by-search { display: none !important; }

/* Card header (red gradient) */
.branch-card-header {
    display: flex; justify-content: space-between;
    align-items: flex-start;
    padding: 14px 16px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    gap: 10px;
    position: relative; overflow: hidden;
}
.branch-card-header::before {
    content: ''; position: absolute;
    top: -40%; right: -15%;
    width: 140px; height: 140px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.branch-inactive .branch-card-header {
    background: linear-gradient(135deg, #6B7280 0%, #4B5563 100%);
}
.branch-card-header-left {
    display: flex; align-items: center; gap: 10px;
    min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-card-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: #FFFFFF;
    flex-shrink: 0;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.branch-card-header-text { min-width: 0; flex: 1; }
.branch-card-header h3 {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF;
    margin: 0 0 4px 0;
    line-height: 1.2;
    word-break: break-word;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.branch-card-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.branch-code-chip {
    font-size: 10px; font-weight: 800;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 8px; border-radius: 6px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.4px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 9.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
    flex-shrink: 0;
    position: relative; z-index: 1;
}
.status-pill.active {
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.status-pill.inactive {
    background: rgba(0, 0, 0, 0.2);
    color: #FCA5A5;
    border: 1px solid rgba(0, 0, 0, 0.15);
}
.status-pill i { font-size: 8px; }

/* Location strip */
.branch-card-location {
    padding: 8px 16px;
    font-size: 12px;
    color: var(--text-secondary);
    display: flex; align-items: center; gap: 6px;
    background: var(--bg-input);
    border-bottom: 1px solid var(--border-color);
}
.branch-card-location i {
    color: #DC2626;
    font-size: 11px;
    flex-shrink: 0;
}
.branch-card-location span {
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; min-width: 0;
}

/* Stats mini grid */
.branch-card-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 12px 14px;
}
.mini-stat {
    display: flex; flex-direction: column;
    align-items: center; gap: 4px;
    padding: 8px 6px;
    background: var(--bg-input);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
    min-width: 0;
    text-align: center;
}
.mini-stat:hover {
    border-color: #FCA5A5;
    background: var(--bg-card);
}
.mini-stat-icon {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; flex-shrink: 0;
}
.mini-stat-icon.icon-blue {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #1D4ED8;
}
.mini-stat-icon.icon-green {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669;
}
.mini-stat-icon.icon-purple {
    background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%);
    color: #7C3AED;
}
html.dark-mode .mini-stat-icon.icon-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .mini-stat-icon.icon-green { background: #065F46; color: #34D399; }
html.dark-mode .mini-stat-icon.icon-purple { background: #4C1D95; color: #DDD6FE; }
.mini-stat-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; width: 100%; }
.mini-stat-label {
    font-size: 8.5px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.4px;
}
.mini-stat-value {
    font-size: 11px; font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

/* Counts row */
.branch-card-counts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    padding: 0 14px 12px 14px;
}
.count-box {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid;
    font-size: 11px;
    min-width: 0;
}
.count-emp {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-color: #93C5FD;
    color: #1E40AF;
}
.count-prov {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #FCA5A5;
    color: #991B1B;
}
html.dark-mode .count-emp {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
    color: #93C5FD;
}
html.dark-mode .count-prov {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626;
    color: #FCA5A5;
}
.count-box i { font-size: 12px; flex-shrink: 0; }
.count-num {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: 14px;
}
.count-label {
    font-size: 10px;
    font-weight: 700;
    opacity: 0.9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Provider chips */
.branch-card-chips {
    display: flex; flex-wrap: wrap; gap: 5px;
    padding: 0 14px 12px 14px;
}
.provider-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    white-space: nowrap;
    max-width: 130px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.provider-chip i {
    font-size: 6px;
    color: #DC2626;
    flex-shrink: 0;
}
.provider-chip-more {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #FCA5A5;
    color: #DC2626;
    font-weight: 800;
}
html.dark-mode .provider-chip-more {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626;
    color: #FCA5A5;
}

/* Footer actions */
.branch-card-footer {
    display: flex; gap: 6px;
    padding: 12px 14px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
    margin-top: auto;
}
html.dark-mode .branch-card-footer { background: #0f172a; }

.btn-card-action {
    flex: 1;
    height: 36px;
    border-radius: 8px;
    border: 1.5px solid;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
    padding: 0 6px;
    gap: 5px;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-card-action span { display: inline; }

.action-view {
    background: #DBEAFE; color: #1D4ED8; border-color: #BFDBFE;
}
.action-view:hover {
    background: #1D4ED8; color: #FFFFFF;
    border-color: #1D4ED8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.35);
}
.action-providers {
    background: #FCE7F3; color: #BE185D; border-color: #FBCFE8;
}
.action-providers:hover {
    background: #BE185D; color: #FFFFFF;
    border-color: #BE185D;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(190, 24, 93, 0.35);
}
.action-edit {
    background: #FEF3C7; color: #D97706; border-color: #FDE68A;
}
.action-edit:hover {
    background: #D97706; color: #FFFFFF;
    border-color: #D97706;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.35);
}
.action-delete {
    background: #FEE2E2; color: #DC2626; border-color: #FECACA;
}
.action-delete:hover {
    background: #DC2626; color: #FFFFFF;
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
}
html.dark-mode .action-view { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .action-providers { background: #5F1E3A; color: #F472B6; border-color: #BE185D; }
html.dark-mode .action-edit { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .action-delete { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

/* NO RESULTS */
.no-results {
    padding: 50px 20px;
    text-align: center;
    background: var(--bg-input);
    border-radius: 12px;
    margin-top: 16px;
}
.no-results i {
    font-size: 48px;
    color: var(--text-light);
    opacity: 0.4;
    margin-bottom: 12px;
    display: block;
}
.no-results p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 16px 0;
}
.btn-clear-search {
    padding: 10px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.btn-clear-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
}

/* EMPTY STATE */
.empty-state {
    padding: 80px 20px;
    text-align: center;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
}
.empty-state i {
    font-size: 64px;
    color: #FCA5A5;
    opacity: 0.5;
    margin-bottom: 20px;
    display: block;
}
.empty-state h3 {
    font-size: 20px; font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 10px 0;
}
.empty-state p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 24px 0;
}
.btn-add-empty {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 26px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
    transition: all 0.3s ease;
}
.btn-add-empty:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    .toolbar-search { flex: 1 1 100%; max-width: 100%; min-width: 0; }
    .toolbar-scroll-center {
        width: 100%; justify-content: center; order: 3;
    }
    .toolbar-count { width: 100%; justify-content: center; order: 2; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn-add { width: 100%; justify-content: center; }

    .filter-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px 18px;
    }
    .filter-card-actions { width: 100%; }
    .filter-card-stat { width: 100%; }

    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }

    .branches-wrapper { padding: 14px; }
    .branches-grid { grid-template-columns: 1fr; }

    .branch-card-stats { grid-template-columns: 1fr 1fr 1fr; }
    .branch-card-footer { flex-wrap: wrap; }
    .btn-card-action { min-width: 60px; }
    .btn-card-action span { display: none; }
    .btn-card-action i { font-size: 13px; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .branch-card-counts { grid-template-columns: 1fr; }
    .branch-card-header { flex-direction: column; align-items: flex-start; }
    .status-pill { align-self: flex-start; }
    .branch-card-stats { grid-template-columns: 1fr; }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(id, name) {
    return confirm('Delete branch "' + name + '"?\n\nThis action cannot be undone.');
}

// ============================================================
// BRANCH SEARCH (client-side)
// ============================================================
function onBranchSearch(input) {
    var term = input.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.branch-card');
    var clearBtn = document.getElementById('branchSearchClear');
    var noResults = document.getElementById('noResults');
    var grid = document.getElementById('branchesGrid');

    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';

    if (term === '') {
        cards.forEach(function(c) { c.classList.remove('hidden-by-search'); });
        if (noResults) noResults.style.display = 'none';
        if (grid) grid.style.display = '';
        return;
    }

    var matches = 0;
    cards.forEach(function(card) {
        var data = card.getAttribute('data-search') || '';
        if (data.includes(term)) {
            card.classList.remove('hidden-by-search');
            matches++;
        } else {
            card.classList.add('hidden-by-search');
        }
    });

    if (noResults) noResults.style.display = matches === 0 ? 'block' : 'none';
    if (grid) grid.style.display = matches === 0 ? 'none' : '';
}

function clearBranchSearch() {
    var input = document.getElementById('branchSearch');
    if (!input) return;
    input.value = '';
    onBranchSearch(input);
    input.focus();
}

// ============================================================
// SCROLL < >
// ============================================================
function scrollBranches(direction) {
    var wrapper = document.getElementById('branchesWrapper');
    if (!wrapper) return;
    var scrollAmount = 380;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// DARK MODE + BRANCH SELECTOR SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true' || localStorage.getItem('darkMode') === 'enabled';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function() { syncDarkMode(); });

    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 6000);
    }

    // Sync topbar branch selector
    var branchSelect = document.getElementById('branchFilter');
    if (branchSelect) {
        var urlParams = new URLSearchParams(window.location.search);
        var branchParam = urlParams.get('branch_id') || urlParams.get('branch') || '0';
        branchSelect.value = parseInt(branchParam) || 0;
    }

    // Show clear button on load if search present
    var searchInput = document.getElementById('branchSearch');
    if (searchInput && searchInput.value.trim() !== '') {
        var clearBtn = document.getElementById('branchSearchClear');
        if (clearBtn) clearBtn.style.display = 'flex';
    }
});
</script>
</body>
</html>