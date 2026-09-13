<?php
// ================================================================
// FILE: modules/branches/providers.php
// WAKALA FINANCIAL SYSTEM - MANAGE BRANCH PROVIDERS
// RED THEME + AJAX ASSIGN/UNASSIGN + SEARCH + SCROLL
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
// GET BRANCH
// ============================================================
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
if ($branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch.';
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index.php');
    exit();
}

$branch_name = $branch['branch_name'];
$branch_code = $branch['branch_code'] ?? '';
$branch_location = $branch['location'] ?? '';

// ============================================================
// FETCH ALL ACTIVE PROVIDERS WITH ASSIGNMENT STATUS
// ============================================================
$all_providers = [];
try {
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.provider_name,
            p.provider_code AS main_code,
            p.provider_type,
            p.icon_class,
            p.color_code,
            p.display_order,
            bp.id AS branch_provider_id,
            bp.provider_code AS branch_provider_code,
            bp.is_active AS is_assigned,
            (SELECT COALESCE(SUM(drp.current_float), 0)
             FROM daily_report_providers drp
             INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
             WHERE drp.provider_id = p.id 
             AND dr.branch_id = ?
             AND dr.id = (SELECT MAX(id) FROM daily_reports WHERE branch_id = ?)
            ) AS current_float
        FROM providers p
        LEFT JOIN branch_providers bp 
               ON bp.provider_id = p.id AND bp.branch_id = ?
        WHERE p.is_active = 1
        ORDER BY p.display_order ASC, p.provider_name ASC
    ");
    $stmt->execute([$branch_id, $branch_id, $branch_id]);
    $all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_providers = [];
}

// Split into assigned / available
$assigned_providers = [];
$available_providers = [];
foreach ($all_providers as $p) {
    if (!empty($p['is_assigned']) && intval($p['is_assigned']) === 1) {
        $assigned_providers[] = $p;
    } else {
        $available_providers[] = $p;
    }
}

// ============================================================
// STATS
// ============================================================
$total_providers = count($all_providers);
$total_assigned = count($assigned_providers);
$total_available = count($available_providers);
$total_banks = 0;
$total_mobile = 0;
foreach ($all_providers as $p) {
    if (($p['provider_type'] ?? '') === 'bank') $total_banks++;
    elseif (($p['provider_type'] ?? '') === 'mobile_money') $total_mobile++;
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

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-university"></i> Manage Branch Providers</h2>
                <p class="text-muted">Assign or remove providers for this branch</p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Branch
                </a>
                <a href="../providers/index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-list"></i> All Providers
                </a>
            </div>
        </div>

        <!-- BRANCH CARD -->
        <div class="branch-header-card">
            <div class="branch-header-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-header-info">
                <span class="branch-header-label">Managing Providers For</span>
                <div class="branch-header-title-row">
                    <span class="branch-header-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if (!empty($branch_code)): ?>
                        <span class="branch-header-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($branch_location)): ?>
                    <span class="branch-header-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-header-stats">
                <div class="bh-stat">
                    <span class="bh-stat-num"><?php echo $total_assigned; ?></span>
                    <span class="bh-stat-label">Assigned</span>
                </div>
                <div class="bh-stat">
                    <span class="bh-stat-num"><?php echo $total_available; ?></span>
                    <span class="bh-stat-label">Available</span>
                </div>
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

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card stat-red">
                <div class="stat-icon"><i class="fas fa-university"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Providers</span>
                    <span class="stat-value"><?php echo number_format($total_providers); ?></span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Assigned</span>
                    <span class="stat-value"><?php echo number_format($total_assigned); ?></span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-icon"><i class="fas fa-landmark"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Banks</span>
                    <span class="stat-value"><?php echo number_format($total_banks); ?></span>
                </div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Mobile Money</span>
                    <span class="stat-value"><?php echo number_format($total_mobile); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        RED TOOLBAR: Search + < > scroll
        ============================================================ -->
        <div class="toolbar">
            <div class="toolbar-inner">

                <!-- LEFT: Search -->
                <div class="toolbar-search">
                    <i class="fas fa-search toolbar-search-icon"></i>
                    <input type="text"
                           id="providerSearch"
                           class="toolbar-search-input"
                           placeholder="Search provider..."
                           oninput="onProviderSearch(this)">
                    <button type="button" class="toolbar-search-clear"
                            id="providerSearchClear"
                            onclick="clearProviderSearch()"
                            style="display:none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- CENTER: < > scroll -->
                <div class="toolbar-scroll-center">
                    <button type="button" class="toolbar-scroll-btn" onclick="scrollProviders('left')" title="Scroll Left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="toolbar-scroll-label">
                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                    </span>
                    <button type="button" class="toolbar-scroll-btn" onclick="scrollProviders('right')" title="Scroll Right">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <!-- RIGHT: Filter buttons -->
                <div class="toolbar-filters">
                    <button type="button" class="toolbar-filter-btn active" data-filter="all" onclick="setFilter('all', this)">
                        <i class="fas fa-list"></i> All
                    </button>
                    <button type="button" class="toolbar-filter-btn" data-filter="assigned" onclick="setFilter('assigned', this)">
                        <i class="fas fa-check-circle"></i> Assigned
                    </button>
                    <button type="button" class="toolbar-filter-btn" data-filter="available" onclick="setFilter('available', this)">
                        <i class="fas fa-plus-circle"></i> Available
                    </button>
                </div>

            </div>
        </div>

        <!-- ============================================================
        SECTION: ASSIGNED PROVIDERS
        ============================================================ -->
        <div class="section-block" id="assignedSection">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-head-icon icon-green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <h3>Assigned Providers</h3>
                        <p>Providers already active at this branch</p>
                    </div>
                </div>
                <span class="section-count-badge green" id="assignedCount">
                    <?php echo $total_assigned; ?>
                </span>
            </div>

            <?php if (count($assigned_providers) > 0): ?>
                <div class="providers-wrapper" id="assignedWrapper">
                    <div class="providers-grid" id="assignedGrid">
                        <?php foreach ($assigned_providers as $p):
                            $color = $p['color_code'] ?? '#0B5ED7';
                            $icon = $p['icon_class'] ?? 'fas fa-university';
                            $ptype = $p['provider_type'] ?? 'bank';
                            $type_label = ucfirst(str_replace('_', ' ', $ptype));
                            $type_icon = $ptype === 'mobile_money' ? 'fa-mobile-alt' : ($ptype === 'other' ? 'fa-coins' : 'fa-landmark');
                            $pcode = $p['branch_provider_code'] ?? $p['main_code'];
                            $float_amt = floatval($p['current_float'] ?? 0);
                            $search_data = strtolower(($p['provider_name'] ?? '') . ' ' . $pcode . ' ' . $type_label);
                        ?>
                        <div class="provider-card assigned"
                             data-status="assigned"
                             data-search="<?php echo htmlspecialchars($search_data); ?>">
                            <div class="provider-card-accent" style="background: <?php echo htmlspecialchars($color); ?>;"></div>

                            <div class="provider-card-body">
                                <div class="provider-card-top">
                                    <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($color); ?>;">
                                        <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                    </div>
                                    <div class="provider-info">
                                        <div class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></div>
                                        <div class="provider-code-chip"><?php echo htmlspecialchars($pcode); ?></div>
                                    </div>
                                </div>

                                <div class="provider-meta-row">
                                    <span class="type-badge type-<?php echo $ptype; ?>">
                                        <i class="fas <?php echo $type_icon; ?>"></i>
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </span>
                                    <?php if ($float_amt > 0): ?>
                                    <span class="float-badge">
                                        <i class="fas fa-coins"></i>
                                        <?php echo formatCurrency($float_amt); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="float-badge float-zero">
                                        <i class="fas fa-coins"></i> No float yet
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="provider-card-footer">
                                <button type="button"
                                        class="btn-toggle-assign unassign"
                                        onclick="toggleAssign(<?php echo $p['id']; ?>, 'unassign', this)">
                                    <i class="fas fa-times-circle"></i>
                                    <span>Unassign</span>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-section">
                    <i class="fas fa-university"></i>
                    <p>No providers assigned to this branch yet</p>
                    <span>Use the <strong>Available Providers</strong> section below to assign one.</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================
        SECTION: AVAILABLE PROVIDERS
        ============================================================ -->
        <div class="section-block" id="availableSection">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-head-icon icon-red">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div>
                        <h3>Available Providers</h3>
                        <p>Providers you can assign to this branch</p>
                    </div>
                </div>
                <span class="section-count-badge red" id="availableCount">
                    <?php echo $total_available; ?>
                </span>
            </div>

            <?php if (count($available_providers) > 0): ?>
                <div class="providers-wrapper" id="availableWrapper">
                    <div class="providers-grid" id="availableGrid">
                        <?php foreach ($available_providers as $p):
                            $color = $p['color_code'] ?? '#0B5ED7';
                            $icon = $p['icon_class'] ?? 'fas fa-university';
                            $ptype = $p['provider_type'] ?? 'bank';
                            $type_label = ucfirst(str_replace('_', ' ', $ptype));
                            $type_icon = $ptype === 'mobile_money' ? 'fa-mobile-alt' : ($ptype === 'other' ? 'fa-coins' : 'fa-landmark');
                            $pcode = $p['main_code'];
                            $search_data = strtolower(($p['provider_name'] ?? '') . ' ' . $pcode . ' ' . $type_label);
                        ?>
                        <div class="provider-card available"
                             data-status="available"
                             data-search="<?php echo htmlspecialchars($search_data); ?>">
                            <div class="provider-card-accent" style="background: <?php echo htmlspecialchars($color); ?>;"></div>

                            <div class="provider-card-body">
                                <div class="provider-card-top">
                                    <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($color); ?>;">
                                        <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                    </div>
                                    <div class="provider-info">
                                        <div class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></div>
                                        <div class="provider-code-chip"><?php echo htmlspecialchars($pcode); ?></div>
                                    </div>
                                </div>

                                <div class="provider-meta-row">
                                    <span class="type-badge type-<?php echo $ptype; ?>">
                                        <i class="fas <?php echo $type_icon; ?>"></i>
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </span>
                                    <span class="not-assigned-badge">
                                        <i class="fas fa-info-circle"></i> Not assigned
                                    </span>
                                </div>
                            </div>

                            <div class="provider-card-footer">
                                <button type="button"
                                        class="btn-toggle-assign assign"
                                        onclick="toggleAssign(<?php echo $p['id']; ?>, 'assign', this)">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Assign to Branch</span>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-section">
                    <i class="fas fa-check-double"></i>
                    <p>All active providers are already assigned to this branch</p>
                    <span>Any new provider you add will appear here.</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- No search results -->
        <div class="no-results" id="noResults" style="display:none;">
            <i class="fas fa-search-minus"></i>
            <p>No providers match your search</p>
            <button type="button" class="btn-clear-search" onclick="clearProviderSearch()">
                <i class="fas fa-times"></i> Clear Search
            </button>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- TOAST NOTIFICATION -->
<div class="toast" id="toast" style="display:none;">
    <div class="toast-icon" id="toastIcon">
        <i class="fas fa-check-circle"></i>
    </div>
    <div class="toast-message" id="toastMessage"></div>
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

.btn {
    padding: 10px 20px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-back {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-back:hover {
    background: #FEF2F2; color: var(--red-primary);
    border-color: var(--red-primary); transform: translateY(-2px);
}

/* BRANCH HEADER CARD */
.branch-header-card {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap;
    position: relative; overflow: hidden;
}
.branch-header-card::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-header-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
}
.branch-header-info {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-header-label {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-header-title-row {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.branch-header-name {
    font-size: 18px; font-weight: 800;
    color: #FFFFFF;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.branch-header-code {
    font-size: 11px; font-weight: 700;
    color: rgba(255, 255, 255, 0.9);
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
}
.branch-header-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.85);
}
.branch-header-stats {
    display: flex; align-items: center; gap: 10px;
    position: relative; z-index: 1;
    flex-shrink: 0;
}
.bh-stat {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
    padding: 8px 18px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.bh-stat-num {
    font-size: 20px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    line-height: 1.1;
}
.bh-stat-label {
    font-size: 9px; font-weight: 700;
    color: rgba(255, 255, 255, 0.8);
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

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* STATS */
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
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0; color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(0,0,0,0.12);
}
.stat-red    .stat-icon { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.stat-green  .stat-icon { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.stat-blue   .stat-icon { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.stat-orange .stat-icon { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.stat-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--text-muted);
}
.stat-value {
    font-size: 20px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    line-height: 1.15;
    word-break: break-all;
}

/* RED TOOLBAR */
.toolbar {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px;
    padding: 12px 18px;
    margin-bottom: 18px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.25);
}
.toolbar::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.07);
    border-radius: 50%; pointer-events: none;
}
.toolbar-inner {
    display: flex; align-items: center;
    gap: 12px; flex-wrap: wrap;
    position: relative; z-index: 1;
    justify-content: space-between;
}

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
    font-size: 8px;
    transition: all 0.2s ease;
    flex-shrink: 0; margin-left: 6px;
}
.toolbar-search-clear:hover { background: #DC2626; color: #FFFFFF; transform: scale(1.1); }

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

.toolbar-filters {
    display: flex; gap: 6px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.toolbar-filter-btn {
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-weight: 700; font-size: 11.5px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.toolbar-filter-btn:hover {
    background: rgba(255, 255, 255, 0.28);
    border-color: #FCD34D;
}
.toolbar-filter-btn.active {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    box-shadow: 0 3px 10px rgba(252, 211, 77, 0.4);
}
.toolbar-filter-btn i { font-size: 11px; }

/* SECTION BLOCKS */
.section-block {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
}
.section-head {
    display: flex; justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
    gap: 12px; flex-wrap: wrap;
}
.section-head-left {
    display: flex; align-items: center; gap: 14px;
    min-width: 0;
}
.section-head-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.section-head-icon.icon-green {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
}
.section-head-icon.icon-red {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.section-head h3 {
    font-size: 16px; font-weight: 800;
    margin: 0 0 2px 0;
    color: var(--text-primary);
}
.section-head p {
    font-size: 12px;
    margin: 0;
    color: var(--text-muted);
    font-weight: 500;
}
.section-count-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 44px; height: 32px;
    padding: 0 14px;
    border-radius: 10px;
    font-size: 14px; font-weight: 900;
    font-family: 'Courier New', monospace;
}
.section-count-badge.green {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #065F46;
    border: 1.5px solid #6EE7B7;
}
.section-count-badge.red {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .section-count-badge.green {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .section-count-badge.red {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* PROVIDERS WRAPPER */
.providers-wrapper {
    padding: 20px;
    overflow-x: auto;
    scroll-behavior: smooth;
}
.providers-wrapper::-webkit-scrollbar { height: 8px; }
.providers-wrapper::-webkit-scrollbar-track { background: var(--bg-input); border-radius: 4px; }
.providers-wrapper::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
    min-width: min-content;
}

/* PROVIDER CARD */
.provider-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.provider-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(0,0,0,0.1);
    border-color: #FCA5A5;
}
.provider-card.hidden-by-search { display: none !important; }
.provider-card.hidden-by-filter { display: none !important; }
.provider-card.assigned {
    border-left: 4px solid #10B981;
}
.provider-card.available {
    border-left: 4px solid #DC2626;
}

.provider-card-accent {
    height: 5px;
    width: 100%;
    flex-shrink: 0;
}
.provider-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.provider-card-top {
    display: flex; align-items: center; gap: 12px;
}
.provider-icon-circle {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.provider-info {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 0; flex: 1;
}
.provider-name {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.provider-code-chip {
    display: inline-block;
    font-size: 9.5px;
    font-weight: 800;
    color: #DC2626;
    background: #FEF2F2;
    padding: 2px 8px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.4px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
}
html.dark-mode .provider-code-chip {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

.provider-meta-row {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-items: center;
}
.type-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.type-badge i { font-size: 9px; }
.type-bank {
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
.type-mobile_money {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.type-other {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
html.dark-mode .type-bank {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .type-mobile_money {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .type-other {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}

.float-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    background: #ECFDF5;
    color: #059669;
    border: 1px solid #A7F3D0;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.float-badge i { font-size: 9px; }
.float-badge.float-zero {
    background: #F3F4F6; color: #6B7280; border-color: #E5E7EB;
}
html.dark-mode .float-badge {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .float-badge.float-zero {
    background: #334155; color: #94A3B8; border-color: #475569;
}

.not-assigned-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    background: #FEF2F2;
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
    white-space: nowrap;
}
.not-assigned-badge i { font-size: 9px; }
html.dark-mode .not-assigned-badge {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}

/* FOOTER */
.provider-card-footer {
    padding: 12px 14px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
}
html.dark-mode .provider-card-footer { background: #0f172a; }

.btn-toggle-assign {
    width: 100%;
    height: 38px;
    border-radius: 8px;
    border: 1.5px solid;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 12px;
    font-weight: 800;
    padding: 0 14px;
    gap: 6px;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-toggle-assign.assign {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-color: #B91C1C;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.btn-toggle-assign.assign:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
}
.btn-toggle-assign.unassign {
    background: #FFFFFF;
    color: #DC2626;
    border-color: #FCA5A5;
}
.btn-toggle-assign.unassign:hover {
    background: #FEE2E2;
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
}
html.dark-mode .btn-toggle-assign.unassign {
    background: #1e293b;
}
html.dark-mode .btn-toggle-assign.unassign:hover {
    background: #7F1D1D;
    color: #FCA5A5;
    border-color: #DC2626;
}
.btn-toggle-assign:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}

/* EMPTY */
.empty-section {
    padding: 50px 20px;
    text-align: center;
    color: var(--text-muted);
}
.empty-section i {
    font-size: 52px;
    color: #FCA5A5;
    opacity: 0.5;
    display: block;
    margin-bottom: 14px;
}
.empty-section p {
    font-size: 15px;
    margin: 0 0 6px 0;
    font-weight: 700;
    color: var(--text-primary);
}
.empty-section span {
    font-size: 12.5px;
    color: var(--text-muted);
}

/* NO RESULTS */
.no-results {
    padding: 50px 20px;
    text-align: center;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
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

/* TOAST */
.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 14px 22px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
    display: flex; align-items: center; gap: 12px;
    z-index: 9999;
    min-width: 280px;
    max-width: 420px;
    animation: toastIn 0.3s ease forwards;
}
@keyframes toastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.toast.toast-success { border-left: 5px solid #10B981; }
.toast.toast-error { border-left: 5px solid #DC2626; }
.toast-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.toast-success .toast-icon {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669;
}
.toast-error .toast-icon {
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #DC2626;
}
.toast-message {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    flex: 1;
    line-height: 1.4;
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
    .toolbar-filters {
        width: 100%; justify-content: center; order: 2;
    }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .branch-header-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px 18px;
    }
    .branch-header-stats {
        width: 100%; justify-content: center;
    }

    .stats-grid { grid-template-columns: 1fr; }
    .providers-wrapper { padding: 14px; }
    .providers-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .bh-stat { padding: 6px 14px; }
    .bh-stat-num { font-size: 17px; }
    .provider-icon-circle { width: 42px; height: 42px; font-size: 18px; }
    .provider-name { font-size: 13px; }
}
</style>

<script>
// ============================================================
// TOGGLE ASSIGN (AJAX)
// ============================================================
function toggleAssign(providerId, action, btn) {
    if (!providerId) return;

    var originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (action === 'assign' ? 'Assigning...' : 'Removing...');

    var branchId = <?php echo $branch_id; ?>;
    var fd = new FormData();
    fd.append('provider_id', providerId);
    fd.append('branch_id', branchId);
    fd.append('action', action);

    fetch('toggle_provider.php', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast(data.message || (action === 'assign' ? 'Provider assigned successfully' : 'Provider removed successfully'), 'success');
            // Reload page to reflect changes (simplest approach)
            setTimeout(function() {
                window.location.reload();
            }, 700);
        } else {
            showToast(data.error || 'Something went wrong', 'error');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    })
    .catch(function(err) {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// ============================================================
// TOAST
// ============================================================
function showToast(message, type) {
    type = type || 'success';
    var toast = document.getElementById('toast');
    var icon = document.getElementById('toastIcon');
    var msg = document.getElementById('toastMessage');

    if (!toast) return;

    toast.className = 'toast toast-' + type;
    msg.textContent = message;
    icon.innerHTML = type === 'success'
        ? '<i class="fas fa-check-circle"></i>'
        : '<i class="fas fa-exclamation-circle"></i>';

    toast.style.display = 'flex';

    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(function() {
        toast.style.display = 'none';
    }, 3500);
}

// ============================================================
// SEARCH
// ============================================================
function onProviderSearch(input) {
    var term = input.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.provider-card');
    var clearBtn = document.getElementById('providerSearchClear');
    var noResults = document.getElementById('noResults');
    var currentFilter = document.querySelector('.toolbar-filter-btn.active')?.getAttribute('data-filter') || 'all';

    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';

    var matches = 0;
    cards.forEach(function(card) {
        var data = card.getAttribute('data-search') || '';
        var status = card.getAttribute('data-status') || '';
        var matchesSearch = (term === '') || data.includes(term);
        var matchesFilter = (currentFilter === 'all') || (status === currentFilter);

        if (matchesSearch && matchesFilter) {
            card.classList.remove('hidden-by-search', 'hidden-by-filter');
            matches++;
        } else {
            if (!matchesSearch) card.classList.add('hidden-by-search');
            else card.classList.remove('hidden-by-search');
            if (!matchesFilter) card.classList.add('hidden-by-filter');
            else card.classList.remove('hidden-by-filter');
        }
    });

    if (noResults) noResults.style.display = matches === 0 ? 'block' : 'none';
}

function clearProviderSearch() {
    var input = document.getElementById('providerSearch');
    if (!input) return;
    input.value = '';
    onProviderSearch(input);
    input.focus();
}

// ============================================================
// FILTER
// ============================================================
function setFilter(filter, btn) {
    document.querySelectorAll('.toolbar-filter-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    btn.classList.add('active');

    var assignedSection = document.getElementById('assignedSection');
    var availableSection = document.getElementById('availableSection');

    if (filter === 'assigned') {
        if (assignedSection) assignedSection.style.display = 'block';
        if (availableSection) availableSection.style.display = 'none';
    } else if (filter === 'available') {
        if (assignedSection) assignedSection.style.display = 'none';
        if (availableSection) availableSection.style.display = 'block';
    } else {
        if (assignedSection) assignedSection.style.display = 'block';
        if (availableSection) availableSection.style.display = 'block';
    }

    // Re-apply search after filter
    var searchInput = document.getElementById('providerSearch');
    if (searchInput) onProviderSearch(searchInput);
}

// ============================================================
// SCROLL < >
// ============================================================
function scrollProviders(direction) {
    var activeFilter = document.querySelector('.toolbar-filter-btn.active')?.getAttribute('data-filter') || 'all';
    var scrollAmount = 320;

    var sections = [];
    if (activeFilter === 'assigned') sections.push('assignedWrapper');
    else if (activeFilter === 'available') sections.push('availableWrapper');
    else sections.push('assignedWrapper', 'availableWrapper');

    sections.forEach(function(id) {
        var wrapper = document.getElementById(id);
        if (wrapper) {
            wrapper.scrollBy({
                left: direction === 'left' ? -scrollAmount : scrollAmount,
                behavior: 'smooth'
            });
        }
    });
}

// ============================================================
// DARK MODE
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
</script>

</body>
</html>