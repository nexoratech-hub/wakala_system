<?php
// ================================================================
// FILE: modules/branches/providers.php
// WAKALA FINANCIAL SYSTEM - BRANCH PROVIDERS MANAGEMENT
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================================
// GET BRANCH ID
// ============================================================
$branch_id = 0;

if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $branch_id = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0' && intval($_GET['branch']) > 0) {
    $branch_id = intval($_GET['branch']);
} else {
    $_SESSION['error_message'] = 'No branch selected. Please select a branch first.';
    header('Location: index.php');
    exit();
}

if ($branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch selected.';
    header('Location: index.php');
    exit();
}

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
// GET BRANCH PROVIDERS WITH FLOAT
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

$provider_count = count($branch_providers);

// Stats for cards
$total_banks = 0;
$total_mobile = 0;
foreach ($branch_providers as $p) {
    if (($p['provider_type'] ?? '') === 'bank') $total_banks++;
    elseif (($p['provider_type'] ?? '') === 'mobile_money') $total_mobile++;
}

// Flash messages
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
                <h2><i class="fas fa-university"></i> Branch Providers</h2>
                <p class="text-muted">Providers assigned to <?php echo htmlspecialchars($branch['branch_name']); ?></p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Branch
                </a>
                <a href="providers_add.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-add">
                    <i class="fas fa-plus-circle"></i> Add Provider
                </a>
            </div>
        </div>

        <!-- BRANCH INDICATOR CARD -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                    <?php if (!empty($branch['branch_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($branch['location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch['location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-stats">
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo number_format($provider_count); ?></span>
                    <span class="bi-stat-label">Providers</span>
                </div>
                <div class="bi-divider"></div>
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo formatCurrency($total_float); ?></span>
                    <span class="bi-stat-label">Float</span>
                </div>
                <div class="bi-divider"></div>
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo formatCurrency($total_cash); ?></span>
                    <span class="bi-stat-label">Cash</span>
                </div>
                <div class="bi-divider"></div>
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo formatCurrency($total_capital); ?></span>
                    <span class="bi-stat-label">Capital</span>
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

        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card stat-red">
                <div class="stat-icon"><i class="fas fa-university"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Providers</span>
                    <span class="stat-value"><?php echo number_format($provider_count); ?></span>
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
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Float</span>
                    <span class="stat-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        RED TOOLBAR: Search + < > scroll
        ============================================================ -->
        <div class="providers-toolbar">
            <div class="providers-toolbar-inner">

                <!-- LEFT: Compact Search -->
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

                <!-- RIGHT: Count -->
                <div class="toolbar-count">
                    <i class="fas fa-university"></i>
                    <strong><?php echo number_format($provider_count); ?></strong>
                </div>

            </div>
        </div>

        <!-- ============================================================
        PROVIDERS GRID
        ============================================================ -->
        <?php if (empty($branch_providers)): ?>
            <div class="empty-state">
                <i class="fas fa-university"></i>
                <h3>No Providers Assigned</h3>
                <p>This branch doesn't have any providers yet.</p>
                <a href="providers_add.php?branch_id=<?php echo $branch_id; ?>" class="btn-add-empty">
                    <i class="fas fa-plus-circle"></i> Add First Provider
                </a>
            </div>
        <?php else: ?>
            <div class="providers-wrapper" id="providersWrapper">
                <div class="providers-grid" id="providersGrid">
                    <?php foreach ($branch_providers as $provider):
                        $is_active = $provider['is_active'] ?? 1;
                        $status_class = $is_active ? 'active' : 'inactive';
                        $status_text = $is_active ? 'Active' : 'Inactive';
                        $status_icon = $is_active ? 'fa-check-circle' : 'fa-times-circle';

                        $float_balance = floatval($provider['float_balance'] ?? 0);
                        $today_deposits = floatval($provider['today_deposits'] ?? 0);
                        $today_withdrawals = floatval($provider['today_withdrawals'] ?? 0);

                        $has_amount = ($today_deposits > 0 || $today_withdrawals > 0 || $float_balance > 0);

                        $type_icon = $provider['provider_type'] == 'mobile_money' ? 'fa-mobile-alt' : 'fa-landmark';
                        $type_label = ucfirst(str_replace('_', ' ', $provider['provider_type'] ?? 'Bank'));
                        $color = $provider['color_code'] ?? '#0B5ED7';
                        $icon = $provider['icon_class'] ?? 'fas fa-university';

                        $search_data = strtolower(
                            ($provider['provider_name'] ?? '') . ' ' .
                            ($provider['provider_code'] ?? '') . ' ' .
                            ($provider['main_code'] ?? '') . ' ' .
                            $type_label
                        );
                    ?>
                    <div class="provider-card <?php echo $status_class; ?> <?php echo $has_amount ? 'has-amount' : ''; ?>"
                         data-search="<?php echo htmlspecialchars($search_data); ?>">

                        <div class="provider-card-accent" style="background: <?php echo htmlspecialchars($color); ?>;"></div>

                        <div class="provider-card-body">

                            <!-- Top: Icon + Name + Status -->
                            <div class="provider-card-top">
                                <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($color); ?>;">
                                    <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                </div>
                                <div class="provider-info">
                                    <div class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></div>
                                    <div class="provider-code-chip">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($provider['provider_code']); ?>
                                    </div>
                                </div>
                                <span class="status-pill <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo $status_text; ?>
                                </span>
                            </div>

                            <!-- Type + Has amount badge -->
                            <div class="provider-meta-row">
                                <span class="type-pill type-<?php echo $provider['provider_type']; ?>">
                                    <i class="fas <?php echo $type_icon; ?>"></i>
                                    <?php echo $type_label; ?>
                                </span>
                                <?php if ($has_amount): ?>
                                <span class="has-amount-pill">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Has balance
                                </span>
                                <?php endif; ?>
                            </div>

                            <!-- Amounts mini grid -->
                            <div class="amounts-grid">
                                <div class="amount-mini amount-float">
                                    <div class="amount-mini-icon">
                                        <i class="fas fa-coins"></i>
                                    </div>
                                    <div class="amount-mini-info">
                                        <span class="amount-mini-label">Float</span>
                                        <span class="amount-mini-value"><?php echo formatCurrency($float_balance); ?></span>
                                    </div>
                                </div>
                                <div class="amount-mini amount-deposit">
                                    <div class="amount-mini-icon">
                                        <i class="fas fa-arrow-down"></i>
                                    </div>
                                    <div class="amount-mini-info">
                                        <span class="amount-mini-label">Deposits</span>
                                        <span class="amount-mini-value"><?php echo formatCurrency($today_deposits); ?></span>
                                    </div>
                                </div>
                                <div class="amount-mini amount-withdrawal">
                                    <div class="amount-mini-icon">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                    <div class="amount-mini-info">
                                        <span class="amount-mini-label">Withdrawals</span>
                                        <span class="amount-mini-value"><?php echo formatCurrency($today_withdrawals); ?></span>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Footer actions -->
                        <div class="provider-card-footer">
                            <a href="providers_edit.php?id=<?php echo $provider['id']; ?>&branch_id=<?php echo $branch_id; ?>"
                               class="btn-card-action action-edit">
                                <i class="fas fa-edit"></i>
                                <span>Edit</span>
                            </a>
                            <?php if ($has_amount): ?>
                                <button type="button" class="btn-card-action action-locked" disabled
                                        title="Cannot delete - provider has balance or transactions">
                                    <i class="fas fa-lock"></i>
                                    <span>Locked</span>
                                </button>
                            <?php else: ?>
                                <a href="providers_delete.php?id=<?php echo $provider['id']; ?>&branch_id=<?php echo $branch_id; ?>"
                                   class="btn-card-action action-delete"
                                   onclick="return confirmDelete('<?php echo addslashes($provider['provider_name']); ?>', '<?php echo addslashes($provider['provider_code']); ?>')">
                                    <i class="fas fa-trash"></i>
                                    <span>Delete</span>
                                </a>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
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

.btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-back {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-back:hover {
    background: #FEF2F2; color: var(--red-primary);
    border-color: var(--red-primary); transform: translateY(-2px);
}
.btn-add {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 14px;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: wrap; min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-icon-wrapper {
    width: 44px; height: 44px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px;
    color: #FFFFFF;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.branch-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px; white-space: nowrap;
}
.branch-indicator-stats {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 18px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative; z-index: 1;
    flex-wrap: wrap;
}
.bi-stat {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
}
.bi-stat-num {
    font-size: 17px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    line-height: 1.1;
}
.bi-stat-label {
    font-size: 9px; font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.bi-divider {
    width: 1px; height: 28px;
    background: rgba(255, 255, 255, 0.15);
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
.providers-toolbar {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px 12px 0 0;
    padding: 12px 18px;
    position: relative;
    overflow: hidden;
}
.providers-toolbar::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.07);
    border-radius: 50%; pointer-events: none;
}
.providers-toolbar-inner {
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
    font-size: 8px;
    transition: all 0.2s ease;
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

/* PROVIDERS WRAPPER */
.providers-wrapper {
    background: var(--bg-card);
    border-radius: 0 0 14px 14px;
    border: 1.5px solid var(--border-color);
    border-top: none;
    padding: 20px;
    overflow-x: auto;
    scroll-behavior: smooth;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.providers-wrapper::-webkit-scrollbar { height: 8px; }
.providers-wrapper::-webkit-scrollbar-track { background: var(--bg-input); border-radius: 4px; }
.providers-wrapper::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
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
    position: relative;
}
.provider-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
    border-color: #FCA5A5;
}
.provider-card.inactive { opacity: 0.85; }
.provider-card.has-amount {
    border-left: 4px solid #F59E0B;
}
.provider-card.hidden-by-search { display: none !important; }

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
    display: flex;
    align-items: flex-start;
    gap: 12px;
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
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 9.5px;
    font-weight: 800;
    color: #DC2626;
    background: #FEF2F2;
    padding: 3px 8px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.4px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
}
html.dark-mode .provider-code-chip {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.provider-code-chip i { font-size: 8px; }

.status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 9.5px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    flex-shrink: 0;
}
.status-pill i { font-size: 8px; }
.status-pill.active {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.status-pill.inactive {
    background: #F3F4F6; color: #6B7280;
    border: 1.5px solid #E5E7EB;
}
html.dark-mode .status-pill.active {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .status-pill.inactive {
    background: #334155; color: #94A3B8; border-color: #475569;
}

.provider-meta-row {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-items: center;
}
.type-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.type-pill i { font-size: 9px; }
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

.has-amount-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    background: #FEF3C7;
    color: #D97706;
    border: 1.5px solid #FDE68A;
    white-space: nowrap;
}
.has-amount-pill i { font-size: 9px; }
html.dark-mode .has-amount-pill {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}

/* AMOUNTS GRID */
.amounts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    padding-top: 4px;
}
.amount-mini {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 8px 4px;
    border-radius: 8px;
    border: 1.5px solid;
    min-width: 0;
    text-align: center;
    transition: all 0.2s ease;
}
.amount-mini:hover { transform: translateY(-1px); }
.amount-mini-icon {
    width: 26px; height: 26px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
}
.amount-mini-info {
    display: flex; flex-direction: column; gap: 1px;
    min-width: 0; width: 100%;
}
.amount-mini-label {
    font-size: 8px; font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.amount-mini-value {
    font-size: 10.5px; font-weight: 900;
    font-family: 'Courier New', monospace;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

.amount-float {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-color: #93C5FD;
}
.amount-float .amount-mini-icon {
    background: #DBEAFE; color: #1D4ED8;
}
.amount-float .amount-mini-value { color: #1D4ED8; }

.amount-deposit {
    background: linear-gradient(135deg, #F0FDF4 0%, #D1FAE5 100%);
    border-color: #A7F3D0;
}
.amount-deposit .amount-mini-icon {
    background: #D1FAE5; color: #059669;
}
.amount-deposit .amount-mini-value { color: #059669; }

.amount-withdrawal {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #FCA5A5;
}
.amount-withdrawal .amount-mini-icon {
    background: #FEE2E2; color: #DC2626;
}
.amount-withdrawal .amount-mini-value { color: #DC2626; }

html.dark-mode .amount-float {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
html.dark-mode .amount-float .amount-mini-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .amount-float .amount-mini-value { color: #93C5FD; }

html.dark-mode .amount-deposit {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-color: #10B981;
}
html.dark-mode .amount-deposit .amount-mini-icon { background: #065F46; color: #34D399; }
html.dark-mode .amount-deposit .amount-mini-value { color: #6EE7B7; }

html.dark-mode .amount-withdrawal {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626;
}
html.dark-mode .amount-withdrawal .amount-mini-icon { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .amount-withdrawal .amount-mini-value { color: #FCA5A5; }

/* FOOTER ACTIONS */
.provider-card-footer {
    display: flex; gap: 6px;
    padding: 12px 14px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
}
html.dark-mode .provider-card-footer { background: #0f172a; }

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
    padding: 0 8px;
    gap: 5px;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-card-action span { display: inline; }

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
.action-locked {
    background: #F3F4F6; color: #9CA3AF;
    border-color: #E5E7EB;
    cursor: not-allowed;
    opacity: 0.7;
}
.action-locked:hover {
    transform: none;
}
html.dark-mode .action-edit { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .action-delete { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .action-locked { background: #334155; color: #64748B; border-color: #475569; }

/* NO RESULTS */
.no-results {
    padding: 50px 20px;
    text-align: center;
    background: var(--bg-input);
    border-radius: 12px;
    margin-top: 16px;
}
.no-results i {
    font-size: 48px; color: var(--text-light);
    opacity: 0.4; margin-bottom: 12px;
    display: block;
}
.no-results p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 16px 0;
}
.btn-clear-search {
    padding: 10px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border: none; border-radius: 10px;
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
    font-size: 64px; color: #FCA5A5;
    opacity: 0.5; margin-bottom: 20px;
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
    color: #FFFFFF; border-radius: 10px;
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
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .branch-indicator {
        flex-direction: column; align-items: flex-start;
    }
    .branch-indicator-stats { width: 100%; justify-content: center; }

    .stats-grid { grid-template-columns: 1fr; }

    .providers-wrapper { padding: 14px; }
    .providers-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .amounts-grid { grid-template-columns: 1fr; }
    .btn-card-action span { display: none; }
    .btn-card-action i { font-size: 14px; }
    .bi-stat-num { font-size: 14px; }
    .bi-stat-label { font-size: 8px; }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(name, code) {
    return confirm('Delete "' + name + '" (' + code + ') from this branch?\n\nThis provider has no balance or transactions and can be safely removed.');
}

// ============================================================
// SEARCH
// ============================================================
function onProviderSearch(input) {
    var term = input.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.provider-card');
    var clearBtn = document.getElementById('providerSearchClear');
    var noResults = document.getElementById('noResults');
    var grid = document.getElementById('providersGrid');

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

function clearProviderSearch() {
    var input = document.getElementById('providerSearch');
    if (!input) return;
    input.value = '';
    onProviderSearch(input);
    input.focus();
}

// ============================================================
// SCROLL < >
// ============================================================
function scrollProviders(direction) {
    var wrapper = document.getElementById('providersWrapper');
    if (!wrapper) return;
    var scrollAmount = 350;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
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