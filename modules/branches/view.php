<?php
// ================================================================
// FILE: modules/branches/view.php
// WAKALA FINANCIAL SYSTEM - VIEW BRANCH DETAILS
// RED THEME + PROVIDERS + EMPLOYEES + FINANCIAL SUMMARY
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
// GET BRANCH ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH BRANCH
// ============================================================
$stmt = $db->prepare("
    SELECT b.*,
           e.full_name AS manager_name,
           e.email AS manager_email,
           e.phone AS manager_phone,
           e.profile_pic AS manager_avatar,
           e.employee_id AS manager_code
    FROM branches b
    LEFT JOIN employees e ON b.manager_id = e.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// ASSIGNED PROVIDERS
// ============================================================
$providers = [];
try {
    $stmt = $db->prepare("
        SELECT bp.*,
               p.provider_name,
               p.provider_type,
               p.icon_class,
               p.color_code,
               COALESCE(drp.current_float, 0) AS current_float
        FROM branch_providers bp
        LEFT JOIN providers p ON bp.provider_id = p.id
        LEFT JOIN daily_reports dr ON dr.branch_id = bp.branch_id
        LEFT JOIN daily_report_providers drp
               ON drp.provider_id = p.id AND drp.daily_report_id = dr.id
        WHERE bp.branch_id = ? AND bp.is_active = 1
        GROUP BY bp.id
        ORDER BY p.provider_name ASC
    ");
    $stmt->execute([$id]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $providers = [];
}

// ============================================================
// EMPLOYEES (top 10)
// ============================================================
$employees = [];
$total_employees = 0;
try {
    $stmt = $db->prepare("
        SELECT id, employee_id, full_name, email, phone, role, profile_pic,
               employment_status, is_active
        FROM employees
        WHERE branch_id = ?
        ORDER BY full_name ASC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $total_employees = intval($stmt->fetchColumn());
} catch (Exception $e) {
    $employees = [];
}

// ============================================================
// LATEST DAILY REPORT
// ============================================================
$latest_dr = null;
$today_float = 0;
$today_cash = 0;
$today_capital = 0;

try {
    $stmt = $db->prepare("
        SELECT * FROM daily_reports
        WHERE branch_id = ?
        ORDER BY report_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($latest_dr) {
        $today_cash = floatval($latest_dr['current_cash'] ?? 0);

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(current_float), 0) FROM daily_report_providers
            WHERE daily_report_id = ?
        ");
        $stmt->execute([$latest_dr['id']]);
        $today_float = floatval($stmt->fetchColumn());

        $today_capital = $today_float + $today_cash;
    }
} catch (Exception $e) {}

// ============================================================
// RECENT TRANSFERS
// ============================================================
$transfers = [];
$total_transfers = 0;
try {
    $stmt = $db->prepare("
        SELECT t.*, p.provider_name
        FROM transfers t
        LEFT JOIN providers p ON t.provider_id = p.id
        WHERE t.branch_id = ?
        ORDER BY t.id DESC
        LIMIT 8
    ");
    $stmt->execute([$id]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(*) FROM transfers WHERE branch_id = ?");
    $stmt->execute([$id]);
    $total_transfers = intval($stmt->fetchColumn());
} catch (Exception $e) {
    $transfers = [];
}

// ============================================================
// DERIVED
// ============================================================
$is_active = intval($branch['is_active'] ?? 1) === 1;
$initial = strtoupper(substr($branch['branch_name'] ?? 'B', 0, 1));
$provider_count = count($providers);

$manager_avatar = $branch['manager_avatar'] ?? '';
$manager_has_avatar = !empty($manager_avatar) && file_exists('../../' . $manager_avatar);
$manager_initial = strtoupper(substr($branch['manager_name'] ?? 'N', 0, 1));

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
                <h2><i class="fas fa-store-alt"></i> Branch Details</h2>
                <p class="text-muted">
                    <i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($branch['branch_code'] ?? 'N/A'); ?>
                    &nbsp;·&nbsp;
                    <?php echo htmlspecialchars($branch['branch_name']); ?>
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
                        onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($branch['branch_name']); ?>')">
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
        BRANCH HERO
        ============================================================ -->
        <div class="branch-hero">
            <div class="branch-hero-bg"></div>
            <div class="branch-hero-content">
                <div class="branch-avatar-wrapper">
                    <div class="branch-avatar-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="branch-status-dot <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>"
                          title="<?php echo $is_active ? 'Active' : 'Inactive'; ?>"></span>
                </div>

                <div class="branch-hero-info">
                    <h1 class="branch-name"><?php echo htmlspecialchars($branch['branch_name']); ?></h1>
                    <div class="branch-meta">
                        <span class="meta-chip meta-code">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($branch['branch_code'] ?? 'N/A'); ?>
                        </span>
                        <span class="meta-chip <?php echo $is_active ? 'chip-active' : 'chip-inactive'; ?>">
                            <i class="fas fa-<?php echo $is_active ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php if (!empty($branch['location'])): ?>
                            <span class="meta-chip meta-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($branch['location']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($branch['manager_name'])): ?>
                    <div class="branch-manager-line">
                        <i class="fas fa-user-tie"></i>
                        <span>Manager: <strong><?php echo htmlspecialchars($branch['manager_name']); ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="branch-hero-actions">
                    <a href="edit.php?id=<?php echo $id; ?>" class="btn-hero">
                        <i class="fas fa-edit"></i> Edit Branch
                    </a>
                </div>
            </div>
        </div>

        <!-- ============================================================
        QUICK STATS
        ============================================================ -->
        <div class="quick-stats">
            <div class="quick-stat">
                <div class="quick-stat-icon quick-blue">
                    <i class="fas fa-users"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Employees</span>
                    <span class="quick-stat-value"><?php echo number_format($total_employees); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-red">
                    <i class="fas fa-university"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Providers</span>
                    <span class="quick-stat-value"><?php echo number_format($provider_count); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-green">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Total Float</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_float); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-orange">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Cash Balance</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_cash); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILS GRID
        ============================================================ -->
        <div class="details-grid">

            <!-- BRANCH INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-red">
                            <i class="fas fa-store-alt"></i>
                        </div>
                        <div>
                            <h3>Branch Information</h3>
                            <p>Basic branch details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-store"></i> Branch Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-tag"></i> Branch Code</span>
                        <span class="info-value">
                            <span class="chip-red"><?php echo htmlspecialchars($branch['branch_code'] ?? '-'); ?></span>
                        </span>
                    </div>
                    <?php if (!empty($branch['location'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['location']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-toggle-on"></i> Status</span>
                        <span class="info-value">
                            <?php if ($is_active): ?>
                                <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTACT INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-green">
                            <i class="fas fa-address-book"></i>
                        </div>
                        <div>
                            <h3>Contact Information</h3>
                            <p>How to reach this branch</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value">
                            <?php if (!empty($branch['phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($branch['phone']); ?>" class="link-red">
                                    <?php echo htmlspecialchars($branch['phone']); ?>
                                </a>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-value">
                            <?php if (!empty($branch['email'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($branch['email']); ?>" class="link-red">
                                    <?php echo htmlspecialchars($branch['email']); ?>
                                </a>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($branch['address'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-map"></i> Address</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch['address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- MANAGER INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-blue">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <h3>Branch Manager</h3>
                            <p>Who runs this branch</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <?php if (!empty($branch['manager_name'])): ?>
                        <div class="manager-box">
                            <?php if ($manager_has_avatar): ?>
                                <img src="../../<?php echo htmlspecialchars($manager_avatar); ?>"
                                     class="manager-avatar-img"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="manager-avatar" style="display:none;"><?php echo $manager_initial; ?></div>
                            <?php else: ?>
                                <div class="manager-avatar"><?php echo $manager_initial; ?></div>
                            <?php endif; ?>
                            <div class="manager-info">
                                <span class="manager-name"><?php echo htmlspecialchars($branch['manager_name']); ?></span>
                                <?php if (!empty($branch['manager_code'])): ?>
                                    <span class="manager-code"><?php echo htmlspecialchars($branch['manager_code']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($branch['manager_email'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                            <span class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($branch['manager_email']); ?>" class="link-red">
                                    <?php echo htmlspecialchars($branch['manager_email']); ?>
                                </a>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($branch['manager_phone'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['manager_phone']); ?></span>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-manager">
                            <i class="fas fa-user-slash"></i>
                            <p>No manager assigned</p>
                        </div>
                    <?php endif; ?>
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
                            <p>Metadata</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-hashtag"></i> Branch ID</span>
                        <span class="info-value">
                            <span class="chip-mono">#<?php echo $branch['id']; ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-users"></i> Total Employees</span>
                        <span class="info-value"><?php echo number_format($total_employees); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-university"></i> Total Providers</span>
                        <span class="info-value"><?php echo number_format($provider_count); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-exchange-alt"></i> Total Transfers</span>
                        <span class="info-value"><?php echo number_format($total_transfers); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-calendar-plus"></i> Created</span>
                        <span class="info-value">
                            <?php echo !empty($branch['created_at']) ? date('d M Y H:i', strtotime($branch['created_at'])) : '-'; ?>
                        </span>
                    </div>
                    <?php if (!empty($branch['updated_at'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($branch['updated_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
        FINANCIAL SNAPSHOT
        ============================================================ -->
        <?php if ($latest_dr): ?>
        <div class="finance-card">
            <div class="finance-header">
                <div class="finance-header-left">
                    <div class="finance-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3>Latest Financial Snapshot</h3>
                        <p>
                            Daily report · <?php echo date('d M Y', strtotime($latest_dr['report_date'])); ?>
                        </p>
                    </div>
                </div>
                <a href="../daily_report/view.php?id=<?php echo $latest_dr['id']; ?>" class="btn-link-light">
                    <i class="fas fa-external-link-alt"></i>
                    View Report
                </a>
            </div>
            <div class="finance-body">
                <div class="finance-item">
                    <div class="finance-item-icon finance-float">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="finance-item-info">
                        <span class="finance-item-label">Total Float</span>
                        <span class="finance-item-value"><?php echo formatCurrency($today_float); ?></span>
                    </div>
                </div>
                <div class="finance-item">
                    <div class="finance-item-icon finance-cash">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="finance-item-info">
                        <span class="finance-item-label">Cash Balance</span>
                        <span class="finance-item-value"><?php echo formatCurrency($today_cash); ?></span>
                    </div>
                </div>
                <div class="finance-item finance-item-highlight">
                    <div class="finance-item-icon finance-capital">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="finance-item-info">
                        <span class="finance-item-label">Total Capital</span>
                        <span class="finance-item-value"><?php echo formatCurrency($today_capital); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        ASSIGNED PROVIDERS
        ============================================================ -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <h3>Assigned Providers</h3>
                        <p><?php echo $provider_count; ?> provider<?php echo $provider_count !== 1 ? 's' : ''; ?> on this branch</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-university"></i> <?php echo $provider_count; ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <?php if (count($providers) > 0): ?>
                    <div class="table-mini-wrapper">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>Provider</th>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th class="text-right">Current Float</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($providers as $p):
                                    $p_color = $p['color_code'] ?? '#0B5ED7';
                                    $p_icon = $p['icon_class'] ?? 'fas fa-university';
                                    $p_type = $p['provider_type'] ?? 'bank';
                                    $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                                    $p_type_icon = $p_type === 'mobile_money' ? 'fa-mobile-alt' : ($p_type === 'other' ? 'fa-coins' : 'fa-landmark');
                                ?>
                                <tr>
                                    <td><span class="row-num"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="provider-cell-mini">
                                            <div class="provider-icon-mini" style="background: <?php echo htmlspecialchars($p_color); ?>;">
                                                <i class="<?php echo htmlspecialchars($p_icon); ?>"></i>
                                            </div>
                                            <span class="provider-name-mini"><?php echo htmlspecialchars($p['provider_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="chip-mono"><?php echo htmlspecialchars($p['provider_code'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <span class="type-pill type-<?php echo $p_type; ?>">
                                            <i class="fas <?php echo $p_type_icon; ?>"></i>
                                            <?php echo htmlspecialchars($p_type_label); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="float-mini"><?php echo formatCurrency($p['current_float'] ?? 0); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-section">
                        <i class="fas fa-university"></i>
                        <p>No providers assigned to this branch yet.</p>
                        <a href="../providers/index.php?branch_id=<?php echo $id; ?>" class="btn-primary-small">
                            <i class="fas fa-plus-circle"></i> Manage Providers
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        EMPLOYEES
        ============================================================ -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <h3>Employees</h3>
                        <p><?php echo $total_employees; ?> active employee<?php echo $total_employees !== 1 ? 's' : ''; ?> at this branch</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-users"></i> <?php echo $total_employees; ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <?php if (count($employees) > 0): ?>
                    <div class="table-mini-wrapper">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>Employee</th>
                                    <th>Contact</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($employees as $e):
                                    $e_initial = strtoupper(substr($e['full_name'] ?? 'N', 0, 1));
                                    $e_avatar = $e['profile_pic'] ?? '';
                                    $e_has_avatar = !empty($e_avatar) && file_exists('../../' . $e_avatar);
                                    $e_status = $e['employment_status'] ?? 'active';
                                    $e_status_icon = [
                                        'active' => 'fa-check-circle',
                                        'on_leave' => 'fa-clock',
                                        'suspended' => 'fa-pause-circle',
                                        'terminated' => 'fa-ban',
                                    ][$e_status] ?? 'fa-circle';
                                ?>
                                <tr>
                                    <td><span class="row-num"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="employee-cell-mini">
                                            <?php if ($e_has_avatar): ?>
                                                <img src="../../<?php echo htmlspecialchars($e_avatar); ?>"
                                                     class="employee-avatar-mini"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="employee-initial" style="display:none;"><?php echo $e_initial; ?></div>
                                            <?php else: ?>
                                                <div class="employee-initial"><?php echo $e_initial; ?></div>
                                            <?php endif; ?>
                                            <div class="employee-text-mini">
                                                <span class="employee-name-mini"><?php echo htmlspecialchars($e['full_name']); ?></span>
                                                <?php if (!empty($e['employee_id'])): ?>
                                                    <span class="employee-code-mini"><?php echo htmlspecialchars($e['employee_id']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-mini">
                                            <?php if (!empty($e['email'])): ?>
                                                <span class="contact-line">
                                                    <i class="fas fa-envelope"></i>
                                                    <?php echo htmlspecialchars($e['email']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($e['phone'])): ?>
                                                <span class="contact-line">
                                                    <i class="fas fa-phone"></i>
                                                    <?php echo htmlspecialchars($e['phone']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-pill role-<?php echo $e['role'] ?? 'employee'; ?>">
                                            <i class="fas fa-user-tag"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $e['role'] ?? 'employee')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-pill-mini status-<?php echo $e_status; ?>">
                                            <i class="fas <?php echo $e_status_icon; ?>"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $e_status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../employees/view.php?id=<?php echo $e['id']; ?>"
                                           class="btn-mini-view" title="View Employee">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_employees > 10): ?>
                        <div class="table-footer-note">
                            <i class="fas fa-info-circle"></i>
                            Showing 10 of <?php echo number_format($total_employees); ?> employees.
                            <a href="../employees/index.php?branch_id=<?php echo $id; ?>" class="link-red">
                                View all →
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-section">
                        <i class="fas fa-users-slash"></i>
                        <p>No employees assigned to this branch yet.</p>
                        <a href="../employees/add.php" class="btn-primary-small">
                            <i class="fas fa-user-plus"></i> Add Employee
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
                        <p>Last <?php echo count($transfers); ?> transfer<?php echo count($transfers) !== 1 ? 's' : ''; ?> at this branch</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-list"></i> <?php echo count($transfers); ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <div class="table-mini-wrapper">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Transfer #</th>
                                <th>Type</th>
                                <th>Provider</th>
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
                                <td><span class="chip-mono"><?php echo htmlspecialchars($t['transfer_number']); ?></span></td>
                                <td>
                                    <span class="type-pill <?php echo $type_cls; ?>">
                                        <i class="fas fa-arrow-<?php echo $is_c2f ? 'right' : 'left'; ?>"></i>
                                        <?php echo $type_label_t; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($t['provider_name'] ?? '-'); ?></td>
                                <td class="text-right">
                                    <span class="float-mini <?php echo $type_cls; ?>">
                                        <?php echo formatCurrency($t['amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="date-mini"><?php echo date('d M Y', strtotime($t['transfer_date'])); ?></span>
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
                <span>Back to Branches</span>
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit-large">
                <i class="fas fa-edit"></i>
                <span>Edit Branch</span>
            </a>
            <button type="button" class="btn btn-delete-large"
                    onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($branch['branch_name']); ?>')">
                <i class="fas fa-trash"></i>
                <span>Delete Branch</span>
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
        <h3 class="modal-title">Delete Branch?</h3>
        <p class="modal-message">
            Are you sure you want to delete <strong id="deleteBranchName"></strong>?
            This action cannot be undone and may affect employees, providers and reports linked to this branch.
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

/* PAGE HEADER */
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

/* BRANCH HERO */
.branch-hero {
    background: var(--bg-card);
    border-radius: 16px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    box-shadow: 0 4px 16px var(--shadow-color);
    overflow: hidden;
    position: relative;
}
.branch-hero-bg {
    height: 90px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    position: relative; overflow: hidden;
}
.branch-hero-bg::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%; pointer-events: none;
}
.branch-hero-bg::after {
    content: ''; position: absolute;
    bottom: -60%; left: 30%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-hero-content {
    padding: 0 26px 22px 26px;
    display: flex; align-items: flex-end; gap: 22px;
    flex-wrap: wrap; margin-top: -50px;
    position: relative; z-index: 1;
}
.branch-avatar-wrapper { position: relative; flex-shrink: 0; }
.branch-avatar-icon {
    width: 100px; height: 100px;
    border-radius: 24px;
    border: 4px solid #FFFFFF;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 44px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}
html.dark-mode .branch-avatar-icon { border-color: #334155; }
.branch-status-dot {
    position: absolute; bottom: 6px; right: 6px;
    width: 22px; height: 22px; border-radius: 50%;
    border: 3.5px solid #FFFFFF;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
html.dark-mode .branch-status-dot { border-color: #1e293b; }
.branch-status-dot.status-active   { background: #10B981; }
.branch-status-dot.status-inactive { background: #6B7280; }

.branch-hero-info { flex: 1; min-width: 0; padding-bottom: 4px; }
.branch-name {
    font-size: 24px; font-weight: 900;
    margin: 0 0 10px 0;
    color: var(--text-primary);
    word-break: break-word; line-height: 1.2;
}
.branch-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
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
.chip-active { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.chip-inactive { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .chip-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .chip-inactive { background: #334155; color: #94A3B8; border-color: #475569; }
.meta-location {
    background: #EFF6FF; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
html.dark-mode .meta-location { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.branch-manager-line {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 6px;
}
.branch-manager-line i { color: #DC2626; font-size: 12px; }
.branch-manager-line strong { color: var(--text-primary); font-weight: 800; }

.branch-hero-actions { padding-bottom: 4px; flex-shrink: 0; }
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
.quick-blue   { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.quick-red    { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
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
    font-family: 'Inter', 'Courier New', monospace;
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
.icon-blue   { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.icon-green  { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
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
.chip-mono {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 11.5px; font-weight: 800;
}
.link-red {
    color: #DC2626; text-decoration: none; font-weight: 700;
    transition: all 0.2s ease;
    border-bottom: 1.5px dashed transparent;
}
.link-red:hover { color: #991B1B; border-bottom-color: #DC2626; }
html.dark-mode .link-red { color: #FCA5A5; }

.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
}
.badge i { font-size: 10px; }
.badge-active { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.badge-inactive { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .badge-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .badge-inactive { background: #334155; color: #94A3B8; border-color: #475569; }

/* MANAGER BOX */
.manager-box {
    display: flex; align-items: center; gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    margin-bottom: 16px;
}
html.dark-mode .manager-box {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.manager-avatar-img {
    width: 56px; height: 56px;
    border-radius: 50%; object-fit: cover;
    border: 3px solid #1D4ED8;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
}
.manager-avatar {
    width: 56px; height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 22px;
    flex-shrink: 0;
    border: 3px solid #3B82F6;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
}
.manager-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.manager-name {
    font-size: 15px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.manager-code {
    display: inline-block;
    padding: 2px 10px;
    background: #1D4ED8; color: #FFFFFF;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 10px; font-weight: 800;
    align-self: flex-start;
}
.empty-manager {
    padding: 30px 20px;
    text-align: center;
    color: var(--text-muted);
}
.empty-manager i {
    font-size: 40px;
    color: #FCA5A5;
    opacity: 0.5;
    display: block;
    margin-bottom: 10px;
}
.empty-manager p { margin: 0; font-size: 13px; }

/* FINANCE CARD */
.finance-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.finance-header {
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px;
    color: #FFFFFF;
    position: relative; overflow: hidden;
}
.finance-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.finance-header-left {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1;
}
.finance-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.finance-header h3 { font-size: 16px; font-weight: 800; margin: 0 0 2px 0; }
.finance-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); font-weight: 500; }
.btn-link-light {
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    font-size: 12px; font-weight: 700;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    position: relative; z-index: 1;
    white-space: nowrap;
}
.btn-link-light:hover {
    background: rgba(255, 255, 255, 0.35);
    color: #FFFFFF;
    transform: translateY(-1px);
}
.finance-body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    padding: 18px 22px;
}
.finance-item {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    min-width: 0;
}
.finance-item-highlight {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #FCA5A5;
}
html.dark-mode .finance-item-highlight {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626;
}
.finance-item-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    border: 1.5px solid;
}
.finance-float {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #1D4ED8; border-color: #93C5FD;
}
.finance-cash {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669; border-color: #6EE7B7;
}
.finance-capital {
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #DC2626; border-color: #FCA5A5;
}
html.dark-mode .finance-float { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .finance-cash { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .finance-capital { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.finance-item-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.finance-item-label {
    font-size: 10.5px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 800;
    color: var(--text-muted);
}
.finance-item-value {
    font-size: 17px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word; line-height: 1.2;
}

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
.section-card-header h3 { font-size: 16px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.section-card-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); font-weight: 500; }
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
.table-mini-wrapper {
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

/* Provider cell */
.provider-cell-mini { display: flex; align-items: center; gap: 10px; }
.provider-icon-mini {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 14px; flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.provider-name-mini {
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 200px;
}

/* Employee cell */
.employee-cell-mini { display: flex; align-items: center; gap: 10px; }
.employee-avatar-mini {
    width: 38px; height: 38px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid #DC2626;
    flex-shrink: 0;
    background: #F3F4F6;
}
.employee-initial {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 15px;
    flex-shrink: 0;
    border: 2px solid #FCA5A5;
}
.employee-text-mini { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.employee-name-mini {
    font-size: 12.5px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 180px;
}
.employee-code-mini {
    font-size: 10px; font-weight: 700;
    color: #DC2626;
    font-family: 'Courier New', monospace;
    background: #FEF2F2;
    padding: 1px 6px; border-radius: 4px;
    align-self: flex-start;
}

.contact-mini { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; }
.contact-line {
    display: flex; align-items: center; gap: 5px;
    color: var(--text-secondary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 200px;
}
.contact-line i { color: #DC2626; font-size: 10px; width: 12px; text-align: center; flex-shrink: 0; }

.role-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px;
    border-radius: 8px;
    font-size: 10.5px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.role-pill i { font-size: 9px; }
.role-employee { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.role-admin { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.role-super_admin { background: #EDE9FE; color: #7C3AED; border: 1.5px solid #C4B5FD; }
html.dark-mode .role-employee { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .role-admin { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .role-super_admin { background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6; }

.status-pill-mini {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.status-active { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.status-on_leave { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.status-suspended { background: #FEE2E2; color: #DC2626; border: 1.5px solid #FECACA; }
.status-terminated { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .status-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .status-on_leave { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .status-suspended { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .status-terminated { background: #334155; color: #94A3B8; border-color: #475569; }

.type-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.type-pill i { font-size: 9px; }
.type-bank { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.type-mobile_money { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.type-other { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.type-c2f { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.type-f2c { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .type-bank { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-mobile_money { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-other,
html.dark-mode .type-c2f { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-f2c { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

.float-mini {
    font-family: 'Courier New', monospace;
    font-size: 12.5px; font-weight: 900;
    color: #1D4ED8;
    white-space: nowrap;
}
html.dark-mode .float-mini { color: #60A5FA; }
.float-mini.type-c2f { color: #059669; }
.float-mini.type-f2c { color: #D97706; }

.date-mini {
    font-size: 11.5px; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.btn-mini-view {
    width: 34px; height: 34px;
    border-radius: 8px;
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.2s ease;
}
.btn-mini-view:hover {
    background: #1D4ED8; color: #FFFFFF;
    border-color: #1D4ED8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.35);
}

.table-footer-note {
    padding: 14px 20px;
    font-size: 12px;
    color: var(--text-muted);
    background: var(--bg-input);
    border-top: 1.5px solid var(--border-color);
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.table-footer-note i { color: #DC2626; font-size: 12px; }
.table-footer-note .link-red { margin-left: auto; font-weight: 800; }

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
    flex-wrap: wrap; justify-content: flex-end;
    position: sticky; bottom: 16px; z-index: 10;
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
    position: fixed; inset: 0;
    background: rgba(0, 0, 0, 0.55);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999; padding: 20px;
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
.modal-message strong { color: var(--text-primary); font-weight: 800; }
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
    .finance-body { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .branch-hero-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 0 20px 22px 20px;
    }
    .branch-hero-info { text-align: center; }
    .branch-meta { justify-content: center; }
    .branch-manager-line { justify-content: center; }
    .branch-name { font-size: 20px; }

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
    .branch-avatar-icon { width: 80px; height: 80px; font-size: 34px; }
    .branch-status-dot { width: 18px; height: 18px; border-width: 3px; }
    .branch-name { font-size: 18px; }
    .quick-stat { padding: 14px 16px; gap: 10px; }
    .quick-stat-icon { width: 40px; height: 40px; font-size: 17px; }
    .quick-stat-value { font-size: 14px; }
    .detail-card-header { padding: 14px 16px; }
    .detail-card-icon { width: 40px; height: 40px; font-size: 17px; }
    .detail-card-body { padding: 14px 16px; }
    .finance-body { padding: 14px; gap: 10px; }
    .finance-item { padding: 14px; gap: 12px; }
}
</style>

<script>
// ============================================================
// DELETE MODAL
// ============================================================
function confirmDelete(id, name) {
    document.getElementById('deleteBranchName').textContent = name;
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