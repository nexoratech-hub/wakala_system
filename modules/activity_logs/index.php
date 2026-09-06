<?php
// ================================================================
// FILE: modules/activity_logs/index.php
// ACTIVITY LOGS - VIEW ALL SYSTEM ACTIVITIES
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Only admin and super_admin can view activity logs
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// Get branches for filter
try {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

// Branch filter
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
if (isset($_GET['branch'])) {
    $_SESSION['selected_branch'] = $selected_branch;
} elseif (isset($_SESSION['selected_branch']) && !isset($_GET['branch'])) {
    $selected_branch = $_SESSION['selected_branch'];
}
$selected_branch = $selected_branch ?? 0;

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

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$module_filter = isset($_GET['module']) ? $_GET['module'] : '';
$employee_filter = isset($_GET['employee']) ? intval($_GET['employee']) : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-7 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

$error = '';
$logs = [];
$total_records = 0;
$total_pages = 0;

try {
    // Build query with branch filter
    $sql = "SELECT al.*, 
            e.full_name as employee_name,
            e.username,
            b.branch_name
            FROM activity_logs al
            LEFT JOIN employees e ON al.employee_id = e.id
            LEFT JOIN branches b ON al.branch_id = b.id
            WHERE DATE(al.created_at) BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND al.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($action_filter)) {
        $sql .= " AND al.action LIKE ?";
        $params[] = '%' . $action_filter . '%';
    }

    if (!empty($module_filter)) {
        $sql .= " AND al.module = ?";
        $params[] = $module_filter;
    }

    if ($employee_filter > 0) {
        $sql .= " AND al.employee_id = ?";
        $params[] = $employee_filter;
    }

    // Count total records
    $count_sql = str_replace("al.*, e.full_name as employee_name, e.username, b.branch_name", "COUNT(*) as total", $sql);
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $total_pages = ceil($total_records / $per_page);

    // Get logs with pagination
    $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get distinct modules for filter
    $stmt = $db->prepare("SELECT DISTINCT module FROM activity_logs ORDER BY module");
    $stmt->execute();
    $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get employees for filter
    $stmt = $db->prepare("SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get distinct actions for filter
    $stmt = $db->prepare("SELECT DISTINCT action FROM activity_logs ORDER BY action");
    $stmt->execute();
    $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    error_log("Error loading activity logs: " . $e->getMessage());
    $logs = [];
    $modules = [];
    $employees = [];
    $actions = [];
}

// Get summary statistics
try {
    $sql_summary = "SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT employee_id) as unique_users,
            COUNT(DISTINCT module) as unique_modules,
            MAX(created_at) as last_activity
            FROM activity_logs
            WHERE DATE(created_at) BETWEEN ? AND ?";
    $params_summary = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql_summary .= " AND branch_id = ?";
        $params_summary[] = $selected_branch;
    }
    
    $stmt = $db->prepare($sql_summary);
    $stmt->execute($params_summary);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $summary = [
        'total_activities' => 0,
        'unique_users' => 0,
        'unique_modules' => 0,
        'last_activity' => null
    ];
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH FILTER CARD ===== -->
        <div class="branch-card">
            <i class="fas fa-store-alt"></i>
            <span class="branch-label">Branch:</span>
            <select id="branchFilter" class="branch-select" onchange="window.location.href='?branch='+this.value">
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-history" style="color:#bb0404;"></i> Activity Logs</h2>
                <p class="text-muted">View all system activities and user actions</p>
            </div>
            <div class="header-right">
                <button onclick="window.location.reload()" class="btn btn-secondary">
                    <i class="fas fa-sync"></i> Refresh
                </button>
                <a href="export.php" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Export
                </a>
                <button onclick="clearLogs()" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Clear All
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-list"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Activities</span>
                    <span class="summary-value"><?php echo number_format($summary['total_activities'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#D1FAE5;color:#065F46;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Unique Users</span>
                    <span class="summary-value"><?php echo number_format($summary['unique_users'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#EDE9FE;color:#6D28D9;">
                    <i class="fas fa-cubes"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Modules Used</span>
                    <span class="summary-value"><?php echo number_format($summary['unique_modules'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#FEF3C7;color:#92400E;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Last Activity</span>
                    <span class="summary-value" style="font-size:14px;">
                        <?php echo $summary['last_activity'] ? date('d M Y H:i', strtotime($summary['last_activity'])) : 'N/A'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>Module</label>
                    <select name="module" class="form-control">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module_filter == $m ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Action</label>
                    <select name="action" class="form-control">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action_filter == $a ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Employee</label>
                    <select name="employee" class="form-control">
                        <option value="0">All Employees</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $employee_filter == $e['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($e['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Logs Table -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Activity Logs</h4>
                <span class="record-count"><?php echo number_format($total_records); ?> records</span>
            </div>
            
            <div class="table-responsive">
                <table class="data-table" id="logsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date & Time</th>
                            <th>Employee</th>
                            <th>Module</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logs) > 0): ?>
                            <?php $counter = $offset + 1; ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="datetime">
                                            <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                                            <br>
                                            <small><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-name">
                                            <?php echo htmlspecialchars($log['employee_name'] ?? 'Unknown'); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($log['username'] ?? ''); ?></small>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="module-badge"><?php echo htmlspecialchars($log['module']); ?></span>
                                    </td>
                                    <td>
                                        <span class="action-badge <?php echo getActionClass($log['action']); ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['new_value']): ?>
                                            <button class="btn-detail" onclick="showDetails(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars($log['new_value']); ?>')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ip-address"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="viewLog(<?php echo $log['id']; ?>)" class="btn-action btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($role === 'super_admin'): ?>
                                                <button onclick="deleteLog(<?php echo $log['id']; ?>)" class="btn-action btn-delete" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No activity logs found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Try adjusting your filters</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?>
                    </div>
                    <div class="pagination-links">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&module=<?php echo $module_filter; ?>&action=<?php echo $action_filter; ?>&employee=<?php echo $employee_filter; ?>&branch=<?php echo $selected_branch; ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&module=<?php echo $module_filter; ?>&action=<?php echo $action_filter; ?>&employee=<?php echo $employee_filter; ?>&branch=<?php echo $selected_branch; ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&module=<?php echo $module_filter; ?>&action=<?php echo $action_filter; ?>&employee=<?php echo $employee_filter; ?>&branch=<?php echo $selected_branch; ?>" class="page-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- View Log Modal -->
<div id="viewModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-info-circle" style="color:#bb0404;"></i> Log Details</h4>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="logDetails">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-code" style="color:#bb0404;"></i> Data Details</h4>
            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <pre id="detailsContent" style="background:var(--bg-input);padding:16px;border-radius:8px;overflow:auto;max-height:400px;font-size:12px;"></pre>
        </div>
    </div>
</div>

<style>
/* ============================================================
   BRANCH CARD - RED
   ============================================================ */
.branch-card {
    background: #bb0404;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.3);
    flex-wrap: wrap;
}

.branch-card i {
    font-size: 18px;
}

.branch-card .branch-label {
    font-weight: 500;
    font-size: 13px;
    opacity: 0.9;
}

.branch-card .branch-select {
    padding: 6px 14px;
    border-radius: 6px;
    border: none;
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    min-width: 150px;
}

.branch-card .branch-select:hover {
    background: rgba(255, 255, 255, 0.3);
}

.branch-card .branch-select:focus {
    background: rgba(255, 255, 255, 0.3);
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.5);
}

.branch-card .branch-select option {
    background: #1f2937;
    color: #ffffff;
}

body.dark-mode .branch-card .branch-select option {
    background: #1e293b;
    color: #f1f5f9;
}

.branch-card .branch-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

/* Dark mode support for branch card */
body.dark-mode .branch-card {
    background: #bb0404;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.5);
}

/* ============================================================
   SUMMARY CARDS
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.summary-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
}

.summary-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.summary-info {
    display: flex;
    flex-direction: column;
}

.summary-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.summary-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 8px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.btn-export { background: #10B981; color: white; }
.btn-export:hover { background: #059669; transform: translateY(-1px); }

.btn-danger { background: #DC2626; color: white; }
.btn-danger:hover { background: #991B1B; transform: translateY(-1px); }

.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; }

.btn-reset { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-reset:hover { background: var(--bg-table-hover); }

.btn-detail {
    background: #DBEAFE;
    color: #1D4ED8;
    border: none;
    padding: 4px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
}
.btn-detail:hover { background: #1D4ED8; color: white; }

/* ============================================================
   FILTERS BAR
   ============================================================ */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.filters-form {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    min-width: 150px;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

/* ============================================================
   TABLE
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.table-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.table-header h4 i { color: #bb0404; margin-right: 8px; }
.record-count { font-size: 12px; color: var(--text-muted); }

.table-responsive { overflow-x: auto; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #bb0404;
}
.data-table thead th {
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody td { padding: 10px 12px; color: var(--text-secondary); }

.datetime {
    font-size: 12px;
}
.datetime small {
    color: var(--text-muted);
    font-size: 11px;
}

.employee-name {
    font-weight: 500;
}
.employee-name small {
    font-weight: 400;
}

.module-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    background: #EDE9FE;
    color: #6D28D9;
}

.action-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.action-badge.action-add { background: #D1FAE5; color: #065F46; }
.action-badge.action-edit { background: #DBEAFE; color: #1D4ED8; }
.action-badge.action-delete { background: #FEE2E2; color: #991B1B; }
.action-badge.action-view { background: #FEF3C7; color: #92400E; }
.action-badge.action-login { background: #E0E7FF; color: #3730A3; }
.action-badge.action-logout { background: #F3F4F6; color: #6B7280; }
.action-badge.action-generate { background: #EDE9FE; color: #6D28D9; }

.ip-address {
    font-size: 12px;
    color: var(--text-muted);
    font-family: monospace;
}

/* ============================================================
   ACTION BUTTONS
   ============================================================ */
.action-buttons { display: flex; gap: 4px; }
.btn-action {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
}

.btn-view { background: #DBEAFE; color: #1D4ED8; }
.btn-view:hover { background: #1D4ED8; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

/* ============================================================
   PAGINATION
   ============================================================ */
.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
    margin-top: 16px;
    flex-wrap: wrap;
    gap: 12px;
}

.pagination-info {
    font-size: 13px;
    color: var(--text-muted);
}

.pagination-links {
    display: flex;
    gap: 4px;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s ease;
    min-width: 36px;
}

.page-link:hover {
    background: var(--bg-table-hover);
    border-color: #bb0404;
}

.page-link.active {
    background: #bb0404;
    color: white;
    border-color: #bb0404;
}

/* ============================================================
   MODAL
   ============================================================ */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h4 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0 8px;
    line-height: 1;
}

.modal-close:hover { color: var(--text-primary); }

.modal-body {
    padding: 20px;
}

/* ============================================================
   ALERT
   ============================================================ */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

.no-data { padding: 40px 20px; text-align: center; }
.text-muted { color: var(--text-muted); }

/* ============================================================
   DARK MODE
   ============================================================ */
:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
}

body.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

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
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .branch-card {
        padding: 10px 16px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    
    .branch-card .branch-select {
        min-width: 120px;
        width: 100%;
        flex: 1;
    }
    
    .summary-cards {
        grid-template-columns: 1fr;
    }
    .filters-form {
        flex-direction: column;
    }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; flex-wrap: wrap; }
    .header-right .btn { flex: 1; justify-content: center; }
    .pagination {
        flex-direction: column;
        align-items: center;
    }
}

@media (max-width: 480px) {
    .branch-card {
        flex-direction: column;
        text-align: center;
        gap: 6px;
    }
    
    .branch-card .branch-select {
        min-width: 100%;
        width: 100%;
    }
}
</style>

<script>
// ============================================================
// HELPER FUNCTIONS
// ============================================================

function getActionClass(action) {
    const classes = {
        'Add': 'action-add',
        'Edit': 'action-edit',
        'Update': 'action-edit',
        'Delete': 'action-delete',
        'Remove': 'action-delete',
        'View': 'action-view',
        'Login': 'action-login',
        'Logout': 'action-logout',
        'Generate': 'action-generate',
        'Create': 'action-add',
        'Save': 'action-add'
    };
    
    for (let [key, value] of Object.entries(classes)) {
        if (action.toLowerCase().includes(key.toLowerCase())) {
            return value;
        }
    }
    return '';
}

// ============================================================
// MODAL FUNCTIONS
// ============================================================

function viewLog(id) {
    // Fetch log details via AJAX
    fetch('get_log.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('logDetails').innerHTML = data.html;
                document.getElementById('viewModal').style.display = 'flex';
            }
        })
        .catch(error => {
            alert('Error loading log details');
        });
}

function showDetails(id, data) {
    try {
        const parsed = JSON.parse(data);
        document.getElementById('detailsContent').textContent = JSON.stringify(parsed, null, 2);
    } catch (e) {
        document.getElementById('detailsContent').textContent = data;
    }
    document.getElementById('detailsModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Close modals on outside click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

// ============================================================
// DELETE FUNCTIONS
// ============================================================

function deleteLog(id) {
    if (confirm('Are you sure you want to delete this log entry? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

function clearLogs() {
    if (confirm('Are you sure you want to clear ALL activity logs? This action cannot be undone.')) {
        if (confirm('This will permanently delete all activity logs. Are you absolutely sure?')) {
            window.location.href = 'clear.php';
        }
    }
}

// ============================================================
// DARK MODE TOGGLE
// ============================================================

function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const btn = document.getElementById('darkModeToggle');
    if (document.body.classList.contains('dark-mode')) {
        btn.querySelector('i').className = 'fas fa-sun';
        btn.querySelector('span').textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        btn.querySelector('i').className = 'fas fa-moon';
        btn.querySelector('span').textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
});
</script>

</body>
</html>