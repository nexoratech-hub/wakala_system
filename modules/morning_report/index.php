<?php
// ================================================================
// FILE: modules/morning_report/index.php
// WAKALA SYSTEM - MORNING REPORT LIST
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
// BRANCH FILTER
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

// Get selected branch name
$selected_branch_name = 'All Branches';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $selected_branch_name = $b['branch_name'];
            break;
        }
    }
}

// ============================================================
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// ============================================================
// GET MORNING REPORTS WITH FILTERS
// ============================================================
$sql = "SELECT mr.*, 
        e.full_name as employee_name, 
        b.branch_name as branch_name
        FROM morning_reports mr
        LEFT JOIN employees e ON mr.employee_id = e.id
        LEFT JOIN branches b ON mr.branch_id = b.id
        WHERE mr.report_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql .= " AND mr.branch_id = ?";
    $params[] = $selected_branch;
}

// If employee, show only their reports
if ($role == 'employee') {
    $sql .= " AND mr.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY mr.report_date DESC, mr.submitted_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// ============================================================
// GET SUMMARY TOTALS
// ============================================================
$sql_summary = "SELECT 
        COUNT(*) as total_reports,
        SUM(cumm_total) as total_float,
        SUM(cash_balance) as total_cash,
        SUM(cumm_total + cash_balance) as total_stock
        FROM morning_reports
        WHERE report_date BETWEEN ? AND ?";

$params_summary = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql_summary .= " AND branch_id = ?";
    $params_summary[] = $selected_branch;
}

if ($role == 'employee') {
    $sql_summary .= " AND employee_id = ?";
    $params_summary[] = $user_id;
}

$stmt = $db->prepare($sql_summary);
$stmt->execute($params_summary);
$summary = $stmt->fetch();

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
PAGE CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH CARD ===== -->
        <div class="branch-card">
            <i class="fas fa-building"></i>
            <span class="branch-label">Current Branch:</span>
            <span class="branch-name"><?php echo htmlspecialchars($selected_branch_name); ?></span>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-sun" style="color:#bb0404;"></i> Morning Reports</h2>
                <p class="text-muted">Manage and view all morning reports</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Report
                </a>
                <!-- ===== EXPORT DROPDOWN ===== -->
                <div class="dropdown export-dropdown">
                    <button class="btn btn-success dropdown-toggle" type="button" id="exportDropdown" onclick="toggleExportDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down" style="margin-left: 6px; font-size: 11px;"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a class="dropdown-item" href="#" onclick="exportData('csv')">
                            <i class="fas fa-file-csv" style="color: #2D9CDB;"></i> Export as CSV
                        </a>
                        <a class="dropdown-item" href="#" onclick="exportData('excel')">
                            <i class="fas fa-file-excel" style="color: #27AE60;"></i> Export as Excel
                        </a>
                        <a class="dropdown-item" href="#" onclick="exportData('pdf')">
                            <i class="fas fa-file-pdf" style="color: #E74C3C;"></i> Export as PDF
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" onclick="printData()">
                            <i class="fas fa-print" style="color: #6B7280;"></i> Print
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== FILTERS ===== -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form" id="filterForm">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="form-control">
                </div>
                <?php if ($role == 'admin' || $role == 'super_admin'): ?>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ===== SUMMARY CARDS ===== -->
        <div class="summary-cards">
            <div class="summary-card total-reports">
                <div class="summary-icon"><i class="fas fa-file-alt"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Reports</span>
                    <span class="summary-value"><?php echo number_format($summary['total_reports'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card total-float">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Float</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_float'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card total-cash">
                <div class="summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Cash</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_cash'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card total-stock">
                <div class="summary-icon"><i class="fas fa-boxes"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Stock</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_stock'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <!-- ===== REPORTS TABLE ===== -->
        <div class="table-container" id="printableArea">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Morning Reports List</h4>
                <span class="record-count"><?php echo count($reports); ?> records found</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="dataTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Report Number</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Employee</th>
                            <th>Float</th>
                            <th>Cash</th>
                            <th>Total Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reports) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="report-number"><?php echo htmlspecialchars($report['report_number']); ?></span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($report['report_date'])); ?></td>
                                    <td>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($report['branch_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></td>
                                    <td class="text-primary"><?php echo formatCurrency($report['cumm_total'] ?? 0); ?></td>
                                    <td class="text-success"><?php echo formatCurrency($report['cash_balance'] ?? 0); ?></td>
                                    <td class="text-info font-bold"><?php echo formatCurrency(($report['cumm_total'] ?? 0) + ($report['cash_balance'] ?? 0)); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $report['id']; ?>" class="btn-action view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $report['id']; ?>" class="btn-action edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="print.php?id=<?php echo $report['id']; ?>" class="btn-action print" title="Print" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="btn-action delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No morning reports found for the selected filters</p>
                                    <a href="add.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus"></i> Add First Report
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES WITH DARK MODE SUPPORT
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
    --bg-header: #ffffff;
    --bg-input: #f9fafb;
    --bg-empty: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
}

/* Dark Mode - Full Page */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-card-hover: #334155;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-header: #1e293b;
    --bg-input: #334155;
    --bg-empty: #1a2332;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
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
   BRANCH CARD - RED CARD
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
}

.branch-card i {
    font-size: 18px;
}

.branch-card .branch-label {
    font-weight: 500;
    font-size: 13px;
    opacity: 0.9;
}

.branch-card .branch-name {
    font-weight: 700;
    font-size: 15px;
}

/* ============================================================
   DARK MODE TOGGLE - IN HEADER ONLY
   ============================================================ */
/* Dark mode toggle is now in the header (admin_topbar.php) */

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

.page-header .header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.btn-primary {
    background: #bb0404;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
    color: #ffffff;
}

.btn-success {
    background: #10B981;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    color: #ffffff;
}

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.export-dropdown {
    position: relative;
    display: inline-block;
}

.export-dropdown .dropdown-toggle {
    background: #10B981;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.export-dropdown .dropdown-toggle:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}

.export-dropdown .dropdown-toggle i {
    font-size: 14px;
}

.export-dropdown .dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 10px 30px var(--shadow-color);
    min-width: 200px;
    z-index: 1000;
    padding: 6px 0;
    overflow: hidden;
}

.export-dropdown .dropdown-menu.show {
    display: block;
    animation: slideDown 0.2s ease forwards;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.export-dropdown .dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    color: var(--text-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}

.export-dropdown .dropdown-item:hover {
    background: var(--bg-card-hover);
    color: #bb0404;
}

.export-dropdown .dropdown-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.export-dropdown .dropdown-divider {
    height: 1px;
    background: var(--border-color);
    margin: 4px 0;
}

/* ============================================================
   FILTERS BAR
   ============================================================ */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--shadow-color);
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

.btn-filter {
    background: #bb0404;
    color: #ffffff;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #8a0303;
}

.btn-reset {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--bg-table-hover);
}

/* ============================================================
   SUMMARY CARDS
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.summary-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
}

.summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.summary-info {
    flex: 1;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--text-muted);
    display: block;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
}

.summary-card.total-reports .summary-icon {
    background: #DBEAFE;
    color: #1D4ED8;
}
.summary-card.total-reports { border-left: 4px solid #3B82F6; }

.summary-card.total-float .summary-icon {
    background: #DBEAFE;
    color: #1E40AF;
}
.summary-card.total-float { border-left: 4px solid #1E40AF; }

.summary-card.total-cash .summary-icon {
    background: #D1FAE5;
    color: #065F46;
}
.summary-card.total-cash { border-left: 4px solid #10B981; }

.summary-card.total-stock .summary-icon {
    background: #DBEAFE;
    color: #1E40AF;
}
.summary-card.total-stock { border-left: 4px solid #1E40AF; }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px var(--shadow-color);
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

.table-header h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.record-count {
    font-size: 12px;
    color: var(--text-muted);
}

.table-responsive {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

/* ============================================================
   TABLE HEADER - RED BACKGROUND
   ============================================================ */
.table thead th {
    background: #bb0404 !important;
    color: #ffffff !important;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.table thead th:first-child {
    border-radius: 6px 0 0 0;
}

.table thead th:last-child {
    border-radius: 0 6px 0 0;
}

.table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.table tbody tr:hover {
    background: var(--bg-table-hover);
}

.table tbody tr:nth-child(even) {
    background: var(--bg-table-even);
}

.table tbody tr:nth-child(even):hover {
    background: var(--bg-table-hover);
}

.table tbody .no-data {
    padding: 40px 20px;
}

.report-number {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 12px;
}

.branch-badge {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.text-primary {
    color: #1E40AF;
    font-weight: 600;
}

.text-success {
    color: #10B981;
    font-weight: 600;
}

.text-info {
    color: #3B82F6;
    font-weight: 600;
}

.font-bold {
    font-weight: 700;
}

.text-muted {
    color: var(--text-muted);
}

/* ============================================================
   ACTION BUTTONS
   ============================================================ */
.action-buttons {
    display: flex;
    gap: 4px;
}

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

.btn-action.view {
    background: #DBEAFE;
    color: #1D4ED8;
}
.btn-action.view:hover {
    background: #1D4ED8;
    color: #ffffff;
}

.btn-action.edit {
    background: #D1FAE5;
    color: #065F46;
}
.btn-action.edit:hover {
    background: #065F46;
    color: #ffffff;
}

.btn-action.print {
    background: #FEF3C7;
    color: #92400E;
}
.btn-action.print:hover {
    background: #92400E;
    color: #ffffff;
}

.btn-action.delete {
    background: #FEE2E2;
    color: #991B1B;
}
.btn-action.delete:hover {
    background: #991B1B;
    color: #ffffff;
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
    
    .branch-card .branch-name {
        font-size: 14px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-header .header-right {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .page-header .header-right .btn,
    .page-header .header-right .export-dropdown {
        flex: 1;
        min-width: 120px;
    }
    
    .page-header .header-right .btn {
        justify-content: center;
    }
    
    .export-dropdown .dropdown-toggle {
        width: 100%;
        justify-content: center;
    }
    
    .export-dropdown .dropdown-menu {
        right: 0;
        left: auto;
        min-width: 180px;
    }
    
    .filters-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-group .form-control {
        width: 100%;
    }
    
    .summary-cards {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .summary-card {
        padding: 12px 16px;
    }
    
    .summary-value {
        font-size: 16px;
    }
    
    .table-container {
        padding: 12px 14px;
    }
    
    .table thead th,
    .table tbody td {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    .table thead th {
        font-size: 10px;
        padding: 6px 8px;
    }
}

@media (max-width: 480px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .branch-card {
        flex-direction: column;
        text-align: center;
        gap: 4px;
    }
    
    .page-header .header-right .btn,
    .page-header .header-right .export-dropdown {
        flex: 1 1 100%;
    }
    
    .export-dropdown .dropdown-menu {
        left: 0;
        right: auto;
        min-width: 160px;
    }
    
    .action-buttons {
        flex-wrap: wrap;
    }
    
    .table thead th {
        font-size: 9px;
        padding: 4px 6px;
    }
    
    .table tbody td {
        font-size: 10px;
        padding: 4px 6px;
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
    animation: fadeInUp 0.3s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.05s; }
.summary-card:nth-child(2) { animation-delay: 0.10s; }
.summary-card:nth-child(3) { animation-delay: 0.15s; }
.summary-card:nth-child(4) { animation-delay: 0.20s; }
</style>

<!-- ============================================================
JAVASCRIPT
============================================================ -->
<script>
// ============================================================
// DARK MODE TOGGLE - Now handled by header
// ============================================================
// Dark mode toggle is now in admin_topbar.php
// The localStorage check below will still work for saved preference

// Check for saved dark mode preference
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = localStorage.getItem('darkMode');
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
    }
});

// ============================================================
// EXPORT DROPDOWN TOGGLE
// ============================================================
function toggleExportDropdown() {
    var menu = document.getElementById('exportMenu');
    menu.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dropdown = document.querySelector('.export-dropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        var menu = document.getElementById('exportMenu');
        if (menu) {
            menu.classList.remove('show');
        }
    }
});

// ============================================================
// EXPORT FUNCTIONS
// ============================================================
function getFilterParams() {
    const fromDate = document.querySelector('input[name="from_date"]')?.value || '';
    const toDate = document.querySelector('input[name="to_date"]')?.value || '';
    const branch = document.querySelector('select[name="branch"]')?.value || '0';
    return { from_date: fromDate, to_date: toDate, branch: branch };
}

function exportData(format) {
    const params = getFilterParams();
    const url = 'export.php?format=' + format + 
                '&from_date=' + params.from_date + 
                '&to_date=' + params.to_date + 
                '&branch=' + params.branch;
    window.location.href = url;
}

function printData() {
    // Get the table content
    const table = document.getElementById('dataTable');
    const title = 'Morning Reports List';
    const dateRange = document.querySelector('input[name="from_date"]')?.value + ' to ' + document.querySelector('input[name="to_date"]')?.value || '';
    
    // Create print window
    const printWindow = window.open('', '_blank', 'width=1000,height=600');
    printWindow.document.write('<html><head><title>Morning Reports</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(`
        body { font-family: Arial, sans-serif; padding: 20px; }
        h2 { color: #bb0404; margin-bottom: 5px; }
        .subtitle { color: #6B7280; font-size: 14px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #bb0404; color: white; padding: 8px 12px; text-align: left; }
        td { padding: 8px 12px; border-bottom: 1px solid #E5E7EB; }
        tr:nth-child(even) { background: #FAFAFA; }
        .branch-badge { background: #DBEAFE; color: #1D4ED8; padding: 2px 8px; border-radius: 4px; }
        .text-primary { color: #1E40AF; }
        .text-success { color: #10B981; }
        .text-info { color: #3B82F6; }
        .footer { margin-top: 20px; font-size: 11px; color: #9CA3AF; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 10px; }
        .print-date { float: right; color: #6B7280; font-size: 12px; }
    `);
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2><i class="fas fa-sun"></i> Morning Reports</h2>');
    printWindow.document.write('<div class="subtitle">Date Range: ' + dateRange + '</div>');
    printWindow.document.write('<div class="print-date">Printed: ' + new Date().toLocaleString() + '</div>');
    printWindow.document.write(table.outerHTML);
    printWindow.document.write('<div class="footer">Wakala System - Morning Reports</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    
    // Wait for content to load then print
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// ============================================================
// DELETE FUNCTION
// ============================================================
function deleteReport(id) {
    if (confirm('Are you sure you want to delete this morning report? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}
</script>

</body>
</html>