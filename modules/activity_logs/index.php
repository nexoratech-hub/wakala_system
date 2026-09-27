<?php
// ================================================================
// FILE: modules/activity_logs/index.php
// WAKALA FINANCIAL SYSTEM - ACTIVITY LOGS
// ✅ Modern design with soft background cards
// ✅ Dark mode support (html.dark-mode)
// ✅ Filters: Date, Branch, Employee, Action, Module
// ✅ Search, Pagination, Export (CSV/Excel/PDF/Print)
// ✅ View details modal
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Only admin/super_admin can access activity logs
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET FILTERS
// ============================================================
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$custom_from = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$custom_to = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$today = date('Y-m-d');

switch ($filter) {
    case 'all':
        $from_date = '2000-01-01';
        $to_date = date('Y-m-d');
        break;
    case 'today':
        $from_date = $today;
        $to_date = $today;
        break;
    case '1d':
        $from_date = date('Y-m-d', strtotime('-1 day'));
        $to_date = $today;
        break;
    case '1w':
        $from_date = date('Y-m-d', strtotime('-7 days'));
        $to_date = $today;
        break;
    case '1m':
        $from_date = date('Y-m-d', strtotime('-1 month'));
        $to_date = $today;
        break;
    case '3m':
        $from_date = date('Y-m-d', strtotime('-3 months'));
        $to_date = $today;
        break;
    case '6m':
        $from_date = date('Y-m-d', strtotime('-6 months'));
        $to_date = $today;
        break;
    case '1y':
        $from_date = date('Y-m-d', strtotime('-1 year'));
        $to_date = $today;
        break;
    case 'custom':
        $from_date = !empty($custom_from) ? $custom_from : date('Y-m-01');
        $to_date = !empty($custom_to) ? $custom_to : $today;
        break;
    default:
        $from_date = $today;
        $to_date = $today;
}

$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}

$selected_employee = 0;
if (isset($_GET['employee_id']) && $_GET['employee_id'] !== '' && $_GET['employee_id'] !== '0') {
    $selected_employee = intval($_GET['employee_id']);
}

$selected_module = isset($_GET['module']) ? trim($_GET['module']) : '';
$selected_action = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// ============================================================
// BUILD QUERY
// ============================================================
$where = ["DATE(al.created_at) BETWEEN ? AND ?"];
$params = [$from_date, $to_date];

if ($selected_branch > 0) {
    $where[] = "al.branch_id = ?";
    $params[] = $selected_branch;
}

if ($selected_employee > 0) {
    $where[] = "al.employee_id = ?";
    $params[] = $selected_employee;
}

if (!empty($selected_module)) {
    $where[] = "al.module = ?";
    $params[] = $selected_module;
}

if (!empty($selected_action)) {
    $where[] = "al.action = ?";
    $params[] = $selected_action;
}

if (!empty($search_term)) {
    $where[] = "(al.description LIKE ? OR al.action LIKE ? OR e.full_name LIKE ? OR al.ip_address LIKE ?)";
    $search_like = '%' . $search_term . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

$where_sql = implode(' AND ', $where);

try {
    // Get total count
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        WHERE $where_sql
    ");
    $stmt->execute($params);
    $total_records = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    $total_pages = ceil($total_records / $per_page);
    
    // Get records
    $sql = "
        SELECT 
            al.*,
            e.full_name as employee_name,
            e.profile_pic as employee_pic,
            e.employee_id as employee_code,
            b.branch_name,
            b.branch_code
        FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        LEFT JOIN branches b ON al.branch_id = b.id
        WHERE $where_sql
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get branches for filter
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employees for filter
    $stmt = $db->prepare("SELECT id, full_name, employee_id FROM employees WHERE status = 'active' ORDER BY full_name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique modules
    $stmt = $db->prepare("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module != '' ORDER BY module");
    $stmt->execute();
    $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique actions
    $stmt = $db->prepare("SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL AND action != '' ORDER BY action");
    $stmt->execute();
    $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // ============================================================
    // STATISTICS CARDS
    // ============================================================
    // Total logs in period
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        WHERE $where_sql
    ");
    $stmt->execute($params);
    $stat_total = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Today's logs
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM activity_logs 
        WHERE DATE(created_at) = ?
    ");
    $stmt->execute([$today]);
    $stat_today = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // This week
    $week_start = date('Y-m-d', strtotime('-7 days'));
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM activity_logs 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$week_start, $today]);
    $stat_week = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Unique active employees
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT employee_id) as total FROM activity_logs
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$from_date, $to_date]);
    $stat_active_users = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
} catch (PDOException $e) {
    error_log("Activity Logs Error: " . $e->getMessage());
    $logs = [];
    $branches = [];
    $employees = [];
    $modules = [];
    $actions = [];
    $total_records = 0;
    $total_pages = 0;
    $stat_total = 0;
    $stat_today = 0;
    $stat_week = 0;
    $stat_active_users = 0;
}

// ============================================================
// BUILD QUERY STRING FOR PAGINATION
// ============================================================
$query_params = $_GET;
unset($query_params['page']);
$query_string = http_build_query($query_params);

// ============================================================
// MODULE ICONS & COLORS
// ============================================================
$module_icons = [
    'Daily Report' => ['icon' => 'fa-file-alt', 'color' => '#2563EB'],
    'Transactions' => ['icon' => 'fa-exchange-alt', 'color' => '#059669'],
    'Commissions' => ['icon' => 'fa-hand-holding-usd', 'color' => '#7C3AED'],
    'Capital Management' => ['icon' => 'fa-vault', 'color' => '#F59E0B'],
    'Employees' => ['icon' => 'fa-users', 'color' => '#DC2626'],
    'Branches' => ['icon' => 'fa-store-alt', 'color' => '#0EA5E9'],
    'Providers' => ['icon' => 'fa-university', 'color' => '#8B5CF6'],
    'Authentication' => ['icon' => 'fa-shield-alt', 'color' => '#EF4444'],
    'Expenses' => ['icon' => 'fa-receipt', 'color' => '#F97316'],
    'Reports' => ['icon' => 'fa-chart-bar', 'color' => '#10B981'],
    'System' => ['icon' => 'fa-cog', 'color' => '#6B7280'],
];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-history"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Module</span>
                    <span class="branch-indicator-name">Activity Logs</span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y, H:i'); ?>
                </span>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-history" style="color:#7C3AED;"></i> Activity Logs</h2>
                <p class="text-muted">
                    <i class="fas fa-shield-alt"></i>
                    System audit trail • <?php echo number_format($total_records); ?> records in current filter
                </p>
            </div>
            <div class="header-right">
                <div class="dropdown export-dropdown">
                    <button type="button" class="btn-action-big btn-action-export dropdown-toggle" onclick="toggleExportDropdown(event)">
                        <i class="fas fa-file-export"></i>
                        <span>Export</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    <div class="dropdown-menu">
                        <a href="#" onclick="exportData('csv'); return false;">
                            <i class="fas fa-file-csv" style="color:#059669;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as CSV</span>
                                <span class="dropdown-item-desc">Excel compatible</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('excel'); return false;">
                            <i class="fas fa-file-excel" style="color:#10B981;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as Excel</span>
                                <span class="dropdown-item-desc">Spreadsheet format</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('pdf'); return false;">
                            <i class="fas fa-file-pdf" style="color:#DC2626;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as PDF</span>
                                <span class="dropdown-item-desc">Print-ready</span>
                            </div>
                        </a>
                        <a href="#" onclick="window.print(); return false;">
                            <i class="fas fa-print" style="color:#6B7280;"></i>
                            <div>
                                <span class="dropdown-item-title">Print</span>
                                <span class="dropdown-item-desc">Print current view</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- STATISTICS CARDS - SOFT BACKGROUND -->
        <div class="stats-grid-soft">
            <!-- Total Logs -->
            <div class="stat-card-soft stat-card-soft-purple">
                <div class="stat-icon-soft">
                    <i class="fas fa-database"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Logs</span>
                    <span class="stat-value-soft"><?php echo number_format($stat_total); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-filter"></i>
                        In current period
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Today -->
            <div class="stat-card-soft stat-card-soft-blue">
                <div class="stat-icon-soft">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Today</span>
                    <span class="stat-value-soft"><?php echo number_format($stat_today); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-clock"></i>
                        Actions today
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- This Week -->
            <div class="stat-card-soft stat-card-soft-green">
                <div class="stat-icon-soft">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">This Week</span>
                    <span class="stat-value-soft"><?php echo number_format($stat_week); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-chart-line"></i>
                        Last 7 days
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Active Users -->
            <div class="stat-card-soft stat-card-soft-orange">
                <div class="stat-icon-soft">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Active Users</span>
                    <span class="stat-value-soft"><?php echo number_format($stat_active_users); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-user-check"></i>
                        Unique employees
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
        </div>

        <!-- TIME FILTER BAR -->
        <div class="time-filter-bar">
            <div class="time-filter-left">
                <i class="fas fa-calendar-alt"></i>
                <span class="time-filter-label">Period:</span>
            </div>
            <div class="time-filter-buttons">
                <a href="?filter=all&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=today&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
                <a href="?filter=1d&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1d' ? 'active' : ''; ?>">1D</a>
                <a href="?filter=1w&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1w' ? 'active' : ''; ?>">1W</a>
                <a href="?filter=1m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1m' ? 'active' : ''; ?>">1M</a>
                <a href="?filter=3m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '3m' ? 'active' : ''; ?>">3M</a>
                <a href="?filter=6m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '6m' ? 'active' : ''; ?>">6M</a>
                <a href="?filter=1y&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1y' ? 'active' : ''; ?>">1Y</a>
                <a href="?filter=custom&branch_id=<?php echo $selected_branch; ?>&from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-d'); ?>" 
                   class="time-btn time-btn-custom <?php echo $filter === 'custom' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i> Custom
                </a>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar-main">
            <form method="GET" action="" class="filter-form-main">
                <input type="hidden" name="filter" value="custom">
                
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> From</label>
                    <input type="date" name="from_date" class="filter-input" 
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> To</label>
                    <input type="date" name="to_date" class="filter-input" 
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-store-alt"></i> Branch</label>
                    <select name="branch_id" class="filter-input filter-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-user"></i> Employee</label>
                    <select name="employee_id" class="filter-input filter-select">
                        <option value="0">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo $selected_employee == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-layer-group"></i> Module</label>
                    <select name="module" class="filter-input filter-select">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $selected_module === $m ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-bolt"></i> Action</label>
                    <select name="action_type" class="filter-input filter-select">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $selected_action === $a ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter-main">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn-reset-main">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- SEARCH BAR -->
        <div class="search-bar-wrapper">
            <div class="search-input-group">
                <i class="fas fa-search"></i>
                <input type="text" 
                       id="searchInput" 
                       placeholder="Search description, action, employee, IP..."
                       value="<?php echo htmlspecialchars($search_term); ?>"
                       oninput="onGlobalSearch(this)">
                <button type="button" id="searchClear" onclick="clearSearch()" style="display:none;">
                    <i class="fas fa-times"></i>
                </button>
                <span class="search-count" id="searchCount" style="display:none;">0</span>
            </div>
            <span class="record-count" id="recordCount"><?php echo count($logs); ?> logs shown</span>
        </div>

        <!-- ACTIVITY LOGS TABLE -->
        <div class="table-container">
            
            <!-- RED HEADER with Scroll Controls -->
            <div class="table-header-red-with-controls">
                <div class="thrc-left">
                    <span class="table-header-title">
                        <i class="fas fa-list-ul"></i>
                        Audit Trail
                    </span>
                </div>
                
                <div class="thrc-center">
                    <button type="button" class="scroll-btn" onclick="scrollActivityTable('left')" title="Scroll Left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="scroll-label">
                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                    </span>
                    <button type="button" class="scroll-btn" onclick="scrollActivityTable('right')" title="Scroll Right">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div class="thrc-right">
                    <span class="record-count-red">
                        <i class="fas fa-history"></i>
                        <?php echo number_format($total_records); ?> records
                    </span>
                </div>
            </div>

            <?php if (count($logs) > 0): ?>
                
                <div class="table-responsive" id="activityTableWrapper">
                    <table class="data-table activity-table" id="activityTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date & Time</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                                <th style="width: 80px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_num = $offset + 1;
                            foreach ($logs as $log): 
                                $emp_name = $log['employee_name'] ?? 'System';
                                $emp_initial = strtoupper(substr($emp_name, 0, 1));
                                $module = $log['module'] ?? 'System';
                                $m_info = $module_icons[$module] ?? ['icon' => 'fa-cog', 'color' => '#6B7280'];
                                
                                $search_text = strtolower(
                                    ($log['description'] ?? '') . ' ' .
                                    ($log['action'] ?? '') . ' ' .
                                    ($log['module'] ?? '') . ' ' .
                                    $emp_name . ' ' .
                                    ($log['ip_address'] ?? '')
                                );
                            ?>
                                <tr class="activity-row" 
                                    data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td class="row-number"><?php echo $row_num++; ?></td>
                                    
                                    <td>
                                        <div class="date-time-cell">
                                            <span class="dt-date">
                                                <i class="far fa-calendar"></i>
                                                <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                                            </span>
                                            <span class="dt-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('h:i:s A', strtotime($log['created_at'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="employee-cell">
                                            <?php if (!empty($log['employee_pic']) && file_exists('../../' . $log['employee_pic'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($log['employee_pic']); ?>" 
                                                     alt="" class="emp-avatar-img">
                                            <?php else: ?>
                                                <div class="emp-avatar-initial"><?php echo $emp_initial; ?></div>
                                            <?php endif; ?>
                                            <div class="emp-info">
                                                <span class="emp-name"><?php echo htmlspecialchars($emp_name); ?></span>
                                                <?php if (!empty($log['employee_code'])): ?>
                                                    <span class="emp-code"><?php echo htmlspecialchars($log['employee_code']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <?php if (!empty($log['branch_name'])): ?>
                                            <span class="branch-badge">
                                                <i class="fas fa-store-alt"></i>
                                                <?php echo htmlspecialchars($log['branch_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span class="module-badge" style="--module-color: <?php echo $m_info['color']; ?>;">
                                            <i class="fas <?php echo $m_info['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($module); ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <span class="action-badge">
                                            <i class="fas fa-bolt"></i>
                                            <?php echo htmlspecialchars($log['action'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <div class="description-cell">
                                            <?php 
                                            $desc = $log['description'] ?? '—';
                                            if (strlen($desc) > 60) {
                                                echo htmlspecialchars(substr($desc, 0, 60)) . '...';
                                            } else {
                                                echo htmlspecialchars($desc);
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <span class="ip-badge">
                                            <i class="fas fa-network-wired"></i>
                                            <?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <div class="actions-cell">
                                            <button type="button" 
                                                    class="btn-view-details" 
                                                    onclick='showDetails(<?php echo json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing <strong><?php echo $offset + 1; ?></strong> - 
                        <strong><?php echo min($offset + $per_page, $total_records); ?></strong> 
                        of <strong><?php echo number_format($total_records); ?></strong> records
                    </div>
                    
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo $query_string; ?>&page=1" class="page-btn" title="First Page">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?<?php echo $query_string; ?>&page=<?php echo $page - 1; ?>" class="page-btn" title="Previous">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php else: ?>
                            <button class="page-btn" disabled><i class="fas fa-angle-double-left"></i></button>
                            <button class="page-btn" disabled><i class="fas fa-angle-left"></i></button>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<a href="?' . $query_string . '&page=1" class="page-btn">1</a>';
                            if ($start_page > 2) echo '<span class="page-ellipsis">...</span>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?<?php echo $query_string; ?>&page=<?php echo $i; ?>" 
                               class="page-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1) echo '<span class="page-ellipsis">...</span>'; ?>
                            <a href="?<?php echo $query_string; ?>&page=<?php echo $total_pages; ?>" class="page-btn">
                                <?php echo $total_pages; ?>
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo $query_string; ?>&page=<?php echo $page + 1; ?>" class="page-btn" title="Next">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?<?php echo $query_string; ?>&page=<?php echo $total_pages; ?>" class="page-btn" title="Last Page">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php else: ?>
                            <button class="page-btn" disabled><i class="fas fa-angle-right"></i></button>
                            <button class="page-btn" disabled><i class="fas fa-angle-double-right"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No Activity Logs Found</h3>
                    <p>No activity logs match your current filters.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-undo"></i> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- DETAILS MODAL -->
<div class="details-modal-overlay" id="detailsModal" onclick="closeDetailsModal(event)">
    <div class="details-modal" onclick="event.stopPropagation()">
        <div class="details-modal-header">
            <div class="dmh-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="dmh-content">
                <h3>Activity Log Details</h3>
                <p>Full information about this activity</p>
            </div>
            <button type="button" class="dmh-close" onclick="closeDetailsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="details-modal-body" id="detailsModalBody">
            <!-- Filled by JS -->
        </div>
        
        <div class="details-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">
                <i class="fas fa-times"></i> Close
            </button>
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
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    border-radius: 12px;
    padding: 14px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.3);
    flex-wrap: wrap;
    gap: 10px;
    width: 100%;
    color: #FFFFFF;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FCD34D; flex-shrink: 0;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600;
    opacity: 0.8; text-transform: uppercase;
    letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name { font-weight: 800; font-size: 16px; color: #FFFFFF; }
.date-display {
    font-size: 12px; color: rgba(255,255,255,0.9);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.12);
    border-radius: 16px; display: flex; align-items: center; gap: 6px;
    font-weight: 500;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
}
.page-header .header-left h2 {
    font-size: 20px; font-weight: 700; margin: 0;
    display: flex; align-items: center; gap: 8px;
    color: var(--text-primary);
}
.page-header .header-left h2 i { margin-right: 4px; }
.page-header .header-left .text-muted {
    font-size: 12px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.header-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

/* ============================================================
   STATS GRID - SOFT BACKGROUND
   ============================================================ */
.stats-grid-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.stat-card-soft {
    position: relative;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.stat-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

.stat-card-soft-purple {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.stat-card-soft-purple .stat-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.stat-card-soft-purple .stat-value-soft { color: #6D28D9; }

.stat-card-soft-blue {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.stat-card-soft-blue .stat-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.stat-card-soft-blue .stat-value-soft { color: #1D4ED8; }

.stat-card-soft-green {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.stat-card-soft-green .stat-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.stat-card-soft-green .stat-value-soft { color: #047857; }

.stat-card-soft-orange {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.stat-card-soft-orange .stat-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.stat-card-soft-orange .stat-value-soft { color: #B45309; }

html.dark-mode .stat-card-soft-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .stat-card-soft-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-orange { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }

.stat-icon-soft {
    width: 50px; height: 50px;
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    transition: all 0.3s ease;
}
.stat-card-soft:hover .stat-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}
.stat-info-soft {
    display: flex; flex-direction: column;
    min-width: 0; flex: 1; gap: 2px;
}
.stat-label-soft {
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.stat-value-soft {
    font-size: 22px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    line-height: 1.2;
}
.stat-sub-soft {
    font-size: 10px; font-weight: 600;
    color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px;
    margin-top: 2px;
}
.stat-sub-soft i { font-size: 9px; color: var(--text-light); }
.stat-decoration-soft {
    position: absolute; top: -30px; right: -30px;
    width: 100px; height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
}

/* ============================================================
   TIME FILTER BAR
   ============================================================ */
.time-filter-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex; align-items: center;
    gap: 16px; flex-wrap: wrap;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.time-filter-left {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 1px;
    flex-shrink: 0;
}
.time-filter-left i { color: #7C3AED; font-size: 14px; }
.time-filter-buttons {
    display: flex; align-items: center;
    gap: 6px; flex-wrap: wrap; flex: 1;
}
.time-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 6px; padding: 8px 16px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    text-decoration: none; cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.time-btn:hover {
    background: var(--bg-table-hover);
    border-color: #7C3AED;
    color: #7C3AED;
    transform: translateY(-1px);
}
.time-btn.active {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    border-color: #6D28D9;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}
.time-btn-custom {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    border-color: #D97706;
}
.time-btn-custom:hover {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
    border-color: #B45309;
}

/* ============================================================
   MAIN FILTER BAR
   ============================================================ */
.filter-bar-main {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.filter-form-main {
    display: flex; align-items: flex-end;
    gap: 12px; flex-wrap: wrap;
}
.filter-item {
    display: flex; flex-direction: column;
    gap: 6px; flex: 1; min-width: 140px;
}
.filter-item label {
    font-size: 11px; font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 6px;
}
.filter-item label i { color: #7C3AED; font-size: 11px; }
.filter-input {
    padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px; font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.25s ease;
    width: 100%;
}
.filter-input:focus {
    outline: none;
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
    background: var(--bg-card);
}
.filter-select { cursor: pointer; }
.filter-actions {
    display: flex; gap: 8px;
    align-items: flex-end; flex-shrink: 0;
}
.btn-filter-main {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 22px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF; border: none; border-radius: 10px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase; letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
    white-space: nowrap;
}
.btn-filter-main:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
    color: #FFFFFF;
}
.btn-reset-main {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 22px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px; font-weight: 800;
    text-decoration: none; cursor: pointer;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.btn-reset-main:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
    border-color: #94A3B8;
    transform: translateY(-2px);
}

/* ============================================================
   SEARCH BAR
   ============================================================ */
.search-bar-wrapper {
    display: flex; align-items: center;
    justify-content: space-between; gap: 12px;
    margin-bottom: 16px; flex-wrap: wrap;
    max-width: 100%;
}
.search-input-group {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 8px 14px;
    width: 400px; max-width: 100%;
    transition: all 0.3s ease;
}
.search-input-group:focus-within {
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
}
.search-input-group > i { color: #7C3AED; font-size: 13px; }
.search-input-group input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 13px;
    color: var(--text-primary); outline: none;
    font-family: 'Inter', sans-serif; min-width: 0;
}
.search-input-group input::placeholder {
    color: var(--text-light); font-size: 12px;
}
.search-input-group button {
    background: #FEE2E2; color: #DC2626;
    border: none; width: 22px; height: 22px;
    border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; transition: all 0.2s ease;
    flex-shrink: 0;
}
.search-input-group button:hover {
    background: #DC2626; color: white;
}
.search-count {
    font-size: 10px; font-weight: 800;
    padding: 3px 9px;
    background: #F59E0B; color: #FFFFFF;
    border-radius: 8px; flex-shrink: 0;
}
.record-count {
    font-size: 12px; font-weight: 700;
    color: var(--text-muted);
    padding: 8px 16px;
    background: var(--bg-card);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    white-space: nowrap;
}

/* ============================================================
   TABLE
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}
.table-header-red-with-controls {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.table-header-red-with-controls::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.thrc-left, .thrc-center, .thrc-right {
    position: relative; z-index: 1;
    display: flex; align-items: center;
}
.thrc-left { justify-content: flex-start; }
.thrc-center { justify-content: center; gap: 12px; }
.thrc-right { justify-content: flex-end; }
.table-header-title {
    font-size: 14px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1px;
    display: inline-flex; align-items: center; gap: 8px;
}
.table-header-title i { color: #FCD34D; }

.scroll-btn {
    width: 40px; height: 40px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #DC2626; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
}
.scroll-label {
    font-size: 11px; font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.record-count-red {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.record-count-red i { font-size: 11px; color: #FCD34D; }

.table-responsive {
    overflow-x: auto;
    width: 100%; max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive::-webkit-scrollbar { height: 10px; }
.table-responsive::-webkit-scrollbar-track {
    background: var(--bg-input);
    border-radius: 5px;
}
.table-responsive::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    border-radius: 5px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1200px;
}
.data-table thead { background: #DC2626; }
.data-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
    position: sticky; top: 0; z-index: 5;
}
.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.04), rgba(124, 58, 237, 0.02));
}
.data-table tbody tr:nth-child(even) { background: var(--bg-input); }
.data-table tbody td {
    padding: 12px 14px;
    color: var(--text-primary);
    vertical-align: middle;
}
.activity-row.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg-input);
    font-size: 11px; font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.date-time-cell {
    display: flex; flex-direction: column;
    gap: 3px; font-size: 11px;
}
.dt-date {
    font-weight: 700;
    color: var(--text-secondary);
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.dt-date i { color: #7C3AED; font-size: 10px; }
.dt-time {
    font-size: 10px;
    color: var(--text-muted);
    font-family: 'Courier New', monospace;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.dt-time i { color: #059669; font-size: 9px; }

.employee-cell {
    display: flex; align-items: center;
    gap: 10px; min-width: 0;
}
.emp-avatar-img, .emp-avatar-initial {
    width: 36px; height: 36px;
    border-radius: 50%; flex-shrink: 0;
    border: 2px solid #7C3AED;
}
.emp-avatar-initial {
    background: linear-gradient(135deg, #7C3AED, #6D28D9);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 14px;
    font-family: 'Courier New', monospace;
}
.emp-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.emp-name {
    font-weight: 800; font-size: 12px;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.emp-code {
    font-size: 9px; font-weight: 700;
    color: #7C3AED;
    background: #EDE9FE;
    padding: 1px 6px; border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .emp-code { background: #2D1B5F; color: #C4B5FD; }

.branch-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border-radius: 8px;
    font-size: 10px; font-weight: 700;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
.branch-badge i { font-size: 9px; }
html.dark-mode .branch-badge {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD; border-color: #3B82F6;
}

.module-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 10px; font-weight: 800;
    color: var(--module-color, #6B7280);
    background: color-mix(in srgb, var(--module-color, #6B7280) 12%, transparent);
    border: 1.5px solid color-mix(in srgb, var(--module-color, #6B7280) 30%, transparent);
    white-space: nowrap;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.module-badge i { font-size: 9px; }

.action-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 10px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #92400E;
    border-radius: 8px;
    font-size: 10px; font-weight: 800;
    border: 1.5px solid #FCD34D;
    white-space: nowrap;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.action-badge i { color: #D97706; font-size: 9px; }
html.dark-mode .action-badge {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FCD34D; border-color: #F59E0B;
}

.description-cell {
    font-size: 11px; font-weight: 500;
    color: var(--text-secondary);
    max-width: 300px;
    line-height: 1.4;
}

.ip-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px;
    background: var(--bg-input);
    color: var(--text-muted);
    border-radius: 6px;
    font-size: 10px; font-weight: 700;
    font-family: 'Courier New', monospace;
    border: 1px solid var(--border-color);
    white-space: nowrap;
}
.ip-badge i { color: #6B7280; font-size: 9px; }

.actions-cell { display: flex; justify-content: center; }
.btn-view-details {
    width: 36px; height: 36px;
    border-radius: 10px;
    border: 1.5px solid #C4B5FD;
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.25s ease;
}
.btn-view-details:hover {
    background: linear-gradient(135deg, #7C3AED, #6D28D9);
    color: #FFFFFF;
    border-color: #7C3AED;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
}
html.dark-mode .btn-view-details {
    background: linear-gradient(135deg, #2D1B5F, #4C1D95);
    color: #C4B5FD;
    border-color: #A78BFA;
}

/* ============================================================
   PAGINATION
   ============================================================ */
.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: var(--bg-input);
    border-top: 1.5px solid var(--border-color);
    flex-wrap: wrap;
    gap: 12px;
}
.pagination-info {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
}
.pagination-info strong {
    color: var(--text-primary);
    font-weight: 800;
}
.pagination-controls {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 36px; height: 36px;
    padding: 0 10px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 12px; font-weight: 700;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
}
.page-btn:hover:not(:disabled) {
    background: #EDE9FE;
    color: #7C3AED;
    border-color: #7C3AED;
    transform: translateY(-1px);
}
.page-btn.active {
    background: linear-gradient(135deg, #7C3AED, #6D28D9);
    color: #FFFFFF;
    border-color: #6D28D9;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}
.page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.page-ellipsis {
    padding: 0 6px;
    color: var(--text-light);
    font-weight: 700;
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 70px 20px;
}
.empty-state i {
    font-size: 64px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 20px 0;
}

.btn {
    padding: 10px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-primary {
    background: linear-gradient(135deg, #7C3AED, #6D28D9);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
    color: #FFFFFF;
}
.btn-secondary {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
}

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.dropdown { position: relative; display: inline-block; }
.btn-action-big {
    display: inline-flex;
    align-items: center; justify-content: center;
    gap: 10px;
    padding: 12px 22px;
    border: none; border-radius: 12px;
    font-size: 13px; font-weight: 800;
    cursor: pointer; text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Inter', sans-serif;
    white-space: nowrap; letter-spacing: 0.5px;
    text-transform: uppercase;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
    position: relative; overflow: hidden;
}
.btn-action-export {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
}
.btn-action-export:hover {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
}
.dropdown-arrow {
    font-size: 11px;
    transition: transform 0.3s ease;
    margin-left: 2px;
}
.dropdown.open .dropdown-arrow { transform: rotate(180deg); }
.dropdown-menu {
    display: none;
    position: absolute; right: 0;
    top: calc(100% + 8px);
    min-width: 260px;
    background: var(--bg-card);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    border: 1.5px solid var(--border-color);
    overflow: hidden; z-index: 1000;
    animation: dropdownFadeIn 0.2s ease forwards;
}
.dropdown.open .dropdown-menu { display: block; }
@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.dropdown-menu a {
    display: flex; align-items: center;
    gap: 14px;
    padding: 14px 18px;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 13px; font-weight: 600;
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}
.dropdown-menu a:last-child { border-bottom: none; }
.dropdown-menu a:hover {
    background: var(--bg-input);
    padding-left: 22px;
}
.dropdown-menu a > i {
    font-size: 22px; width: 28px;
    text-align: center; flex-shrink: 0;
}
.dropdown-menu a > div {
    display: flex; flex-direction: column;
    gap: 2px; flex: 1;
}
.dropdown-item-title {
    font-size: 13px; font-weight: 800;
    color: var(--text-primary);
}
.dropdown-item-desc {
    font-size: 11px; font-weight: 500;
    color: var(--text-muted);
}

/* ============================================================
   DETAILS MODAL
   ============================================================ */
.details-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    justify-content: center; align-items: center;
    padding: 20px; overflow-y: auto;
}
.details-modal-overlay.show {
    display: flex;
    animation: fadeIn 0.2s ease forwards;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUpModal {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.details-modal {
    background: var(--bg-card);
    border-radius: 16px;
    width: 100%; max-width: 640px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: slideUpModal 0.3s ease forwards;
    border: 1px solid var(--border-color);
}
.details-modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    border-radius: 16px 16px 0 0;
    display: flex; align-items: center;
    gap: 16px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.details-modal-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.dmh-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative; z-index: 1;
}
.dmh-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.dmh-content h3 {
    font-size: 18px; font-weight: 800;
    margin: 0 0 2px 0; color: #FFFFFF;
}
.dmh-content p {
    font-size: 12px; margin: 0;
    color: rgba(255, 255, 255, 0.85);
}
.dmh-close {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    position: relative; z-index: 1;
}
.dmh-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.details-modal-body {
    padding: 24px;
}
.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 16px;
    flex-wrap: wrap;
}
.detail-row:last-child { border-bottom: none; }
.detail-label {
    font-size: 12px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-flex; align-items: center; gap: 6px;
    flex-shrink: 0;
}
.detail-label i { color: #7C3AED; font-size: 11px; }
.detail-value {
    font-size: 13px; font-weight: 600;
    color: var(--text-primary);
    text-align: right;
    max-width: 65%;
    word-break: break-word;
}
.detail-value.mono {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #7C3AED;
    background: #EDE9FE;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 12px;
}
html.dark-mode .detail-value.mono {
    background: #2D1B5F;
    color: #C4B5FD;
}

.details-modal-footer {
    padding: 16px 24px;
    border-top: 1.5px solid var(--border-color);
    background: var(--bg-input);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    border-radius: 0 0 16px 16px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid-soft { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .filter-form-main { flex-wrap: wrap; }
    .filter-item { min-width: 140px; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .stats-grid-soft { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .dropdown { width: 100%; }
    .btn-action-big { width: 100%; justify-content: center; }
    .time-filter-bar { flex-direction: column; align-items: stretch; }
    .time-filter-left { justify-content: center; }
    .time-filter-buttons { justify-content: center; }
    .time-btn { flex: 1; min-width: 60px; }
    .filter-form-main { flex-direction: column; align-items: stretch; }
    .filter-item { min-width: 100%; }
    .filter-actions { width: 100%; flex-direction: column; }
    .btn-filter-main, .btn-reset-main {
        width: 100%; justify-content: center;
    }
    .search-input-group { width: 100%; }
    .table-header-red-with-controls {
        grid-template-columns: 1fr;
        gap: 12px; text-align: center;
    }
    .thrc-left, .thrc-center, .thrc-right { justify-content: center; width: 100%; }
    .pagination-wrapper { flex-direction: column; }
    .pagination-controls { justify-content: center; }
    .details-modal { max-width: 95vw; max-height: 95vh; }
    .details-modal-body { padding: 18px; }
    .detail-row { flex-direction: column; gap: 6px; }
    .detail-value { text-align: left; max-width: 100%; }
    .dropdown-menu { width: 100%; right: auto; left: 0; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .stat-value-soft { font-size: 18px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .scroll-btn { width: 34px; height: 34px; font-size: 13px; }
    .scroll-label { font-size: 9px; }
}

/* ============================================================
   PRINT
   ============================================================ */
@media print {
    .branch-indicator,
    .page-header,
    .time-filter-bar,
    .filter-bar-main,
    .search-bar-wrapper,
    .pagination-wrapper,
    .dropdown,
    .actions-cell,
    .table-header-red-with-controls {
        display: none !important;
    }
    .main-wrapper, .main-content {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        background: #FFFFFF !important;
    }
    .table-container {
        box-shadow: none;
        border: 1px solid #ccc;
    }
    .data-table { font-size: 10px; min-width: 100% !important; }
    .data-table thead th,
    .data-table tbody td { padding: 6px 8px; }
    .stats-grid-soft {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }
    .stat-card-soft {
        box-shadow: none;
        border: 1px solid #ccc;
        padding: 8px 10px;
        break-inside: avoid;
    }
}
</style>

<script>
// ============================================================
// EXPORT DROPDOWN
// ============================================================
function toggleExportDropdown(event) {
    event.stopPropagation();
    var dropdown = event.currentTarget.closest('.dropdown');
    dropdown.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    document.querySelectorAll('.dropdown.open').forEach(function(d) {
        if (!d.contains(e.target)) d.classList.remove('open');
    });
});

// ============================================================
// EXPORT DATA
// ============================================================
function exportData(format) {
    document.querySelectorAll('.dropdown.open').forEach(function(d) {
        d.classList.remove('open');
    });
    
    var params = new URLSearchParams(window.location.search);
    params.set('format', format);
    
    var formatLabels = { 'csv': 'CSV', 'excel': 'Excel', 'pdf': 'PDF' };
    var label = formatLabels[format] || format.toUpperCase();
    
    var msg = 'Export Activity Logs as ' + label + '?\n\n' +
              'Total Records: <?php echo number_format($total_records); ?>\n' +
              'Period: <?php echo $from_date; ?> to <?php echo $to_date; ?>\n\n' +
              'Continue?';
    
    if (confirm(msg)) {
        window.location.href = 'export.php?' + params.toString();
    }
}

// ============================================================
// SEARCH FUNCTION
// ============================================================
function onGlobalSearch(input) {
    var searchTerm = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('.activity-row');
    var clearBtn = document.getElementById('searchClear');
    var countBadge = document.getElementById('searchCount');
    var recordCount = document.getElementById('recordCount');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(function(row) {
            row.classList.remove('hidden-by-search');
        });
        if (countBadge) countBadge.style.display = 'none';
        if (recordCount) recordCount.textContent = rows.length + ' logs shown';
        return;
    }
    
    var matchCount = 0;
    rows.forEach(function(row) {
        var searchData = row.getAttribute('data-search') || '';
        if (searchData.indexOf(searchTerm) !== -1) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    if (recordCount) {
        recordCount.textContent = matchCount + ' of ' + rows.length + ' logs';
    }
}

function clearSearch() {
    var input = document.getElementById('searchInput');
    if (input) {
        input.value = '';
        onGlobalSearch(input);
        input.focus();
    }
}

// ============================================================
// SCROLL TABLE
// ============================================================
function scrollActivityTable(direction) {
    var wrapper = document.getElementById('activityTableWrapper');
    if (!wrapper) return;
    var scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// DETAILS MODAL
// ============================================================
function showDetails(log) {
    var body = document.getElementById('detailsModalBody');
    
    var html = '';
    
    html += buildDetailRow('fa-calendar', 'Date & Time', 
                           formatDateTime(log.created_at));
    html += buildDetailRow('fa-user', 'Employee', 
                           escapeHtml(log.employee_name || 'System'));
    html += buildDetailRow('fa-id-badge', 'Employee ID', 
                           escapeHtml(log.employee_code || '—'), true);
    html += buildDetailRow('fa-store-alt', 'Branch', 
                           escapeHtml(log.branch_name || '—'));
    html += buildDetailRow('fa-layer-group', 'Module', 
                           escapeHtml(log.module || 'System'));
    html += buildDetailRow('fa-bolt', 'Action', 
                           escapeHtml(log.action || 'Unknown'));
    html += buildDetailRow('fa-align-left', 'Description', 
                           escapeHtml(log.description || '—'));
    html += buildDetailRow('fa-network-wired', 'IP Address', 
                           escapeHtml(log.ip_address || '—'), true);
    html += buildDetailRow('fa-desktop', 'User Agent', 
                           escapeHtml(log.user_agent || '—'));
    html += buildDetailRow('fa-hashtag', 'Log ID', 
                           '#' + (log.id || '—'), true);
    
    body.innerHTML = html;
    
    document.getElementById('detailsModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function buildDetailRow(icon, label, value, isMono) {
    var valueClass = isMono ? 'detail-value mono' : 'detail-value';
    return '<div class="detail-row">' +
           '<span class="detail-label"><i class="fas ' + icon + '"></i> ' + label + '</span>' +
           '<span class="' + valueClass + '">' + value + '</span>' +
           '</div>';
}

function closeDetailsModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('detailsModal').classList.remove('show');
    document.body.style.overflow = '';
}

function formatDateTime(str) {
    if (!str) return '—';
    var d = new Date(str);
    if (isNaN(d.getTime())) return str;
    var options = { 
        year: 'numeric', month: 'short', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: true
    };
    return d.toLocaleString('en-US', options);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '—';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('detailsModal');
        if (modal && modal.classList.contains('show')) {
            closeDetailsModal();
            return;
        }
        var input = document.getElementById('searchInput');
        if (input && input.value.length > 0) {
            clearSearch();
        }
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        var input = document.getElementById('searchInput');
        if (input) {
            input.focus();
            input.select();
        }
    }
});

// ============================================================
// DARK MODE SYNC
// ============================================================
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