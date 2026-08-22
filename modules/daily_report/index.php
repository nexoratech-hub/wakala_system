<?php
// ================================================================
// FILE: modules/daily_report/index.php
// WAKALA SYSTEM - DAILY REPORT LIST
// WITH FULL DARK MODE SUPPORT
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

// ============================================================
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// ============================================================
// GET DAILY REPORTS WITH FILTERS
// ============================================================
$sql = "SELECT dr.*, 
        e.full_name as employee_name, 
        b.branch_name as branch_name,
        p.provider_name as provider_name,
        p.provider_code
        FROM daily_reports dr
        LEFT JOIN employees e ON dr.employee_id = e.id
        LEFT JOIN branches b ON dr.branch_id = b.id
        LEFT JOIN providers p ON dr.provider_id = p.id
        WHERE dr.report_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql .= " AND dr.branch_id = ?";
    $params[] = $selected_branch;
}

// If employee, show only their reports
if ($role == 'employee') {
    $sql .= " AND dr.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY dr.report_date DESC, dr.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// ============================================================
// GET SUMMARY TOTALS
// ============================================================
$sql_summary = "SELECT 
        COUNT(*) as total_reports,
        SUM(total_commission + other_income) as total_income,
        SUM(total_expenses) as total_expenses,
        SUM(total_salaries) as total_salaries,
        SUM(total_cash_out) as total_cashout,
        SUM((total_commission + other_income) - total_expenses - total_salaries - total_cash_out) as total_profit,
        SUM(total_deposits) as total_deposits,
        SUM(total_withdrawals) as total_withdrawals
        FROM daily_reports
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
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Reports</h2>
                <p class="text-muted">Manage and view all daily reports</p>
            </div>
            <div class="header-right">
                <a href="deposit.php" class="btn btn-deposit">
                    <i class="fas fa-arrow-down"></i> Deposit
                </a>
                <a href="withdrawal.php" class="btn btn-withdrawal">
                    <i class="fas fa-arrow-up"></i> Withdrawal
                </a>
                <a href="generate.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Generate Report
                </a>
                <!-- ===== EXPORT DROPDOWN ===== -->
                <div class="dropdown export-dropdown">
                    <button class="btn btn-success dropdown-toggle" type="button" id="exportDropdown" onclick="toggleDropdown()">
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
            <div class="summary-card total-income">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Income</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_income'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card total-deposits">
                <div class="summary-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Deposits</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card total-withdrawals">
                <div class="summary-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Withdrawals</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <!-- ===== REPORTS TABLE ===== -->
        <div class="table-container" id="printableArea">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Daily Reports List</h4>
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
                            <th>Provider</th>
                            <th>Float</th>
                            <th>Deposits</th>
                            <th>Withdrawals</th>
                            <th>Commission</th>
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
                                    <td>
                                        <?php if ($report['provider_code']): ?>
                                            <span class="provider-badge">
                                                <?php echo htmlspecialchars($report['provider_code']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($report['current_float'] ?? 0); ?></td>
                                    <td class="text-success"><?php echo formatCurrency($report['total_deposits'] ?? 0); ?></td>
                                    <td class="text-danger"><?php echo formatCurrency($report['total_withdrawals'] ?? 0); ?></td>
                                    <td class="text-primary font-bold"><?php echo formatCurrency($report['total_commission'] ?? 0); ?></td>
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
                                <td colspan="11" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--daily-text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--daily-text-secondary);">No daily reports found for the selected filters</p>
                                    <a href="generate.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus"></i> Generate First Report
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
STYLES - WITH FULL DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --daily-bg: #FFFFFF;
    --daily-text: #1F2937;
    --daily-text-secondary: #6B7280;
    --daily-text-light: #9CA3AF;
    --daily-border: #E5E7EB;
    --daily-card-bg: #FFFFFF;
    --daily-input-bg: #F9FAFB;
    --daily-hover: #F3F4F6;
    --daily-shadow: rgba(0,0,0,0.06);
    --daily-shadow-lg: rgba(0,0,0,0.12);
    --daily-dropdown-bg: #FFFFFF;
    --daily-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --daily-bg: #1F2937;
    --daily-text: #F9FAFB;
    --daily-text-secondary: #9CA3AF;
    --daily-text-light: #6B7280;
    --daily-border: #374151;
    --daily-card-bg: #1F2937;
    --daily-input-bg: #374151;
    --daily-hover: #374151;
    --daily-shadow: rgba(0,0,0,0.3);
    --daily-shadow-lg: rgba(0,0,0,0.4);
    --daily-dropdown-bg: #1F2937;
    --daily-dropdown-border: #374151;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--daily-bg) !important;
    color: var(--daily-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--daily-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--daily-bg) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   PAGE HEADER - DARK MODE
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
    color: var(--daily-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--daily-text-secondary);
    margin: 4px 0 0 0;
    transition: color 0.3s ease;
}

.page-header .header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* ============================================================
   BUTTONS - DARK MODE
   ============================================================ */
.btn-deposit {
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

.btn-deposit:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    color: #ffffff;
}

.btn-withdrawal {
    background: #DC2626;
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

.btn-withdrawal:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
    color: #ffffff;
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
   EXPORT DROPDOWN - DARK MODE
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
    background: var(--daily-dropdown-bg);
    border: 1px solid var(--daily-dropdown-border);
    border-radius: 10px;
    box-shadow: 0 10px 30px var(--daily-shadow-lg);
    min-width: 200px;
    z-index: 1000;
    padding: 6px 0;
    overflow: hidden;
    transition: all 0.3s ease;
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
    color: var(--daily-text);
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
    background: var(--daily-hover);
    color: #bb0404;
}

.export-dropdown .dropdown-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.export-dropdown .dropdown-divider {
    height: 1px;
    background: var(--daily-border);
    margin: 4px 0;
}

/* ============================================================
   FILTERS BAR - DARK MODE
   ============================================================ */
.filters-bar {
    background: var(--daily-card-bg);
    padding: 16px 20px;
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--daily-shadow);
    border: 1px solid var(--daily-border);
    margin-bottom: 20px;
    transition: all 0.3s ease;
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
    color: var(--daily-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid var(--daily-border);
    border-radius: 6px;
    font-size: 13px;
    color: var(--daily-text);
    background: var(--daily-input-bg);
    transition: all 0.3s ease;
    min-width: 150px;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.form-control option {
    background: var(--daily-dropdown-bg);
    color: var(--daily-text);
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
    background: var(--daily-hover);
    color: var(--daily-text-secondary);
    border: none;
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
    background: var(--daily-border);
    color: var(--daily-text);
}

/* ============================================================
   SUMMARY CARDS - DARK MODE
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.summary-card {
    background: var(--daily-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--daily-shadow);
    border: 1px solid var(--daily-border);
    transition: all 0.3s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--daily-shadow-lg);
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
    color: var(--daily-text-secondary);
    display: block;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--daily-text);
}

.summary-card.total-reports .summary-icon {
    background: #DBEAFE;
    color: #1D4ED8;
}
.summary-card.total-reports { border-left: 4px solid #3B82F6; }

.summary-card.total-income .summary-icon {
    background: #D1FAE5;
    color: #065F46;
}
.summary-card.total-income { border-left: 4px solid #10B981; }

.summary-card.total-deposits .summary-icon {
    background: #DBEAFE;
    color: #1E40AF;
}
.summary-card.total-deposits { border-left: 4px solid #1E40AF; }

.summary-card.total-withdrawals .summary-icon {
    background: #FEE2E2;
    color: #991B1B;
}
.summary-card.total-withdrawals { border-left: 4px solid #DC2626; }

/* ============================================================
   TABLE CONTAINER - DARK MODE
   ============================================================ */
.table-container {
    background: var(--daily-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px var(--daily-shadow);
    border: 1px solid var(--daily-border);
    transition: all 0.3s ease;
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
    color: var(--daily-text);
    margin: 0;
}

.table-header h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.record-count {
    font-size: 12px;
    color: var(--daily-text-secondary);
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
   TABLE HEADER - RED BACKGROUND (Stays Red)
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
    border-bottom: 1px solid var(--daily-border);
    vertical-align: middle;
    color: var(--daily-text);
    transition: color 0.3s ease;
}

.table tbody tr:hover {
    background: var(--daily-hover);
}

.table tbody tr:nth-child(even) {
    background: var(--daily-hover);
}

.table tbody tr:nth-child(even):hover {
    background: var(--daily-border);
}

.table tbody .no-data {
    padding: 40px 20px;
}

.report-number {
    font-weight: 600;
    color: var(--daily-text);
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

.provider-badge {
    background: #FEF3C7;
    color: #92400E;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.text-success {
    color: #10B981;
    font-weight: 600;
}

.text-danger {
    color: #DC2626;
    font-weight: 600;
}

.text-primary {
    color: #1E40AF;
    font-weight: 600;
}

.font-bold {
    font-weight: 700;
}

.text-muted {
    color: var(--daily-text-light);
}

/* ============================================================
   ACTION BUTTONS - DARK MODE
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
        min-width: 100px;
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
// EXPORT DROPDOWN TOGGLE
// ============================================================
function toggleDropdown() {
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
    var fromDate = document.querySelector('input[name="from_date"]')?.value || '';
    var toDate = document.querySelector('input[name="to_date"]')?.value || '';
    var branch = document.querySelector('select[name="branch"]')?.value || '0';
    return { from_date: fromDate, to_date: toDate, branch: branch };
}

function exportData(format) {
    var params = getFilterParams();
    var url = 'export.php?format=' + format + 
              '&from_date=' + params.from_date + 
              '&to_date=' + params.to_date + 
              '&branch=' + params.branch;
    window.location.href = url;
}

function printData() {
    var table = document.getElementById('dataTable');
    var title = 'Daily Reports List';
    var dateRange = document.querySelector('input[name="from_date"]')?.value + ' to ' + document.querySelector('input[name="to_date"]')?.value || '';
    
    var printWindow = window.open('', '_blank', 'width=1000,height=600');
    printWindow.document.write('<html><head><title>Daily Reports</title>');
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
        .provider-badge { background: #FEF3C7; color: #92400E; padding: 2px 8px; border-radius: 4px; }
        .text-success { color: #10B981; }
        .text-danger { color: #DC2626; }
        .text-primary { color: #1E40AF; }
        .footer { margin-top: 20px; font-size: 11px; color: #9CA3AF; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 10px; }
        .print-date { float: right; color: #6B7280; font-size: 12px; }
    `);
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2><i class="fas fa-file-alt"></i> Daily Reports</h2>');
    printWindow.document.write('<div class="subtitle">Date Range: ' + dateRange + '</div>');
    printWindow.document.write('<div class="print-date">Printed: ' + new Date().toLocaleString() + '</div>');
    printWindow.document.write(table.outerHTML);
    printWindow.document.write('<div class="footer">Wakala System - Daily Reports</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// ============================================================
// DELETE FUNCTION
// ============================================================
function deleteReport(id) {
    if (confirm('Are you sure you want to delete this daily report? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
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