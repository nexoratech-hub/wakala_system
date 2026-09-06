<?php
// ================================================================
// FILE: modules/reports/profit.php
// WAKALA FINANCIAL SYSTEM - PROFIT & LOSS REPORT
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
// GET FILTER PARAMETERS
// ============================================================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// ============================================================
// GET PROFIT DATA
// ============================================================

// 1. Total Commission
$comm_stmt = $db->prepare("SELECT SUM(total_commission) as total FROM daily_reports 
                           WHERE report_date BETWEEN ? AND ?");
$comm_stmt->execute([$start_date, $end_date]);
$commission = floatval($comm_stmt->fetch()['total'] ?? 0);

// 2. Total Other Income
$income_stmt = $db->prepare("SELECT SUM(other_income) as total FROM daily_reports 
                            WHERE report_date BETWEEN ? AND ?");
$income_stmt->execute([$start_date, $end_date]);
$other_income = floatval($income_stmt->fetch()['total'] ?? 0);

// 3. Total Business Income
$business_income = $commission + $other_income;

// 4. Total Expenses
$exp_stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses 
                          WHERE expense_date BETWEEN ? AND ? 
                          AND is_business_expense = 1");
$exp_stmt->execute([$start_date, $end_date]);
$expenses = floatval($exp_stmt->fetch()['total'] ?? 0);

// 5. Total Salaries
$salary_stmt = $db->prepare("SELECT SUM(net_pay) as total FROM employee_salaries 
                             WHERE payment_date BETWEEN ? AND ? 
                             AND status = 'paid'");
$salary_stmt->execute([$start_date, $end_date]);
$salaries = floatval($salary_stmt->fetch()['total'] ?? 0);

// 6. Total Cash Out
$cashout_stmt = $db->prepare("SELECT SUM(amount) as total FROM store_cash_out 
                              WHERE cashout_date BETWEEN ? AND ? 
                              AND status = 'approved'");
$cashout_stmt->execute([$start_date, $end_date]);
$cash_out = floatval($cashout_stmt->fetch()['total'] ?? 0);

// 7. Total Expenses (including salaries and cash out)
$total_expenses = $expenses + $salaries + $cash_out;

// 8. Net Profit
$net_profit = $business_income - $total_expenses;

// ============================================================
// GET DAILY PROFIT TREND
// ============================================================
$trend_stmt = $db->prepare("SELECT 
    report_date,
    total_commission,
    other_income,
    total_expenses,
    net_profit
    FROM daily_reports 
    WHERE report_date BETWEEN ? AND ?
    ORDER BY report_date ASC");
$trend_stmt->execute([$start_date, $end_date]);
$daily_trend = $trend_stmt->fetchAll();

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
                <h2><i class="fas fa-chart-line"></i> Profit & Loss Report</h2>
                <span class="record-count"><?php echo date('M Y', strtotime($start_date)); ?> - <?php echo date('M Y', strtotime($end_date)); ?></span>
            </div>
            <div class="page-header-right">
                <a href="export.php?type=profit&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="btn btn-export">
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
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <a href="profit.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        MAIN PROFIT CARD
        ============================================================ -->
        <div class="profit-summary">
            <div class="profit-card <?php echo $net_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                <div class="profit-icon">
                    <i class="fas <?php echo $net_profit >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                </div>
                <div class="profit-content">
                    <div class="profit-label">Net Profit / (Loss)</div>
                    <div class="profit-value">
                        <?php echo formatCurrency($net_profit); ?>
                    </div>
                    <div class="profit-period">
                        <?php echo formatDate($start_date); ?> - <?php echo formatDate($end_date); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILED BREAKDOWN
        ============================================================ -->
        <div class="breakdown-grid">
            <!-- Income Section -->
            <div class="breakdown-section income-section">
                <div class="section-header">
                    <h4><i class="fas fa-arrow-down" style="color:#10B981;"></i> Income</h4>
                    <span class="section-total"><?php echo formatCurrency($business_income); ?></span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Commission</span>
                    <span class="item-value"><?php echo formatCurrency($commission); ?></span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Other Income</span>
                    <span class="item-value"><?php echo formatCurrency($other_income); ?></span>
                </div>
                <div class="breakdown-total">
                    <span class="total-label">Total Income</span>
                    <span class="total-value"><?php echo formatCurrency($business_income); ?></span>
                </div>
            </div>

            <!-- Expenses Section -->
            <div class="breakdown-section expenses-section">
                <div class="section-header">
                    <h4><i class="fas fa-arrow-up" style="color:#DC2626;"></i> Expenses</h4>
                    <span class="section-total"><?php echo formatCurrency($total_expenses); ?></span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">General Expenses</span>
                    <span class="item-value"><?php echo formatCurrency($expenses); ?></span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Salaries</span>
                    <span class="item-value"><?php echo formatCurrency($salaries); ?></span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Cash Out</span>
                    <span class="item-value"><?php echo formatCurrency($cash_out); ?></span>
                </div>
                <div class="breakdown-total">
                    <span class="total-label">Total Expenses</span>
                    <span class="total-value"><?php echo formatCurrency($total_expenses); ?></span>
                </div>
            </div>

            <!-- Net Profit Section -->
            <div class="breakdown-section net-section <?php echo $net_profit >= 0 ? 'net-positive' : 'net-negative'; ?>">
                <div class="section-header">
                    <h4><i class="fas fa-calculator"></i> Net Profit / (Loss)</h4>
                    <span class="section-total" style="color: <?php echo $net_profit >= 0 ? '#10B981' : '#DC2626'; ?>;">
                        <?php echo formatCurrency($net_profit); ?>
                    </span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Profit Margin</span>
                    <span class="item-value">
                        <?php 
                        $margin = $business_income > 0 ? round(($net_profit / $business_income) * 100, 1) : 0;
                        echo $margin . '%';
                        ?>
                    </span>
                </div>
                <div class="breakdown-item">
                    <span class="item-label">Total Transactions</span>
                    <span class="item-value"><?php echo count($daily_trend); ?></span>
                </div>
                <div class="breakdown-total">
                    <span class="total-label">Status</span>
                    <span class="total-value" style="color: <?php echo $net_profit >= 0 ? '#10B981' : '#DC2626'; ?>;">
                        <i class="fas <?php echo $net_profit >= 0 ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <?php echo $net_profit >= 0 ? 'Profitable' : 'Loss'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DAILY TREND TABLE
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-chart-bar"></i> Daily Profit Trend</h3>
            </div>

            <?php if (empty($daily_trend)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <h3>No Data Found</h3>
                    <p>No daily profit data found for the selected period.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Commission</th>
                                <th>Other Income</th>
                                <th>Total Income</th>
                                <th>Expenses</th>
                                <th>Net Profit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_trend as $day): 
                                $day_income = floatval($day['total_commission'] ?? 0) + floatval($day['other_income'] ?? 0);
                                $day_profit = floatval($day['net_profit'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo formatDate($day['report_date']); ?></td>
                                    <td><?php echo formatCurrency($day['total_commission']); ?></td>
                                    <td><?php echo formatCurrency($day['other_income']); ?></td>
                                    <td><?php echo formatCurrency($day_income); ?></td>
                                    <td><?php echo formatCurrency($day['total_expenses']); ?></td>
                                    <td>
                                        <span style="color: <?php echo $day_profit >= 0 ? '#10B981' : '#DC2626'; ?>; font-weight:600;">
                                            <?php echo formatCurrency($day_profit); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $day_profit >= 0 ? 'status-profit' : 'status-loss'; ?>">
                                            <?php echo $day_profit >= 0 ? 'Profit' : 'Loss'; ?>
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
    --pl-bg: #FFFFFF;
    --pl-text: #1F2937;
    --pl-text-secondary: #6B7280;
    --pl-text-light: #9CA3AF;
    --pl-border: #E5E7EB;
    --pl-card-bg: #FFFFFF;
    --pl-hover: #F3F4F6;
    --pl-shadow: rgba(0,0,0,0.06);
    --pl-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --pl-bg: #1F2937;
    --pl-text: #F9FAFB;
    --pl-text-secondary: #9CA3AF;
    --pl-text-light: #6B7280;
    --pl-border: #374151;
    --pl-card-bg: #1F2937;
    --pl-hover: #374151;
    --pl-shadow: rgba(0,0,0,0.3);
    --pl-shadow-lg: rgba(0,0,0,0.4);
}

body {
    background: var(--pl-bg) !important;
    color: var(--pl-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--pl-bg) !important; }
.main-content { background: var(--pl-bg) !important; }

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
    color: var(--pl-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #8B5CF6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--pl-text-secondary);
    background: var(--pl-hover);
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
    background: var(--pl-hover);
    color: var(--pl-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--pl-border);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--pl-border);
}

/* Filter */
.filter-container {
    background: var(--pl-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--pl-border);
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--pl-shadow);
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
    color: var(--pl-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--pl-border);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    background: var(--pl-hover);
    color: var(--pl-text);
    transition: all 0.3s ease;
    min-width: 140px;
}

.filter-group input:focus {
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
    background: var(--pl-hover);
    color: var(--pl-text);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--pl-border);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--pl-border);
}

/* Profit Summary */
.profit-summary {
    margin-bottom: 16px;
}

.profit-card {
    background: var(--pl-card-bg);
    border-radius: 12px;
    padding: 24px 30px;
    display: flex;
    align-items: center;
    gap: 24px;
    border: 1px solid var(--pl-border);
    box-shadow: 0 2px 8px var(--pl-shadow);
    transition: all 0.3s ease;
}

.profit-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px var(--pl-shadow-lg);
}

.profit-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    flex-shrink: 0;
}

.profit-positive .profit-icon {
    background: #D1FAE5;
    color: #10B981;
}

.profit-negative .profit-icon {
    background: #FEE2E2;
    color: #DC2626;
}

html.dark-mode .profit-positive .profit-icon {
    background: #065F46;
    color: #10B981;
}

html.dark-mode .profit-negative .profit-icon {
    background: #7F1D1D;
    color: #DC2626;
}

.profit-content {
    flex: 1;
}

.profit-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--pl-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.profit-value {
    font-size: 32px;
    font-weight: 800;
    margin: 4px 0;
}

.profit-positive .profit-value {
    color: #10B981;
}

.profit-negative .profit-value {
    color: #DC2626;
}

.profit-period {
    font-size: 13px;
    color: var(--pl-text-light);
}

/* Breakdown Grid */
.breakdown-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.breakdown-section {
    background: var(--pl-card-bg);
    border-radius: 10px;
    padding: 16px 18px;
    border: 1px solid var(--pl-border);
    box-shadow: 0 1px 3px var(--pl-shadow);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--pl-border);
    margin-bottom: 10px;
}

.section-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--pl-text);
    margin: 0;
}

.section-header h4 i {
    margin-right: 6px;
}

.section-total {
    font-size: 14px;
    font-weight: 700;
    color: var(--pl-text);
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px dashed var(--pl-border);
}

.breakdown-item:last-of-type {
    border-bottom: none;
}

.item-label {
    font-size: 13px;
    color: var(--pl-text-secondary);
}

.item-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--pl-text);
}

.breakdown-total {
    display: flex;
    justify-content: space-between;
    padding-top: 10px;
    margin-top: 8px;
    border-top: 2px solid var(--pl-border);
}

.total-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--pl-text);
}

.total-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--pl-text);
}

.net-positive .section-total,
.net-positive .total-value {
    color: #10B981;
}

.net-negative .section-total,
.net-negative .total-value {
    color: #DC2626;
}

/* Table */
.table-container {
    background: var(--pl-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--pl-shadow);
    border: 1px solid var(--pl-border);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--pl-border);
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--pl-text);
    margin: 0;
}

.table-header h3 i {
    color: #8B5CF6;
    margin-right: 8px;
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
    border-bottom: 1px solid var(--pl-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--pl-hover);
}

.data-table tbody td {
    padding: 8px 12px;
    color: var(--pl-text);
    font-size: 12px;
}

.status-badge {
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.status-profit {
    background: #D1FAE5;
    color: #065F46;
}

.status-loss {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .status-profit {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-loss {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 48px;
    color: var(--pl-text-light);
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 18px;
    color: var(--pl-text);
    margin: 0 0 6px 0;
}

.empty-state p {
    color: var(--pl-text-secondary);
    font-size: 13px;
    margin: 0;
}

/* Responsive */
@media (max-width: 1024px) {
    .breakdown-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .breakdown-grid {
        grid-template-columns: 1fr;
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
    
    .filter-group input {
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
    
    .profit-card {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }
    
    .profit-value {
        font-size: 24px;
    }
}

@media (max-width: 480px) {
    .profit-card {
        padding: 16px;
    }
    
    .profit-icon {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
    
    .profit-value {
        font-size: 20px;
    }
}
</style>

<script>
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