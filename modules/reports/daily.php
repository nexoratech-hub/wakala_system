<?php
// ================================================================
// FILE: modules/reports/daily.php
// WAKALA FINANCIAL SYSTEM - DAILY REPORT
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
// GET FILTER PARAMETERS
// ============================================================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

// ============================================================
// GET DAILY REPORTS
// ============================================================
$sql = "SELECT 
            dr.*,
            e.full_name as employee_name,
            b.branch_name as branch_name
        FROM daily_reports dr
        LEFT JOIN employees e ON dr.employee_id = e.id
        LEFT JOIN branches b ON dr.branch_id = b.id
        WHERE dr.report_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];

if ($branch_id > 0) {
    $sql .= " AND dr.branch_id = ?";
    $params[] = $branch_id;
}

if ($role === 'employee') {
    $sql .= " AND dr.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY dr.report_date DESC, dr.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_commission = 0;
$total_deposits = 0;
$total_withdrawals = 0;
$total_expenses = 0;
$total_profit = 0;

foreach ($reports as $r) {
    $total_commission += floatval($r['total_commission'] ?? 0);
    $total_deposits += floatval($r['total_deposits'] ?? 0);
    $total_withdrawals += floatval($r['total_withdrawals'] ?? 0);
    $total_expenses += floatval($r['total_expenses'] ?? 0);
    $total_profit += floatval($r['net_profit'] ?? 0);
}

// ============================================================
// GET BRANCHES FOR FILTER
// ============================================================
$branches = [];
if ($role === 'admin' || $role === 'super_admin') {
    $branch_stmt = $db->query("SELECT id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $branches = $branch_stmt->fetchAll();
}

// ============================================================
// INCLUDE HEADER
// ============================================================
if ($role === 'employee') {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
} else {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
}
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-calendar-day"></i> Daily Reports</h2>
                <span class="record-count"><?php echo count($reports); ?> records</span>
            </div>
            <div class="page-header-right">
                <a href="export.php?type=daily&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Export
                </a>
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- ============================================================
        FILTER FORM
        ============================================================ -->
        <div class="filter-container">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch_id">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <a href="daily.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - 5 CARDS
        ============================================================ -->
        <div class="summaries-grid-five">
            <div class="summary-card card-commission">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Commission</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_commission); ?></div>
                </div>
            </div>

            <div class="summary-card card-deposits">
                <div class="summary-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Deposits</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_deposits); ?></div>
                </div>
            </div>

            <div class="summary-card card-withdrawals">
                <div class="summary-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Withdrawals</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_withdrawals); ?></div>
                </div>
            </div>

            <div class="summary-card card-expenses">
                <div class="summary-icon"><i class="fas fa-receipt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Expenses</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_expenses); ?></div>
                </div>
            </div>

            <div class="summary-card card-profit">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Net Profit</div>
                    <div class="summary-value" style="color: <?php echo $total_profit >= 0 ? '#10B981' : '#DC2626'; ?>;">
                        <?php echo formatCurrencyShort($total_profit); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DAILY REPORTS TABLE
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Daily Reports</h3>
                <div class="table-actions">
                    <input type="text" id="searchInput" placeholder="Search reports..." class="search-input">
                </div>
            </div>

            <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-day"></i>
                    <h3>No Daily Reports Found</h3>
                    <p>No reports found for the selected date range.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="reportsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Report No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Commission</th>
                                <th>Deposits</th>
                                <th>Withdrawals</th>
                                <th>Expenses</th>
                                <th>Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($reports as $report): 
                                $profit = floatval($report['net_profit'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="report-number">
                                            <?php echo htmlspecialchars($report['report_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo formatDate($report['report_date']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($report['employee_name'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?>
                                    </td>
                                    <td>
                                        <span class="amount-comm">
                                            <?php echo formatCurrency($report['total_commission']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-dep">
                                            <?php echo formatCurrency($report['total_deposits']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-wth">
                                            <?php echo formatCurrency($report['total_withdrawals']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-exp">
                                            <?php echo formatCurrency($report['total_expenses']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-profit" style="color: <?php echo $profit >= 0 ? '#10B981' : '#DC2626'; ?>;">
                                            <?php echo formatCurrency($profit); ?>
                                        </span>
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
    <?php if ($role === 'employee') {
        include_once '../../includes/employee_footer.php';
    } else {
        include_once '../../includes/admin_footer.php';
    } ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
:root {
    --daily-bg: #FFFFFF;
    --daily-text: #1F2937;
    --daily-text-secondary: #6B7280;
    --daily-text-light: #9CA3AF;
    --daily-border: #E5E7EB;
    --daily-card-bg: #FFFFFF;
    --daily-hover: #F3F4F6;
    --daily-shadow: rgba(0,0,0,0.06);
    --daily-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --daily-bg: #1F2937;
    --daily-text: #F9FAFB;
    --daily-text-secondary: #9CA3AF;
    --daily-text-light: #6B7280;
    --daily-border: #374151;
    --daily-card-bg: #1F2937;
    --daily-hover: #374151;
    --daily-shadow: rgba(0,0,0,0.3);
    --daily-shadow-lg: rgba(0,0,0,0.4);
}

body {
    background: var(--daily-bg) !important;
    color: var(--daily-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--daily-bg) !important; }
.main-content { background: var(--daily-bg) !important; }

/* Page Header */
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
    color: var(--daily-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #F59E0B;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--daily-text-secondary);
    background: var(--daily-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    background: #1D4ED8;
    color: white;
}

.btn-back {
    background: var(--daily-hover);
    color: var(--daily-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--daily-border);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--daily-border);
}

/* Filter Container */
.filter-container {
    background: var(--daily-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--daily-border);
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--daily-shadow);
}

.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 20px;
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
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--daily-border);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    background: var(--daily-hover);
    color: var(--daily-text);
    transition: all 0.3s ease;
    min-width: 140px;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.filter-actions {
    flex-direction: row;
    gap: 8px;
}

.btn-filter {
    background: #DC2626;
    color: white;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #B91C1C;
}

.btn-reset {
    background: var(--daily-hover);
    color: var(--daily-text);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--daily-border);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--daily-border);
}

/* Summaries Grid - 5 Cards */
.summaries-grid-five {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.summary-card {
    background: var(--daily-card-bg);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px var(--daily-shadow);
    border: 1px solid var(--daily-border);
    transition: all 0.3s ease;
    min-height: 85px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--daily-shadow-lg);
}

.summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
}

.summary-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--daily-text-secondary);
}

.summary-value {
    font-size: 16px;
    font-weight: 800;
    color: var(--daily-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.card-commission .summary-icon { background: #D1FAE5; color: #065F46; }
.card-commission { border-left: 4px solid #10B981; }

.card-deposits .summary-icon { background: #FEF3C7; color: #D97706; }
.card-deposits { border-left: 4px solid #F59E0B; }

.card-withdrawals .summary-icon { background: #FEE2E2; color: #DC2626; }
.card-withdrawals { border-left: 4px solid #DC2626; }

.card-expenses .summary-icon { background: #FCE4EC; color: #E11D48; }
.card-expenses { border-left: 4px solid #E11D48; }

.card-profit .summary-icon { background: #D1FAE5; color: #10B981; }
.card-profit { border-left: 4px solid #10B981; }

html.dark-mode .card-profit .summary-icon { background: #065F46; color: #10B981; }

/* Table */
.table-container {
    background: var(--daily-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--daily-shadow);
    border: 1px solid var(--daily-border);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--daily-border);
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--daily-text);
    margin: 0;
}

.table-header h3 i {
    color: #F59E0B;
    margin-right: 8px;
}

.table-actions {
    display: flex;
    gap: 10px;
}

.search-input {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--daily-border);
    font-size: 12px;
    outline: none;
    width: 180px;
    transition: all 0.3s ease;
    background: var(--daily-hover);
    color: var(--daily-text);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--daily-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--daily-hover);
}

.data-table tbody td {
    padding: 8px 12px;
    color: var(--daily-text);
    font-size: 12px;
}

.report-number {
    font-weight: 600;
    color: #3B82F6;
}

.amount-comm { color: #10B981; font-weight: 500; }
.amount-dep { color: #F59E0B; font-weight: 500; }
.amount-wth { color: #DC2626; font-weight: 500; }
.amount-exp { color: #E11D48; font-weight: 500; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 48px;
    color: var(--daily-text-light);
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 18px;
    color: var(--daily-text);
    margin: 0 0 6px 0;
}

.empty-state p {
    color: var(--daily-text-secondary);
    font-size: 13px;
    margin: 0;
}

/* Responsive */
@media (max-width: 1024px) {
    .summaries-grid-five {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .summaries-grid-five {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-group input,
    .filter-group select {
        width: 100%;
        min-width: auto;
    }
    
    .filter-actions {
        flex-direction: row;
    }
    
    .filter-actions .btn-filter,
    .filter-actions .btn-reset {
        flex: 1;
        justify-content: center;
    }
    
    .summary-card {
        min-height: 75px;
        padding: 10px 12px;
    }
    
    .summary-icon {
        width: 34px;
        height: 34px;
        font-size: 14px;
    }
    
    .summary-value {
        font-size: 14px;
    }
    
    .table-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .search-input {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .summaries-grid-five {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .summary-card {
        min-height: 65px;
        padding: 8px 10px;
    }
    
    .summary-icon {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .summary-value {
        font-size: 12px;
    }
    
    .summary-label {
        font-size: 8px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#reportsTable tbody tr');
            
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
            });
        });
    }
    
    // Dark Mode Sync
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