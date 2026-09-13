<?php
// ================================================================
// FILE: modules/providers/index.php
// WAKALA FINANCIAL SYSTEM - PROVIDERS LIST
// RED THEME + SEARCH + SCROLL + CARDS GRID
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// FILTERS
// ============================================================
$selected_branch = intval($_GET['branch_id'] ?? 0);
$search          = trim($_GET['search'] ?? '');
$type_filter     = trim($_GET['type'] ?? '');
$status_filter   = trim($_GET['status'] ?? '');

// ============================================================
// LOAD BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-select if only 1 branch
if ($selected_branch === 0 && count($branches) === 1) {
    $selected_branch = intval($branches[0]['id']);
}

// ============================================================
// BRANCH INFO
// ============================================================
$branch_name  = 'All Branches';
$branch_code  = '';
$branch_location = '';

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name     = $branch['branch_name'];
        $branch_code     = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// ============================================================
// BUILD QUERY FOR PROVIDERS
// ============================================================
$where  = " WHERE p.is_active = 1 ";
$params = [];

if ($selected_branch > 0) {
    $where .= " AND EXISTS (SELECT 1 FROM branch_providers bp WHERE bp.branch_id = ? AND bp.provider_id = p.id AND bp.is_active = 1)";
    $params[] = $selected_branch;
}

if ($search !== '') {
    $where .= " AND (p.provider_name LIKE ? OR p.provider_code LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($type_filter !== '' && in_array($type_filter, ['bank', 'mobile_money', 'other'])) {
    $where .= " AND p.provider_type = ?";
    $params[] = $type_filter;
}

// ============================================================
// FETCH PROVIDERS
// ============================================================
$providers = [];
try {
    $sql = "
        SELECT p.*,
               (SELECT COUNT(*) FROM branch_providers bp WHERE bp.provider_id = p.id AND bp.is_active = 1) AS branch_count
        FROM providers p
        $where
        ORDER BY p.display_order ASC, p.provider_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback query without branch_count
    try {
        $sql = "
            SELECT p.*, 0 AS branch_count
            FROM providers p
            $where
            ORDER BY p.provider_name ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $providers = [];
    }
}

// ============================================================
// STATS
// ============================================================
$total_providers = count($providers);
$total_banks = 0;
$total_mobile = 0;
$total_assigned = 0;

foreach ($providers as $p) {
    $type = $p['provider_type'] ?? 'bank';
    if ($type === 'mobile_money') $total_mobile++;
    elseif ($type === 'bank')     $total_banks++;
    if (intval($p['branch_count'] ?? 0) > 0) $total_assigned++;
}

// ============================================================
// FLASH MESSAGES
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

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- ============================================================
        BRANCH INDICATOR
        ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">
                        <?php echo $selected_branch > 0 ? 'Current Branch' : 'Showing'; ?>
                    </span>
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
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i>
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-university"></i> Providers</h2>
                <p class="text-muted">Manage banks, mobile money and other financial providers</p>
            </div>
            <div class="header-right">
                <a href="add.php<?php echo $selected_branch > 0 ? '?branch_id=' . $selected_branch : ''; ?>" class="btn btn-add">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Provider</span>
                </a>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        STATS CARDS
        ============================================================ -->
        <div class="stats-grid">
            <div class="stat-card stat-red">
                <div class="stat-icon"><i class="fas fa-university"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Providers</span>
                    <span class="stat-value"><?php echo number_format($total_providers); ?></span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-icon"><i class="fas fa-landmark"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Banks</span>
                    <span class="stat-value"><?php echo number_format($total_banks); ?></span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Mobile Money</span>
                    <span class="stat-value"><?php echo number_format($total_mobile); ?></span>
                </div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-icon"><i class="fas fa-link"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Assigned to Branch</span>
                    <span class="stat-value"><?php echo number_format($total_assigned); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE CONTAINER with RED HEADER
        ============================================================ -->
        <div class="table-container">

            <!-- RED HEADER: Search (left) + Scroll < > (center) + Actions (right) -->
            <div class="table-red-header">
                <div class="table-red-header-content">

                    <!-- LEFT: Compact Search -->
                    <div class="header-search-wrapper">
                        <i class="fas fa-search header-search-icon"></i>
                        <input type="text"
                               class="header-search-input"
                               id="quickSearch"
                               placeholder="Search provider..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               oninput="onQuickSearch(this)"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}">
                        <button type="button" class="header-search-clear"
                                id="searchClearBtn"
                                onclick="clearQuickSearch()"
                                style="<?php echo $search !== '' ? '' : 'display:none;'; ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- CENTER: Scroll < > -->
                    <div class="header-scroll-center">
                        <button type="button" class="header-scroll-btn"
                                onclick="scrollTable('left')"
                                title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="header-scroll-label">
                            <i class="fas fa-arrows-alt-h"></i> SCROLL
                        </span>
                        <button type="button" class="header-scroll-btn"
                                onclick="scrollTable('right')"
                                title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>

                    <!-- RIGHT: Filters + Count -->
                    <div class="header-actions">
                        <button type="button" class="btn-filters-toggle" onclick="toggleAdvancedFilters()">
                            <i class="fas fa-filter"></i>
                            <span>Filters</span>
                            <i class="fas fa-chevron-down" id="filtersChevron"></i>
                        </button>
                        <span class="count-badge">
                            <i class="fas fa-university"></i>
                            <strong><?php echo number_format($total_providers); ?></strong>
                        </span>
                    </div>

                </div>
            </div>

            <!-- ADVANCED FILTERS (collapsible) -->
            <div class="advanced-filters" id="advancedFilters" style="display:none;">
                <form method="GET" action="" class="filters-form" id="filtersForm">
                    <input type="hidden" name="search" id="hiddenSearch" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="filter-group">
                        <label><i class="fas fa-store"></i> Branch</label>
                        <select name="branch_id" class="filter-control">
                            <option value="0">All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"
                                    <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Type</label>
                        <select name="type" class="filter-control">
                            <option value="">All Types</option>
                            <option value="bank"         <?php echo $type_filter === 'bank'         ? 'selected' : ''; ?>>Bank</option>
                            <option value="mobile_money" <?php echo $type_filter === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="other"        <?php echo $type_filter === 'other'        ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-apply-filter">
                            <i class="fas fa-check"></i> Apply
                        </button>
                        <a href="index.php" class="btn-clear-filter">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- ============================================================
            PROVIDERS CARDS GRID
            ============================================================ -->
            <?php if (count($providers) > 0): ?>
            <div class="providers-wrapper" id="providersWrapper">
                <div class="providers-grid" id="providersGrid">
                    <?php foreach ($providers as $p):
                        $color = $p['color_code'] ?? '#0B5ED7';
                        $icon  = $p['icon_class'] ?? 'fas fa-university';
                        $code  = $p['provider_code'] ?? 'N/A';
                        $type  = $p['provider_type'] ?? 'bank';
                        $type_label = ucfirst(str_replace('_', ' ', $type));
                        $type_icon = $type === 'mobile_money' ? 'fa-mobile-alt' : ($type === 'other' ? 'fa-coins' : 'fa-landmark');
                        $branch_count = intval($p['branch_count'] ?? 0);
                        $initial = strtoupper(substr($p['provider_name'] ?? 'P', 0, 1));
                        $search_data = strtolower(($p['provider_name'] ?? '') . ' ' . $code . ' ' . $type_label);
                    ?>
                    <div class="provider-card" data-search="<?php echo htmlspecialchars($search_data); ?>">
                        <div class="provider-card-accent" style="background: <?php echo htmlspecialchars($color); ?>;"></div>

                        <div class="provider-card-body">
                            <div class="provider-card-top">
                                <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($color); ?>;">
                                    <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                </div>

                                <div class="provider-info">
                                    <div class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></div>
                                    <div class="provider-code-chip"><?php echo htmlspecialchars($code); ?></div>
                                </div>
                            </div>

                            <div class="provider-meta-row">
                                <span class="provider-type-badge type-<?php echo $type; ?>">
                                    <i class="fas <?php echo $type_icon; ?>"></i>
                                    <?php echo htmlspecialchars($type_label); ?>
                                </span>

                                <?php if ($branch_count > 0): ?>
                                <span class="provider-branch-badge">
                                    <i class="fas fa-store"></i>
                                    <?php echo $branch_count; ?> branch<?php echo $branch_count !== 1 ? 'es' : ''; ?>
                                </span>
                                <?php else: ?>
                                <span class="provider-branch-badge badge-unassigned">
                                    <i class="fas fa-store-slash"></i>
                                    Not assigned
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="provider-card-footer">
                            <a href="view.php?id=<?php echo $p['id']; ?>"
                               class="card-action-btn action-view" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="edit.php?id=<?php echo $p['id']; ?>"
                               class="card-action-btn action-edit" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button type="button"
                                    class="card-action-btn action-delete"
                                    onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo addslashes($p['provider_name']); ?>')"
                                    title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- No search results -->
                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No providers match your search</p>
                    <button type="button" class="btn-clear-search" onclick="clearQuickSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-university"></i>
                <h3>No Providers Found</h3>
                <p>
                    <?php if ($search !== '' || $type_filter !== '' || $selected_branch > 0): ?>
                        Try adjusting your filters or search terms.
                    <?php else: ?>
                        Start by adding your first provider.
                    <?php endif; ?>
                </p>
                <?php if ($search === '' && $type_filter === '' && $selected_branch === 0): ?>
                    <a href="add.php" class="btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add First Provider
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal" style="display:none;">
    <div class="modal-box">
        <div class="modal-icon modal-icon-danger">
            <i class="fas fa-trash"></i>
        </div>
        <h3 class="modal-title">Delete Provider?</h3>
        <p class="modal-message">
            Are you sure you want to delete <strong id="deleteProviderName"></strong>?
            This action cannot be undone.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="#" class="btn-modal btn-modal-danger" id="deleteConfirmBtn">
                <i class="fas fa-trash"></i> Delete
            </a>
        </div>
    </div>
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

/* ============================================================
   BRANCH INDICATOR
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 14px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 12px;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; position: relative; z-index: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px; background: rgba(255, 255, 255, 0.2);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700; color: #FFFFFF;
    padding: 3px 12px; background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 12px;
    color: rgba(255,255,255,0.9); padding: 4px 12px;
    background: rgba(255, 255, 255, 0.12); border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; position: relative; z-index: 1; }
.branch-indicator-right .date-display {
    font-size: 12px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center; gap: 6px;
    white-space: nowrap; font-weight: 600;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0;
}
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
}
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

/* ============================================================
   ALERTS
   ============================================================ */
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

/* ============================================================
   STATS CARDS
   ============================================================ */
.stats-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.stat-card {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; min-width: 0;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: var(--red-primary);
}
.stat-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0; color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.stat-red    .stat-icon { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.stat-blue   .stat-icon { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.stat-green  .stat-icon { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.stat-orange .stat-icon { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.stat-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.stat-label {
    font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--text-muted);
}
.stat-value {
    font-size: 22px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    line-height: 1.2;
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}

/* RED HEADER */
.table-red-header {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    padding: 14px 20px;
    position: relative; overflow: hidden;
}
.table-red-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.table-red-header-content {
    display: flex; align-items: center;
    gap: 14px; flex-wrap: wrap;
    position: relative; z-index: 1;
    justify-content: space-between;
}

/* COMPACT SEARCH */
.header-search-wrapper {
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
.header-search-wrapper:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 3px 14px rgba(252, 211, 77, 0.5);
}
html.dark-mode .header-search-wrapper { background: rgba(30, 41, 59, 0.98); }
.header-search-icon {
    color: var(--red-primary);
    font-size: 12px;
    flex-shrink: 0;
    margin-right: 8px;
}
.header-search-input {
    flex: 1; border: none; background: transparent;
    padding: 0; outline: none;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: #1f2937; min-width: 0;
}
html.dark-mode .header-search-input { color: #f1f5f9; }
.header-search-input::placeholder { color: #9ca3af; font-size: 11px; }
.header-search-clear {
    width: 18px; height: 18px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px; transition: all 0.2s ease;
    flex-shrink: 0; margin-left: 6px;
}
.header-search-clear:hover { background: #DC2626; color: #FFFFFF; transform: scale(1.1); }

/* SCROLL < > CENTER */
.header-scroll-center {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; flex: 1; min-width: 0; padding: 0 8px;
}
.header-scroll-btn {
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
.header-scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.header-scroll-btn i { font-size: 12px; display: block; line-height: 1; }
.header-scroll-label {
    font-size: 10px; font-weight: 800;
    color: #FCD34D; text-transform: uppercase;
    letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.header-scroll-label i { font-size: 10px; color: #FCD34D; }

/* HEADER ACTIONS */
.header-actions {
    display: flex; align-items: center; gap: 10px;
    flex-shrink: 0;
}
.btn-filters-toggle {
    padding: 9px 16px;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    font-weight: 700; font-size: 12px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-filters-toggle:hover {
    background: rgba(255, 255, 255, 0.28);
    border-color: #FCD34D;
}
.btn-filters-toggle.active {
    background: #FCD34D; color: #78350F; border-color: #FCD34D;
}
.btn-filters-toggle i:last-child {
    transition: transform 0.3s ease; font-size: 10px;
}
.btn-filters-toggle.active i:last-child { transform: rotate(180deg); }

.count-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border-radius: 10px;
    font-size: 12px; font-weight: 700;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    white-space: nowrap;
}
.count-badge i { color: #FCD34D; font-size: 12px; }
.count-badge strong { font-size: 14px; font-weight: 900; }

/* ============================================================
   ADVANCED FILTERS
   ============================================================ */
.advanced-filters {
    padding: 18px 22px;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-bottom: 1.5px solid #FCA5A5;
    animation: slideDown 0.3s ease forwards;
}
html.dark-mode .advanced-filters {
    background: linear-gradient(135deg, #2d1f1f 0%, #3f1f1f 100%);
    border-bottom-color: #7F1D1D;
}
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group {
    display: flex; flex-direction: column; gap: 6px;
    min-width: 180px; flex: 1;
}
.filter-group label {
    font-size: 11px; font-weight: 800;
    color: #991B1B;
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 6px;
}
.filter-group label i { font-size: 11px; }
html.dark-mode .filter-group label { color: #FCA5A5; }
.filter-control {
    padding: 10px 14px;
    border: 1.5px solid #FCA5A5;
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: #FFFFFF;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%; cursor: pointer;
}
html.dark-mode .filter-control { background: #1e293b; border-color: #991B1B; }
.filter-control:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}
.filter-actions {
    display: flex; gap: 10px; align-items: center;
    flex-shrink: 0;
}
.btn-apply-filter, .btn-clear-filter {
    padding: 11px 20px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-apply-filter {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.btn-apply-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
}
.btn-clear-filter {
    background: #FFFFFF;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-clear-filter:hover {
    background: #F3F4F6;
    transform: translateY(-2px);
}
html.dark-mode .btn-clear-filter { background: #1e293b; }

/* ============================================================
   PROVIDERS GRID (Cards)
   ============================================================ */
.providers-wrapper {
    padding: 22px;
    overflow-x: auto;
    scroll-behavior: smooth;
}
.providers-wrapper::-webkit-scrollbar { height: 8px; }
.providers-wrapper::-webkit-scrollbar-track { background: var(--bg-input); border-radius: 4px; }
.providers-wrapper::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    min-width: min-content;
}

/* PROVIDER CARD */
.provider-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    position: relative;
    min-width: 0;
}
.provider-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
    border-color: #FCA5A5;
}
.provider-card.hidden-by-search { display: none !important; }

.provider-card-accent {
    height: 6px;
    width: 100%;
    flex-shrink: 0;
}

.provider-card-body {
    padding: 18px 18px 14px 18px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.provider-card-top {
    display: flex;
    align-items: center;
    gap: 14px;
}

.provider-icon-circle {
    width: 56px; height: 56px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.provider-info {
    display: flex; flex-direction: column; gap: 6px;
    min-width: 0; flex: 1;
}
.provider-name {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.provider-code-chip {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    color: #DC2626;
    background: #FEF2F2;
    padding: 3px 10px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
}
html.dark-mode .provider-code-chip {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

.provider-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.provider-type-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.provider-type-badge.type-bank {
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
.provider-type-badge.type-mobile_money {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.provider-type-badge.type-other {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
html.dark-mode .provider-type-badge.type-bank {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .provider-type-badge.type-mobile_money {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .provider-type-badge.type-other {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.provider-type-badge i { font-size: 10px; }

.provider-branch-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    background: #F3F4F6;
    color: #6B7280;
    border: 1.5px solid #E5E7EB;
    white-space: nowrap;
}
.provider-branch-badge i { font-size: 10px; }
.provider-branch-badge.badge-unassigned {
    background: #FEF3C7; color: #92400E;
    border-color: #FDE68A;
}
html.dark-mode .provider-branch-badge {
    background: #334155; color: #94A3B8; border-color: #475569;
}
html.dark-mode .provider-branch-badge.badge-unassigned {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}

.provider-card-footer {
    display: flex;
    gap: 6px;
    padding: 12px 14px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
}
html.dark-mode .provider-card-footer { background: #0f172a; }

.card-action-btn {
    flex: 1;
    height: 38px;
    border-radius: 8px;
    border: 1.5px solid;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
    padding: 0 10px;
    gap: 6px;
    font-family: 'Inter', sans-serif;
    font-weight: 700;
}
.action-view {
    background: #DBEAFE; color: #1D4ED8; border-color: #BFDBFE;
}
.action-view:hover {
    background: #1D4ED8; color: #FFFFFF;
    border-color: #1D4ED8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.4);
}
.action-edit {
    background: #FEF3C7; color: #D97706; border-color: #FDE68A;
}
.action-edit:hover {
    background: #D97706; color: #FFFFFF;
    border-color: #D97706;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.4);
}
.action-delete {
    background: #FEE2E2; color: #DC2626; border-color: #FECACA;
}
.action-delete:hover {
    background: #DC2626; color: #FFFFFF;
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}
html.dark-mode .action-view { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
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

/* ============================================================
   MODAL
   ============================================================ */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
    padding: 20px;
    animation: fadeIn 0.2s ease forwards;
    backdrop-filter: blur(4px);
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.modal-box {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 32px 28px;
    max-width: 460px; width: 100%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    text-align: center;
    animation: slideUp 0.3s ease forwards;
    border: 1.5px solid var(--border-color);
}
@keyframes slideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    margin: 0 auto 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px;
}
.modal-icon-danger {
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #DC2626;
    border: 3px solid #FCA5A5;
}
html.dark-mode .modal-icon-danger {
    background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);
    color: #FCA5A5;
}
.modal-title {
    font-size: 20px; font-weight: 900;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}
.modal-message {
    font-size: 14px; line-height: 1.6;
    color: var(--text-muted);
    margin: 0 0 24px 0;
}
.modal-message strong {
    color: var(--text-primary);
    font-weight: 800;
}
.modal-actions {
    display: flex; gap: 10px;
}
.btn-modal {
    flex: 1;
    padding: 12px 20px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
}
.btn-modal-cancel {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-modal-cancel:hover {
    background: var(--bg-body);
    color: var(--text-primary);
    transform: translateY(-2px);
}
.btn-modal-danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
}
.btn-modal-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    .header-search-wrapper { flex: 1 1 100%; max-width: 100%; min-width: 0; }
    .header-scroll-center {
        width: 100%; justify-content: center; order: 3;
    }
    .header-actions { width: 100%; justify-content: center; order: 2; }
    .btn-filters-toggle, .count-badge { flex: 1; justify-content: center; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn-add { width: 100%; justify-content: center; }
    .stats-grid { grid-template-columns: 1fr; gap: 10px; }
    .table-red-header-content {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .header-scroll-btn { width: 38px; height: 38px; }
    .filters-form { flex-direction: column; }
    .filter-group { min-width: 100%; }
    .filter-actions { width: 100%; }
    .filter-actions button, .filter-actions a { flex: 1; justify-content: center; }
    .providers-wrapper { padding: 14px; }
    .providers-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .stat-card { padding: 14px 16px; gap: 10px; }
    .stat-icon { width: 42px; height: 42px; font-size: 18px; }
    .stat-value { font-size: 18px; }
    .provider-icon-circle { width: 48px; height: 48px; font-size: 20px; }
    .provider-name { font-size: 14px; }
}
</style>

<script>
// ============================================================
// QUICK SEARCH (client-side filtering)
// ============================================================
function onQuickSearch(input) {
    var term = input.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.provider-card');
    var clearBtn = document.getElementById('searchClearBtn');
    var noResults = document.getElementById('noResults');
    var grid = document.getElementById('providersGrid');
    var hiddenSearch = document.getElementById('hiddenSearch');

    if (hiddenSearch) hiddenSearch.value = input.value;
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

function clearQuickSearch() {
    var input = document.getElementById('quickSearch');
    if (!input) return;
    input.value = '';
    onQuickSearch(input);
    input.focus();

    var url = new URL(window.location.href);
    if (url.searchParams.has('search')) {
        url.searchParams.delete('search');
        window.location.href = url.toString();
    }
}

function applyFilters() {
    var form = document.getElementById('filtersForm');
    if (form) form.submit();
}

// ============================================================
// ADVANCED FILTERS TOGGLE
// ============================================================
function toggleAdvancedFilters() {
    var filters = document.getElementById('advancedFilters');
    var btn = document.querySelector('.btn-filters-toggle');
    if (!filters) return;

    var isHidden = filters.style.display === 'none' || filters.style.display === '';
    filters.style.display = isHidden ? 'block' : 'none';
    btn.classList.toggle('active', isHidden);
}

// Auto-open if any filter is active
document.addEventListener('DOMContentLoaded', function() {
    var url = new URL(window.location.href);
    var hasFilter = url.searchParams.get('branch_id') > 0
                 || url.searchParams.get('type');

    if (hasFilter) {
        document.getElementById('advancedFilters').style.display = 'block';
        document.querySelector('.btn-filters-toggle').classList.add('active');
    }

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
});

// ============================================================
// TABLE SCROLL < >
// ============================================================
function scrollTable(direction) {
    var wrapper = document.getElementById('providersWrapper');
    if (!wrapper) return;
    var scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// DELETE MODAL
// ============================================================
function confirmDelete(id, name) {
    document.getElementById('deleteProviderName').textContent = name;
    document.getElementById('deleteConfirmBtn').href = 'delete.php?id=' + id;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

document.addEventListener('click', function(e) {
    var modal = document.getElementById('deleteModal');
    if (e.target === modal) closeDeleteModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});

// Dark mode sync
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true' || localStorage.getItem('darkMode') === 'enabled';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function() { syncDarkMode(); });
});
</script>

</body>
</html>