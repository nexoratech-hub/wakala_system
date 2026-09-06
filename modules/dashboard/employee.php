<?php
// ================================================================
// FILE: modules/dashboard/employee.php
// WAKALA SYSTEM - EMPLOYEE DASHBOARD
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

$user_id = $_SESSION['user_id'];

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ../../login.php');
    exit();
}

// ============================================================
// GET EMPLOYEE STATISTICS
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// Morning Reports count this month
$stmt = $db->prepare("SELECT COUNT(*) as count FROM morning_reports WHERE employee_id = ? AND MONTH(report_date) = ? AND YEAR(report_date) = ?");
$stmt->execute([$user_id, $month, $year]);
$morning_count = $stmt->fetch()['count'] ?? 0;

// Evening Stocks count this month
$stmt = $db->prepare("SELECT COUNT(*) as count FROM evening_stocks WHERE employee_id = ? AND MONTH(stock_date) = ? AND YEAR(stock_date) = ?");
$stmt->execute([$user_id, $month, $year]);
$evening_count = $stmt->fetch()['count'] ?? 0;

// Commissions total this month
$stmt = $db->prepare("SELECT SUM(total_commission) as total FROM commissions WHERE employee_id = ? AND MONTH(commission_date) = ? AND YEAR(commission_date) = ?");
$stmt->execute([$user_id, $month, $year]);
$commission_total = $stmt->fetch()['total'] ?? 0;

// Expenses total this month
$stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE employee_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$user_id, $month, $year]);
$expense_total = $stmt->fetch()['total'] ?? 0;

// Today's Morning Report
$stmt = $db->prepare("SELECT * FROM morning_reports WHERE employee_id = ? AND report_date = ?");
$stmt->execute([$user_id, $today]);
$today_morning = $stmt->fetch();

// Today's Evening Stock
$stmt = $db->prepare("SELECT * FROM evening_stocks WHERE employee_id = ? AND stock_date = ?");
$stmt->execute([$user_id, $today]);
$today_evening = $stmt->fetch();

// ============================================================
// INCLUDE EMPLOYEE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="employee-wrapper">
    <div class="employee-content">
        
        <!-- Welcome Section -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>! 👋</h2>
                <p>Here's your activity summary for <?php echo date('F Y'); ?></p>
            </div>
            <div class="welcome-date">
                <span class="day"><?php echo date('d'); ?></span>
                <span class="month"><?php echo date('M Y'); ?></span>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="../morning_report/add.php" class="btn btn-morning"><i class="fas fa-sun"></i> Morning Report</a>
            <a href="../evening_stock/add.php" class="btn btn-evening"><i class="fas fa-moon"></i> Evening Stock</a>
            <a href="../commissions/add.php" class="btn btn-commission"><i class="fas fa-hand-holding-usd"></i> Commission</a>
            <a href="../expenses/add.php" class="btn btn-expense"><i class="fas fa-receipt"></i> Expense</a>
            <a href="../store_cash_out/add.php" class="btn btn-cashout"><i class="fas fa-money-bill-wave"></i> Cash Out</a>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card card-morning">
                <div class="card-icon"><i class="fas fa-sun"></i></div>
                <div class="card-info">
                    <span class="card-label">Morning Reports</span>
                    <span class="card-value"><?php echo $morning_count; ?></span>
                    <span class="card-sub">This month</span>
                </div>
            </div>
            <div class="summary-card card-evening">
                <div class="card-icon"><i class="fas fa-moon"></i></div>
                <div class="card-info">
                    <span class="card-label">Evening Stocks</span>
                    <span class="card-value"><?php echo $evening_count; ?></span>
                    <span class="card-sub">This month</span>
                </div>
            </div>
            <div class="summary-card card-commission">
                <div class="card-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="card-info">
                    <span class="card-label">Commissions</span>
                    <span class="card-value"><?php echo formatCurrency($commission_total); ?></span>
                    <span class="card-sub">This month</span>
                </div>
            </div>
            <div class="summary-card card-expense">
                <div class="card-icon"><i class="fas fa-receipt"></i></div>
                <div class="card-info">
                    <span class="card-label">Expenses</span>
                    <span class="card-value"><?php echo formatCurrency($expense_total); ?></span>
                    <span class="card-sub">This month</span>
                </div>
            </div>
        </div>

        <!-- Today's Reports -->
        <div class="today-reports">
            <div class="report-card morning">
                <div class="report-header">
                    <h4><i class="fas fa-sun" style="color:#F59E0B;"></i> Today's Morning Report</h4>
                    <span class="status <?php echo $today_morning ? 'submitted' : 'pending'; ?>">
                        <?php echo $today_morning ? '✅ Submitted' : '⏳ Pending'; ?>
                    </span>
                </div>
                <?php if ($today_morning): ?>
                    <div class="report-body">
                        <div><span>Report No.</span> <strong><?php echo htmlspecialchars($today_morning['report_number']); ?></strong></div>
                        <div><span>Float</span> <strong><?php echo formatCurrency($today_morning['cumm_total']); ?></strong></div>
                        <div><span>Cash</span> <strong><?php echo formatCurrency($today_morning['cash_balance']); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div class="report-empty">
                        <p>No morning report submitted today</p>
                        <a href="../morning_report/add.php" class="btn btn-morning btn-sm">Submit Now</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="report-card evening">
                <div class="report-header">
                    <h4><i class="fas fa-moon" style="color:#3B82F6;"></i> Today's Evening Stock</h4>
                    <span class="status <?php echo $today_evening ? 'submitted' : 'pending'; ?>">
                        <?php echo $today_evening ? '✅ Submitted' : '⏳ Pending'; ?>
                    </span>
                </div>
                <?php if ($today_evening): ?>
                    <div class="report-body">
                        <div><span>Stock No.</span> <strong><?php echo htmlspecialchars($today_evening['stock_number']); ?></strong></div>
                        <div><span>Float</span> <strong><?php echo formatCurrency($today_evening['cumm_total']); ?></strong></div>
                        <div><span>Cash</span> <strong><?php echo formatCurrency($today_evening['cash_balance']); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div class="report-empty">
                        <p>No evening stock submitted today</p>
                        <a href="../evening_stock/add.php" class="btn btn-evening btn-sm">Submit Now</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    
    <!-- Footer -->
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   EMPLOYEE DASHBOARD STYLES
   ============================================================ */

/* Welcome Card */
.welcome-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 14px 20px;
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: background 0.3s ease, border-color 0.3s ease;
}

body.dark-mode .welcome-card {
    background: #1e293b;
    border-color: #334155;
}

.welcome-text h2 {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

body.dark-mode .welcome-text h2 {
    color: #f1f5f9;
}

.welcome-text p {
    font-size: 12px;
    color: #6b7280;
    margin: 2px 0 0 0;
}

body.dark-mode .welcome-text p {
    color: #94a3b8;
}

.welcome-date {
    text-align: center;
    background: #bb0404;
    color: white;
    padding: 4px 12px;
    border-radius: 8px;
    min-width: 50px;
}

.welcome-date .day {
    display: block;
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
}

.welcome-date .month {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Quick Actions */
.quick-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.btn {
    padding: 7px 14px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Inter', sans-serif;
}

.btn-morning { background: #F59E0B; color: white; }
.btn-morning:hover { background: #D97706; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(245,158,11,0.3); }

.btn-evening { background: #3B82F6; color: white; }
.btn-evening:hover { background: #2563EB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }

.btn-commission { background: #10B981; color: white; }
.btn-commission:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

.btn-expense { background: #DC2626; color: white; }
.btn-expense:hover { background: #B91C1C; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,38,38,0.3); }

.btn-cashout { background: #7F1D1D; color: white; }
.btn-cashout:hover { background: #5C1313; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(127,29,29,0.3); }

.btn-sm { padding: 4px 10px; font-size: 10px; }

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 14px;
}

.summary-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: background 0.3s ease, border-color 0.3s ease;
}

body.dark-mode .summary-card {
    background: #1e293b;
    border-color: #334155;
}

.summary-card .card-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.card-morning .card-icon { background: #FEF3C7; color: #D97706; }
.card-morning { border-left: 4px solid #F59E0B; }

.card-evening .card-icon { background: #DBEAFE; color: #1D4ED8; }
.card-evening { border-left: 4px solid #3B82F6; }

.card-commission .card-icon { background: #D1FAE5; color: #065F46; }
.card-commission { border-left: 4px solid #10B981; }

.card-expense .card-icon { background: #FEE2E2; color: #991B1B; }
.card-expense { border-left: 4px solid #DC2626; }

.summary-card .card-info {
    flex: 1;
}

.summary-card .card-label {
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 600;
    color: #6b7280;
}

body.dark-mode .summary-card .card-label {
    color: #94a3b8;
}

.summary-card .card-value {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    display: block;
}

body.dark-mode .summary-card .card-value {
    color: #f1f5f9;
}

.summary-card .card-sub {
    font-size: 10px;
    color: #9ca3af;
}

body.dark-mode .summary-card .card-sub {
    color: #64748b;
}

/* Today's Reports */
.today-reports {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.report-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: background 0.3s ease, border-color 0.3s ease;
}

body.dark-mode .report-card {
    background: #1e293b;
    border-color: #334155;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.report-header h4 {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

body.dark-mode .report-header h4 {
    color: #f1f5f9;
}

.report-header .status {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
}

.report-header .status.submitted {
    background: #D1FAE5;
    color: #065F46;
}

.report-header .status.pending {
    background: #FEF3C7;
    color: #92400E;
}

.report-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
}

.report-body div {
    font-size: 11px;
    color: #6b7280;
}

body.dark-mode .report-body div {
    color: #94a3b8;
}

.report-body div strong {
    color: #1f2937;
    display: block;
    font-size: 13px;
}

body.dark-mode .report-body div strong {
    color: #f1f5f9;
}

.report-empty {
    text-align: center;
    padding: 10px 0;
}

.report-empty p {
    font-size: 12px;
    color: #6b7280;
    margin: 0 0 8px 0;
}

body.dark-mode .report-empty p {
    color: #94a3b8;
}

/* Responsive */
@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr 1fr;
    }
    
    .today-reports {
        grid-template-columns: 1fr;
    }
    
    .quick-actions {
        flex-direction: column;
    }
    
    .quick-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .welcome-card {
        flex-direction: column;
        text-align: center;
        gap: 8px;
    }
}

@media (max-width: 480px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .report-body {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// ============================================================
// AUTO-REFRESH TODAY'S REPORTS (Every 30 seconds)
// ============================================================
setInterval(function() {
    location.reload();
}, 30000);
</script>

</body>
</html>