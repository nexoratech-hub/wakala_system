<?php
// ================================================================
// FILE: modules/employees/index.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEES LIST
// RED HEADER + COMPACT SEARCH + CENTERED < > SCROLL
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
$search          = trim($_GET['search'] ?? '');
$branch_filter   = intval($_GET['branch_id'] ?? 0);
$role_filter     = trim($_GET['role'] ?? '');
$status_filter   = trim($_GET['status'] ?? '');
$page            = max(1, intval($_GET['page'] ?? 1));
$per_page        = 15;
$offset          = ($page - 1) * $per_page;

// ============================================================
// LOAD BRANCHES FOR FILTER
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// BUILD QUERY
// ============================================================
$where  = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (
        e.full_name LIKE ? OR 
        e.employee_id LIKE ? OR 
        e.email LIKE ? OR 
        e.phone LIKE ? OR 
        e.username LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($branch_filter > 0) {
    $where .= " AND e.branch_id = ?";
    $params[] = $branch_filter;
}

if ($role_filter !== '' && in_array($role_filter, ['admin', 'super_admin', 'employee'])) {
    $where .= " AND e.role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== '' && in_array($status_filter, ['active', 'on_leave', 'suspended', 'terminated'])) {
    $where .= " AND e.employment_status = ?";
    $params[] = $status_filter;
}

// ============================================================
// COUNT TOTAL
// ============================================================
$count_sql = "SELECT COUNT(*) FROM employees e" . $where;
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_employees = intval($stmt->fetchColumn());
$total_pages     = max(1, ceil($total_employees / $per_page));

// ============================================================
// FETCH EMPLOYEES
// ============================================================
$sql = "
    SELECT e.*,
           b.branch_name AS branch_display,
           b.branch_code AS branch_display_code
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    $where
    ORDER BY e.id DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// STATS CARDS
// ============================================================
$stmt = $db->query("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND employment_status = 'active'");
$total_active = intval($stmt->fetchColumn());

$stmt = $db->query("SELECT COUNT(*) FROM employees WHERE role = 'admin' OR role = 'super_admin'");
$total_admins = intval($stmt->fetchColumn());

$stmt = $db->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'on_leave'");
$total_on_leave = intval($stmt->fetchColumn());

// ============================================================
// SUCCESS/ERROR MESSAGES
// ============================================================
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
                <h2><i class="fas fa-users"></i> Employees</h2>
                <p class="text-muted">Manage all employees in the system</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-add">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Employee</span>
                </a>
                <a href="export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-export">
                    <i class="fas fa-download"></i>
                    <span>Export CSV</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
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
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Employees</span>
                    <span class="stat-value"><?php echo number_format($total_employees); ?></span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Active</span>
                    <span class="stat-value"><?php echo number_format($total_active); ?></span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Admins</span>
                    <span class="stat-value"><?php echo number_format($total_admins); ?></span>
                </div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
                <div class="stat-info">
                    <span class="stat-label">On Leave</span>
                    <span class="stat-value"><?php echo number_format($total_on_leave); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE CONTAINER
        ============================================================ -->
        <div class="table-container">

            <!-- RED HEADER: Search (left) + Scroll < > (center) + Filters/Count (right) -->
            <div class="table-red-header">
                <div class="table-red-header-content">

                    <!-- LEFT: Compact Search Box -->
                    <div class="header-search-wrapper">
                        <i class="fas fa-search header-search-icon"></i>
                        <input type="text"
                               class="header-search-input"
                               id="quickSearch"
                               placeholder="Search..."
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
                            <i class="fas fa-users"></i>
                            <strong><?php echo number_format($total_employees); ?></strong>
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
                                    <?php echo $branch_filter == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-user-tag"></i> Role</label>
                        <select name="role" class="filter-control">
                            <option value="">All Roles</option>
                            <option value="employee"    <?php echo $role_filter === 'employee'    ? 'selected' : ''; ?>>Employee</option>
                            <option value="admin"       <?php echo $role_filter === 'admin'       ? 'selected' : ''; ?>>Admin</option>
                            <option value="super_admin" <?php echo $role_filter === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <select name="status" class="filter-control">
                            <option value="">All Statuses</option>
                            <option value="active"     <?php echo $status_filter === 'active'     ? 'selected' : ''; ?>>Active</option>
                            <option value="on_leave"   <?php echo $status_filter === 'on_leave'   ? 'selected' : ''; ?>>On Leave</option>
                            <option value="suspended"  <?php echo $status_filter === 'suspended'  ? 'selected' : ''; ?>>Suspended</option>
                            <option value="terminated" <?php echo $status_filter === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
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
            EMPLOYEE TABLE
            ============================================================ -->
            <?php if (count($employees) > 0): ?>
            <div class="table-wrapper" id="tableWrapper">
                <table class="data-table" id="employeesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="min-width: 240px;">Employee</th>
                            <th style="min-width: 150px;">Contact</th>
                            <th style="min-width: 140px;">Branch</th>
                            <th style="min-width: 110px;">Role</th>
                            <th style="min-width: 110px;">Status</th>
                            <th style="min-width: 140px;">Hire Date</th>
                            <th style="min-width: 130px;">Salary</th>
                            <th style="width: 190px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="employeesTableBody">
                        <?php
                        $i = $offset + 1;
                        foreach ($employees as $emp):
                            $initial = strtoupper(substr($emp['full_name'] ?? 'N', 0, 1));

                            $avatar = $emp['profile_pic'] ?? '';
                            $has_avatar = !empty($avatar) && file_exists('../../' . $avatar);

                            $role_class = 'role-' . ($emp['role'] ?? 'employee');
                            $role_label = ucfirst(str_replace('_', ' ', $emp['role'] ?? 'employee'));

                            $status = $emp['employment_status'] ?? 'active';
                            $status_class = 'status-' . $status;
                            $status_label = ucfirst(str_replace('_', ' ', $status));
                            $status_icon = [
                                'active'     => 'fa-check-circle',
                                'on_leave'   => 'fa-clock',
                                'suspended'  => 'fa-pause-circle',
                                'terminated' => 'fa-ban',
                            ][$status] ?? 'fa-circle';

                            $search_data = strtolower(
                                ($emp['full_name'] ?? '') . ' ' .
                                ($emp['employee_id'] ?? '') . ' ' .
                                ($emp['email'] ?? '') . ' ' .
                                ($emp['phone'] ?? '') . ' ' .
                                ($emp['username'] ?? '') . ' ' .
                                ($emp['branch_display'] ?? '')
                            );
                        ?>
                            <tr class="employee-row" data-search="<?php echo htmlspecialchars($search_data); ?>">
                                <td>
                                    <span class="row-number"><?php echo $i++; ?></span>
                                </td>

                                <td>
                                    <div class="employee-cell">
                                        <?php if ($has_avatar): ?>
                                            <img src="../../<?php echo htmlspecialchars($avatar); ?>"
                                                 class="employee-avatar-img"
                                                 alt="<?php echo htmlspecialchars($emp['full_name']); ?>"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="employee-avatar" style="display:none;">
                                                <?php echo $initial; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="employee-avatar">
                                                <?php echo $initial; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="employee-details">
                                            <span class="employee-name">
                                                <?php echo htmlspecialchars($emp['full_name'] ?? 'N/A'); ?>
                                            </span>
                                            <span class="employee-id">
                                                <i class="fas fa-id-badge"></i>
                                                <?php echo htmlspecialchars($emp['employee_id'] ?? '-'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="contact-cell">
                                        <span class="contact-line">
                                            <i class="fas fa-envelope"></i>
                                            <?php echo htmlspecialchars($emp['email'] ?? '-'); ?>
                                        </span>
                                        <?php if (!empty($emp['phone'])): ?>
                                        <span class="contact-line">
                                            <i class="fas fa-phone"></i>
                                            <?php echo htmlspecialchars($emp['phone']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="branch-cell">
                                        <i class="fas fa-store-alt"></i>
                                        <div>
                                            <span class="branch-name">
                                                <?php echo htmlspecialchars($emp['branch_display'] ?? $emp['branch'] ?? 'N/A'); ?>
                                            </span>
                                            <?php if (!empty($emp['branch_display_code'])): ?>
                                                <span class="branch-code">
                                                    <?php echo htmlspecialchars($emp['branch_display_code']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="role-badge <?php echo $role_class; ?>">
                                        <i class="fas fa-user-tag"></i>
                                        <?php echo htmlspecialchars($role_label); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i>
                                        <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="date-cell">
                                        <i class="far fa-calendar-alt"></i>
                                        <?php echo !empty($emp['hire_date']) ? date('d M Y', strtotime($emp['hire_date'])) : '-'; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="salary-cell">
                                        <?php echo !empty($emp['base_salary']) ? formatCurrency($emp['base_salary']) : '-'; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <a href="view.php?id=<?php echo $emp['id']; ?>"
                                           class="btn-action btn-view"
                                           title="View Employee">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $emp['id']; ?>"
                                           class="btn-action btn-edit"
                                           title="Edit Employee">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button"
                                                class="btn-action btn-delete"
                                                onclick="confirmDelete(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['full_name']); ?>')"
                                                title="Delete Employee">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No employees match your search</p>
                    <button type="button" class="btn-clear-search" onclick="clearQuickSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>

            <!-- ============================================================
            PAGINATION
            ============================================================ -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Showing <strong><?php echo number_format($offset + 1); ?></strong>
                    to <strong><?php echo number_format(min($offset + $per_page, $total_employees)); ?></strong>
                    of <strong><?php echo number_format($total_employees); ?></strong> employees
                </div>
                <div class="pagination-controls">
                    <?php
                    $qs = $_GET;
                    $qs['page'] = max(1, $page - 1);
                    $prev_url = '?' . http_build_query($qs);
                    $qs['page'] = min($total_pages, $page + 1);
                    $next_url = '?' . http_build_query($qs);
                    ?>
                    <a href="<?php echo $prev_url; ?>"
                       class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>

                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);
                    if ($start > 1) {
                        echo '<a href="?page=1" class="page-num">1</a>';
                        if ($start > 2) echo '<span class="page-dots">…</span>';
                    }
                    for ($p = $start; $p <= $end; $p++):
                        $qs['page'] = $p;
                        $url = '?' . http_build_query($qs);
                    ?>
                        <a href="<?php echo $url; ?>"
                           class="page-num <?php echo $p === $page ? 'active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php
                    endfor;
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="page-dots">…</span>';
                        echo '<a href="?page=' . $total_pages . '" class="page-num">' . $total_pages . '</a>';
                    }
                    ?>

                    <a href="<?php echo $next_url; ?>"
                       class="page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <h3>No Employees Found</h3>
                <p>
                    <?php if ($search !== '' || $branch_filter > 0 || $role_filter !== '' || $status_filter !== ''): ?>
                        Try adjusting your filters or search terms.
                    <?php else: ?>
                        Start by adding your first employee.
                    <?php endif; ?>
                </p>
                <?php if ($search === '' && $branch_filter == 0 && $role_filter === '' && $status_filter === ''): ?>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-user-plus"></i> Add First Employee
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- DELETE CONFIRM MODAL -->
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
    color: var(--text-primary);
}
.page-header .header-left h2 i {
    color: var(--red-primary); margin-right: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
}
.page-header .header-right {
    display: flex; gap: 10px; flex-wrap: wrap;
}

.btn {
    padding: 11px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-add {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}
.btn-export {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-export:hover {
    background: #F3F4F6; color: var(--text-primary);
    transform: translateY(-2px);
}
html.dark-mode .btn-export:hover { background: #334155; }

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
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   STATS CARDS
   ============================================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.stat-card {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px var(--shadow-hover);
    border-color: var(--red-primary);
}
.stat-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.stat-red .stat-icon { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.stat-green .stat-icon { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.stat-blue .stat-icon { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
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
    position: relative;
    overflow: hidden;
}
.table-red-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.table-red-header-content {
    display: flex; align-items: center;
    gap: 14px; flex-wrap: wrap;
    position: relative; z-index: 1;
    justify-content: space-between;
}

/* ============================================================
   HEADER SEARCH BOX - COMPACT
   ============================================================ */
.header-search-wrapper {
    position: relative;
    display: flex; align-items: center;
    flex: 0 1 240px;
    max-width: 240px;
    min-width: 180px;
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
html.dark-mode .header-search-wrapper {
    background: rgba(30, 41, 59, 0.98);
}
html.dark-mode .header-search-wrapper:focus-within {
    background: #1e293b;
    border-color: #FCD34D;
}
.header-search-icon {
    color: var(--red-primary);
    font-size: 12px;
    flex-shrink: 0;
    margin-right: 8px;
}
.header-search-input {
    flex: 1;
    border: none; background: transparent;
    padding: 0; outline: none;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: #1f2937;
    min-width: 0;
}
html.dark-mode .header-search-input { color: #f1f5f9; }
.header-search-input::placeholder { color: #9ca3af; font-size: 11px; }
.header-search-clear {
    width: 18px; height: 18px;
    border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    margin-left: 6px;
}
.header-search-clear:hover {
    background: #DC2626; color: #FFFFFF;
    transform: scale(1.1);
}

/* ============================================================
   HEADER SCROLL CENTER < >
   ============================================================ */
.header-scroll-center {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
    padding: 0 8px;
}
.header-scroll-btn {
    width: 34px; height: 34px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #DC2626;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
    padding: 0;
    line-height: 1;
}
.header-scroll-btn:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.header-scroll-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}
.header-scroll-btn i {
    font-size: 12px;
    display: block;
    line-height: 1;
}
.header-scroll-label {
    font-size: 10px;
    font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.header-scroll-label i {
    font-size: 10px;
    color: #FCD34D;
}

/* ============================================================
   HEADER ACTIONS
   ============================================================ */
.header-actions {
    display: flex; align-items: center; gap: 10px;
    flex-shrink: 0;
}

/* Filters Toggle */
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
    flex-shrink: 0;
    white-space: nowrap;
}
.btn-filters-toggle:hover {
    background: rgba(255, 255, 255, 0.28);
    border-color: #FCD34D;
}
.btn-filters-toggle.active {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
}
.btn-filters-toggle i:last-child {
    transition: transform 0.3s ease;
    font-size: 10px;
}
.btn-filters-toggle.active i:last-child { transform: rotate(180deg); }

/* Count Badge */
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
.filters-form {
    display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;
}
.filter-group {
    display: flex; flex-direction: column; gap: 6px;
    min-width: 160px; flex: 1;
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
    width: 100%;
    cursor: pointer;
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
   DATA TABLE
   ============================================================ */
.table-wrapper {
    overflow-x: auto;
    overflow-y: hidden;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
}
.table-wrapper::-webkit-scrollbar { height: 8px; }
.table-wrapper::-webkit-scrollbar-track { background: var(--bg-table-even); }
.table-wrapper::-webkit-scrollbar-thumb {
    background: #DC2626;
    border-radius: 4px;
}
.table-wrapper::-webkit-scrollbar-thumb:hover { background: #991B1B; }

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1200px;
}
.data-table thead { background: var(--bg-table-even); }
.data-table thead th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 800; font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: all 0.2s ease;
}
.data-table tbody tr:hover {
    background: var(--bg-table-hover);
}
.data-table tbody tr:nth-child(even) {
    background: var(--bg-table-even);
}
.data-table tbody tr:nth-child(even):hover {
    background: var(--bg-table-hover);
}
.data-table tbody tr.hidden-by-search { display: none !important; }
.data-table tbody td {
    padding: 14px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg-table-hover);
    font-size: 11px; font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

/* EMPLOYEE CELL */
.employee-cell {
    display: flex; align-items: center; gap: 12px;
    min-width: 0;
}
.employee-avatar-img {
    width: 42px; height: 42px;
    border-radius: 50%; object-fit: cover;
    border: 2.5px solid #DC2626;
    flex-shrink: 0;
    background: #F3F4F6;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}
.employee-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 16px;
    flex-shrink: 0;
    border: 2.5px solid #FCA5A5;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
}
.employee-details {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0;
}
.employee-name {
    font-size: 13px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 200px;
}
.employee-id {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 700;
    color: #DC2626; font-family: 'Courier New', monospace;
    background: #FEF2F2;
    padding: 2px 8px; border-radius: 6px;
    align-self: flex-start;
    letter-spacing: 0.3px;
}
html.dark-mode .employee-id { background: #7F1D1D; color: #FCA5A5; }
.employee-id i { font-size: 9px; }

/* CONTACT CELL */
.contact-cell {
    display: flex; flex-direction: column; gap: 4px;
    font-size: 11.5px;
}
.contact-line {
    display: flex; align-items: center; gap: 6px;
    color: var(--text-secondary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 200px;
}
.contact-line i {
    color: #DC2626;
    font-size: 10px;
    width: 12px; text-align: center;
    flex-shrink: 0;
}

/* BRANCH CELL */
.branch-cell {
    display: flex; align-items: flex-start; gap: 8px;
}
.branch-cell > i {
    color: #DC2626; font-size: 13px;
    margin-top: 2px;
    flex-shrink: 0;
}
.branch-cell > div {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0;
}
.branch-name {
    font-size: 12.5px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 150px;
}
.branch-code {
    display: inline-block;
    font-size: 9px; font-weight: 800;
    color: #DC2626;
    background: #FEF2F2;
    padding: 1px 6px; border-radius: 4px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
    letter-spacing: 0.5px;
}
html.dark-mode .branch-code { background: #7F1D1D; color: #FCA5A5; }

/* ROLE BADGE */
.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.role-employee {
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
.role-admin {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
.role-super_admin {
    background: #EDE9FE; color: #7C3AED;
    border: 1.5px solid #C4B5FD;
}
html.dark-mode .role-employee { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .role-admin { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .role-super_admin { background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6; }
.role-badge i { font-size: 10px; }

/* STATUS BADGE */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.status-active {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.status-on_leave {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
}
.status-suspended {
    background: #FEE2E2; color: #DC2626;
    border: 1.5px solid #FECACA;
}
.status-terminated {
    background: #F3F4F6; color: #6B7280;
    border: 1.5px solid #E5E7EB;
}
html.dark-mode .status-active { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .status-on_leave { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .status-suspended { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .status-terminated { background: #334155; color: #94A3B8; border-color: #475569; }
.status-badge i { font-size: 10px; }

/* DATE / SALARY */
.date-cell {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}
.date-cell i { color: #DC2626; font-size: 11px; }

.salary-cell {
    font-family: 'Courier New', monospace;
    font-size: 13px; font-weight: 800;
    color: #059669;
    white-space: nowrap;
}
html.dark-mode .salary-cell { color: #34D399; }

/* ACTION BUTTONS */
.action-buttons {
    display: flex; gap: 6px;
    justify-content: center;
}
.btn-action {
    width: 36px; height: 36px;
    border-radius: 8px;
    border: 1.5px solid;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
}
.btn-view {
    background: #DBEAFE; color: #1D4ED8;
    border-color: #BFDBFE;
}
.btn-view:hover {
    background: #1D4ED8; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.4);
    border-color: #1D4ED8;
}
.btn-edit {
    background: #FEF3C7; color: #D97706;
    border-color: #FDE68A;
}
.btn-edit:hover {
    background: #D97706; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.4);
    border-color: #D97706;
}
.btn-delete {
    background: #FEE2E2; color: #DC2626;
    border-color: #FECACA;
}
.btn-delete:hover {
    background: #DC2626; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
    border-color: #DC2626;
}
html.dark-mode .btn-view { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .btn-edit { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .btn-delete { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

/* NO RESULTS */
.no-results {
    padding: 50px 20px;
    text-align: center;
    background: var(--bg-table-even);
    border-top: 1.5px solid var(--border-color);
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

/* ============================================================
   PAGINATION
   ============================================================ */
.pagination-wrapper {
    padding: 18px 22px;
    background: var(--bg-table-even);
    border-top: 1.5px solid var(--border-color);
    display: flex; justify-content: space-between;
    align-items: center; flex-wrap: wrap; gap: 14px;
}
.pagination-info {
    font-size: 12px; color: var(--text-muted);
    font-weight: 500;
}
.pagination-info strong { color: var(--text-primary); font-weight: 800; }
.pagination-controls {
    display: flex; align-items: center; gap: 6px;
    flex-wrap: wrap;
}
.page-btn, .page-num {
    min-width: 38px; height: 38px;
    padding: 0 14px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    gap: 6px;
    font-family: 'Inter', sans-serif;
}
.page-btn:hover, .page-num:hover {
    background: #FEF2F2;
    color: #DC2626;
    border-color: #DC2626;
    transform: translateY(-2px);
}
html.dark-mode .page-btn:hover, html.dark-mode .page-num:hover {
    background: #7F1D1D;
    color: #FCA5A5;
}
.page-num.active {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-color: #991B1B;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
}
.page-btn.disabled {
    opacity: 0.4;
    pointer-events: none;
    cursor: not-allowed;
}
.page-dots {
    color: var(--text-muted);
    font-weight: 800;
    padding: 0 4px;
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
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
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    .header-search-wrapper {
        flex: 1 1 100%;
        max-width: 100%;
        min-width: 0;
    }
    .header-scroll-center {
        width: 100%;
        justify-content: center;
        order: 3;
    }
    .header-actions {
        width: 100%;
        justify-content: center;
        order: 2;
    }
    .btn-filters-toggle,
    .count-badge {
        flex: 1;
        justify-content: center;
    }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .stats-grid { grid-template-columns: 1fr; gap: 10px; }

    .table-red-header-content {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .header-scroll-btn {
        width: 38px; height: 38px;
        font-size: 14px;
    }
    .header-scroll-label {
        font-size: 9px;
    }

    .filters-form { flex-direction: column; }
    .filter-group { min-width: 100%; }
    .filter-actions { width: 100%; }
    .filter-actions button,
    .filter-actions a { flex: 1; justify-content: center; }

    .pagination-wrapper { flex-direction: column; }
    .pagination-controls { justify-content: center; width: 100%; }
}
@media (max-width: 480px) {
    .stat-card { padding: 14px 16px; gap: 10px; }
    .stat-icon { width: 42px; height: 42px; font-size: 18px; }
    .stat-value { font-size: 18px; }
    .employee-name { max-width: 140px; }
    .btn { padding: 10px 16px; font-size: 12px; }
    .header-scroll-btn { width: 32px; height: 32px; font-size: 12px; }
    .header-scroll-label { font-size: 8px; }
}
</style>

<script>
// ============================================================
// QUICK SEARCH (client-side filtering)
// ============================================================
function onQuickSearch(input) {
    var term = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#employeesTableBody .employee-row');
    var clearBtn = document.getElementById('searchClearBtn');
    var noResults = document.getElementById('noResults');
    var wrapper = document.getElementById('tableWrapper');
    var hiddenSearch = document.getElementById('hiddenSearch');

    if (hiddenSearch) hiddenSearch.value = input.value;

    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';

    if (term === '') {
        rows.forEach(function(row) { row.classList.remove('hidden-by-search'); });
        if (noResults) noResults.style.display = 'none';
        if (wrapper) wrapper.style.display = '';
        return;
    }

    var matches = 0;
    rows.forEach(function(row) {
        var data = row.getAttribute('data-search') || '';
        if (data.includes(term)) {
            row.classList.remove('hidden-by-search');
            matches++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });

    if (noResults) noResults.style.display = matches === 0 ? 'block' : 'none';
    if (wrapper) wrapper.style.display = matches === 0 ? 'none' : '';
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
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }
}

function applyFilters() {
    var form = document.getElementById('filtersForm');
    if (form) {
        form.submit();
    } else {
        var url = new URL(window.location.href);
        var s = document.getElementById('quickSearch').value;
        if (s) url.searchParams.set('search', s);
        else url.searchParams.delete('search');
        window.location.href = url.toString();
    }
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

// ============================================================
// TABLE SCROLL (< > buttons in header)
// ============================================================
function scrollTable(direction) {
    var wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    var scrollAmount = 350;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// AUTO-OPEN FILTERS + ALERT HIDE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var url = new URL(window.location.href);
    var hasFilter = url.searchParams.get('branch_id') > 0
                 || url.searchParams.get('role')
                 || url.searchParams.get('status');

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

// Dark mode sync
document.addEventListener('DOMContentLoaded', function() {
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