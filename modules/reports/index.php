<?php
// ================================================================
// FILE: modules/reports/index.php
// WAKALA FINANCIAL SYSTEM - REPORTS DASHBOARD
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
$full_name = $_SESSION['full_name'] ?? 'User';

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET REPORT STATISTICS
// ============================================================

// 1. Total Daily Reports
$daily_stmt = $db->query("SELECT COUNT(*) as count, SUM(total_commission) as total_comm, 
                          SUM(total_deposits) as total_dep, SUM(total_withdrawals) as total_wth,
                          SUM(net_profit) as total_profit 
                          FROM daily_reports");
$daily_stats = $daily_stmt->fetch();

// 2. Total Morning Reports
$morning_stmt = $db->query("SELECT COUNT(*) as count, SUM(cumm_total) as total_cumm 
                            FROM morning_reports");
$morning_stats = $morning_stmt->fetch();

// 3. Total Evening Stocks
$evening_stmt = $db->query("SELECT COUNT(*) as count, SUM(cumm_total) as total_cumm 
                            FROM evening_stocks");
$evening_stats = $evening_stmt->fetch();

// 4. Total Commissions
$comm_stmt = $db->query("SELECT COUNT(*) as count, SUM(total_commission) as total_comm 
                         FROM commissions");
$comm_stats = $comm_stmt->fetch();

// 5. Total Expenses
$exp_stmt = $db->query("SELECT COUNT(*) as count, SUM(amount) as total_exp 
                        FROM expenses WHERE is_business_expense = 1");
$exp_stats = $exp_stmt->fetch();

// 6. Total Salaries
$salary_stmt = $db->query("SELECT COUNT(*) as count, SUM(net_pay) as total_salaries 
                           FROM employee_salaries WHERE status = 'paid'");
$salary_stats = $salary_stmt->fetch();

// 7. Get recent reports (last 7 days)
$recent_stmt = $db->prepare("SELECT 
    'Daily Report' as type,
    report_number as number,
    report_date as date,
    total_commission as amount,
    'daily' as icon
    FROM daily_reports 
    WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY report_date DESC LIMIT 5");
$recent_stmt->execute();
$recent_reports = $recent_stmt->fetchAll();

// 8. Get monthly trends (last 6 months)
$monthly_stmt = $db->prepare("SELECT 
    DATE_FORMAT(report_date, '%Y-%m') as month,
    SUM(total_commission) as commission,
    SUM(total_deposits) as deposits,
    SUM(total_withdrawals) as withdrawals,
    SUM(net_profit) as profit
    FROM daily_reports 
    WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(report_date, '%Y-%m')
    ORDER BY month ASC");
$monthly_stmt->execute();
$monthly_data = $monthly_stmt->fetchAll();

// Calculate totals
$total_reports = ($daily_stats['count'] ?? 0) + ($morning_stats['count'] ?? 0) + ($evening_stats['count'] ?? 0);
$total_commission = floatval($daily_stats['total_comm'] ?? 0) + floatval($comm_stats['total_comm'] ?? 0);
$total_profit = floatval($daily_stats['total_profit'] ?? 0);
$total_expenses = floatval($exp_stats['total_exp'] ?? 0) + floatval($salary_stats['total_salaries'] ?? 0);
$total_deposits = floatval($daily_stats['total_dep'] ?? 0);
$total_withdrawals = floatval($daily_stats['total_wth'] ?? 0);
$net_profit = $total_profit - $total_expenses;

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
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
                <h2><i class="fas fa-chart-pie"></i> Reports Dashboard</h2>
                <span class="record-count">Overview</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="export.php" class="btn btn-export">
                        <i class="fas fa-file-export"></i> Export All
                    </a>
                    <button class="btn btn-refresh" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - 6 CARDS
        ============================================================ -->
        <div class="summaries-grid-six">
            <!-- Total Reports -->
            <div class="summary-card card-reports">
                <div class="summary-icon"><i class="fas fa-file-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Reports</div>
                    <div class="summary-value"><?php echo number_format($total_reports); ?></div>
                    <div class="summary-sub">All Reports</div>
                </div>
            </div>

            <!-- Total Commission -->
            <div class="summary-card card-commission">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Commission</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_commission); ?></div>
                    <div class="summary-sub">All Commissions</div>
                </div>
            </div>

            <!-- Total Deposits -->
            <div class="summary-card card-deposits">
                <div class="summary-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Deposits</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_deposits); ?></div>
                    <div class="summary-sub">All Deposits</div>
                </div>
            </div>

            <!-- Total Withdrawals -->
            <div class="summary-card card-withdrawals">
                <div class="summary-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Withdrawals</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_withdrawals); ?></div>
                    <div class="summary-sub">All Withdrawals</div>
                </div>
            </div>

            <!-- Total Expenses -->
            <div class="summary-card card-expenses">
                <div class="summary-icon"><i class="fas fa-receipt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Expenses</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_expenses); ?></div>
                    <div class="summary-sub">All Expenses</div>
                </div>
            </div>

            <!-- Net Profit -->
            <div class="summary-card card-profit">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Net Profit</div>
                    <div class="summary-value" style="color: <?php echo $net_profit >= 0 ? '#10B981' : '#DC2626'; ?>;">
                        <?php echo formatCurrencyShort($net_profit); ?>
                    </div>
                    <div class="summary-sub"><?php echo $net_profit >= 0 ? 'Profit' : 'Loss'; ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        QUICK LINKS - REPORT TYPES
        ============================================================ -->
        <div class="quick-links-section">
            <h3><i class="fas fa-rocket"></i> Generate Reports</h3>
            <div class="quick-links-grid">
                <a href="daily.php" class="quick-link">
                    <div class="ql-icon" style="background:#DBEAFE;color:#1D4ED8;">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Daily Report</h4>
                        <p>View daily transactions summary</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>

                <a href="monthly.php" class="quick-link">
                    <div class="ql-icon" style="background:#D1FAE5;color:#065F46;">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Monthly Report</h4>
                        <p>View monthly performance summary</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>

                <a href="commission.php" class="quick-link">
                    <div class="ql-icon" style="background:#FEF3C7;color:#D97706;">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Commission Report</h4>
                        <p>View commission breakdown</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>

                <a href="expense.php" class="quick-link">
                    <div class="ql-icon" style="background:#FEE2E2;color:#DC2626;">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Expense Report</h4>
                        <p>View expense breakdown</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>

                <a href="profit.php" class="quick-link">
                    <div class="ql-icon" style="background:#D1FAE5;color:#10B981;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Profit & Loss Report</h4>
                        <p>View profit and loss statement</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>

                <a href="export.php" class="quick-link">
                    <div class="ql-icon" style="background:#E0E7FF;color:#4F46E5;">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <div class="ql-info">
                        <h4>Export Data</h4>
                        <p>Export reports in various formats</p>
                    </div>
                    <i class="fas fa-chevron-right ql-arrow"></i>
                </a>
            </div>
        </div>

        <!-- ============================================================
        RECENT REPORTS
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-clock"></i> Recent Reports (Last 7 Days)</h3>
                <a href="daily.php" class="view-all-link">View All →</a>
            </div>

            <?php if (empty($recent_reports)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-file-alt"></i>
                    <p>No recent reports found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Report Number</th>
                                <th>Date</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_reports as $report): ?>
                                <tr>
                                    <td>
                                        <span class="report-type">
                                            <i class="fas <?php echo $report['icon']; ?>"></i>
                                            <?php echo $report['type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="report-number">
                                            <?php echo htmlspecialchars($report['number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo formatDate($report['date']); ?>
                                    </td>
                                    <td>
                                        <span class="report-amount">
                                            <?php echo formatCurrency($report['amount']); ?>
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
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --reports-bg: #FFFFFF;
    --reports-text: #1F2937;
    --reports-text-secondary: #6B7280;
    --reports-text-light: #9CA3AF;
    --reports-border: #E5E7EB;
    --reports-card-bg: #FFFFFF;
    --reports-hover: #F3F4F6;
    --reports-shadow: rgba(0,0,0,0.06);
    --reports-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --reports-bg: #1F2937;
    --reports-text: #F9FAFB;
    --reports-text-secondary: #9CA3AF;
    --reports-text-light: #6B7280;
    --reports-border: #374151;
    --reports-card-bg: #1F2937;
    --reports-hover: #374151;
    --reports-shadow: rgba(0,0,0,0.3);
    --reports-shadow-lg: rgba(0,0,0,0.4);
}

body {
    background: var(--reports-bg) !important;
    color: var(--reports-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--reports-bg) !important; }
.main-content { background: var(--reports-bg) !important; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
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
    color: var(--reports-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--reports-text-secondary);
    background: var(--reports-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.header-actions {
    display: flex;
    gap: 10px;
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
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    color: white;
}

.btn-refresh {
    background: var(--reports-hover);
    color: var(--reports-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--reports-border);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-refresh:hover {
    background: var(--reports-border);
}

/* ============================================================
   SUMMARIES GRID - 6 CARDS
   ============================================================ */
.summaries-grid-six {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--reports-card-bg);
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--reports-shadow);
    border: 1px solid var(--reports-border);
    transition: all 0.3s ease;
    min-height: 100px;
    height: 100px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--reports-shadow-lg);
}

.summary-icon {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.summary-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--reports-text-secondary);
}

.summary-value {
    font-size: 18px;
    font-weight: 800;
    color: var(--reports-text);
    margin: 3px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-sub {
    font-size: 10px;
    color: var(--reports-text-light);
    font-weight: 500;
}

/* Card Colors */
.card-reports .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-reports { border-left: 4px solid #3B82F6; }

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

/* ============================================================
   QUICK LINKS SECTION
   ============================================================ */
.quick-links-section {
    margin-bottom: 18px;
}

.quick-links-section h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--reports-text);
    margin: 0 0 12px 0;
}

.quick-links-section h3 i {
    color: #3B82F6;
    margin-right: 8px;
}

.quick-links-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.quick-link {
    background: var(--reports-card-bg);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    border: 1px solid var(--reports-border);
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px var(--reports-shadow);
}

.quick-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px var(--reports-shadow-lg);
    border-color: #DC2626;
}

.ql-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.ql-info {
    flex: 1;
}

.ql-info h4 {
    font-size: 13px;
    font-weight: 600;
    color: var(--reports-text);
    margin: 0 0 2px 0;
}

.ql-info p {
    font-size: 11px;
    color: var(--reports-text-secondary);
    margin: 0;
}

.ql-arrow {
    color: var(--reports-text-light);
    font-size: 14px;
    transition: all 0.3s ease;
}

.quick-link:hover .ql-arrow {
    transform: translateX(4px);
    color: #DC2626;
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--reports-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--reports-shadow);
    border: 1px solid var(--reports-border);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--reports-border);
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--reports-text);
    margin: 0;
}

.table-header h3 i {
    color: #3B82F6;
    margin-right: 8px;
}

.view-all-link {
    font-size: 12px;
    font-weight: 600;
    color: #DC2626;
    text-decoration: none;
    transition: all 0.3s ease;
}

.view-all-link:hover {
    color: #B91C1C;
    text-decoration: underline;
}

.empty-state-small {
    text-align: center;
    padding: 30px 20px;
}

.empty-state-small i {
    font-size: 32px;
    color: var(--reports-text-light);
    display: block;
    margin-bottom: 8px;
}

.empty-state-small p {
    color: var(--reports-text-secondary);
    font-size: 13px;
    margin: 0;
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 10px 16px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--reports-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--reports-hover);
}

.data-table tbody td {
    padding: 10px 16px;
    color: var(--reports-text);
}

.report-type {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.report-type i {
    color: #3B82F6;
}

.report-number {
    font-weight: 600;
    color: #3B82F6;
}

.report-amount {
    font-weight: 600;
    color: #DC2626;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summaries-grid-six {
        grid-template-columns: repeat(3, 1fr);
    }
    .quick-links-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .summaries-grid-six {
        grid-template-columns: repeat(2, 1fr);
    }
    .quick-links-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .header-actions .btn-export,
    .header-actions .btn-refresh {
        flex: 1;
        justify-content: center;
    }
    
    .summary-card {
        min-height: 90px;
        height: 90px;
        padding: 12px 14px;
    }
    
    .summary-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .summary-value {
        font-size: 16px;
    }
    
    .quick-link {
        padding: 12px 14px;
    }
    
    .ql-icon {
        width: 38px;
        height: 38px;
        font-size: 16px;
    }
    
    .ql-info h4 {
        font-size: 12px;
    }
    
    .ql-info p {
        font-size: 10px;
    }
}

@media (max-width: 480px) {
    .summaries-grid-six {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .quick-links-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-card {
        padding: 10px 12px;
        min-height: 80px;
        height: 80px;
    }
    
    .summary-icon {
        width: 34px;
        height: 34px;
        font-size: 14px;
    }
    
    .summary-value {
        font-size: 14px;
    }
    
    .summary-label {
        font-size: 9px;
    }
    
    .summary-sub {
        font-size: 9px;
    }
    
    .data-table thead th,
    .data-table tbody td {
        padding: 6px 10px;
        font-size: 11px;
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
    animation: fadeInUp 0.4s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.05s; }
.summary-card:nth-child(2) { animation-delay: 0.10s; }
.summary-card:nth-child(3) { animation-delay: 0.15s; }
.summary-card:nth-child(4) { animation-delay: 0.20s; }
.summary-card:nth-child(5) { animation-delay: 0.25s; }
.summary-card:nth-child(6) { animation-delay: 0.30s; }

.quick-links-section {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.35s;
}

.table-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.40s;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
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