<?php
// ================================================================
// FILE: modules/daily_report/view.php
// VIEW DAILY REPORT DETAILS
// ✅ NEW: Shows providers breakdown from daily_report_providers
// ✅ NEW: Shows transactions from daily_report_transactions
// ✅ FIXED: Dark mode uses html.dark-mode
// ================================================================

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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT WITH JOINS
// ============================================================
try {
    $stmt = $db->prepare("SELECT dr.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            p.provider_name,
            p.provider_code,
            mr.report_number as morning_report_number,
            mr.report_date as morning_report_date,
            es.stock_number as evening_stock_number,
            es.stock_date as evening_stock_date,
            c.commission_number as commission_number,
            c.commission_date as commission_date
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN providers p ON dr.provider_id = p.id
            LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
            LEFT JOIN evening_stocks es ON dr.evening_stock_id = es.id
            LEFT JOIN commissions c ON dr.commission_id = c.id
            WHERE dr.id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching report: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$report) {
    header('Location: index.php');
    exit();
}

// ============================================================
// ✅ GET PROVIDERS BREAKDOWN
// ============================================================
$providers = [];
try {
    $stmt = $db->prepare("
        SELECT 
            drp.*,
            p.provider_name as provider_full_name,
            p.provider_type,
            p.icon_class,
            p.color_code
        FROM daily_report_providers drp
        LEFT JOIN providers p ON drp.provider_id = p.id
        WHERE drp.daily_report_id = ?
        ORDER BY drp.provider_name
    ");
    $stmt->execute([$id]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching providers: " . $e->getMessage());
}

// ============================================================
// ✅ GET TRANSACTIONS
// ============================================================
$transactions = [];
try {
    $stmt = $db->prepare("
        SELECT 
            drt.*,
            p.provider_name as provider_full_name,
            p.icon_class,
            p.color_code
        FROM daily_report_transactions drt
        LEFT JOIN providers p ON drt.provider_id = p.id
        WHERE drt.daily_report_id = ?
        ORDER BY drt.transaction_date DESC, drt.id DESC
    ");
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// ============================================================
// CALCULATE PROVIDER TOTALS
// ============================================================
$providers_total_float = 0;
$providers_total_cash = 0;
$providers_total_deposits = 0;
$providers_total_withdrawals = 0;

foreach ($providers as $p) {
    $providers_total_float += floatval($p['morning_float'] ?? 0);
    $providers_total_cash += floatval($p['morning_cash'] ?? 0);
    $providers_total_deposits += floatval($p['total_deposits'] ?? 0);
    $providers_total_withdrawals += floatval($p['total_withdrawals'] ?? 0);
}

$providers_capital = $providers_total_float + $providers_total_cash;

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH CARD
        ============================================================ -->
        <div class="branch-card">
            <div class="branch-card-left">
                <div class="branch-icon">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-label">Branch</span>
                    <span class="branch-name"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></span>
                    <?php if ($report['branch_code']): ?>
                        <span class="branch-code"><?php echo htmlspecialchars($report['branch_code']); ?></span>
                    <?php endif; ?>
                    <?php if ($report['branch_location']): ?>
                        <span class="branch-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($report['branch_location']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?branch_id=<?php echo $report['branch_id']; ?>" class="branch-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Report Details</h2>
                <p class="text-muted">
                    Report: <strong><?php echo htmlspecialchars($report['report_number']); ?></strong>
                    • Date: <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="#" class="btn btn-info" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="index.php?branch_id=<?php echo $report['branch_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Close
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS
        ============================================================ -->
        <div class="summary-cards">
            <div class="summary-card card-float">
                <div class="summary-icon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Float</span>
                    <span class="summary-value"><?php echo formatCurrency($providers_total_float); ?></span>
                    <span class="summary-sub">From <?php echo count($providers); ?> providers</span>
                </div>
            </div>
            
            <div class="summary-card card-cash">
                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Cash</span>
                    <span class="summary-value"><?php echo formatCurrency($providers_total_cash); ?></span>
                    <span class="summary-sub">Cash balance</span>
                </div>
            </div>
            
            <div class="summary-card card-capital">
                <div class="summary-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($providers_capital); ?></span>
                    <span class="summary-sub">Float + Cash</span>
                </div>
            </div>
            
            <div class="summary-card card-transactions">
                <div class="summary-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Transactions</span>
                    <span class="summary-value"><?php echo count($transactions); ?></span>
                    <span class="summary-sub">Today's activity</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        PROVIDERS BREAKDOWN
        ============================================================ -->
        <?php if (!empty($providers)): ?>
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-university"></i>
                    Providers Breakdown
                    <span class="section-count"><?php echo count($providers); ?> providers</span>
                </h3>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table providers-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Provider</th>
                            <th>Code</th>
                            <th class="text-right">Morning Float</th>
                            <th class="text-right">Current Float</th>
                            <th class="text-right">Deposits</th>
                            <th class="text-right">Withdrawals</th>
                            <th class="text-right">Net Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        foreach ($providers as $p): 
                            $net_change = floatval($p['total_deposits'] ?? 0) - floatval($p['total_withdrawals'] ?? 0);
                            $provider_color = $p['color_code'] ?? '#0B5ED7';
                            $provider_icon = $p['icon_class'] ?? 'fas fa-university';
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                            <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                        </div>
                                        <span class="provider-name"><strong><?php echo htmlspecialchars($p['provider_name']); ?></strong></span>
                                    </div>
                                </td>
                                <td><span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span></td>
                                <td class="text-right text-float"><?php echo formatCurrency($p['morning_float'] ?? 0); ?></td>
                                <td class="text-right text-float"><strong><?php echo formatCurrency($p['current_float'] ?? 0); ?></strong></td>
                                <td class="text-right text-success"><?php echo formatCurrency($p['total_deposits'] ?? 0); ?></td>
                                <td class="text-right text-danger"><?php echo formatCurrency($p['total_withdrawals'] ?? 0); ?></td>
                                <td class="text-right <?php echo $net_change >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <strong><?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="totals-row">
                            <td colspan="3"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong class="text-float"><?php echo formatCurrency($providers_total_float); ?></strong></td>
                            <td class="text-right"><strong class="text-float"><?php echo formatCurrency($providers_total_float); ?></strong></td>
                            <td class="text-right"><strong class="text-success"><?php echo formatCurrency($providers_total_deposits); ?></strong></td>
                            <td class="text-right"><strong class="text-danger"><?php echo formatCurrency($providers_total_withdrawals); ?></strong></td>
                            <td class="text-right">
                                <strong class="<?php echo ($providers_total_deposits - $providers_total_withdrawals) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($providers_total_deposits - $providers_total_withdrawals); ?>
                                </strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        TRANSACTIONS BREAKDOWN
        ============================================================ -->
        <?php if (!empty($transactions)): ?>
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-exchange-alt"></i>
                    Transactions
                    <span class="section-count"><?php echo count($transactions); ?> transactions</span>
                </h3>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table transactions-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Provider</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Reference</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $j = 1;
                        foreach ($transactions as $t): 
                            $is_deposit = ($t['transaction_type'] === 'deposit');
                            $type_class = $is_deposit ? 'type-deposit' : 'type-withdrawal';
                            $type_icon = $is_deposit ? 'fa-arrow-down' : 'fa-arrow-up';
                        ?>
                            <tr>
                                <td><?php echo $j++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($t['color_code'] ?? '#0B5ED7'); ?>;">
                                            <i class="<?php echo htmlspecialchars($t['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <span><?php echo htmlspecialchars($t['provider_full_name'] ?? $t['provider_code']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo $type_class; ?>">
                                        <i class="fas <?php echo $type_icon; ?>"></i>
                                        <?php echo ucfirst($t['transaction_type']); ?>
                                    </span>
                                </td>
                                <td class="text-right <?php echo $is_deposit ? 'text-success' : 'text-danger'; ?>">
                                    <strong><?php echo ($is_deposit ? '+' : '-') . formatCurrency($t['amount']); ?></strong>
                                </td>
                                <td>
                                    <span class="ref-badge"><?php echo htmlspecialchars($t['reference_number'] ?? '-'); ?></span>
                                </td>
                                <td>
                                    <span class="time-display">
                                        <?php echo $t['transaction_time'] ? date('h:i A', strtotime($t['transaction_time'])) : '-'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        BASIC INFORMATION
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Report Number</span>
                    <span class="info-value"><strong><?php echo htmlspecialchars($report['report_number']); ?></strong></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Report Date</span>
                    <span class="info-value"><?php echo date('d M Y', strtotime($report['report_date'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Branch</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Provider</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['provider_name'] ?? $report['provider_code'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created By</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created At</span>
                    <span class="info-value"><?php echo date('d M Y H:i', strtotime($report['created_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        REFERENCES
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-link"></i> References</h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Morning Report</span>
                    <span class="info-value">
                        <?php if ($report['morning_report_number']): ?>
                            <a href="../morning_report/view.php?id=<?php echo $report['morning_report_id']; ?>" class="link">
                                <?php echo htmlspecialchars($report['morning_report_number']); ?>
                            </a>
                            <span class="date-badge"><?php echo date('d M Y', strtotime($report['morning_report_date'])); ?></span>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Evening Stock</span>
                    <span class="info-value">
                        <?php if ($report['evening_stock_number']): ?>
                            <a href="../evening_stock/view.php?id=<?php echo $report['evening_stock_id']; ?>" class="link">
                                <?php echo htmlspecialchars($report['evening_stock_number']); ?>
                            </a>
                            <span class="date-badge"><?php echo date('d M Y', strtotime($report['evening_stock_date'])); ?></span>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Commission</span>
                    <span class="info-value">
                        <?php if ($report['commission_number']): ?>
                            <a href="../commissions/view.php?id=<?php echo $report['commission_id']; ?>" class="link">
                                <?php echo htmlspecialchars($report['commission_number']); ?>
                            </a>
                            <span class="date-badge"><?php echo date('d M Y', strtotime($report['commission_date'])); ?></span>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FINANCIAL SUMMARY
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-coins"></i> Financial Summary</h3>
            </div>
            <div class="financial-grid">
                <div class="financial-item">
                    <span class="financial-label">Morning Total (Float)</span>
                    <span class="financial-value text-float"><?php echo formatCurrency($report['morning_total']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Evening Total</span>
                    <span class="financial-value"><?php echo formatCurrency($report['evening_total']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Float Difference</span>
                    <span class="financial-value <?php echo ($report['float_difference'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($report['float_difference']); ?>
                    </span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Commission</span>
                    <span class="financial-value text-success"><?php echo formatCurrency($report['total_commission']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Other Income</span>
                    <span class="financial-value text-success"><?php echo formatCurrency($report['other_income']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Business Income</span>
                    <span class="financial-value text-success"><?php echo formatCurrency($report['total_business_income']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Deposits</span>
                    <span class="financial-value text-success"><?php echo formatCurrency($report['total_deposits']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Withdrawals</span>
                    <span class="financial-value text-danger"><?php echo formatCurrency($report['total_withdrawals']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Current Float</span>
                    <span class="financial-value text-float"><?php echo formatCurrency($report['current_float']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Current Cash</span>
                    <span class="financial-value text-cash"><?php echo formatCurrency($report['current_cash']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Expenses</span>
                    <span class="financial-value text-danger"><?php echo formatCurrency($report['total_expenses']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Cash Out</span>
                    <span class="financial-value text-danger"><?php echo formatCurrency($report['total_cash_out']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Salaries</span>
                    <span class="financial-value text-danger"><?php echo formatCurrency($report['total_salaries']); ?></span>
                </div>
                <div class="financial-item highlight">
                    <span class="financial-label">Net Profit</span>
                    <span class="financial-value <?php echo ($report['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($report['net_profit']); ?>
                    </span>
                </div>
                <div class="financial-item highlight">
                    <span class="financial-label">Net Profit After Salaries</span>
                    <span class="financial-value <?php echo ($report['net_profit_after_salaries'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($report['net_profit_after_salaries']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        CAPITAL SUMMARY
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-building"></i> Capital Summary</h3>
            </div>
            <div class="capital-summary">
                <div class="capital-item">
                    <span class="capital-label">Opening Capital</span>
                    <span class="capital-value"><?php echo formatCurrency($report['opening_capital']); ?></span>
                </div>
                <div class="capital-item">
                    <span class="capital-label">Additional Capital</span>
                    <span class="capital-value text-success"><?php echo formatCurrency($report['additional_capital']); ?></span>
                </div>
                <div class="capital-item">
                    <span class="capital-label">Profit Allocated</span>
                    <span class="capital-value text-success"><?php echo formatCurrency($report['profit_allocated']); ?></span>
                </div>
                <div class="capital-item highlight">
                    <span class="capital-label">Current Capital</span>
                    <span class="capital-value capital-value-lg"><?php echo formatCurrency($report['current_capital']); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        NOTES
        ============================================================ -->
        <?php if (!empty($report['notes'])): ?>
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-sticky-note"></i> Notes</h3>
            </div>
            <div class="notes-content">
                <?php echo nl2br(htmlspecialchars($report['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
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
    --shadow-hover: rgba(0,0,0,0.12);
}

html.dark-mode {
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
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
}
.main-wrapper { background: var(--bg-body) !important; }
.main-content { background: var(--bg-body) !important; }

/* Branch Card */
.branch-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap;
    gap: 10px;
    color: #FFFFFF;
}
.branch-card-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.branch-icon {
    width: 38px;
    height: 38px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.branch-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.branch-label {
    font-size: 10px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.branch-name {
    font-weight: 700;
    font-size: 14px;
}
.branch-code {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
}
.branch-location {
    font-size: 11px;
    opacity: 0.85;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.branch-back {
    background: rgba(255,255,255,0.15);
    color: #FFFFFF;
    text-decoration: none;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}
.branch-back:hover {
    background: rgba(255,255,255,0.25);
    color: #FFFFFF;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}
.page-header .header-left .text-muted {
    font-size: 12px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}
.header-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
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
    white-space: nowrap;
}
.btn-warning { background: #F59E0B; color: white; }
.btn-warning:hover { background: #D97706; color: white; transform: translateY(-1px); }
.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; color: white; transform: translateY(-1px); }
.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); color: var(--text-primary); }

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}
.summary-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.summary-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px var(--shadow-hover);
}
.card-float::before { background: #3B82F6; }
.card-cash::before { background: #10B981; }
.card-capital::before { background: #7C3AED; }
.card-transactions::before { background: #F59E0B; }

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
.card-float .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-cash .summary-icon { background: #D1FAE5; color: #059669; }
.card-capital .summary-icon { background: #EDE9FE; color: #7C3AED; }
.card-transactions .summary-icon { background: #FEF3C7; color: #D97706; }

html.dark-mode .card-float .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-cash .summary-icon { background: #065F46; color: #34D399; }
html.dark-mode .card-capital .summary-icon { background: #4C1D95; color: #A78BFA; }
html.dark-mode .card-transactions .summary-icon { background: #78350F; color: #FBBF24; }

.summary-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
}
.summary-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 2px;
}
.summary-value {
    font-size: 18px;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: 0.3px;
    word-break: break-word;
}
.summary-sub {
    font-size: 10px;
    color: var(--text-light);
    margin-top: 2px;
}

/* Section Card */
.section-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 1px 3px var(--shadow-color);
}
.section-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-table-even);
}
.section-header h3 {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.section-header h3 i {
    color: #bb0404;
}
.section-count {
    margin-left: auto;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 12px;
    background: #bb0404;
    color: #FFFFFF;
    border-radius: 12px;
}

/* Table Wrapper */
.table-wrapper {
    overflow-x: auto;
    max-height: 500px;
    overflow-y: auto;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 800px;
}
.data-table thead {
    background: #bb0404;
    position: sticky;
    top: 0;
    z-index: 10;
}
.data-table thead th {
    padding: 11px 14px;
    text-align: left;
    color: #FFFFFF;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.data-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }

.provider-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.provider-icon-sm {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 12px;
    flex-shrink: 0;
}
.provider-name {
    font-size: 13px;
}

.code-badge {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; }

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}
.type-deposit {
    background: #D1FAE5;
    color: #065F46;
}
.type-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
}
html.dark-mode .type-deposit { background: #065F46; color: #34D399; }
html.dark-mode .type-withdrawal { background: #7F1D1D; color: #FCA5A5; }

.ref-badge {
    background: var(--bg-table-even);
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    font-family: 'Courier New', monospace;
}
.time-display {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
}

.text-right { text-align: right; }
.text-float { color: #3B82F6; font-weight: 700; }
.text-cash { color: #10B981; font-weight: 700; }
.text-success { color: #10B981; font-weight: 700; }
.text-danger { color: #DC2626; font-weight: 700; }

.totals-row {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%) !important;
    border-top: 2px solid #bb0404;
}
html.dark-mode .totals-row {
    background: linear-gradient(135deg, #2d3a4f 0%, #334155 100%) !important;
}
.totals-row td { 
    padding: 12px 14px; 
    font-weight: 700; 
    color: var(--text-primary);
    border-bottom: none;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
.info-item {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.info-item:nth-child(3n) { border-right: none; }
.info-item:nth-last-child(-n+3) { border-bottom: none; }

.info-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-value {
    font-size: 14px;
    color: var(--text-primary);
}
.info-value strong { 
    color: #bb0404; 
    font-family: 'Courier New', monospace;
}
.link {
    color: #3B82F6;
    text-decoration: none;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    font-size: 12px;
}
.link:hover { text-decoration: underline; }
.date-badge {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 8px;
    background: var(--bg-table-even);
    border-radius: 8px;
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
}

/* Financial Grid */
.financial-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
.financial-item {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.financial-item:nth-child(3n) { border-right: none; }
.financial-item:nth-last-child(-n+3) { border-bottom: none; }
.financial-item.highlight {
    background: linear-gradient(135deg, rgba(187, 4, 4, 0.04), rgba(187, 4, 4, 0.01));
}
.financial-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.financial-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
}

/* Capital Summary */
.capital-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
}
.capital-item {
    padding: 20px 24px;
    border-right: 1px solid var(--border-color);
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.capital-item:last-child { border-right: none; }
.capital-item.highlight {
    background: linear-gradient(135deg, rgba(187, 4, 4, 0.06), rgba(187, 4, 4, 0.02));
    border-left: 4px solid #bb0404;
}
.capital-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.capital-value {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-primary);
}
.capital-value-lg {
    font-size: 22px;
    color: #bb0404;
}

/* Notes Content */
.notes-content {
    padding: 16px 20px;
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.6;
}

/* Responsive */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, 1fr); }
    .info-grid { grid-template-columns: repeat(2, 1fr); }
    .info-item:nth-child(3n) { border-right: 1px solid var(--border-color); }
    .info-item:nth-child(2n) { border-right: none; }
    .financial-grid { grid-template-columns: repeat(2, 1fr); }
    .financial-item:nth-child(3n) { border-right: 1px solid var(--border-color); }
    .financial-item:nth-child(2n) { border-right: none; }
    .capital-summary { grid-template-columns: repeat(2, 1fr); }
    .capital-item:nth-child(2n) { border-right: none; }
    .capital-item { border-bottom: 1px solid var(--border-color); }
    .capital-item:nth-last-child(-n+2) { border-bottom: none; }
}

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .summary-cards { grid-template-columns: 1fr; }
    .summary-value { font-size: 16px; }
    
    .info-grid { grid-template-columns: 1fr; }
    .info-item { border-right: none !important; border-bottom: 1px solid var(--border-color); }
    .info-item:last-child { border-bottom: none; }
    
    .financial-grid { grid-template-columns: 1fr; }
    .financial-item { border-right: none !important; }
    
    .capital-summary { grid-template-columns: 1fr; }
    .capital-item { border-right: none; }
    
    .branch-card { flex-direction: column; align-items: flex-start; }
    .branch-back { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .summary-icon { width: 42px; height: 42px; font-size: 17px; }
    .section-header h3 { font-size: 13px; }
    .capital-value-lg { font-size: 18px; }
}

/* Print */
@media print {
    .branch-back, .header-right, .btn { display: none !important; }
    .section-card, .summary-card { box-shadow: none; border: 1px solid #ccc; }
    body { background: #FFFFFF !important; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
});
</script>

</body>
</html>