<?php
// ================================================================
// FILE: modules/activity_logs/index.php
// WAKALA FINANCIAL SYSTEM - ACTIVITY LOGS
// WITH DARK MODE SUPPORT
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
// FILTER HANDLING
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
$selected_action = isset($_GET['action']) ? $_GET['action'] : '';
$selected_module = isset($_GET['module']) ? $_GET['module'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Build filter SQL
$where_conditions = [];
$params = [];

if ($selected_branch > 0) {
    $where_conditions[] = "al.branch_id = ?";
    $params[] = $selected_branch;
}

if (!empty($selected_action)) {
    $where_conditions[] = "al.action = ?";
    $params[] = $selected_action;
}

if (!empty($selected_module)) {
    $where_conditions[] = "al.module = ?";
    $params[] = $selected_module;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// ============================================================
// GET ACTIVITY LOGS SUMMARIES
// ============================================================
// Total logs
$sql = "SELECT COUNT(*) as total FROM activity_logs al";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch();
$total_logs = $result['total'] ?? 0;

// Today's logs
$sql = "SELECT COUNT(*) as total FROM activity_logs al WHERE DATE(created_at) = CURDATE()";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch();
$today_logs = $result['total'] ?? 0;

// Unique users who logged activity
$sql = "SELECT COUNT(DISTINCT employee_id) as total FROM activity_logs al";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch();
$active_users = $result['total'] ?? 0;

// ============================================================
// GET ACTIVITY LOGS LIST
// ============================================================
$sql = "SELECT 
            al.id,
            al.employee_id,
            al.action,
            al.module,
            al.record_id,
            al.old_value,
            al.new_value,
            al.ip_address,
            al.user_agent,
            al.branch_id,
            al.created_at,
            e.full_name as employee_name,
            e.role as employee_role,
            b.branch_name as branch_name
        FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        LEFT JOIN branches b ON al.branch_id = b.id
        " . $where_clause . "
        ORDER BY al.created_at DESC
        LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Count logs
$log_count = count($logs);

// Get unique actions and modules for filters
$action_stmt = $db->prepare("SELECT DISTINCT action FROM activity_logs ORDER BY action");
$action_stmt->execute();
$actions = $action_stmt->fetchAll();

$module_stmt = $db->prepare("SELECT DISTINCT module FROM activity_logs ORDER BY module");
$module_stmt->execute();
$modules = $module_stmt->fetchAll();

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
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-history"></i> Activity Logs</h2>
                <span class="record-count"><?php echo number_format($log_count); ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <!-- Export Dropdown -->
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
        SUMMARIES CARDS
        ============================================================ -->
        <div class="summaries-grid-three">
            <!-- Total Logs -->
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-file-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL LOGS</div>
                    <div class="summary-value"><?php echo number_format($total_logs); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>

            <!-- Today's Logs -->
            <div class="summary-card card-today">
                <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY'S LOGS</div>
                    <div class="summary-value"><?php echo number_format($today_logs); ?></div>
                    <div class="summary-sub"><?php echo date('d M Y'); ?></div>
                </div>
            </div>

            <!-- Active Users -->
            <div class="summary-card card-users">
                <div class="summary-icon"><i class="fas fa-users"></i></div>
                <div class="summary-content">
                    <div class="summary-label">ACTIVE USERS</div>
                    <div class="summary-value"><?php echo number_format($active_users); ?></div>
                    <div class="summary-sub">Who Logged Activity</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FILTERS
        ============================================================ -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="branch">Branch</label>
                        <select id="branch" name="branch" class="form-control">
                            <option value="0">All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="action">Action</label>
                        <select id="action" name="action" class="form-control">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $a): ?>
                                <option value="<?php echo htmlspecialchars($a['action']); ?>" <?php echo $selected_action == $a['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($a['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="module">Module</label>
                        <select id="module" name="module" class="form-control">
                            <option value="">All Modules</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['module']); ?>" <?php echo $selected_module == $m['module'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['module']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    
                    <div class="filter-group filter-actions">
                        <button type="submit" class="btn btn-filter">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="?branch=0&action=&module=&date_from=&date_to=" class="btn btn-reset-filter">
                            <i class="fas fa-times"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- ============================================================
        TABLE - ACTIVITY LOGS
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Activity Logs</h3>
                <div class="table-actions">
                    <input type="text" id="searchInput" placeholder="Search logs..." class="search-input">
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No Activity Logs Found</h3>
                    <p>No activity logs match your filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="logsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th>Branch</th>
                                <th>Date/Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($logs as $log): 
                                // Action color
                                $action = $log['action'] ?? 'Unknown';
                                $action_class = 'action-default';
                                
                                $action_colors = [
                                    'Login' => 'action-login',
                                    'Logout' => 'action-logout',
                                    'Add' => 'action-add',
                                    'Edit' => 'action-edit',
                                    'Update' => 'action-edit',
                                    'Delete' => 'action-delete',
                                    'View' => 'action-view',
                                    'Export' => 'action-export',
                                    'Print' => 'action-print',
                                    'Generate' => 'action-generate',
                                    'Approve' => 'action-approve',
                                    'Reject' => 'action-reject',
                                    'Cancel' => 'action-cancel'
                                ];
                                
                                foreach ($action_colors as $key => $class) {
                                    if (stripos($action, $key) !== false) {
                                        $action_class = $class;
                                        break;
                                    }
                                }
                                
                                // Get role badge color
                                $role_class = '';
                                if (strtolower($log['employee_role'] ?? '') == 'super_admin') {
                                    $role_class = 'role-super-admin';
                                } elseif (strtolower($log['employee_role'] ?? '') == 'admin') {
                                    $role_class = 'role-admin';
                                } else {
                                    $role_class = 'role-employee';
                                }
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="user-cell">
                                            <span class="user-name"><?php echo htmlspecialchars($log['employee_name'] ?? 'Unknown'); ?></span>
                                            <span class="user-role-badge <?php echo $role_class; ?>">
                                                <?php echo ucfirst($log['employee_role'] ?? 'N/A'); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="action-badge <?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($action); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="module-badge">
                                            <?php echo htmlspecialchars($log['module'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="record-id">
                                            <?php echo $log['record_id'] ? '#' . $log['record_id'] : '—'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ip-address">
                                            <?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($log['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="datetime-cell">
                                            <span class="log-date"><?php echo date('d M Y', strtotime($log['created_at'])); ?></span>
                                            <span class="log-time"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action btn-view" onclick="viewLog(<?php echo $log['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-action btn-delete" onclick="deleteLog(<?php echo $log['id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
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
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --logs-bg: #FFFFFF;
    --logs-text: #1F2937;
    --logs-text-secondary: #6B7280;
    --logs-text-light: #9CA3AF;
    --logs-border: #E5E7EB;
    --logs-card-bg: #FFFFFF;
    --logs-input-bg: #F9FAFB;
    --logs-hover: #F3F4F6;
    --logs-shadow: rgba(0,0,0,0.06);
    --logs-shadow-lg: rgba(0,0,0,0.12);
    --logs-dropdown-bg: #FFFFFF;
    --logs-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --logs-bg: #1F2937;
    --logs-text: #F9FAFB;
    --logs-text-secondary: #9CA3AF;
    --logs-text-light: #6B7280;
    --logs-border: #374151;
    --logs-card-bg: #1F2937;
    --logs-input-bg: #374151;
    --logs-hover: #374151;
    --logs-shadow: rgba(0,0,0,0.3);
    --logs-shadow-lg: rgba(0,0,0,0.4);
    --logs-dropdown-bg: #1F2937;
    --logs-dropdown-border: #374151;
}

/* ============================================================
   PAGE HEADER - DARK MODE SUPPORT
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
    color: var(--logs-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: var(--logs-text-secondary);
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--logs-text-secondary);
    background: var(--logs-hover);
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
   EXPORT BUTTON - DARK MODE SUPPORT
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
    background: var(--logs-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--logs-shadow-lg);
    border: 1px solid var(--logs-dropdown-border);
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
    color: var(--logs-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--logs-hover);
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
   SUMMARIES GRID - 3 CARDS - DARK MODE SUPPORT
   ============================================================ */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--logs-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--logs-shadow);
    border: 1px solid var(--logs-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--logs-shadow-lg);
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
    color: var(--logs-text-secondary);
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--logs-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--logs-text-light);
    font-weight: 500;
}

.card-total .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total { border-left: 4px solid #3B82F6; }

.card-today .summary-icon { background: #D1FAE5; color: #065F46; }
.card-today { border-left: 4px solid #10B981; }

.card-users .summary-icon { background: #FEF3C7; color: #D97706; }
.card-users { border-left: 4px solid #D97706; }

/* ============================================================
   FILTER BAR - DARK MODE SUPPORT
   ============================================================ */
.filter-bar {
    background: var(--logs-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 16px;
    border: 1px solid var(--logs-border);
    box-shadow: 0 1px 3px var(--logs-shadow);
    transition: all 0.3s ease;
}

.filter-form {
    width: 100%;
}

.filter-row {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 120px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--logs-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
}

.filter-group .form-control {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid var(--logs-border);
    font-size: 13px;
    outline: none;
    transition: all 0.3s ease;
    background: var(--logs-input-bg);
    color: var(--logs-text);
    width: 100%;
    font-family: 'Inter', sans-serif;
}

.filter-group .form-control option {
    background: var(--logs-dropdown-bg);
    color: var(--logs-text);
}

.filter-group .form-control:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-actions {
    display: flex;
    flex-direction: row;
    gap: 8px;
    align-items: flex-end;
    min-width: 180px;
}

.btn-filter {
    background: #DC2626;
    color: white;
    padding: 8px 18px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-filter:hover {
    background: #B91C1C;
    transform: translateY(-1px);
}

.btn-reset-filter {
    background: var(--logs-hover);
    color: var(--logs-text-secondary);
    padding: 8px 18px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-reset-filter:hover {
    background: var(--logs-border);
    color: var(--logs-text);
}

/* ============================================================
   TABLE CONTAINER - DARK MODE SUPPORT
   ============================================================ */
.table-container {
    background: var(--logs-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--logs-shadow);
    border: 1px solid var(--logs-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--logs-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--logs-text);
    margin: 0;
}

.table-header h3 i {
    color: var(--logs-text-secondary);
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
    border: 1px solid var(--logs-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--logs-input-bg);
    color: var(--logs-text);
}

.search-input::placeholder {
    color: var(--logs-text-light);
}

.search-input:focus {
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
   TABLE HEADER - RED BACKGROUND (Stays Red in Dark Mode)
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
    border-bottom: 1px solid var(--logs-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--logs-hover);
}

.data-table tbody td {
    padding: 10px 16px;
    color: var(--logs-text);
    transition: color 0.3s ease;
}

/* User Cell */
.user-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.user-name {
    font-weight: 500;
    color: var(--logs-text);
}

.user-role-badge {
    font-size: 9px;
    font-weight: 600;
    padding: 1px 8px;
    border-radius: 10px;
    display: inline-block;
    width: fit-content;
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

/* Action Badge */
.action-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.action-login {
    background: #DBEAFE;
    color: #1E40AF;
}

.action-logout {
    background: #FEE2E2;
    color: #991B1B;
}

.action-add {
    background: #D1FAE5;
    color: #065F46;
}

.action-edit {
    background: #FEF3C7;
    color: #92400E;
}

.action-delete {
    background: #FEE2E2;
    color: #991B1B;
}

.action-view {
    background: #EDE9FE;
    color: #5B21B6;
}

.action-export {
    background: #DBEAFE;
    color: #1E40AF;
}

.action-print {
    background: #F3F4F6;
    color: #374151;
}

.action-generate {
    background: #D1FAE5;
    color: #065F46;
}

.action-approve {
    background: #D1FAE5;
    color: #065F46;
}

.action-reject {
    background: #FEE2E2;
    color: #991B1B;
}

.action-cancel {
    background: #F3F4F6;
    color: #6B7280;
}

.action-default {
    background: #F3F4F6;
    color: #374151;
}

/* Module Badge */
.module-badge {
    background: var(--logs-hover);
    color: var(--logs-text-secondary);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    transition: all 0.3s ease;
}

/* Record ID */
.record-id {
    font-size: 12px;
    color: var(--logs-text-light);
    font-weight: 500;
}

/* IP Address */
.ip-address {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: var(--logs-text-light);
}

/* Branch Name */
.branch-name {
    background: var(--logs-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--logs-text-secondary);
    transition: all 0.3s ease;
}

/* DateTime Cell */
.datetime-cell {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.log-date {
    font-weight: 500;
    color: var(--logs-text);
    font-size: 12px;
}

.log-time {
    font-size: 11px;
    color: var(--logs-text-light);
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
    border: none;
    cursor: pointer;
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

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
}

/* ============================================================
   EMPTY STATE - DARK MODE SUPPORT
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 60px;
    color: var(--logs-text-light);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--logs-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--logs-text-secondary);
    font-size: 14px;
    margin: 0;
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
    }
    
    .header-actions .btn-export {
        width: 100%;
        justify-content: center;
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
    
    .filter-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .filter-group {
        min-width: 100%;
    }
    
    .filter-actions {
        flex-direction: row;
        min-width: 100%;
    }
    
    .filter-actions .btn-filter,
    .filter-actions .btn-reset-filter {
        flex: 1;
        justify-content: center;
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

.filter-bar {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.10s;
}

.table-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.20s;
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
    
    var table = document.getElementById('logsTable');
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
    a.download = 'activity_logs_export_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT EXCEL
// ============================================================
function exportExcel(headers, data) {
    var html = '<html><head><meta charset="UTF-8"><title>Activity Logs Export</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    html += 'h1 { color: #DC2626; }';
    html += 'table { width: 100%; border-collapse: collapse; }';
    html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
    html += '</style>';
    html += '</head><body>';
    html += '<h1>Activity Logs Report</h1>';
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
    a.download = 'activity_logs_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT PDF
// ============================================================
function exportPDF(headers, data) {
    var printContent = '<html><head><title>Activity Logs Export</title>';
    printContent += '<style>';
    printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    printContent += 'h1 { color: #DC2626; }';
    printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    printContent += '</style>';
    printContent += '</head><body>';
    printContent += '<h1>Activity Logs Report</h1>';
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
// VIEW LOG DETAILS
// ============================================================
function viewLog(id) {
    alert('View log details for ID: ' + id);
}

// ============================================================
// DELETE LOG
// ============================================================
function deleteLog(id) {
    if (confirm('Are you sure you want to delete this log entry?')) {
        alert('Delete log ID: ' + id);
    }
}

// ============================================================
// SEARCH FUNCTIONALITY
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#logsTable tbody tr');
            
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
    
    // ============================================================
    // DARK MODE SYNC
    // ============================================================
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
    
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
});
</script>

</body>
</html>