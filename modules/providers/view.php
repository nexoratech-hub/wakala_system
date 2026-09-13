<?php
// ================================================================
// FILE: modules/providers/view.php
// WAKALA FINANCIAL SYSTEM - VIEW PROVIDER DETAILS
// RED THEME + BRANCH ASSIGNMENTS + STATS
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
// GET PROVIDER ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH PROVIDER
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE id = ?");
$stmt->execute([$id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH ASSIGNED BRANCHES
// ============================================================
$assigned_branches = [];
try {
    $stmt = $db->prepare("
        SELECT bp.*,
               b.branch_name,
               b.branch_code,
               b.location,
               b.phone,
               b.email,
               b.is_active AS branch_active
        FROM branch_providers bp
        LEFT JOIN branches b ON bp.branch_id = b.id
        WHERE bp.provider_id = ?
        ORDER BY b.branch_name ASC
    ");
    $stmt->execute([$id]);
    $assigned_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $assigned_branches = [];
}

// ============================================================
// FETCH RECENT TRANSFERS
// ============================================================
$transfers = [];
$total_transfers = 0;
$total_transfer_amount = 0;
try {
    $stmt = $db->prepare("
        SELECT t.*, b.branch_name AS branch_display
        FROM transfers t
        LEFT JOIN branches b ON t.branch_id = b.id
        WHERE t.provider_id = ?
        ORDER BY t.id DESC
        LIMIT 8
    ");
    $stmt->execute([$id]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
        FROM transfers
        WHERE provider_id = ?
    ");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_transfers = intval($t['cnt'] ?? 0);
    $total_transfer_amount = floatval($t['total'] ?? 0);
} catch (Exception $e) {
    $transfers = [];
}

// ============================================================
// FETCH TOTAL FLOAT (across latest daily reports per branch)
// ============================================================
$total_float = 0;
try {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(drp.current_float), 0) as total_float
        FROM daily_report_providers drp
        INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
        WHERE drp.provider_id = ?
        AND dr.id IN (
            SELECT MAX(id) FROM daily_reports GROUP BY branch_id
        )
    ");
    $stmt->execute([$id]);
    $total_float = floatval($stmt->fetchColumn());
} catch (Exception $e) {
    $total_float = 0;
}

// ============================================================
// DERIVED VALUES
// ============================================================
$color         = $provider['color_code'] ?? '#0B5ED7';
$icon          = $provider['icon_class'] ?? 'fas fa-university';
$provider_type = $provider['provider_type'] ?? 'bank';
$type_label    = ucfirst(str_replace('_', ' ', $provider_type));
$type_icon     = $provider_type === 'mobile_money' ? 'fa-mobile-alt' : ($provider_type === 'other' ? 'fa-coins' : 'fa-landmark');

$is_active = intval($provider['is_active'] ?? 1) === 1;
$branch_count = count($assigned_branches);

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
                <h2><i class="fas fa-university"></i> Provider Details</h2>
                <p class="text-muted">
                    <i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?>
                    &nbsp;·&nbsp;
                    <?php echo htmlspecialchars($provider['provider_name']); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button type="button" class="btn btn-delete"
                        onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($provider['provider_name']); ?>')">
                    <i class="fas fa-trash"></i> Delete
                </button>
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
        PROVIDER HERO
        ============================================================ -->
        <div class="provider-hero" style="--provider-color: <?php echo htmlspecialchars($color); ?>;">
            <div class="provider-hero-bg"></div>
            <div class="provider-hero-content">
                <div class="provider-avatar-wrapper">
                    <div class="provider-avatar-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                        <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                    </div>
                    <?php if ($is_active): ?>
                        <span class="provider-status-dot status-active" title="Active"></span>
                    <?php else: ?>
                        <span class="provider-status-dot status-inactive" title="Inactive"></span>
                    <?php endif; ?>
                </div>

                <div class="provider-hero-info">
                    <h1 class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></h1>
                    <div class="provider-meta">
                        <span class="meta-chip meta-code">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?>
                        </span>
                        <span class="meta-chip type-<?php echo $provider_type; ?>">
                            <i class="fas <?php echo $type_icon; ?>"></i>
                            <?php echo htmlspecialchars($type_label); ?>
                        </span>
                        <?php if ($is_active): ?>
                            <span class="meta-chip status-active-chip">
                                <i class="fas fa-check-circle"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="meta-chip status-inactive-chip">
                                <i class="fas fa-times-circle"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="provider-hero-actions">
                    <a href="edit.php?id=<?php echo $id; ?>" class="btn-hero">
                        <i class="fas fa-edit"></i> Edit Provider
                    </a>
                </div>
            </div>
        </div>

        <!-- ============================================================
        QUICK STATS
        ============================================================ -->
        <div class="quick-stats">
            <div class="quick-stat">
                <div class="quick-stat-icon quick-red">
                    <i class="fas fa-store"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Assigned Branches</span>
                    <span class="quick-stat-value"><?php echo number_format($branch_count); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-blue">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Total Transfers</span>
                    <span class="quick-stat-value"><?php echo number_format($total_transfers); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-green">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Current Total Float</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-orange">
                    <i class="far fa-calendar-plus"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Added On</span>
                    <span class="quick-stat-value">
                        <?php echo !empty($provider['created_at']) ? date('d M Y', strtotime($provider['created_at'])) : '-'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILS GRID
        ============================================================ -->
        <div class="details-grid">

            <!-- PROVIDER INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-red">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h3>Provider Information</h3>
                            <p>Basic provider details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-building"></i> Provider Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-tag"></i> Provider Code</span>
                        <span class="info-value">
                            <span class="chip-red"><?php echo htmlspecialchars($provider['provider_code'] ?? '-'); ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas <?php echo $type_icon; ?>"></i> Type</span>
                        <span class="info-value">
                            <span class="badge type-<?php echo $provider_type; ?>">
                                <i class="fas <?php echo $type_icon; ?>"></i>
                                <?php echo htmlspecialchars($type_label); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-palette"></i> Brand Color</span>
                        <span class="info-value">
                            <span class="color-preview">
                                <span class="color-swatch" style="background: <?php echo htmlspecialchars($color); ?>;"></span>
                                <span class="color-code"><?php echo htmlspecialchars($color); ?></span>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-star"></i> Icon</span>
                        <span class="info-value">
                            <span class="chip-mono">
                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                <?php echo htmlspecialchars($icon); ?>
                            </span>
                        </span>
                    </div>
                    <?php if (isset($provider['display_order'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-sort-numeric-up"></i> Display Order</span>
                        <span class="info-value"><?php echo intval($provider['display_order']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-toggle-on"></i> Status</span>
                        <span class="info-value">
                            <?php if ($is_active): ?>
                                <span class="badge badge-active">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="badge badge-inactive">
                                    <i class="fas fa-times-circle"></i> Inactive
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- SYSTEM INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-purple">
                            <i class="fas fa-server"></i>
                        </div>
                        <div>
                            <h3>System Information</h3>
                            <p>Provider metadata</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-hashtag"></i> Provider ID</span>
                        <span class="info-value">
                            <span class="chip-mono">#<?php echo $provider['id']; ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-store"></i> Branch Assignments</span>
                        <span class="info-value"><?php echo number_format($branch_count); ?> branch<?php echo $branch_count !== 1 ? 'es' : ''; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-exchange-alt"></i> Transfers Processed</span>
                        <span class="info-value"><?php echo number_format($total_transfers); ?></span>
                    </div>
                    <?php if ($total_transfer_amount > 0): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-money-bill-wave"></i> Total Volume</span>
                        <span class="info-value salary"><?php echo formatCurrency($total_transfer_amount); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-calendar-plus"></i> Created</span>
                        <span class="info-value">
                            <?php echo !empty($provider['created_at']) ? date('d M Y H:i', strtotime($provider['created_at'])) : '-'; ?>
                        </span>
                    </div>
                    <?php if (!empty($provider['updated_at'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($provider['updated_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
        ASSIGNED BRANCHES
        ============================================================ -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon">
                        <i class="fas fa-store-alt"></i>
                    </div>
                    <div>
                        <h3>Assigned Branches</h3>
                        <p><?php echo $branch_count; ?> branch<?php echo $branch_count !== 1 ? 'es' : ''; ?> using this provider</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-store"></i> <?php echo $branch_count; ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <?php if (count($assigned_branches) > 0): ?>
                    <div class="mini-table-wrapper">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Branch</th>
                                    <th>Code</th>
                                    <th>Location</th>
                                    <th>Provider Code</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($assigned_branches as $b): ?>
                                <tr>
                                    <td><span class="row-num"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="branch-cell">
                                            <i class="fas fa-store-alt"></i>
                                            <span><?php echo htmlspecialchars($b['branch_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="chip-mono"><?php echo htmlspecialchars($b['branch_code'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($b['location'])): ?>
                                            <span class="location-cell">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($b['location']); ?>
                                            </span>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="chip-red-sm"><?php echo htmlspecialchars($b['provider_code'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <?php if (intval($b['branch_active'] ?? 1) === 1): ?>
                                            <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-section">
                        <i class="fas fa-store-slash"></i>
                        <p>This provider is not assigned to any branch yet.</p>
                        <a href="edit.php?id=<?php echo $id; ?>" class="btn-primary-small">
                            <i class="fas fa-plus-circle"></i> Assign to Branch
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        RECENT TRANSFERS
        ============================================================ -->
        <?php if (count($transfers) > 0): ?>
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div>
                        <h3>Recent Transfers</h3>
                        <p>Last <?php echo count($transfers); ?> transfer<?php echo count($transfers) !== 1 ? 's' : ''; ?> using this provider</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-list"></i> <?php echo count($transfers); ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <div class="mini-table-wrapper">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Transfer #</th>
                                <th>Type</th>
                                <th>Branch</th>
                                <th class="text-right">Amount</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfers as $t):
                                $is_c2f = $t['transfer_type'] === 'cash_to_float';
                                $type_label_t = $is_c2f ? 'Cash → Float' : 'Float → Cash';
                                $type_cls = $is_c2f ? 'type-c2f' : 'type-f2c';
                            ?>
                            <tr>
                                <td>
                                    <span class="chip-mono"><?php echo htmlspecialchars($t['transfer_number']); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $type_cls; ?>">
                                        <i class="fas fa-arrow-<?php echo $is_c2f ? 'right' : 'left'; ?>"></i>
                                        <?php echo $type_label_t; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($t['branch_display'] ?? $t['branch'] ?? '-'); ?></td>
                                <td class="text-right">
                                    <span class="mini-amount <?php echo $type_cls; ?>">
                                        <?php echo formatCurrency($t['amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="mini-date">
                                        <?php echo date('d M Y', strtotime($t['transfer_date'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        ACTIONS
        ============================================================ -->
        <div class="actions-card">
            <a href="index.php" class="btn btn-secondary-large">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Providers</span>
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit-large">
                <i class="fas fa-edit"></i>
                <span>Edit Provider</span>
            </a>
            <button type="button" class="btn btn-delete-large"
                    onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($provider['provider_name']); ?>')">
                <i class="fas fa-trash"></i>
                <span>Delete Provider</span>
            </button>
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
            This action cannot be undone and may affect branches currently using this provider.
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

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace; font-weight: 600;
}
.page-header .header-right { display: flex; gap: 10px; flex-wrap: wrap; }

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
.btn-edit {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
}
.btn-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(217, 119, 6, 0.5);
}
.btn-delete {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(220, 38, 38, 0.5);
}

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

/* PROVIDER HERO */
.provider-hero {
    background: var(--bg-card);
    border-radius: 16px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    box-shadow: 0 4px 16px var(--shadow-color);
    overflow: hidden;
    position: relative;
}
.provider-hero-bg {
    height: 90px;
    background: linear-gradient(135deg, var(--provider-color, #DC2626) 0%, #B91C1C 100%);
    position: relative; overflow: hidden;
    opacity: 0.95;
}
.provider-hero-bg::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%; pointer-events: none;
}
.provider-hero-bg::after {
    content: ''; position: absolute;
    bottom: -60%; left: 30%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.provider-hero-content {
    padding: 0 26px 22px 26px;
    display: flex; align-items: flex-end; gap: 22px;
    flex-wrap: wrap; margin-top: -50px;
    position: relative; z-index: 1;
}
.provider-avatar-wrapper { position: relative; flex-shrink: 0; }
.provider-avatar-icon {
    width: 100px; height: 100px;
    border-radius: 24px;
    border: 4px solid #FFFFFF;
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 44px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}
html.dark-mode .provider-avatar-icon { border-color: #334155; }
.provider-status-dot {
    position: absolute; bottom: 6px; right: 6px;
    width: 22px; height: 22px; border-radius: 50%;
    border: 3.5px solid #FFFFFF;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
html.dark-mode .provider-status-dot { border-color: #1e293b; }
.provider-status-dot.status-active   { background: #10B981; }
.provider-status-dot.status-inactive { background: #6B7280; }

.provider-hero-info { flex: 1; min-width: 0; padding-bottom: 4px; }
.provider-name {
    font-size: 24px; font-weight: 900;
    margin: 0 0 10px 0;
    color: var(--text-primary);
    word-break: break-word; line-height: 1.2;
}
.provider-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
.meta-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 8px;
    font-size: 11.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
}
.meta-chip i { font-size: 10px; }
.meta-code {
    background: #FEF2F2; color: #DC2626;
    border: 1.5px solid #FCA5A5;
    font-family: 'Courier New', monospace;
}
html.dark-mode .meta-code { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.meta-chip.type-bank         { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.meta-chip.type-mobile_money { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.meta-chip.type-other        { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .meta-chip.type-bank         { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .meta-chip.type-mobile_money { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .meta-chip.type-other        { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
.meta-chip.status-active-chip {
    background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0;
}
.meta-chip.status-inactive-chip {
    background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB;
}
html.dark-mode .meta-chip.status-active-chip { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .meta-chip.status-inactive-chip { background: #334155; color: #94A3B8; border-color: #475569; }
.provider-hero-actions { padding-bottom: 4px; flex-shrink: 0; }
.btn-hero {
    padding: 11px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-radius: 10px;
    font-weight: 700; font-size: 13px;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-hero:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}

/* QUICK STATS */
.quick-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.quick-stat {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; min-width: 0;
}
.quick-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: var(--red-primary);
}
.quick-stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0; color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.quick-red    { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.quick-blue   { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.quick-green  { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.quick-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.quick-stat-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.quick-stat-label {
    font-size: 10.5px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 800;
    color: var(--text-muted);
}
.quick-stat-value {
    font-size: 16px; font-weight: 900;
    color: var(--text-primary);
    word-break: break-word; line-height: 1.2;
}

/* DETAILS GRID */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 18px;
}
.detail-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.detail-card:hover {
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.detail-card-header {
    padding: 16px 20px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
}
.detail-card-header-left { display: flex; align-items: center; gap: 14px; }
.detail-card-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.icon-red    { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.icon-purple { background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%); }
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
    align-items: flex-start;
    padding: 11px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 12px;
    flex-wrap: wrap;
}
.info-row:last-child { border-bottom: none; }
.info-label {
    font-size: 12px; font-weight: 700;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.info-label i {
    color: #DC2626; font-size: 11px;
    width: 14px; text-align: center;
}
.info-value {
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    text-align: right; word-break: break-word;
    display: flex; align-items: center; gap: 8px;
    justify-content: flex-end; flex-wrap: wrap;
}
.info-value.salary {
    font-family: 'Courier New', monospace;
    color: #059669; font-size: 14px; font-weight: 900;
}
html.dark-mode .info-value.salary { color: #34D399; }

.chip-red {
    display: inline-block;
    padding: 4px 12px;
    background: #FEF2F2; color: #DC2626;
    border: 1.5px solid #FCA5A5;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px; font-weight: 800;
    letter-spacing: 0.4px;
}
html.dark-mode .chip-red { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.chip-red-sm {
    display: inline-block;
    padding: 2px 8px;
    background: #FEF2F2; color: #DC2626;
    border: 1px solid #FCA5A5;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 10px; font-weight: 800;
    letter-spacing: 0.3px;
}
.chip-mono {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 11.5px; font-weight: 800;
    letter-spacing: 0.3px;
}
.color-preview {
    display: inline-flex; align-items: center; gap: 8px;
}
.color-swatch {
    width: 24px; height: 24px;
    border-radius: 6px;
    border: 2px solid var(--border-color);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.color-code {
    font-family: 'Courier New', monospace;
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    text-transform: uppercase;
}

.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.badge i { font-size: 10px; }
.badge-active { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.badge-inactive { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .badge-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .badge-inactive { background: #334155; color: #94A3B8; border-color: #475569; }
.type-bank         { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.type-mobile_money { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.type-other        { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .type-bank         { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-mobile_money { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-other        { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
.type-c2f { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.type-f2c { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .type-c2f { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-f2c { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

/* SECTION CARDS */
.section-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 18px;
}
.section-card-header {
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    display: flex; justify-content: space-between; align-items: center;
    color: #FFFFFF; gap: 12px; flex-wrap: wrap;
    position: relative; overflow: hidden;
}
.section-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.section-card-header-left {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1;
}
.section-card-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(6px);
    flex-shrink: 0;
}
.section-card-header h3 {
    font-size: 16px; font-weight: 800;
    margin: 0 0 2px 0; color: #FFFFFF;
}
.section-card-header p {
    font-size: 12px; margin: 0;
    color: rgba(255,255,255,0.85);
    font-weight: 500;
}
.count-badge-red {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 16px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border-radius: 12px;
    font-size: 13px; font-weight: 900;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
}
.count-badge-red i { color: #FCD34D; font-size: 12px; }
.section-card-body { padding: 22px; }
.section-card-body.no-padding { padding: 0; }

/* MINI TABLE */
.mini-table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.mini-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    min-width: 700px;
}
.mini-table thead { background: var(--bg-input); }
.mini-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 800; font-size: 10.5px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.7px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.mini-table thead th.text-right { text-align: right; }
.mini-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: all 0.2s ease;
}
.mini-table tbody tr:hover { background: #FEF2F2; }
html.dark-mode .mini-table tbody tr:hover { background: #2d1f1f; }
.mini-table tbody tr:last-child { border-bottom: none; }
.mini-table tbody td {
    padding: 12px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.mini-table tbody td.text-right { text-align: right; }

.row-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--bg-input);
    font-size: 11px; font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.branch-cell {
    display: flex; align-items: center; gap: 8px;
    font-weight: 700;
}
.branch-cell i { color: #DC2626; font-size: 13px; }
.location-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px;
    color: var(--text-secondary);
}
.location-cell i { color: #DC2626; font-size: 10px; }

.mini-amount {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: 12.5px;
}
.mini-amount.type-c2f { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.mini-amount.type-f2c { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .mini-amount.type-c2f { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .mini-amount.type-f2c { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
.mini-date {
    font-size: 11.5px; font-weight: 600;
    color: var(--text-secondary);
}

.empty-section {
    padding: 50px 20px;
    text-align: center;
    color: var(--text-muted);
}
.empty-section i {
    font-size: 52px;
    color: #FCA5A5;
    opacity: 0.5;
    margin-bottom: 14px;
    display: block;
}
.empty-section p {
    font-size: 14px;
    margin: 0 0 16px 0;
}
.btn-primary-small {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-primary-small:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}

/* ACTIONS CARD */
.actions-card {
    display: flex; gap: 12px;
    padding: 22px 26px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap;
    justify-content: flex-end;
    position: sticky;
    bottom: 16px;
    z-index: 10;
}
.btn-secondary-large, .btn-edit-large, .btn-delete-large {
    padding: 13px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    min-width: 160px;
    justify-content: center;
}
.btn-secondary-large {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary-large:hover {
    background: var(--bg-body);
    color: var(--text-primary);
    transform: translateY(-2px);
}
.btn-edit-large {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}
.btn-edit-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(217, 119, 6, 0.5);
}
.btn-delete-large {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-delete-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}

/* MODAL */
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
.modal-actions { display: flex; gap: 10px; }
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

/* RESPONSIVE */
@media (max-width: 1024px) {
    .quick-stats { grid-template-columns: repeat(2, 1fr); }
    .details-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .provider-hero-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 0 20px 22px 20px;
    }
    .provider-hero-info { text-align: center; }
    .provider-meta { justify-content: center; }
    .provider-name { font-size: 20px; }

    .quick-stats { grid-template-columns: 1fr; }

    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    .info-value {
        text-align: left;
        justify-content: flex-start;
        width: 100%;
    }

    .actions-card {
        flex-direction: column;
        position: static;
        padding: 16px;
    }
    .btn-secondary-large,
    .btn-edit-large,
    .btn-delete-large { width: 100%; }
}
@media (max-width: 480px) {
    .provider-avatar-icon { width: 80px; height: 80px; font-size: 34px; }
    .provider-status-dot { width: 18px; height: 18px; border-width: 3px; }
    .provider-name { font-size: 18px; }
    .quick-stat { padding: 14px 16px; gap: 10px; }
    .quick-stat-icon { width: 40px; height: 40px; font-size: 17px; }
    .quick-stat-value { font-size: 14px; }
    .detail-card-header { padding: 14px 16px; }
    .detail-card-icon { width: 40px; height: 40px; font-size: 17px; }
    .detail-card-body { padding: 14px 16px; }
    .section-card-body { padding: 16px; }
}
</style>

<script>
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

document.addEventListener('DOMContentLoaded', function() {
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