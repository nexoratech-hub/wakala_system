<?php
// ================================================================
// FILE: modules/reports/index.php
// WAKALA SYSTEM - REPORTS DASHBOARD
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
// BRANCH FILTER - FIXED
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

// Store in session
if (isset($_GET['branch'])) {
    $_SESSION['selected_branch'] = $selected_branch;
} elseif (isset($_SESSION['selected_branch']) && !isset($_GET['branch'])) {
    $selected_branch = $_SESSION['selected_branch'];
}

$selected_branch = $selected_branch ?? 0;

// ============================================================
// GET BRANCH NAME FOR DISPLAY - FIXED
// ============================================================
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
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// ============================================================
// GET SUMMARY DATA FOR REPORTS
// ============================================================

// --- 1. MORNING REPORTS SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(cumm_total) as total_float, SUM(cash_balance) as total_cash FROM morning_reports WHERE report_date BETWEEN ? AND ? AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(cumm_total) as total_float, SUM(cash_balance) as total_cash FROM morning_reports WHERE report_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$morning_summary = $stmt->fetch();

// --- 2. EVENING STOCKS SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(cumm_total) as total_float, SUM(cash_balance) as total_cash FROM evening_stocks WHERE stock_date BETWEEN ? AND ? AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(cumm_total) as total_float, SUM(cash_balance) as total_cash FROM evening_stocks WHERE stock_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$evening_summary = $stmt->fetch();

// --- 3. COMMISSIONS SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(total_commission) as total_commission, SUM(other_income) as other_income, SUM(total_business_income) as total_income FROM commissions WHERE commission_date BETWEEN ? AND ? AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(total_commission) as total_commission, SUM(other_income) as other_income, SUM(total_business_income) as total_income FROM commissions WHERE commission_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$commission_summary = $stmt->fetch();

// --- 4. EXPENSES SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total_expenses, category FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_business_expense = 1 AND branch_id = ? GROUP BY category ORDER BY total_expenses DESC";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total_expenses, category FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_business_expense = 1 GROUP BY category ORDER BY total_expenses DESC";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$expense_categories = $stmt->fetchAll();

// Total expenses
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_business_expense = 1 AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_business_expense = 1";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$total_expenses = $stmt->fetch();
$total_expenses_amount = $total_expenses['total'] ?? 0;

// --- 5. SALARIES SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(net_pay) as total_salaries FROM employee_salaries WHERE salary_month BETWEEN ? AND ? AND status = 'paid' AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(net_pay) as total_salaries FROM employee_salaries WHERE salary_month BETWEEN ? AND ? AND status = 'paid'";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$salary_summary = $stmt->fetch();

// --- 6. CASH OUT SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total_cashout FROM store_cash_out WHERE cashout_date BETWEEN ? AND ? AND status IN ('approved', 'pending') AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total_cashout FROM store_cash_out WHERE cashout_date BETWEEN ? AND ? AND status IN ('approved', 'pending')";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cashout_summary = $stmt->fetch();

// --- 7. DAILY REPORTS SUMMARY ---
if ($selected_branch > 0) {
    $sql = "SELECT COUNT(*) as count, SUM(total_commission + other_income) as total_income, SUM(total_expenses) as total_expenses, SUM(total_salaries) as total_salaries, SUM(total_cash_out) as total_cashout, SUM((total_commission + other_income) - total_expenses - total_salaries - total_cash_out) as total_profit FROM daily_reports WHERE report_date BETWEEN ? AND ? AND branch_id = ?";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT COUNT(*) as count, SUM(total_commission + other_income) as total_income, SUM(total_expenses) as total_expenses, SUM(total_salaries) as total_salaries, SUM(total_cash_out) as total_cashout, SUM((total_commission + other_income) - total_expenses - total_salaries - total_cash_out) as total_profit FROM daily_reports WHERE report_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$daily_summary = $stmt->fetch();

// --- 8. TRANSACTIONS SUMMARY (Deposits & Withdrawals) ---
if ($selected_branch > 0) {
    $sql = "SELECT transaction_type, SUM(amount) as total FROM daily_report_transactions WHERE transaction_date BETWEEN ? AND ? AND daily_report_id IN (SELECT id FROM daily_reports WHERE branch_id = ?) GROUP BY transaction_type";
    $params = [$from_date, $to_date, $selected_branch];
} else {
    $sql = "SELECT transaction_type, SUM(amount) as total FROM daily_report_transactions WHERE transaction_date BETWEEN ? AND ? GROUP BY transaction_type";
    $params = [$from_date, $to_date];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transaction_types = $stmt->fetchAll();

$total_deposits = 0;
$total_withdrawals = 0;
foreach ($transaction_types as $t) {
    if ($t['transaction_type'] == 'deposit') {
        $total_deposits = $t['total'];
    } elseif ($t['transaction_type'] == 'withdrawal') {
        $total_withdrawals = $t['total'];
    }
}

// Calculate totals
$total_income = ($commission_summary['total_income'] ?? 0);
$total_commission = ($commission_summary['total_commission'] ?? 0);
$total_other_income = ($commission_summary['other_income'] ?? 0);
$total_salaries = ($salary_summary['total_salaries'] ?? 0);
$total_cashout = ($cashout_summary['total_cashout'] ?? 0);
$total_profit = $total_income - $total_expenses_amount - $total_salaries - $total_cashout;

// --- 9. PROFIT CHART DATA (Monthly) ---
$monthly_profit = [];
for ($m = 1; $m <= 12; $m++) {
    $month = str_pad($m, 2, '0', STR_PAD_LEFT);
    $year = date('Y');
    $start_date = $year . '-' . $month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    if ($selected_branch > 0) {
        $sql = "SELECT SUM((total_commission + other_income) - total_expenses - total_salaries - total_cash_out) as profit FROM daily_reports WHERE report_date BETWEEN ? AND ? AND branch_id = ?";
        $params = [$start_date, $end_date, $selected_branch];
    } else {
        $sql = "SELECT SUM((total_commission + other_income) - total_expenses - total_salaries - total_cash_out) as profit FROM daily_reports WHERE report_date BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $monthly_profit[$m] = $result['profit'] ?? 0;
}

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
                <h2><i class="fas fa-chart-pie" style="color:#bb0404;"></i> Reports Dashboard</h2>
                <p class="text-muted">Comprehensive financial reports and analytics</p>
            </div>
            <div class="header-right">
                <a href="export.php" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export All Reports
                </a>
                <!-- ===== EXPORT DROPDOWN ===== -->
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" type="button" id="exportDropdown" onclick="toggleDropdown()">
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
                    <select name="branch" class="form-control" id="branchFilter" onchange="this.form.submit()">
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

        <!-- ===== BRANCH NOTICE ===== -->
        <?php if ($selected_branch > 0): ?>
            <div class="branch-notice">
                <i class="fas fa-store-alt" style="color:#bb0404;"></i>
                Showing data for: <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                <a href="?branch=0" class="branch-clear">
                    <i class="fas fa-times"></i> Clear Filter
                </a>
            </div>
        <?php else: ?>
            <div class="branch-notice all-branches">
                <i class="fas fa-globe" style="color:#10B981;"></i>
                Showing data for: <strong>All Branches</strong>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        QUICK REPORT LINKS
        ============================================================ -->
        <div class="quick-report-links">
            <a href="daily.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&branch=<?php echo $selected_branch; ?>" class="report-link daily">
                <i class="fas fa-file-alt"></i>
                <span>Daily Reports</span>
            </a>
            <a href="monthly.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&branch=<?php echo $selected_branch; ?>" class="report-link monthly">
                <i class="fas fa-calendar-alt"></i>
                <span>Monthly Reports</span>
            </a>
            <a href="commission.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&branch=<?php echo $selected_branch; ?>" class="report-link commission">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Commission Reports</span>
            </a>
            <a href="expense.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&branch=<?php echo $selected_branch; ?>" class="report-link expense">
                <i class="fas fa-receipt"></i>
                <span>Expense Reports</span>
            </a>
            <a href="profit.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&branch=<?php echo $selected_branch; ?>" class="report-link profit">
                <i class="fas fa-chart-line"></i>
                <span>Profit Reports</span>
            </a>
        </div>

        <!-- ============================================================
        SUMMARY CARDS
        ============================================================ -->
        <div class="summary-cards">
            <div class="summary-card card-morning">
                <div class="summary-icon"><i class="fas fa-sun"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Morning Reports</span>
                    <span class="summary-value"><?php echo number_format($morning_summary['count'] ?? 0); ?></span>
                    <span class="summary-sub">Float: <?php echo formatCurrency($morning_summary['total_float'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card card-evening">
                <div class="summary-icon"><i class="fas fa-moon"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Evening Stocks</span>
                    <span class="summary-value"><?php echo number_format($evening_summary['count'] ?? 0); ?></span>
                    <span class="summary-sub">Float: <?php echo formatCurrency($evening_summary['total_float'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card card-commission">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Commissions</span>
                    <span class="summary-value"><?php echo number_format($commission_summary['count'] ?? 0); ?></span>
                    <span class="summary-sub">Total: <?php echo formatCurrency($total_commission); ?></span>
                </div>
            </div>
            <div class="summary-card card-expense">
                <div class="summary-icon"><i class="fas fa-receipt"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Expenses</span>
                    <span class="summary-value"><?php echo number_format(count($expense_categories)); ?></span>
                    <span class="summary-sub">Total: <?php echo formatCurrency($total_expenses_amount); ?></span>
                </div>
            </div>
            <div class="summary-card card-salary">
                <div class="summary-icon"><i class="fas fa-wallet"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Salaries</span>
                    <span class="summary-value"><?php echo number_format($salary_summary['count'] ?? 0); ?></span>
                    <span class="summary-sub">Total: <?php echo formatCurrency($total_salaries); ?></span>
                </div>
            </div>
            <div class="summary-card card-cashout">
                <div class="summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Cash Out</span>
                    <span class="summary-value"><?php echo number_format($cashout_summary['count'] ?? 0); ?></span>
                    <span class="summary-sub">Total: <?php echo formatCurrency($total_cashout); ?></span>
                </div>
            </div>
            <div class="summary-card card-profit">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Net Profit</span>
                    <span class="summary-value <?php echo $total_profit >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatCurrency($total_profit); ?>
                    </span>
                    <span class="summary-sub"><?php echo date('M Y'); ?></span>
                </div>
            </div>
            <div class="summary-card card-income">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Income</span>
                    <span class="summary-value"><?php echo formatCurrency($total_income); ?></span>
                    <span class="summary-sub">Commission + Other Income</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FINANCIAL SUMMARY TABLE
        ============================================================ -->
        <div class="table-container" id="printableArea">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Financial Summary</h4>
                <span class="record-count"><?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped" id="dataTable">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Count</th>
                            <th>Total Amount</th>
                            <th>Average</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="fas fa-sun" style="color:#F59E0B;"></i> Morning Reports</td>
                            <td><?php echo number_format($morning_summary['count'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($morning_summary['total_float'] ?? 0); ?></td>
                            <td><?php echo formatCurrency(($morning_summary['count'] ?? 0) > 0 ? ($morning_summary['total_float'] ?? 0) / ($morning_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-approved">Active</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-moon" style="color:#3B82F6;"></i> Evening Stocks</td>
                            <td><?php echo number_format($evening_summary['count'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($evening_summary['total_float'] ?? 0); ?></td>
                            <td><?php echo formatCurrency(($evening_summary['count'] ?? 0) > 0 ? ($evening_summary['total_float'] ?? 0) / ($evening_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-approved">Active</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-hand-holding-usd" style="color:#10B981;"></i> Commissions</td>
                            <td><?php echo number_format($commission_summary['count'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($total_commission); ?></td>
                            <td><?php echo formatCurrency(($commission_summary['count'] ?? 0) > 0 ? $total_commission / ($commission_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-approved">Earned</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-receipt" style="color:#DC2626;"></i> Expenses</td>
                            <td><?php echo number_format(count($expense_categories)); ?></td>
                            <td><?php echo formatCurrency($total_expenses_amount); ?></td>
                            <td><?php echo formatCurrency(($commission_summary['count'] ?? 0) > 0 ? $total_expenses_amount / ($commission_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-pending">Incurred</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-wallet" style="color:#7F1D1D;"></i> Salaries</td>
                            <td><?php echo number_format($salary_summary['count'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($total_salaries); ?></td>
                            <td><?php echo formatCurrency(($salary_summary['count'] ?? 0) > 0 ? $total_salaries / ($salary_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-pending">Paid</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-money-bill-wave" style="color:#DC2626;"></i> Cash Out</td>
                            <td><?php echo number_format($cashout_summary['count'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($total_cashout); ?></td>
                            <td><?php echo formatCurrency(($cashout_summary['count'] ?? 0) > 0 ? $total_cashout / ($cashout_summary['count'] ?? 0) : 0); ?></td>
                            <td><span class="status-badge status-pending">Withdrawn</span></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong><i class="fas fa-chart-line" style="color:#bb0404;"></i> NET PROFIT</strong></td>
                            <td><strong>-</strong></td>
                            <td><strong class="<?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($total_profit); ?></strong></td>
                            <td><strong>-</strong></td>
                            <td><span class="status-badge <?php echo $total_profit >= 0 ? 'status-approved' : 'status-rejected'; ?>"><?php echo $total_profit >= 0 ? 'PROFIT' : 'LOSS'; ?></span></td>
                        </tr>
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
STYLES - WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --reports-bg: #F3F4F6;
    --reports-card-bg: #FFFFFF;
    --reports-text: #1F2937;
    --reports-text-secondary: #6B7280;
    --reports-text-light: #9CA3AF;
    --reports-border: #E5E7EB;
    --reports-input-bg: #F9FAFB;
    --reports-hover: #F3F4F6;
    --reports-shadow: rgba(0,0,0,0.06);
    --reports-shadow-lg: rgba(0,0,0,0.12);
    --reports-dropdown-bg: #FFFFFF;
    --reports-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --reports-bg: #111827;
    --reports-card-bg: #1F2937;
    --reports-text: #F9FAFB;
    --reports-text-secondary: #9CA3AF;
    --reports-text-light: #6B7280;
    --reports-border: #374151;
    --reports-input-bg: #374151;
    --reports-hover: #374151;
    --reports-shadow: rgba(0,0,0,0.3);
    --reports-shadow-lg: rgba(0,0,0,0.4);
    --reports-dropdown-bg: #1F2937;
    --reports-dropdown-border: #374151;
}

body {
    background: var(--reports-bg);
    transition: background 0.3s ease;
}

.main-content {
    background: var(--reports-bg);
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
    color: var(--reports-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--reports-text-secondary);
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
   EXPORT DROPDOWN - DARK MODE
   ============================================================ */
.export-dropdown {
    position: relative;
    display: inline-block;
}

.btn-export {
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

.btn-export:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    color: #ffffff;
}

.btn-export i {
    font-size: 14px;
}

.export-dropdown .dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--reports-dropdown-bg);
    border: 1px solid var(--reports-dropdown-border);
    border-radius: 10px;
    box-shadow: 0 10px 30px var(--reports-shadow-lg);
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
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.export-dropdown .dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    color: var(--reports-text);
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
    background: var(--reports-hover);
    color: #bb0404;
}

.export-dropdown .dropdown-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.export-dropdown .dropdown-divider {
    height: 1px;
    background: var(--reports-border);
    margin: 4px 0;
}

/* ============================================================
   FILTERS BAR - DARK MODE
   ============================================================ */
.filters-bar {
    background: var(--reports-card-bg);
    padding: 16px 20px;
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--reports-shadow);
    border: 1px solid var(--reports-border);
    margin-bottom: 16px;
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
    color: var(--reports-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid var(--reports-border);
    border-radius: 6px;
    font-size: 13px;
    color: var(--reports-text);
    background: var(--reports-input-bg);
    transition: all 0.3s ease;
    min-width: 150px;
}

.form-control option {
    background: var(--reports-dropdown-bg);
    color: var(--reports-text);
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
    background: var(--reports-hover);
    color: var(--reports-text-secondary);
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
    background: var(--reports-border);
    color: var(--reports-text);
}

/* ============================================================
   BRANCH NOTICE - DARK MODE
   ============================================================ */
.branch-notice {
    background: var(--reports-card-bg);
    border-radius: 8px;
    padding: 10px 16px;
    margin-bottom: 16px;
    border-left: 4px solid #bb0404;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: var(--reports-text);
    box-shadow: 0 1px 3px var(--reports-shadow);
    transition: all 0.3s ease;
}

.branch-notice i {
    font-size: 16px;
}

.branch-notice .branch-clear {
    margin-left: auto;
    color: #bb0404;
    text-decoration: none;
    font-weight: 500;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    transition: background 0.3s ease;
}

.branch-notice .branch-clear:hover {
    background: rgba(187,4,4,0.08);
}

.branch-notice.all-branches {
    border-left-color: #10B981;
}

.branch-notice.all-branches i {
    color: #10B981;
}

/* ============================================================
   QUICK REPORT LINKS - DARK MODE
   ============================================================ */
.quick-report-links {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.report-link {
    background: var(--reports-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    text-align: center;
    text-decoration: none;
    border: 1px solid var(--reports-border);
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px var(--reports-shadow);
}

.report-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px var(--reports-shadow-lg);
}

.report-link i {
    font-size: 28px;
    display: block;
    margin-bottom: 8px;
}

.report-link span {
    font-size: 13px;
    font-weight: 600;
    color: var(--reports-text);
}

.report-link.daily i { color: #3B82F6; }
.report-link.daily:hover { border-color: #3B82F6; }

.report-link.monthly i { color: #8B5CF6; }
.report-link.monthly:hover { border-color: #8B5CF6; }

.report-link.commission i { color: #10B981; }
.report-link.commission:hover { border-color: #10B981; }

.report-link.expense i { color: #DC2626; }
.report-link.expense:hover { border-color: #DC2626; }

.report-link.profit i { color: #F59E0B; }
.report-link.profit:hover { border-color: #F59E0B; }

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
    background: var(--reports-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--reports-shadow);
    border: 1px solid var(--reports-border);
    transition: all 0.3s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--reports-shadow-lg);
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
    color: var(--reports-text-secondary);
    display: block;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--reports-text);
}

.summary-value.positive { color: #10B981; }
.summary-value.negative { color: #DC2626; }

.summary-sub {
    font-size: 10px;
    color: var(--reports-text-light);
    display: block;
}

/* Card Colors - Stay consistent in dark mode */
.card-morning .summary-icon { background: #FEF3C7; color: #D97706; }
.card-morning { border-left: 4px solid #F59E0B; }

.card-evening .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-evening { border-left: 4px solid #3B82F6; }

.card-commission .summary-icon { background: #D1FAE5; color: #065F46; }
.card-commission { border-left: 4px solid #10B981; }

.card-expense .summary-icon { background: #FEE2E2; color: #991B1B; }
.card-expense { border-left: 4px solid #DC2626; }

.card-salary .summary-icon { background: #FECACA; color: #7F1D1D; }
.card-salary { border-left: 4px solid #7F1D1D; }

.card-cashout .summary-icon { background: #FEE2E2; color: #991B1B; }
.card-cashout { border-left: 4px solid #DC2626; }

.card-profit .summary-icon { background: #D1FAE5; color: #047857; }
.card-profit { border-left: 4px solid #059669; }

.card-income .summary-icon { background: #DBEAFE; color: #1E40AF; }
.card-income { border-left: 4px solid #1E40AF; }

/* ============================================================
   TABLE CONTAINER - DARK MODE
   ============================================================ */
.table-container {
    background: var(--reports-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px var(--reports-shadow);
    border: 1px solid var(--reports-border);
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
    color: var(--reports-text);
    margin: 0;
}

.table-header h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.record-count {
    font-size: 12px;
    color: var(--reports-text-secondary);
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
    border-bottom: 1px solid var(--reports-border);
    vertical-align: middle;
    color: var(--reports-text);
    transition: color 0.3s ease;
}

.table tbody tr:hover {
    background: var(--reports-hover);
}

.table tbody tr:nth-child(even) {
    background: var(--reports-hover);
}

.table tbody tr:nth-child(even):hover {
    background: var(--reports-border);
}

.table tbody .total-row {
    background: rgba(187,4,4,0.08) !important;
    font-weight: 600;
}

html.dark-mode .table tbody .total-row {
    background: rgba(187,4,4,0.2) !important;
}

.table tbody .total-row td {
    border-top: 2px solid #bb0404;
}

.text-success { color: #10B981; }
.text-danger { color: #DC2626; }

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-approved {
    background: #D1FAE5;
    color: #065F46;
}

.status-pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-rejected {
    background: #FEE2E2;
    color: #991B1B;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    .quick-report-links {
        grid-template-columns: repeat(3, 1fr);
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
        min-width: 120px;
    }
    
    .page-header .header-right .btn {
        justify-content: center;
    }
    
    .btn-export {
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
    
    .quick-report-links {
        grid-template-columns: 1fr 1fr;
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
    .page-header .header-right .btn,
    .page-header .header-right .export-dropdown {
        flex: 1 1 100%;
    }
    
    .export-dropdown .dropdown-menu {
        left: 0;
        right: auto;
        min-width: 160px;
    }
    
    .quick-report-links {
        grid-template-columns: 1fr;
    }
    
    .summary-cards {
        grid-template-columns: 1fr;
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

.summary-card:nth-child(1) { animation-delay: 0.03s; }
.summary-card:nth-child(2) { animation-delay: 0.06s; }
.summary-card:nth-child(3) { animation-delay: 0.09s; }
.summary-card:nth-child(4) { animation-delay: 0.12s; }
.summary-card:nth-child(5) { animation-delay: 0.15s; }
.summary-card:nth-child(6) { animation-delay: 0.18s; }
.summary-card:nth-child(7) { animation-delay: 0.21s; }
.summary-card:nth-child(8) { animation-delay: 0.24s; }

.quick-report-links .report-link {
    animation: fadeInUp 0.3s ease forwards;
}
.quick-report-links .report-link:nth-child(1) { animation-delay: 0.05s; }
.quick-report-links .report-link:nth-child(2) { animation-delay: 0.10s; }
.quick-report-links .report-link:nth-child(3) { animation-delay: 0.15s; }
.quick-report-links .report-link:nth-child(4) { animation-delay: 0.20s; }
.quick-report-links .report-link:nth-child(5) { animation-delay: 0.25s; }

.table-container {
    animation: fadeInUp 0.3s ease forwards;
    animation-delay: 0.30s;
}
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
    var title = 'Financial Reports Summary';
    var dateRange = document.querySelector('input[name="from_date"]')?.value + ' to ' + document.querySelector('input[name="to_date"]')?.value || '';
    
    var printWindow = window.open('', '_blank', 'width=1000,height=600');
    printWindow.document.write('<html><head><title>Financial Reports</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(`
        body { font-family: Arial, sans-serif; padding: 20px; }
        h2 { color: #bb0404; margin-bottom: 5px; }
        .subtitle { color: #6B7280; font-size: 14px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #bb0404; color: white; padding: 8px 12px; text-align: left; }
        td { padding: 8px 12px; border-bottom: 1px solid #E5E7EB; }
        tr:nth-child(even) { background: #FAFAFA; }
        .total-row { background: #FEF2F2 !important; font-weight: 600; }
        .text-success { color: #10B981; }
        .text-danger { color: #DC2626; }
        .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .status-approved { background: #D1FAE5; color: #065F46; }
        .status-pending { background: #FEF3C7; color: #92400E; }
        .status-rejected { background: #FEE2E2; color: #991B1B; }
        .footer { margin-top: 20px; font-size: 11px; color: #9CA3AF; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 10px; }
        .print-date { float: right; color: #6B7280; font-size: 12px; }
    `);
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2><i class="fas fa-chart-pie"></i> Financial Reports Summary</h2>');
    printWindow.document.write('<div class="subtitle">Date Range: ' + dateRange + '</div>');
    printWindow.document.write('<div class="print-date">Printed: ' + new Date().toLocaleString() + '</div>');
    printWindow.document.write(table.outerHTML);
    printWindow.document.write('<div class="footer">Wakala System - Reports Dashboard</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
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