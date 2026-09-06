<?php
// ================================================================
// FILE: modules/reports/monthly.php
// MONTHLY REPORT
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

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$month_start = date('Y-m-01', strtotime($month));
$month_end = date('Y-m-t', strtotime($month));

try {
    // Get monthly summary from daily reports
    $sql = "SELECT 
            COUNT(*) as total_days,
            SUM(morning_total) as total_morning,
            SUM(evening_total) as total_evening,
            SUM(total_commission) as total_commission,
            SUM(other_income) as total_other_income,
            SUM(total_business_income) as total_income,
            SUM(total_expenses) as total_expenses,
            SUM(total_cash_out) as total_cash_out,
            SUM(total_salaries) as total_salaries,
            SUM(net_profit) as total_profit,
            SUM(net_profit_after_salaries) as total_profit_after_salaries,
            MAX(current_capital) as current_capital
            FROM daily_reports
            WHERE report_date BETWEEN ? AND ?";
    $params = [$month_start, $month_end];
    
    if ($role === 'employee') {
        $sql .= " AND employee_id = ?";
        $params[] = $user_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get daily breakdown
    $sql = "SELECT 
            report_date,
            total_commission,
            other_income,
            total_business_income,
            total_expenses,
            net_profit,
            current_capital
            FROM daily_reports
            WHERE report_date BETWEEN ? AND ?
            ORDER BY report_date ASC";
    $params_daily = [$month_start, $month_end];
    
    if ($role === 'employee') {
        $sql .= " AND employee_id = ?";
        $params_daily[] = $user_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params_daily);
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get month's expenses
    $stmt = $db->prepare("SELECT 
            category,
            SUM(amount) as total,
            COUNT(*) as count
            FROM expenses
            WHERE expense_date BETWEEN ? AND ? AND is_business_expense = 1
            GROUP BY category
            ORDER BY total DESC");
    $stmt->execute([$month_start, $month_end]);
    $expense_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get month's transactions
    $stmt = $db->prepare("SELECT 
            SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END) as withdrawals,
            COUNT(*) as total_transactions
            FROM daily_report_transactions
            WHERE DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$month_start, $month_end]);
    $month_transactions = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error generating monthly report: " . $e->getMessage());
    $summary = [
        'total_days' => 0,
        'total_morning' => 0,
        'total_evening' => 0,
        'total_commission' => 0,
        'total_other_income' => 0,
        'total_income' => 0,
        'total_expenses' => 0,
        'total_cash_out' => 0,
        'total_salaries' => 0,
        'total_profit' => 0,
        'total_profit_after_salaries' => 0,
        'current_capital' => 0
    ];
    $daily_data = [];
    $expense_categories = [];
    $month_transactions = ['deposits' => 0, 'withdrawals' => 0, 'total_transactions' => 0];
}

// Get previous month
$prev_month = date('Y-m', strtotime($month . ' -1 month'));
$next_month = date('Y-m', strtotime($month . ' +1 month'));

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-calendar-alt" style="color:#bb0404;"></i> Monthly Report</h2>
                <p class="text-muted">Business summary for <?php echo date('F Y', strtotime($month)); ?></p>
            </div>
            <div class="header-right">
                <a href="?month=<?php echo $prev_month; ?>" class="btn btn-secondary">
                    <i class="fas fa-chevron-left"></i> Previous Month
                </a>
                <a href="?month=<?php echo $next_month; ?>" class="btn btn-secondary">
                    Next Month <i class="fas fa-chevron-right"></i>
                </a>
                <a href="?month=<?php echo date('Y-m'); ?>" class="btn btn-info">
                    <i class="fas fa-calendar"></i> Current
                </a>
                <a href="monthly_print.php?month=<?php echo $month; ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Days</span>
                    <span class="summary-value"><?php echo number_format($summary['total_days'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#D1FAE5;color:#065F46;">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Commission</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_commission'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#FEE2E2;color:#991B1B;">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Expenses</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['total_expenses'] ?? 0); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#EDE9FE;color:#6D28D9;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Net Profit</span>
                    <span class="summary-value <?php echo ($summary['total_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($summary['total_profit'] ?? 0); ?>
                    </span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon" style="background:#FEF3C7;color:#92400E;">
                    <i class="fas fa-building"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Current Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($summary['current_capital'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <!-- Monthly Chart -->
        <div class="chart-card">
            <h4><i class="fas fa-chart-bar" style="color:#bb0404;"></i> Daily Performance</h4>
            <div class="chart-container">
                <div class="chart-grid">
                    <?php 
                    $max_profit = max(array_column($daily_data, 'net_profit')) ?: 1;
                    $max_profit = max(abs($max_profit), 1);
                    foreach ($daily_data as $day): 
                        $profit = floatval($day['net_profit']);
                        $height = ($profit / $max_profit) * 100;
                        $height = min(max($height, 5), 100);
                        $is_positive = $profit >= 0;
                    ?>
                        <div class="chart-column">
                            <div class="chart-bar" style="height: <?php echo abs($height); ?>%; background: <?php echo $is_positive ? '#10B981' : '#DC2626'; ?>;">
                                <?php echo formatCurrency($profit); ?>
                            </div>
                            <div class="chart-label"><?php echo date('d M', strtotime($day['report_date'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($daily_data)): ?>
                        <div class="no-data-chart">No data available for this month</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Detailed Stats -->
        <div class="stats-grid">
            <!-- Income Breakdown -->
            <div class="stats-card">
                <h4><i class="fas fa-arrow-down" style="color:#10B981;"></i> Income</h4>
                <div class="stats-item">
                    <span class="stat-label">Commission</span>
                    <span class="stat-value positive"><?php echo formatCurrency($summary['total_commission'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Other Income</span>
                    <span class="stat-value positive"><?php echo formatCurrency($summary['total_other_income'] ?? 0); ?></span>
                </div>
                <div class="stats-item total">
                    <span class="stat-label">Total Income</span>
                    <span class="stat-value positive"><?php echo formatCurrency($summary['total_income'] ?? 0); ?></span>
                </div>
            </div>

            <!-- Expenses Breakdown -->
            <div class="stats-card">
                <h4><i class="fas fa-arrow-up" style="color:#DC2626;"></i> Expenses</h4>
                <div class="stats-item">
                    <span class="stat-label">Business Expenses</span>
                    <span class="stat-value negative"><?php echo formatCurrency($summary['total_expenses'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Salaries</span>
                    <span class="stat-value negative"><?php echo formatCurrency($summary['total_salaries'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Cash Out</span>
                    <span class="stat-value negative"><?php echo formatCurrency($summary['total_cash_out'] ?? 0); ?></span>
                </div>
                <div class="stats-item total">
                    <span class="stat-label">Total Expenses</span>
                    <span class="stat-value negative"><?php echo formatCurrency(($summary['total_expenses'] ?? 0) + ($summary['total_salaries'] ?? 0) + ($summary['total_cash_out'] ?? 0)); ?></span>
                </div>
            </div>

            <!-- Expense Categories -->
            <div class="stats-card">
                <h4><i class="fas fa-tags" style="color:#bb0404;"></i> Expense Categories</h4>
                <?php if (count($expense_categories) > 0): ?>
                    <?php foreach ($expense_categories as $cat): ?>
                        <div class="stats-item">
                            <span class="stat-label"><?php echo htmlspecialchars($cat['category']); ?></span>
                            <span class="stat-value negative"><?php echo formatCurrency($cat['total']); ?></span>
                            <small class="stat-count">(<?php echo $cat['count']; ?> transactions)</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">No expense data available</p>
                <?php endif; ?>
            </div>

            <!-- Transactions -->
            <div class="stats-card">
                <h4><i class="fas fa-exchange-alt" style="color:#3B82F6;"></i> Transactions</h4>
                <div class="stats-item">
                    <span class="stat-label">Total Transactions</span>
                    <span class="stat-value"><?php echo number_format($month_transactions['total_transactions'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Deposits</span>
                    <span class="stat-value positive"><?php echo formatCurrency($month_transactions['deposits'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Withdrawals</span>
                    <span class="stat-value negative"><?php echo formatCurrency($month_transactions['withdrawals'] ?? 0); ?></span>
                </div>
                <div class="stats-item">
                    <span class="stat-label">Net Flow</span>
                    <span class="stat-value <?php echo (($month_transactions['deposits'] ?? 0) - ($month_transactions['withdrawals'] ?? 0)) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatCurrency(($month_transactions['deposits'] ?? 0) - ($month_transactions['withdrawals'] ?? 0)); ?>
                    </span>
                </div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Summary Cards */
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

.text-success { color: #10B981; }
.text-danger { color: #DC2626; }

/* Chart */
.chart-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.chart-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.chart-container {
    overflow-x: auto;
}

.chart-grid {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    min-height: 200px;
    padding-bottom: 30px;
    position: relative;
}

.chart-column {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 30px;
}

.chart-bar {
    width: 100%;
    min-height: 10px;
    border-radius: 4px 4px 0 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    color: white;
    font-size: 9px;
    font-weight: 600;
    padding: 2px 4px;
    transition: height 0.5s ease;
    min-width: 20px;
}

.chart-label {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 4px;
}

.no-data-chart {
    padding: 40px 0;
    text-align: center;
    color: var(--text-muted);
    width: 100%;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 16px;
}

.stats-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
}

.stats-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}

.stats-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid var(--border-color);
}

.stats-item:last-child {
    border-bottom: none;
}

.stats-item .stat-label {
    font-size: 13px;
    color: var(--text-secondary);
}

.stats-item .stat-value {
    font-size: 14px;
    font-weight: 600;
}

.stats-item .stat-value.positive { color: #10B981; }
.stats-item .stat-value.negative { color: #DC2626; }

.stats-item.total {
    padding-top: 8px;
    border-top: 2px solid var(--border-color);
    font-weight: 700;
}

.stats-item.total .stat-label {
    font-weight: 700;
    color: var(--text-primary);
}

.stats-item .stat-count {
    font-size: 11px;
    color: var(--text-muted);
    margin-left: 8px;
}

.text-muted { color: var(--text-muted); }

/* Buttons */
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
.btn-primary:hover { background: #8a0303; }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; }

/* Page Header */
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

/* Dark Mode */
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
    box-shadow: 0 2px 8px var(--shadow-hover);
}

@media (max-width: 1024px) {
    .summary-cards {
        grid-template-columns: repeat(3, 1fr);
    }
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr 1fr;
    }
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .header-right {
        width: 100%;
        flex-wrap: wrap;
    }
    .header-right .btn {
        flex: 1;
        justify-content: center;
    }
}
</style>

<script>
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