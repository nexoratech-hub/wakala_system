<?php
// ================================================================
// FILE: modules/employees/index.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEES LIST
// WITH PROFILE PICTURE SUPPORT & DARK MODE
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
// GET BRANCHES FOR FILTER
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER HANDLING
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

if (isset($_GET['branch'])) {
    $_SESSION['selected_branch'] = $selected_branch;
} elseif (isset($_SESSION['selected_branch']) && !isset($_GET['branch'])) {
    $selected_branch = $_SESSION['selected_branch'];
}

$selected_branch = $selected_branch ?? 0;

// Build branch filter for SQL
$branch_filter = '';
$branch_params = [];

if ($selected_branch > 0) {
    $branch_filter = " AND e.branch_id = ? ";
    $branch_params[] = $selected_branch;
}

// Get branch name for display
$branch_name = 'All Branches';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// ============================================================
// GET EMPLOYEE SUMMARIES
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// TOTAL EMPLOYEES
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as total FROM employees WHERE branch_id = ?";
    $params = [$selected_branch];
} else {
    $sql = "SELECT COUNT(*) as total FROM employees";
    $params = [];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_employees = $result['total'] ?? 0;

// ACTIVE EMPLOYEES
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as total FROM employees WHERE is_active = 1 AND branch_id = ?";
    $params = [$selected_branch];
} else {
    $sql = "SELECT COUNT(*) as total FROM employees WHERE is_active = 1";
    $params = [];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$active_employees = $result['total'] ?? 0;

// THIS MONTH HIRED
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as total FROM employees WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND branch_id = ?";
    $params = [$month, $year, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as total FROM employees WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?";
    $params = [$month, $year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$this_month_hired = $result['total'] ?? 0;

// ============================================================
// GET EMPLOYEES LIST
// ============================================================
$sql = "SELECT 
            e.id,
            e.employee_id,
            e.full_name,
            e.email,
            e.phone,
            e.username,
            e.role,
            e.branch,
            e.branch_id,
            e.profile_pic,
            e.base_salary,
            e.hire_date,
            e.employment_status,
            e.is_active,
            e.last_login,
            e.created_at,
            b.branch_name as branch_name
        FROM employees e
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY e.full_name ASC";

$params = $branch_params;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Count employees
$employee_count = count($employees);

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
        
        <!-- ===== DARK MODE TOGGLE ===== -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- ===== PAGE HEADER WITH ADD BUTTON ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-users"></i> Employees</h2>
                <span class="record-count"><?php echo $employee_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <!-- ADD Button - FIRST -->
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Employee
                    </a>
                    
                    <!-- Export Dropdown - SECOND -->
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

        <!-- ===== BRANCH FILTER ===== -->
        <div class="branch-filter-bar">
            <div class="branch-filter-left">
                <i class="fas fa-store-alt"></i>
                <span>Branch:</span>
                <select id="branchFilter" onchange="window.location.href='?branch='+this.value">
                    <option value="0">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_branch > 0): ?>
                    <span class="branch-badge"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php endif; ?>
            </div>
            <div class="branch-filter-right">
                <span class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('d M Y'); ?></span>
            </div>
        </div>

        <!-- ============================================================
        SUMMARIES CARDS - TOTAL, ACTIVE, THIS MONTH
        ============================================================ -->
        <div class="summaries-grid-three">
            <!-- TOTAL EMPLOYEES - Blue -->
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-users"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL EMPLOYEES</div>
                    <div class="summary-value"><?php echo number_format($total_employees); ?></div>
                    <div class="summary-sub">All Employees</div>
                </div>
            </div>

            <!-- ACTIVE EMPLOYEES - Green -->
            <div class="summary-card card-active">
                <div class="summary-icon"><i class="fas fa-user-check"></i></div>
                <div class="summary-content">
                    <div class="summary-label">ACTIVE EMPLOYEES</div>
                    <div class="summary-value"><?php echo number_format($active_employees); ?></div>
                    <div class="summary-sub">Currently Active</div>
                </div>
            </div>

            <!-- THIS MONTH HIRED - Orange -->
            <div class="summary-card card-month">
                <div class="summary-icon"><i class="fas fa-user-plus"></i></div>
                <div class="summary-content">
                    <div class="summary-label">THIS MONTH HIRED</div>
                    <div class="summary-value"><?php echo number_format($this_month_hired); ?></div>
                    <div class="summary-sub"><?php echo date('F Y'); ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - EMPLOYEES LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Employees</h3>
                <div class="table-actions">
                    <select id="roleFilter" class="filter-select" onchange="filterByRole(this.value)">
                        <option value="">All Roles</option>
                        <option value="super_admin">Super Admin</option>
                        <option value="admin">Admin</option>
                        <option value="employee">Employee</option>
                    </select>
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search employees..." class="search-input">
                </div>
            </div>

            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Employees Found</h3>
                    <p>Start by adding your first employee record.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Employee
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="employeesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Branch</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($employees as $employee): 
                                // Status
                                $is_active = $employee['is_active'] ?? 1;
                                $status = $is_active ? 'Active' : 'Inactive';
                                $status_class = $is_active ? 'status-active' : 'status-inactive';
                                
                                // Role badge color
                                $role_class = '';
                                if (strtolower($employee['role']) == 'super_admin') {
                                    $role_class = 'role-super-admin';
                                } elseif (strtolower($employee['role']) == 'admin') {
                                    $role_class = 'role-admin';
                                } else {
                                    $role_class = 'role-employee';
                                }
                                
                                // ============================================================
                                // GET PROFILE PICTURE PATH
                                // ============================================================
                                $profile_pic_path = '';
                                $profile_pic_url = '';
                                
                                if (!empty($employee['profile_pic'])) {
                                    // Check if file exists in different paths
                                    $paths_to_check = [
                                        '../../' . $employee['profile_pic'],
                                        $employee['profile_pic'],
                                        '../../uploads/profiles/' . basename($employee['profile_pic'])
                                    ];
                                    
                                    foreach ($paths_to_check as $path) {
                                        if (file_exists($path)) {
                                            $profile_pic_url = '../../' . $employee['profile_pic'];
                                            break;
                                        }
                                    }
                                    
                                    // If still not found, try direct path
                                    if (empty($profile_pic_url) && file_exists($employee['profile_pic'])) {
                                        $profile_pic_url = $employee['profile_pic'];
                                    }
                                }
                            ?>
                                <tr data-role="<?php echo strtolower($employee['role']); ?>" data-status="<?php echo $is_active; ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="employee-id">
                                            <?php echo htmlspecialchars($employee['employee_id']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="employee-name-cell">
                                            <?php if (!empty($profile_pic_url)): ?>
                                                <img src="<?php echo htmlspecialchars($profile_pic_url); ?>" alt="Profile" class="profile-thumb" 
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="profile-avatar" style="display:none;">
                                                    <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="profile-avatar">
                                                    <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="employee-name">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="employee-email">
                                            <?php echo htmlspecialchars($employee['email']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-phone">
                                            <?php echo htmlspecialchars($employee['phone'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($employee['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="role-badge <?php echo $role_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $employee['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this employee?')">
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
DASHBOARD STYLES WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --bg-primary: #f3f4f6;
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-card-hover: #f9fafb;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --bg-empty: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
    --dropdown-bg: #ffffff;
    --dropdown-hover: #f3f4f6;
}

/* Dark Mode - Full Page */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-card-hover: #334155;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --bg-empty: #1a2332;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
    --dropdown-bg: #1e293b;
    --dropdown-hover: #334155;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   DARK MODE TOGGLE BUTTON
   ============================================================ */
.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.dark-mode-btn:hover {
    background: var(--bg-card-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.dark-mode-btn i {
    font-size: 16px;
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
    color: var(--text-primary);
    margin: 0;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--text-muted);
    background: var(--bg-table-even);
    padding: 2px 12px;
    border-radius: 12px;
}

.header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* ============================================================
   ADD BUTTON - RED
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

/* ============================================================
   EMPTY STATE ADD BUTTON - RED
   ============================================================ */
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
    border: none;
    cursor: pointer;
}

.btn-add-empty:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.4);
    color: white;
}

/* ============================================================
   EXPORT BUTTON - BLUE
   ============================================================ */
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
    background: var(--dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--shadow-hover);
    border: 1px solid var(--border-color);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
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
    color: var(--text-primary);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--dropdown-hover);
}

.dropdown-menu a i {
    width: 18px;
    font-size: 15px;
}

.dropdown-menu a i.fa-file-csv { color: #0B5ED7; }
.dropdown-menu a i.fa-file-excel { color: #1D7D1D; }
.dropdown-menu a i.fa-file-pdf { color: #DC2626; }
.dropdown-menu a i.fa-print { color: #6B7280; }

/* ============================================================
   BRANCH FILTER BAR
   ============================================================ */
.branch-filter-bar {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.branch-filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-primary);
}

.branch-filter-left i {
    color: #DC2626;
    font-size: 16px;
}

.branch-filter-left select {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: var(--bg-input);
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    cursor: pointer;
}

.branch-filter-left select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.branch-badge {
    background: #DC2626;
    color: white;
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.branch-filter-right .date-display {
    font-size: 13px;
    color: var(--text-muted);
}

.branch-filter-right .date-display i {
    color: #DC2626;
}

/* ============================================================
   SUMMARIES GRID - 3 CARDS
   ============================================================ */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
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
    color: var(--text-muted);
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-sub {
    font-size: 11px;
    color: var(--text-light);
    font-weight: 500;
}

/* Card Colors */
.card-total .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total { border-left: 4px solid #3B82F6; }

.card-active .summary-icon { background: #D1FAE5; color: #065F46; }
.card-active { border-left: 4px solid #10B981; }

.card-month .summary-icon { background: #FEF3C7; color: #D97706; }
.card-month { border-left: 4px solid #D97706; }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 10px;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
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
    border: 1px solid var(--border-color);
    font-size: 13px;
    outline: none;
    width: 200px;
    background: var(--bg-input);
    color: var(--text-primary);
    transition: all 0.3s ease;
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.search-input::placeholder {
    color: var(--text-light);
}

.filter-select {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    font-size: 13px;
    outline: none;
    background: var(--bg-input);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

/* ============================================================
   TABLE HEADER - RED BACKGROUND
   ============================================================ */
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

.data-table thead th i {
    color: #FFFFFF;
    margin-right: 4px;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
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

.data-table tbody td {
    padding: 12px 16px;
    color: var(--text-secondary);
}

/* Employee ID */
.employee-id {
    font-weight: 600;
    color: #3B82F6;
    font-size: 12px;
}

/* Employee Name Cell */
.employee-name-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.profile-thumb {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #DC2626;
    background: #ffffff;
}

.profile-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #3B82F6;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

/* Dark mode profile avatar */
body.dark-mode .profile-avatar {
    background: #3B82F6;
    color: #ffffff;
}

body.dark-mode .profile-thumb {
    border-color: #DC2626;
    background: #1e293b;
}

.employee-name {
    font-weight: 500;
    color: var(--text-primary);
}

/* Employee Email */
.employee-email {
    font-size: 12px;
    color: var(--text-muted);
}

/* Employee Phone */
.employee-phone {
    font-size: 12px;
    color: var(--text-secondary);
}

/* Branch Name */
.branch-name {
    background: var(--bg-table-even);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--text-muted);
}

/* Role Badge */
.role-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.role-super-admin {
    background: #FEF3C7;
    color: #92400E;
}

.role-admin {
    background: #DBEAFE;
    color: #1E40AF;
}

.role-employee {
    background: #D1FAE5;
    color: #065F46;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-active {
    background: #D1FAE5;
    color: #065F46;
}

.status-inactive {
    background: #FEE2E2;
    color: #991B1B;
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

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-empty);
}

.empty-state i {
    font-size: 60px;
    color: #3B82F6;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0 0 24px 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summaries-grid-three {
        grid-template-columns: repeat(3, 1fr);
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
    
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
    }
    
    .summaries-grid-three .summary-card:last-child {
        grid-column: span 2;
    }
    
    .branch-filter-bar {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
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
}

@media (max-width: 480px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .summaries-grid-three .summary-card:last-child {
        grid-column: span 2;
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
    
    .btn-add-empty {
        padding: 10px 20px;
        font-size: 13px;
        width: 100%;
        justify-content: center;
    }
    
    .employee-name-cell {
        flex-direction: column;
        align-items: flex-start;
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

.table-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.20s;
}
</style>

<script>
// ============================================================
// DARK MODE TOGGLE
// ============================================================
function toggleDarkMode() {
    const body = document.body;
    const btn = document.getElementById('darkModeToggle');
    const icon = btn.querySelector('i');
    const text = btn.querySelector('span');
    
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
        icon.className = 'fas fa-sun';
        text.textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        icon.className = 'fas fa-moon';
        text.textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

// Check for saved dark mode preference
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = localStorage.getItem('darkMode');
    const btn = document.getElementById('darkModeToggle');
    const icon = btn?.querySelector('i');
    const text = btn?.querySelector('span');
    
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        if (icon) icon.className = 'fas fa-sun';
        if (text) text.textContent = 'Light Mode';
    }
});

// ============================================================
// DROPDOWN TOGGLE
// ============================================================
function toggleDropdown() {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
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
    
    var table = document.getElementById('employeesTable');
    if (!table) {
        alert('No data to export!');
        return;
    }
    
    var rows = table.querySelectorAll('tbody tr');
    var headers = [];
    var headerCells = table.querySelectorAll('thead th');
    
    // Get headers (skip Actions column)
    for (var i = 0; i < headerCells.length - 1; i++) {
        headers.push(headerCells[i].textContent.trim());
    }
    
    // Get data
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

// ============================================================
// EXPORT CSV
// ============================================================
function exportCSV(headers, data) {
    var csv = headers.join(',') + '\n';
    data.forEach(function(row) {
        csv += row.join(',') + '\n';
    });
    
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'employees_export_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT EXCEL (HTML Table format)
// ============================================================
function exportExcel(headers, data) {
    var html = '<html><head><meta charset="UTF-8"><title>Employees Export</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    html += 'h1 { color: #3B82F6; }';
    html += 'table { width: 100%; border-collapse: collapse; }';
    html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
    html += '</style>';
    html += '</head><body>';
    html += '<h1>Employees Report</h1>';
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
    a.download = 'employees_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT PDF
// ============================================================
function exportPDF(headers, data) {
    var printContent = '<html><head><title>Employees Export</title>';
    printContent += '<style>';
    printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    printContent += 'h1 { color: #3B82F6; }';
    printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    printContent += '.total { margin-top: 20px; font-weight: bold; font-size: 16px; }';
    printContent += '</style>';
    printContent += '</head><body>';
    printContent += '<h1>Employees Report</h1>';
    printContent += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
    
    var totalActive = 0;
    var totalInactive = 0;
    
    printContent += '<table>';
    printContent += '<thead><tr>';
    headers.forEach(function(h) {
        printContent += '<th>' + h + '</th>';
    });
    printContent += '</tr></thead><tbody>';
    
    data.forEach(function(row) {
        printContent += '<tr>';
        row.forEach(function(cell, index) {
            // Status column (index 7)
            if (index === 7) {
                if (cell.trim() === 'Active') {
                    totalActive++;
                } else if (cell.trim() === 'Inactive') {
                    totalInactive++;
                }
            }
            printContent += '<td>' + cell + '</td>';
        });
        printContent += '</tr>';
    });
    
    printContent += '</tbody></table>';
    printContent += '<div class="total">Total Active Employees: ' + totalActive + '</div>';
    printContent += '<div class="total">Total Inactive Employees: ' + totalInactive + '</div>';
    printContent += '<div class="total">Total Employees: ' + (totalActive + totalInactive) + '</div>';
    printContent += '</body></html>';
    
    var printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// ============================================================
// FILTER BY ROLE
// ============================================================
function filterByRole(role) {
    var rows = document.querySelectorAll('#employeesTable tbody tr');
    var roleFilter = role.toLowerCase();
    
    rows.forEach(function(row) {
        var rowRole = row.getAttribute('data-role');
        if (roleFilter === '' || rowRole === roleFilter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// ============================================================
// FILTER BY STATUS
// ============================================================
function filterByStatus(status) {
    var rows = document.querySelectorAll('#employeesTable tbody tr');
    
    rows.forEach(function(row) {
        var rowStatus = row.getAttribute('data-status');
        if (status === '' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// ============================================================
// SEARCH FUNCTIONALITY
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#employeesTable tbody tr');
            
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
});
</script>

</body>
</html>