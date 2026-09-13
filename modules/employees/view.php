<?php
// ================================================================
// FILE: modules/employees/view.php
// WAKALA FINANCIAL SYSTEM - VIEW EMPLOYEE DETAILS
// RED THEME + REFEREES + TRANSFERS SUMMARY
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
// GET EMPLOYEE ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid employee ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH EMPLOYEE
// ============================================================
$stmt = $db->prepare("
    SELECT e.*,
           b.branch_name AS branch_display,
           b.branch_code AS branch_display_code,
           b.location    AS branch_location,
           b.phone       AS branch_phone,
           b.email       AS branch_email
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['error_message'] = 'Employee not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH REFEREES
// ============================================================
$referees = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM employee_referees
        WHERE employee_id = ?
        ORDER BY id ASC
        LIMIT 5
    ");
    $stmt->execute([$id]);
    $referees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $referees = [];
}

// ============================================================
// FETCH RECENT TRANSFERS
// ============================================================
$transfers = [];
$total_transfers = 0;
$total_transfer_amount = 0;
try {
    $stmt = $db->prepare("
        SELECT t.*
        FROM transfers t
        WHERE t.employee_id = ?
        ORDER BY t.id DESC
        LIMIT 5
    ");
    $stmt->execute([$id]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
        FROM transfers
        WHERE employee_id = ?
    ");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_transfers = intval($t['cnt'] ?? 0);
    $total_transfer_amount = floatval($t['total'] ?? 0);
} catch (Exception $e) {
    $transfers = [];
}

// ============================================================
// DERIVED VALUES
// ============================================================
$initial   = strtoupper(substr($employee['full_name'] ?? 'N', 0, 1));
$avatar    = $employee['profile_pic'] ?? '';
$has_avatar = !empty($avatar) && file_exists('../../' . $avatar);

$emp_status = $employee['employment_status'] ?? 'active';
$status_icon = [
    'active'     => 'fa-check-circle',
    'on_leave'   => 'fa-clock',
    'suspended'  => 'fa-pause-circle',
    'terminated' => 'fa-ban',
][$emp_status] ?? 'fa-circle';

$role_label = ucfirst(str_replace('_', ' ', $employee['role'] ?? 'employee'));
$role_class = 'role-' . ($employee['role'] ?? 'employee');

$success_message = '';
$error_message   = '';
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
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-circle"></i> Employee Details</h2>
                <p class="text-muted">
                    <i class="fas fa-id-badge"></i>
                    <?php echo htmlspecialchars($employee['employee_id'] ?? '-'); ?>
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
                        onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($employee['full_name']); ?>')">
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
        PROFILE HERO CARD
        ============================================================ -->
        <div class="profile-hero">
            <div class="profile-hero-bg"></div>
            <div class="profile-hero-content">
                <div class="profile-avatar-wrapper">
                    <?php if ($has_avatar): ?>
                        <img src="../../<?php echo htmlspecialchars($avatar); ?>"
                             class="profile-avatar-img"
                             alt="<?php echo htmlspecialchars($employee['full_name']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="profile-avatar" style="display:none;"><?php echo $initial; ?></div>
                    <?php else: ?>
                        <div class="profile-avatar"><?php echo $initial; ?></div>
                    <?php endif; ?>

                    <span class="profile-status-dot status-<?php echo $emp_status; ?>"
                          title="<?php echo ucfirst(str_replace('_', ' ', $emp_status)); ?>"></span>
                </div>

                <div class="profile-hero-info">
                    <h1 class="profile-name"><?php echo htmlspecialchars($employee['full_name']); ?></h1>
                    <div class="profile-meta">
                        <span class="meta-chip meta-id">
                            <i class="fas fa-id-badge"></i>
                            <?php echo htmlspecialchars($employee['employee_id']); ?>
                        </span>
                        <span class="meta-chip <?php echo $role_class; ?>">
                            <i class="fas fa-user-tag"></i>
                            <?php echo htmlspecialchars($role_label); ?>
                        </span>
                        <span class="meta-chip status-badge-<?php echo $emp_status; ?>">
                            <i class="fas <?php echo $status_icon; ?>"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $emp_status)); ?>
                        </span>
                    </div>
                    <?php if (!empty($employee['email'])): ?>
                        <div class="profile-contact">
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($employee['email']); ?></span>
                            <?php if (!empty($employee['phone'])): ?>
                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($employee['phone']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="profile-hero-actions">
                    <a href="edit.php?id=<?php echo $id; ?>" class="btn-hero btn-hero-primary">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- ============================================================
        MINI STATS
        ============================================================ -->
        <div class="stats-mini-grid">
            <div class="stat-mini stat-mini-red">
                <div class="stat-mini-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-mini-info">
                    <span class="stat-mini-label">Base Salary</span>
                    <span class="stat-mini-value">
                        <?php echo !empty($employee['base_salary']) ? formatCurrency($employee['base_salary']) : '-'; ?>
                    </span>
                </div>
            </div>
            <div class="stat-mini stat-mini-green">
                <div class="stat-mini-icon"><i class="far fa-calendar-check"></i></div>
                <div class="stat-mini-info">
                    <span class="stat-mini-label">Hire Date</span>
                    <span class="stat-mini-value">
                        <?php echo !empty($employee['hire_date']) ? date('d M Y', strtotime($employee['hire_date'])) : '-'; ?>
                    </span>
                </div>
            </div>
            <div class="stat-mini stat-mini-blue">
                <div class="stat-mini-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="stat-mini-info">
                    <span class="stat-mini-label">Total Transfers</span>
                    <span class="stat-mini-value"><?php echo number_format($total_transfers); ?></span>
                </div>
            </div>
            <div class="stat-mini stat-mini-orange">
                <div class="stat-mini-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-mini-info">
                    <span class="stat-mini-label">Transfer Amount</span>
                    <span class="stat-mini-value"><?php echo formatCurrency($total_transfer_amount); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILS GRID
        ============================================================ -->
        <div class="details-grid">

            <!-- PERSONAL INFORMATION -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-red">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <h3>Personal Information</h3>
                            <p>Basic personal details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user"></i> Full Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['full_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-id-badge"></i> Employee ID</span>
                        <span class="info-value">
                            <span class="chip-red"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user-circle"></i> Username</span>
                        <span class="info-value">
                            <span class="chip-mono"><?php echo htmlspecialchars($employee['username'] ?? '-'); ?></span>
                        </span>
                    </div>
                    <?php if (!empty($employee['gender'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-venus-mars"></i> Gender</span>
                        <span class="info-value"><?php echo ucfirst(htmlspecialchars($employee['gender'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['date_of_birth'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-birthday-cake"></i> Date of Birth</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($employee['date_of_birth'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['address'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-map-marker-alt"></i> Address</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- WORK INFORMATION -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-blue">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div>
                            <h3>Work Information</h3>
                            <p>Employment details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-store"></i> Branch</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars($employee['branch_display'] ?? $employee['branch'] ?? '-'); ?>
                            <?php if (!empty($employee['branch_display_code'])): ?>
                                <span class="chip-red-sm"><?php echo htmlspecialchars($employee['branch_display_code']); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user-tag"></i> Role</span>
                        <span class="info-value">
                            <span class="badge <?php echo $role_class; ?>"><?php echo htmlspecialchars($role_label); ?></span>
                        </span>
                    </div>
                    <?php if (!empty($employee['position'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-briefcase"></i> Position</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['position']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-money-bill-wave"></i> Base Salary</span>
                        <span class="info-value salary">
                            <?php echo !empty($employee['base_salary']) ? formatCurrency($employee['base_salary']) : '-'; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-calendar-check"></i> Hire Date</span>
                        <span class="info-value">
                            <?php echo !empty($employee['hire_date']) ? date('d M Y', strtotime($employee['hire_date'])) : '-'; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-toggle-on"></i> Employment Status</span>
                        <span class="info-value">
                            <span class="badge status-badge-<?php echo $emp_status; ?>">
                                <i class="fas <?php echo $status_icon; ?>"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $emp_status)); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTACT INFORMATION -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-green">
                            <i class="fas fa-address-book"></i>
                        </div>
                        <div>
                            <h3>Contact Information</h3>
                            <p>How to reach this employee</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-value">
                            <?php if (!empty($employee['email'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>" class="link-red">
                                    <?php echo htmlspecialchars($employee['email']); ?>
                                </a>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value">
                            <?php if (!empty($employee['phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($employee['phone']); ?>" class="link-red">
                                    <?php echo htmlspecialchars($employee['phone']); ?>
                                </a>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($employee['emergency_contact'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone-volume"></i> Emergency Contact</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['emergency_contact']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['emergency_phone'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-mobile-alt"></i> Emergency Phone</span>
                        <span class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($employee['emergency_phone']); ?>" class="link-red">
                                <?php echo htmlspecialchars($employee['emergency_phone']); ?>
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SYSTEM INFORMATION -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-card-header-left">
                        <div class="detail-card-icon icon-purple">
                            <i class="fas fa-server"></i>
                        </div>
                        <div>
                            <h3>System Information</h3>
                            <p>Account details</p>
                        </div>
                    </div>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-hashtag"></i> User ID</span>
                        <span class="info-value">
                            <span class="chip-mono">#<?php echo $employee['id']; ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-toggle-on"></i> Account Status</span>
                        <span class="info-value">
                            <?php if (intval($employee['is_active'] ?? 1) === 1): ?>
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
                    <?php if (!empty($employee['last_login'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-sign-in-alt"></i> Last Login</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($employee['last_login'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="far fa-calendar-plus"></i> Created</span>
                        <span class="info-value">
                            <?php echo !empty($employee['created_at']) ? date('d M Y H:i', strtotime($employee['created_at'])) : '-'; ?>
                        </span>
                    </div>
                    <?php if (!empty($employee['updated_at'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($employee['updated_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
        REFEREES SECTION
        ============================================================ -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon icon-red">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <h3>Referees</h3>
                        <p><?php echo count($referees); ?> referee<?php echo count($referees) !== 1 ? 's' : ''; ?> on file</p>
                    </div>
                </div>
                <span class="count-badge-red">
                    <i class="fas fa-user-check"></i>
                    <?php echo count($referees); ?>
                </span>
            </div>

            <div class="section-card-body">
                <?php if (count($referees) > 0): ?>
                    <div class="referees-view-grid">
                        <?php foreach ($referees as $idx => $ref): ?>
                        <div class="referee-view-card">
                            <div class="referee-view-header">
                                <div class="referee-view-number"><?php echo $idx + 1; ?></div>
                                <div class="referee-view-title">
                                    <h4><?php echo htmlspecialchars($ref['referee_name'] ?? 'N/A'); ?></h4>
                                    <?php if (!empty($ref['relationship'])): ?>
                                        <span class="referee-relationship-tag">
                                            <i class="fas fa-link"></i>
                                            <?php echo htmlspecialchars($ref['relationship']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="referee-view-body">
                                <?php if (!empty($ref['referee_phone'])): ?>
                                <div class="referee-info-row">
                                    <span class="referee-info-label">
                                        <i class="fas fa-phone"></i> Phone
                                    </span>
                                    <span class="referee-info-value">
                                        <a href="tel:<?php echo htmlspecialchars($ref['referee_phone']); ?>" class="link-red">
                                            <?php echo htmlspecialchars($ref['referee_phone']); ?>
                                        </a>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($ref['address'])): ?>
                                <div class="referee-info-row">
                                    <span class="referee-info-label">
                                        <i class="fas fa-map-marker-alt"></i> Address
                                    </span>
                                    <span class="referee-info-value">
                                        <?php echo htmlspecialchars($ref['address']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($ref['created_at'])): ?>
                                <div class="referee-info-row">
                                    <span class="referee-info-label">
                                        <i class="fas fa-clock"></i> Added
                                    </span>
                                    <span class="referee-info-value">
                                        <?php echo date('d M Y', strtotime($ref['created_at'])); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-section">
                        <i class="fas fa-user-slash"></i>
                        <p>No referees on file for this employee.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        RECENT TRANSFERS (if any)
        ============================================================ -->
        <?php if (count($transfers) > 0): ?>
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-card-icon icon-blue">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div>
                        <h3>Recent Transfers</h3>
                        <p>Last <?php echo count($transfers); ?> transfer<?php echo count($transfers) !== 1 ? 's' : ''; ?> made</p>
                    </div>
                </div>
                <span class="count-badge-blue">
                    <i class="fas fa-list"></i>
                    <?php echo count($transfers); ?>
                </span>
            </div>

            <div class="section-card-body no-padding">
                <div class="mini-table-wrapper">
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
                                $type_label = $is_c2f ? 'Cash → Float' : 'Float → Cash';
                                $type_cls = $is_c2f ? 'type-c2f' : 'type-f2c';
                            ?>
                            <tr>
                                <td>
                                    <span class="chip-mono"><?php echo htmlspecialchars($t['transfer_number']); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $type_cls; ?>">
                                        <i class="fas fa-arrow-<?php echo $is_c2f ? 'right' : 'left'; ?>"></i>
                                        <?php echo $type_label; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($t['provider_name'] ?? '-'); ?></td>
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
        ACTION BUTTONS
        ============================================================ -->
        <div class="actions-card">
            <a href="index.php" class="btn btn-secondary-large">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit-large">
                <i class="fas fa-edit"></i>
                <span>Edit Employee</span>
            </a>
            <button type="button" class="btn btn-delete-large"
                    onclick="confirmDelete(<?php echo $id; ?>, '<?php echo addslashes($employee['full_name']); ?>')">
                <i class="fas fa-trash"></i>
                <span>Delete Employee</span>
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
        <h3 class="modal-title">Delete Employee?</h3>
        <p class="modal-message">
            Are you sure you want to delete <strong id="deleteEmployeeName"></strong>?
            This action cannot be undone and will also delete their referees.
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
    --bg-table-even: #fafafa;
    --bg-table-hover: #fef2f2;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
    --red-darker: #991B1B;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d1f1f;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

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
.page-header .header-left h2 i {
    color: var(--red-primary); margin-right: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
}
.page-header .header-right {
    display: flex; gap: 10px; flex-wrap: wrap;
}

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
    background: var(--bg-card);
    color: var(--text-secondary);
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
.alert-close {
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   PROFILE HERO CARD
   ============================================================ */
.profile-hero {
    background: var(--bg-card);
    border-radius: 16px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    box-shadow: 0 4px 16px var(--shadow-color);
    overflow: hidden;
    position: relative;
}
.profile-hero-bg {
    height: 90px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    position: relative;
    overflow: hidden;
}
.profile-hero-bg::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.profile-hero-bg::after {
    content: '';
    position: absolute;
    bottom: -60%; left: 30%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}
.profile-hero-content {
    padding: 0 26px 22px 26px;
    display: flex;
    align-items: flex-end;
    gap: 22px;
    flex-wrap: wrap;
    margin-top: -50px;
    position: relative;
    z-index: 1;
}
.profile-avatar-wrapper {
    position: relative;
    flex-shrink: 0;
}
.profile-avatar-img,
.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    border: 4px solid #FFFFFF;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    font-weight: 900;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
}
.profile-avatar-img {
    object-fit: cover;
    display: block;
}
html.dark-mode .profile-avatar-img,
html.dark-mode .profile-avatar {
    border-color: #334155;
}
.profile-status-dot {
    position: absolute;
    bottom: 6px;
    right: 6px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 3.5px solid #FFFFFF;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
html.dark-mode .profile-status-dot { border-color: #1e293b; }
.profile-status-dot.status-active     { background: #10B981; }
.profile-status-dot.status-on_leave   { background: #F59E0B; }
.profile-status-dot.status-suspended  { background: #DC2626; }
.profile-status-dot.status-terminated { background: #6B7280; }

.profile-hero-info {
    flex: 1;
    min-width: 0;
    padding-bottom: 4px;
}
.profile-name {
    font-size: 24px;
    font-weight: 900;
    margin: 0 0 10px 0;
    color: var(--text-primary);
    word-break: break-word;
    line-height: 1.2;
}
.profile-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.meta-chip i { font-size: 10px; }
.meta-id {
    background: #FEF2F2;
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
    font-family: 'Courier New', monospace;
}
html.dark-mode .meta-id { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.role-employee    { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.role-admin       { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.role-super_admin { background: #EDE9FE; color: #7C3AED; border: 1.5px solid #C4B5FD; }
html.dark-mode .role-employee    { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .role-admin       { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .role-super_admin { background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6; }
.status-badge-active     { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.status-badge-on_leave   { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.status-badge-suspended  { background: #FEE2E2; color: #DC2626; border: 1.5px solid #FECACA; }
.status-badge-terminated { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .status-badge-active     { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .status-badge-on_leave   { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .status-badge-suspended  { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .status-badge-terminated { background: #334155; color: #94A3B8; border-color: #475569; }

.profile-contact {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 12.5px;
    color: var(--text-muted);
    margin-top: 4px;
}
.profile-contact span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 500;
}
.profile-contact i { color: #DC2626; font-size: 11px; }

.profile-hero-actions {
    padding-bottom: 4px;
    flex-shrink: 0;
}
.btn-hero {
    padding: 11px 22px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-hero-primary {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-hero-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}

/* ============================================================
   MINI STATS
   ============================================================ */
.stats-mini-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.stat-mini {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.stat-mini:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px var(--shadow-hover);
    border-color: var(--red-primary);
}
.stat-mini-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.stat-mini-red .stat-mini-icon    { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.stat-mini-green .stat-mini-icon  { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.stat-mini-blue .stat-mini-icon   { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.stat-mini-orange .stat-mini-icon { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.stat-mini-info {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1;
}
.stat-mini-label {
    font-size: 10.5px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 800;
    color: var(--text-muted);
}
.stat-mini-value {
    font-size: 16px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    line-height: 1.2;
}

/* ============================================================
   DETAILS GRID
   ============================================================ */
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
    box-shadow: 0 8px 24px var(--shadow-hover);
    transform: translateY(-2px);
}
.detail-card-header {
    padding: 16px 20px;
    background: var(--bg-table-even);
    border-bottom: 1.5px solid var(--border-color);
}
.detail-card-header-left {
    display: flex; align-items: center; gap: 14px;
}
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
    color: var(--text-primary);
    margin: 0 0 2px 0;
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
    background: #FEF2F2;
    color: #DC2626;
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
    background: #FEF2F2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 10px; font-weight: 800;
    letter-spacing: 0.3px;
    margin-left: 4px;
}
.chip-mono {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 11.5px; font-weight: 800;
    letter-spacing: 0.3px;
}
.link-red {
    color: #DC2626;
    text-decoration: none;
    font-weight: 700;
    transition: all 0.2s ease;
    border-bottom: 1.5px dashed transparent;
}
.link-red:hover {
    color: #991B1B;
    border-bottom-color: #DC2626;
}
html.dark-mode .link-red { color: #FCA5A5; }
html.dark-mode .link-red:hover { color: #FEE2E2; }

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
.badge-active {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.badge-inactive {
    background: #F3F4F6; color: #6B7280;
    border: 1.5px solid #E5E7EB;
}
html.dark-mode .badge-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .badge-inactive { background: #334155; color: #94A3B8; border-color: #475569; }

.type-c2f {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.type-f2c {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
html.dark-mode .type-c2f { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-f2c { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

/* ============================================================
   SECTION CARDS (Referees, Transfers)
   ============================================================ */
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
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
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
.count-badge-red,
.count-badge-blue {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 16px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border-radius: 12px;
    font-size: 13px; font-weight: 900;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
}
.count-badge-red i, .count-badge-blue i {
    color: #FCD34D; font-size: 12px;
}
.section-card-body { padding: 22px; }
.section-card-body.no-padding { padding: 0; }

/* ============================================================
   REFEREES VIEW
   ============================================================ */
.referees-view-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.referee-view-card {
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    padding: 18px 20px;
    transition: all 0.3s ease;
}
.referee-view-card:hover {
    border-color: #FCA5A5;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.1);
    transform: translateY(-2px);
}
.referee-view-header {
    display: flex; align-items: center; gap: 12px;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1.5px dashed var(--border-color);
}
.referee-view-number {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 15px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.referee-view-title { min-width: 0; flex: 1; }
.referee-view-title h4 {
    font-size: 15px; font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 4px 0;
    word-break: break-word;
}
.referee-relationship-tag {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 10px;
    background: #FEF2F2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
html.dark-mode .referee-relationship-tag {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.referee-relationship-tag i { font-size: 9px; }

.referee-view-body {
    display: flex; flex-direction: column; gap: 10px;
}
.referee-info-row {
    display: flex; justify-content: space-between;
    align-items: center;
    gap: 10px; flex-wrap: wrap;
}
.referee-info-label {
    font-size: 11.5px; font-weight: 700;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.referee-info-label i {
    color: #DC2626; font-size: 11px;
    width: 12px; text-align: center;
}
.referee-info-value {
    font-size: 12.5px; font-weight: 700;
    color: var(--text-primary);
    text-align: right;
    word-break: break-word;
}

.empty-section {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
}
.empty-section i {
    font-size: 48px;
    color: #FCA5A5;
    opacity: 0.5;
    margin-bottom: 12px;
    display: block;
}
.empty-section p {
    font-size: 14px;
    margin: 0;
}

/* ============================================================
   MINI TABLE (Transfers)
   ============================================================ */
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
.mini-table thead { background: var(--bg-table-even); }
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
.mini-table tbody tr:hover { background: var(--bg-table-hover); }
.mini-table tbody tr:last-child { border-bottom: none; }
.mini-table tbody td {
    padding: 12px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.mini-table tbody td.text-right { text-align: right; }
.mini-amount {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: 12.5px;
}
.mini-amount.type-c2f {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.mini-amount.type-f2c {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
html.dark-mode .mini-amount.type-c2f {
    background: #14532D; color: #4ADE80; border-color: #16A34A;
}
html.dark-mode .mini-amount.type-f2c {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.mini-date {
    font-size: 11.5px; font-weight: 600;
    color: var(--text-secondary);
}

/* ============================================================
   ACTIONS CARD
   ============================================================ */
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
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary-large:hover {
    background: var(--bg-table-hover);
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
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.modal-box {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 32px 28px;
    max-width: 460px;
    width: 100%;
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
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-modal-cancel:hover {
    background: var(--bg-table-hover);
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
@media (max-width: 1024px) {
    .details-grid { grid-template-columns: 1fr; }
    .stats-mini-grid { grid-template-columns: repeat(2, 1fr); }
    .referees-view-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .profile-hero-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 0 20px 22px 20px;
    }
    .profile-hero-info { text-align: center; }
    .profile-meta { justify-content: center; }
    .profile-contact { justify-content: center; }
    .profile-name { font-size: 20px; }

    .stats-mini-grid { grid-template-columns: 1fr; }

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
    .profile-avatar-img,
    .profile-avatar {
        width: 80px; height: 80px; font-size: 30px;
    }
    .profile-status-dot { width: 18px; height: 18px; border-width: 3px; }
    .profile-name { font-size: 18px; }
    .stat-mini { padding: 14px 16px; gap: 10px; }
    .stat-mini-icon { width: 40px; height: 40px; font-size: 17px; }
    .stat-mini-value { font-size: 14px; }
    .detail-card-header { padding: 14px 16px; }
    .detail-card-icon { width: 40px; height: 40px; font-size: 17px; }
    .detail-card-body { padding: 14px 16px; }
    .section-card-body { padding: 16px; }
    .referee-view-card { padding: 14px 16px; }
}
</style>

<script>
// ============================================================
// DELETE MODAL
// ============================================================
function confirmDelete(id, name) {
    document.getElementById('deleteEmployeeName').textContent = name;
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

// Auto-hide success alert
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
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function() { syncDarkMode(); });
});
</script>

</body>
</html>