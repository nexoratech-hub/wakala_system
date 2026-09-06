<?php
// ================================================================
// FILE: modules/daily_report/index.php
// DAILY REPORT - LIST ALL REPORTS
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

// Check permission
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// Get user's branch
$selected_branch = isset($_SESSION['user_branch_id']) ? intval($_SESSION['user_branch_id']) : 0;
if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch();
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
        $_SESSION['user_branch_id'] = $selected_branch;
    }
}

// Get branch name
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// Get filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

try {
    // Build query
    $sql = "SELECT dr.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            p.provider_name,
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

    $sql .= " ORDER BY dr.report_date DESC, dr.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get branches for filter
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get summary statistics
    $sql_summary = "SELECT 
            COUNT(*) as total_reports,
            SUM(total_deposits) as total_deposits,
            SUM(total_withdrawals) as total_withdrawals,
            SUM(total_commission) as total_commission,
            SUM(net_profit) as total_profit,
            SUM(current_capital) as total_capital
            FROM daily_reports
            WHERE report_date BETWEEN ? AND ?";
    $params_summary = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql_summary .= " AND branch_id = ?";
        $params_summary[] = $selected_branch;
    }
    
    $stmt = $db->prepare($sql_summary);
    $stmt->execute($params_summary);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error in daily report index: " . $e->getMessage());
    $reports = [];
    $branches = [];
    $summary = [
        'total_reports' => 0,
        'total_deposits' => 0,
        'total_withdrawals' => 0,
        'total_commission' => 0,
        'total_profit' => 0,
        'total_capital' => 0
    ];
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <i class="fas fa-store-alt"></i>
                <span class="branch-indicator-label">Current Branch:</span>
                <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php if ($branch_code): ?>
                    <span class="branch-indicator-code">(<?php echo htmlspecialchars($branch_code); ?>)</span>
                <?php endif; ?>
                <?php if ($branch_location): ?>
                    <span class="branch-indicator-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($branch_location); ?></span>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('d M Y'); ?></span>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Reports</h2>
                <p class="text-muted">Manage and track daily business reports</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Report
                </a>
                <a href="../daily_report/transactions.php?type=deposit" class="btn btn-deposit">
                    <i class="fas fa-arrow-down"></i> Deposits
                </a>
                <a href="../daily_report/transactions.php?type=withdrawal" class="btn btn-withdrawal">
                    <i class="fas fa-arrow-up"></i> Withdrawals
                </a>
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</a>
                        <a href="#" onclick="window.print()"><i class="fas fa-print"></i> Print</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Reports</span>
                    <span class="summary-value"><?php echo number_format($summary['total_reports'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#D1FAE5;color:#065F46;">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Deposits</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#FEE2E2;color:#991B1B;">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Withdrawals</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#FEF3C7;color:#92400E;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Profit</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_profit'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#EDE9FE;color:#6D28D9;">
                    <i class="fas fa-building"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_capital'] ?? 0); ?></span>
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
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Reports Table -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Daily Reports</h4>
                <span class="record-count"><?php echo count($reports); ?> records</span>
            </div>
            
            <div class="table-responsive">
                <table class="data-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Report No.</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Provider</th>
                            <th>Deposits</th>
                            <th>Withdrawals</th>
                            <th>Commission</th>
                            <th>Profit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reports) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><span class="report-number"><?php echo htmlspecialchars($report['report_number']); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($report['report_date'])); ?></td>
                                    <td><span class="branch-badge"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></span></td>
                                    <td><?php echo htmlspecialchars($report['provider_name'] ?? $report['provider_code'] ?? 'N/A'); ?></td>
                                    <td class="text-success"><?php echo formatCurrency($report['total_deposits']); ?></td>
                                    <td class="text-danger"><?php echo formatCurrency($report['total_withdrawals']); ?></td>
                                    <td><?php echo formatCurrency($report['total_commission']); ?></td>
                                    <td class="<?php echo $report['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($report['net_profit']); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $report['id']; ?>" class="btn-action btn-view" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?php echo $report['id']; ?>" class="btn-action btn-edit" title="Edit"><i class="fas fa-edit"></i></a>
                                            <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="btn-action btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No daily reports found</p>
                                    <a href="add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add First Report</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
    border: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #FFFFFF;
}

.branch-indicator-left i {
    font-size: 18px;
    color: rgba(255,255,255,0.9);
}

.branch-indicator-label {
    font-weight: 500;
    opacity: 0.8;
    letter-spacing: 0.5px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 15px;
    color: #FFFFFF;
}

.branch-indicator-code {
    font-size: 12px;
    opacity: 0.7;
    color: #FFFFFF;
}

.branch-indicator-location {
    font-size: 12px;
    opacity: 0.8;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 4px;
}

.branch-indicator-location i {
    font-size: 12px;
}

.branch-indicator-right .date-display {
    font-size: 13px;
    color: rgba(255,255,255,0.8);
}

.branch-indicator-right .date-display i {
    margin-right: 4px;
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

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-deposit { background: #059669; color: white; }
.btn-deposit:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,0.3); }

.btn-withdrawal { background: #DC2626; color: white; }
.btn-withdrawal:hover { background: #B91C1C; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,38,38,0.3); }

.btn-export { background: #10B981; color: white; }
.btn-export:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; }

.btn-reset { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-reset:hover { background: var(--bg-table-hover); }

.btn-sm { padding: 5px 12px; font-size: 12px; }

/* ============================================================
   SUMMARY CARDS
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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
   EXPORT DROPDOWN
   ============================================================ */
.dropdown { position: relative; display: inline-block; }
.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--bg-card);
    min-width: 180px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--shadow-hover);
    border: 1px solid var(--border-color);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 13px;
    transition: background 0.2s ease;
}
.dropdown-menu a:hover { background: var(--bg-table-hover); }
.dropdown-menu a i { width: 18px; font-size: 15px; }

/* ============================================================
   FILTERS
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

.report-number { font-weight: 600; color: #bb0404; font-size: 12px; }
.branch-badge {
    background: var(--bg-table-even);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--text-muted);
}

.text-success { color: #10B981; font-weight: 600; }
.text-danger { color: #DC2626; font-weight: 600; }

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

.btn-edit { background: #D1FAE5; color: #065F46; }
.btn-edit:hover { background: #065F46; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

.no-data { padding: 40px 20px; text-align: center; }

/* ============================================================
   CSS VARIABLES
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

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summary-cards {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    .filters-form {
        flex-direction: column;
    }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; flex-wrap: wrap; }
    .header-right .btn { flex: 1; justify-content: center; }
    .dropdown { flex: 1; }
    .dropdown-toggle { width: 100%; justify-content: center; }
    .branch-indicator {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
        padding: 12px 16px;
    }
    .branch-indicator-left {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .summary-cards {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .summary-card {
        padding: 12px 14px;
    }
    .summary-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    .summary-value {
        font-size: 15px;
    }
    .branch-indicator-name {
        font-size: 13px;
    }
    .branch-indicator-location {
        font-size: 11px;
    }
}
</style>

<script>
function toggleDropdown() {
    document.getElementById('exportMenu').classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        document.getElementById('exportMenu').classList.remove('show');
    }
});

function exportData(format) {
    document.getElementById('exportMenu').classList.remove('show');
    const params = new URLSearchParams(window.location.search);
    window.location.href = 'export.php?format=' + format + '&' + params.toString();
}

function deleteReport(id) {
    if (confirm('Are you sure you want to delete this daily report? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}
</script>

</body>
</html>