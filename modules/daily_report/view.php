<?php
// ================================================================
// FILE: modules/daily_report/view.php
// VIEW DAILY REPORT DETAILS (ADMIN)
// ✅ FIXED: Ondoa provider_id na provider_code (hazipo kwenye daily_reports)
// ✅ FIXED: Dark mode inatumia html.dark-mode
// ✅ NEW: Modern design na soft background cards
// ✅ NEW: Providers breakdown, transactions, financial summary
// ✅ NEW: Consistent styling na files nyingine
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
// GET REPORT
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT dr.*, 
               e.full_name as employee_name,
               b.branch_name as branch_name,
               b.branch_code as branch_code,
               b.location as branch_location,
               mr.report_number as morning_report_number,
               mr.report_date as morning_report_date,
               es.stock_number as evening_stock_number,
               es.stock_date as evening_stock_date,
               c.commission_number as commission_number,
               c.commission_date as commission_date
        FROM daily_reports dr
        LEFT JOIN employees e ON dr.employee_id = e.id
        LEFT JOIN branches b ON dr.branch_id = b.id
        LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
        LEFT JOIN evening_stocks es ON dr.evening_stock_id = es.id
        LEFT JOIN commissions c ON dr.commission_id = c.id
        WHERE dr.id = ?
    ");
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
// GET PROVIDERS BREAKDOWN
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
// GET TRANSACTIONS
// ============================================================
$transactions = [];
try {
    $stmt = $db->prepare("
        SELECT 
            drt.*,
            p.provider_name as provider_full_name,
            p.icon_class,
            p.color_code,
            e.full_name as employee_name
        FROM daily_report_transactions drt
        LEFT JOIN providers p ON drt.provider_id = p.id
        LEFT JOIN employees e ON drt.created_by = e.id
        WHERE drt.daily_report_id = ?
        ORDER BY drt.transaction_date DESC, drt.id DESC
    ");
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// ============================================================
// CALCULATE TOTALS
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
$net_change = $providers_total_deposits - $providers_total_withdrawals;

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        REPORT HERO CARD
        ============================================================ -->
        <div class="report-hero-card">
            <div class="report-hero-left">
                <div class="report-hero-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="report-hero-info">
                    <span class="report-hero-label">Daily Report</span>
                    <h1 class="report-hero-number"><?php echo htmlspecialchars($report['report_number']); ?></h1>
                    <div class="report-hero-meta">
                        <span class="report-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                        </span>
                        <span class="report-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?>
                            <?php if ($report['branch_code']): ?>
                                (<?php echo htmlspecialchars($report['branch_code']); ?>)
                            <?php endif; ?>
                        </span>
                        <span class="report-meta-item">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="report-hero-right">
                <div class="hero-stat-box">
                    <span class="hero-stat-label">Current Capital</span>
                    <span class="hero-stat-value"><?php echo formatCurrency($report['current_capital']); ?></span>
                </div>
                <div class="hero-stat-box">
                    <span class="hero-stat-label">Net Profit</span>
                    <span class="hero-stat-value <?php echo ($report['net_profit'] ?? 0) >= 0 ? 'hero-positive' : 'hero-negative'; ?>">
                        <?php echo formatCurrency($report['net_profit']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        ACTION BAR
        ============================================================ -->
        <div class="action-bar">
            <div class="action-bar-left">
                <span class="action-bar-info">
                    <i class="fas fa-university"></i>
                    <?php echo count($providers); ?> Providers
                </span>
                <span class="action-bar-info">
                    <i class="fas fa-exchange-alt"></i>
                    <?php echo count($transactions); ?> Transactions
                </span>
            </div>
            <div class="action-bar-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn-action btn-action-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button type="button" class="btn-action btn-action-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="index.php?branch_id=<?php echo $report['branch_id']; ?>" class="btn-action btn-action-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - SOFT BACKGROUND
        ============================================================ -->
        <div class="stats-grid-soft">
            <!-- Total Float -->
            <div class="stat-card-soft stat-card-soft-float">
                <div class="stat-icon-soft">
                    <i class="fas fa-university"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($providers_total_float); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-list"></i>
                        From <?php echo count($providers); ?> providers
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Cash -->
            <div class="stat-card-soft stat-card-soft-cash">
                <div class="stat-icon-soft">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Cash</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($providers_total_cash); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-wallet"></i>
                        Cash balance
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Capital -->
            <div class="stat-card-soft stat-card-soft-capital">
                <div class="stat-icon-soft">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Capital</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($providers_capital); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-calculator"></i>
                        Float + Cash
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Transactions -->
            <div class="stat-card-soft stat-card-soft-txn">
                <div class="stat-icon-soft">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Transactions</span>
                    <span class="stat-value-soft"><?php echo number_format(count($transactions)); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-clock"></i>
                        Today's activity
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
        </div>

        <!-- ============================================================
        PROVIDERS BREAKDOWN
        ============================================================ -->
        <?php if (!empty($providers)): ?>
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-university"></i>
                    Providers Breakdown
                </h3>
                <span class="info-badge">
                    <i class="fas fa-list"></i> <?php echo count($providers); ?> providers
                </span>
            </div>
            
            <div class="table-wrapper">
                <table class="providers-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
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
                            $p_net_change = floatval($p['total_deposits'] ?? 0) - floatval($p['total_withdrawals'] ?? 0);
                            $provider_color = $p['color_code'] ?? '#0B5ED7';
                            $provider_icon = $p['icon_class'] ?? 'fas fa-university';
                        ?>
                            <tr>
                                <td>
                                    <span class="row-number"><?php echo $i++; ?></span>
                                </td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                            <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                        </div>
                                        <span class="provider-name-text">
                                            <?php echo htmlspecialchars($p['provider_name']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-float"><?php echo formatCurrency($p['morning_float'] ?? 0); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-float-bold"><?php echo formatCurrency($p['current_float'] ?? 0); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-deposit">+ <?php echo formatCurrency($p['total_deposits'] ?? 0); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-withdrawal">- <?php echo formatCurrency($p['total_withdrawals'] ?? 0); ?></span>
                                </td>
                                <td class="text-right">
                                    <span class="<?php echo $p_net_change >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                        <?php echo ($p_net_change >= 0 ? '+' : '') . formatCurrency($p_net_change); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="totals-row">
                            <td colspan="3"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong class="amount-float"><?php echo formatCurrency($providers_total_float); ?></strong></td>
                            <td class="text-right"><strong class="amount-float-bold"><?php echo formatCurrency($providers_total_float); ?></strong></td>
                            <td class="text-right"><strong class="amount-deposit">+ <?php echo formatCurrency($providers_total_deposits); ?></strong></td>
                            <td class="text-right"><strong class="amount-withdrawal">- <?php echo formatCurrency($providers_total_withdrawals); ?></strong></td>
                            <td class="text-right">
                                <strong class="<?php echo $net_change >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                    <?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?>
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
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-exchange-alt"></i>
                    Transactions
                </h3>
                <span class="info-badge">
                    <i class="fas fa-list"></i> <?php echo count($transactions); ?> transactions
                </span>
            </div>
            
            <div class="table-wrapper">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Provider</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Reference</th>
                            <th>Employee</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $j = 1;
                        foreach ($transactions as $t): 
                            $is_deposit = ($t['transaction_type'] === 'deposit');
                        ?>
                            <tr>
                                <td>
                                    <span class="row-number"><?php echo $j++; ?></span>
                                </td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($t['color_code'] ?? '#0B5ED7'); ?>;">
                                            <i class="<?php echo htmlspecialchars($t['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <span><?php echo htmlspecialchars($t['provider_full_name'] ?? $t['provider_code']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo $is_deposit ? 'type-deposit' : 'type-withdrawal'; ?>">
                                        <i class="fas <?php echo $is_deposit ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
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
                                    <span class="employee-cell">
                                        <i class="fas fa-user"></i>
                                        <?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="time-display">
                                        <i class="far fa-clock"></i>
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
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    Basic Information
                </h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon info-icon-blue">
                        <i class="fas fa-hashtag"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Report Number</span>
                        <span class="info-value code-value"><?php echo htmlspecialchars($report['report_number']); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-orange">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Report Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($report['report_date'])); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-purple">
                        <i class="fas fa-store-alt"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Branch</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-green">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Created By</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-blue">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Created At</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($report['created_at'])); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-orange">
                        <i class="fas fa-sync"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($report['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        REFERENCES
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-link"></i>
                    References
                </h3>
            </div>
            <div class="references-grid">
                <!-- Morning Report -->
                <div class="reference-item">
                    <div class="reference-icon reference-icon-morning">
                        <i class="fas fa-sun"></i>
                    </div>
                    <div class="reference-content">
                        <span class="reference-label">Morning Report</span>
                        <?php if ($report['morning_report_number']): ?>
                            <a href="../morning_report/view.php?id=<?php echo $report['morning_report_id']; ?>" class="reference-link">
                                <?php echo htmlspecialchars($report['morning_report_number']); ?>
                            </a>
                            <span class="reference-date">
                                <i class="far fa-calendar"></i>
                                <?php echo date('d M Y', strtotime($report['morning_report_date'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="reference-empty">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Evening Stock -->
                <div class="reference-item">
                    <div class="reference-icon reference-icon-evening">
                        <i class="fas fa-moon"></i>
                    </div>
                    <div class="reference-content">
                        <span class="reference-label">Evening Stock</span>
                        <?php if ($report['evening_stock_number']): ?>
                            <a href="../evening_stock/view.php?id=<?php echo $report['evening_stock_id']; ?>" class="reference-link">
                                <?php echo htmlspecialchars($report['evening_stock_number']); ?>
                            </a>
                            <span class="reference-date">
                                <i class="far fa-calendar"></i>
                                <?php echo date('d M Y', strtotime($report['evening_stock_date'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="reference-empty">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Commission -->
                <div class="reference-item">
                    <div class="reference-icon reference-icon-commission">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="reference-content">
                        <span class="reference-label">Commission</span>
                        <?php if ($report['commission_number']): ?>
                            <a href="../commissions/view.php?id=<?php echo $report['commission_id']; ?>" class="reference-link">
                                <?php echo htmlspecialchars($report['commission_number']); ?>
                            </a>
                            <span class="reference-date">
                                <i class="far fa-calendar"></i>
                                <?php echo date('d M Y', strtotime($report['commission_date'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="reference-empty">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FINANCIAL SUMMARY - SOFT BACKGROUND
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-coins"></i>
                    Financial Summary
                </h3>
            </div>
            <div class="financial-grid-soft">
                <!-- Morning Total -->
                <div class="financial-item-soft financial-item-morning">
                    <div class="financial-icon-soft">
                        <i class="fas fa-sun"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Morning Total</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['morning_total']); ?></span>
                    </div>
                </div>
                
                <!-- Evening Total -->
                <div class="financial-item-soft financial-item-evening">
                    <div class="financial-icon-soft">
                        <i class="fas fa-moon"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Evening Total</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['evening_total']); ?></span>
                    </div>
                </div>
                
                <!-- Float Difference -->
                <div class="financial-item-soft financial-item-diff <?php echo ($report['float_difference'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                    <div class="financial-icon-soft">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Float Difference</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['float_difference']); ?></span>
                    </div>
                </div>
                
                <!-- Total Commission -->
                <div class="financial-item-soft financial-item-deposit">
                    <div class="financial-icon-soft">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Commission</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['total_commission']); ?></span>
                    </div>
                </div>
                
                <!-- Other Income -->
                <div class="financial-item-soft financial-item-deposit">
                    <div class="financial-icon-soft">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Other Income</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['other_income']); ?></span>
                    </div>
                </div>
                
                <!-- Business Income -->
                <div class="financial-item-soft financial-item-deposit">
                    <div class="financial-icon-soft">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Business Income</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['total_business_income']); ?></span>
                    </div>
                </div>
                
                <!-- Total Deposits -->
                <div class="financial-item-soft financial-item-deposit">
                    <div class="financial-icon-soft">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Deposits</span>
                        <span class="financial-value-soft">+ <?php echo formatCurrency($report['total_deposits']); ?></span>
                    </div>
                </div>
                
                <!-- Total Withdrawals -->
                <div class="financial-item-soft financial-item-withdraw">
                    <div class="financial-icon-soft">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Withdrawals</span>
                        <span class="financial-value-soft">- <?php echo formatCurrency($report['total_withdrawals']); ?></span>
                    </div>
                </div>
                
                <!-- Current Float -->
                <div class="financial-item-soft financial-item-current">
                    <div class="financial-icon-soft">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Current Float</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['current_float']); ?></span>
                    </div>
                </div>
                
                <!-- Current Cash -->
                <div class="financial-item-soft financial-item-cash">
                    <div class="financial-icon-soft">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Current Cash</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['current_cash']); ?></span>
                    </div>
                </div>
                
                <!-- Total Expenses -->
                <div class="financial-item-soft financial-item-withdraw">
                    <div class="financial-icon-soft">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Expenses</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['total_expenses']); ?></span>
                    </div>
                </div>
                
                <!-- Total Cash Out -->
                <div class="financial-item-soft financial-item-withdraw">
                    <div class="financial-icon-soft">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Cash Out</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['total_cash_out']); ?></span>
                    </div>
                </div>
                
                <!-- Total Salaries -->
                <div class="financial-item-soft financial-item-withdraw">
                    <div class="financial-icon-soft">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Salaries</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['total_salaries']); ?></span>
                    </div>
                </div>
                
                <!-- Net Profit -->
                <div class="financial-item-soft financial-item-net <?php echo ($report['net_profit'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                    <div class="financial-icon-soft">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Net Profit</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['net_profit']); ?></span>
                    </div>
                </div>
                
                <!-- Net Profit After Salaries -->
                <div class="financial-item-soft financial-item-net <?php echo ($report['net_profit_after_salaries'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                    <div class="financial-icon-soft">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Net After Salaries</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($report['net_profit_after_salaries']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        CAPITAL SUMMARY - SOFT BACKGROUND
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-building"></i>
                    Capital Summary
                </h3>
            </div>
            <div class="capital-grid-soft">
                <!-- Opening Capital -->
                <div class="capital-item-soft capital-item-opening">
                    <div class="capital-icon-soft">
                        <i class="fas fa-flag"></i>
                    </div>
                    <div class="capital-content-soft">
                        <span class="capital-label-soft">Opening Capital</span>
                        <span class="capital-value-soft"><?php echo formatCurrency($report['opening_capital']); ?></span>
                    </div>
                </div>
                
                <!-- Additional Capital -->
                <div class="capital-item-soft capital-item-additional">
                    <div class="capital-icon-soft">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="capital-content-soft">
                        <span class="capital-label-soft">Additional Capital</span>
                        <span class="capital-value-soft">+ <?php echo formatCurrency($report['additional_capital']); ?></span>
                    </div>
                </div>
                
                <!-- Profit Allocated -->
                <div class="capital-item-soft capital-item-profit">
                    <div class="capital-icon-soft">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="capital-content-soft">
                        <span class="capital-label-soft">Profit Allocated</span>
                        <span class="capital-value-soft">+ <?php echo formatCurrency($report['profit_allocated']); ?></span>
                    </div>
                </div>
                
                <!-- Current Capital -->
                <div class="capital-item-soft capital-item-current">
                    <div class="capital-icon-soft">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="capital-content-soft">
                        <span class="capital-label-soft">Current Capital</span>
                        <span class="capital-value-soft capital-value-lg"><?php echo formatCurrency($report['current_capital']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        NOTES
        ============================================================ -->
        <?php if (!empty($report['notes'])): ?>
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-sticky-note"></i>
                    Notes
                </h3>
            </div>
            <div class="notes-content">
                <div class="notes-icon">
                    <i class="fas fa-quote-left"></i>
                </div>
                <div class="notes-text">
                    <?php echo nl2br(htmlspecialchars($report['notes'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important; padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
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
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   REPORT HERO CARD
   ============================================================ */
.report-hero-card {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 8px 28px rgba(187, 4, 4, 0.3);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.report-hero-card::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -5%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.report-hero-card::after {
    content: '';
    position: absolute;
    bottom: -50%;
    left: 10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.report-hero-left {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.report-hero-icon {
    width: 68px;
    height: 68px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

.report-hero-info {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.report-hero-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}

.report-hero-number {
    font-size: 26px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.5px;
    font-family: 'Inter', 'Courier New', monospace;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-all;
}

.report-hero-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.report-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
}

.report-meta-item i { font-size: 11px; opacity: 0.9; }

.report-hero-right {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.hero-stat-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1.5px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    min-width: 150px;
}

.hero-stat-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255, 255, 255, 0.85);
}

.hero-stat-value {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.hero-positive { color: #86EFAC !important; }
.hero-negative { color: #FCA5A5 !important; }

/* ============================================================
   ACTION BAR
   ============================================================ */
.action-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 20px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.action-bar-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.action-bar-info {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}

.action-bar-info i {
    color: #bb0404;
    font-size: 12px;
}

.action-bar-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}

.btn-action i { font-size: 12px; }

.btn-action-edit {
    background: linear-gradient(135deg, #F59E0B, #D97706);
    color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(245, 158, 11, 0.3);
}
.btn-action-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(245, 158, 11, 0.45);
    color: #FFFFFF;
}

.btn-action-print {
    background: linear-gradient(135deg, #3B82F6, #2563EB);
    color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3);
}
.btn-action-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(59, 130, 246, 0.45);
    color: #FFFFFF;
}

.btn-action-back {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    color: #FFFFFF;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.btn-action-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(220, 38, 38, 0.45);
    color: #FFFFFF;
}

/* ============================================================
   STATS GRID - SOFT BACKGROUND
   ============================================================ */
.stats-grid-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}

.stat-card-soft {
    position: relative;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.stat-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

/* SOFT BLUE - Float */
.stat-card-soft-float {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.stat-card-soft-float .stat-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.stat-card-soft-float .stat-value-soft { color: #1D4ED8; }

/* SOFT GREEN - Cash */
.stat-card-soft-cash {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.stat-card-soft-cash .stat-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.stat-card-soft-cash .stat-value-soft { color: #047857; }

/* SOFT PURPLE - Capital */
.stat-card-soft-capital {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.stat-card-soft-capital .stat-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.stat-card-soft-capital .stat-value-soft { color: #6D28D9; }

/* SOFT ORANGE - Transactions */
.stat-card-soft-txn {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.stat-card-soft-txn .stat-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.stat-card-soft-txn .stat-value-soft { color: #B45309; }

/* Dark mode */
html.dark-mode .stat-card-soft-float { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-cash { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-capital { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .stat-card-soft-txn { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
html.dark-mode .stat-card-soft-float .stat-value-soft { color: #60A5FA; }
html.dark-mode .stat-card-soft-cash .stat-value-soft { color: #34D399; }
html.dark-mode .stat-card-soft-capital .stat-value-soft { color: #C4B5FD; }
html.dark-mode .stat-card-soft-txn .stat-value-soft { color: #FBBF24; }

.stat-icon-soft {
    width: 50px;
    height: 50px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.stat-card-soft:hover .stat-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.stat-info-soft {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.stat-label-soft {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-value-soft {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}

.stat-sub-soft {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

.stat-sub-soft i {
    font-size: 9px;
    color: var(--text-light);
}

.stat-decoration-soft {
    position: absolute;
    top: -30px;
    right: -30px;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
}

/* ============================================================
   INFO CARD
   ============================================================ */
.info-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.info-card-header {
    padding: 16px 22px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.info-card-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.info-card-header h3 i {
    color: #bb0404;
    font-size: 16px;
}

.info-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(187, 4, 4, 0.15);
    color: #bb0404;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1.5px solid rgba(187, 4, 4, 0.3);
}

html.dark-mode .info-badge {
    background: rgba(248, 113, 113, 0.2);
    color: #FCA5A5;
    border-color: rgba(248, 113, 113, 0.4);
}

/* ============================================================
   TABLE WRAPPER
   ============================================================ */
.table-wrapper {
    overflow-x: auto;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
}

.providers-table,
.transactions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 900px;
}

.providers-table thead,
.transactions-table thead {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    position: sticky;
    top: 0;
    z-index: 10;
}

.providers-table thead th,
.transactions-table thead th {
    padding: 12px 14px;
    text-align: left;
    color: #FFFFFF;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}

.providers-table thead th.text-right,
.transactions-table thead th.text-right {
    text-align: right;
}

.providers-table tbody td,
.transactions-table tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
}

.providers-table tbody tr:hover,
.transactions-table tbody tr:hover {
    background: rgba(187, 4, 4, 0.04);
}

.providers-table tbody tr:nth-child(even),
.transactions-table tbody tr:nth-child(even) {
    background: var(--bg-input);
}

.providers-table tbody td.text-right,
.transactions-table tbody td.text-right {
    text-align: right;
}

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--bg-input);
    font-size: 11px;
    font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.provider-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.provider-icon-circle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 14px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.provider-name-text {
    font-weight: 800;
    color: var(--text-primary);
    font-size: 13px;
}

.code-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #93C5FD;
}

html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.amount-float {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}

.amount-float-bold {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: linear-gradient(135deg, #1E40AF, #2563EB);
    color: #FFFFFF;
    border-radius: 8px;
    font-weight: 900;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}

.amount-deposit {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #DCFCE7;
    color: #15803D;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #86EFAC;
    white-space: nowrap;
}

.amount-withdrawal {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #FEE2E2;
    color: #991B1B;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FCA5A5;
    white-space: nowrap;
}

.amount-positive {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #DCFCE7;
    color: #15803D;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #86EFAC;
    white-space: nowrap;
}

.amount-negative {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #FEE2E2;
    color: #991B1B;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FCA5A5;
    white-space: nowrap;
}

html.dark-mode .amount-float { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .amount-float-bold { background: #1E40AF; color: #BFDBFE; }
html.dark-mode .amount-deposit,
html.dark-mode .amount-positive { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .amount-withdrawal,
html.dark-mode .amount-negative { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.totals-row {
    background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%) !important;
    border-top: 3px solid #bb0404;
}

html.dark-mode .totals-row {
    background: linear-gradient(135deg, #334155 0%, #1e293b 100%) !important;
}

.totals-row td {
    padding: 14px;
    font-weight: 900;
    color: var(--text-primary);
    border-bottom: none;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.type-deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #BBF7D0;
}

.type-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

html.dark-mode .type-deposit { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .type-withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.ref-badge {
    background: var(--bg-input);
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    font-family: 'Courier New', monospace;
}

.employee-cell {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.employee-cell i { color: #2563EB; font-size: 10px; }

.time-display {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.time-display i { color: #2563EB; font-size: 10px; }

.text-success { color: #059669 !important; font-weight: 800; }
.text-danger { color: #DC2626 !important; font-weight: 800; }
html.dark-mode .text-success { color: #34D399 !important; }
html.dark-mode .text-danger { color: #FCA5A5 !important; }

/* ============================================================
   INFO GRID
   ============================================================ */
.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px 22px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
    min-width: 0;
}

.info-item:hover {
    border-color: #bb0404;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.1);
}

.info-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    border: 1.5px solid;
}

.info-icon-blue { background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1D4ED8; border-color: #93C5FD; }
.info-icon-purple { background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED; border-color: #C4B5FD; }
.info-icon-orange { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706; border-color: #FCD34D; }
.info-icon-green { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; border-color: #6EE7B7; }

html.dark-mode .info-icon-blue { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .info-icon-purple { background: linear-gradient(135deg, #2D1B5F, #4C1D95); color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .info-icon-orange { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .info-icon-green { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }

.info-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.info-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.info-value {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    word-break: break-word;
}

.info-value.code-value {
    font-family: 'Courier New', monospace;
    color: #bb0404;
    background: rgba(187, 4, 4, 0.1);
    padding: 2px 10px;
    border-radius: 6px;
    align-self: flex-start;
    font-size: 12px;
}

html.dark-mode .info-value.code-value {
    background: rgba(248, 113, 113, 0.2);
    color: #FCA5A5;
}

/* ============================================================
   REFERENCES GRID
   ============================================================ */
.references-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px 22px;
}

.reference-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
    min-width: 0;
}

.reference-item:hover {
    border-color: #2563EB;
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.15);
}

.reference-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid;
}

.reference-icon-morning { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706; border-color: #FCD34D; }
.reference-icon-evening { background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED; border-color: #C4B5FD; }
.reference-icon-commission { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; border-color: #6EE7B7; }

html.dark-mode .reference-icon-morning { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .reference-icon-evening { background: linear-gradient(135deg, #2D1B5F, #4C1D95); color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .reference-icon-commission { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }

.reference-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
}

.reference-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.reference-link {
    font-size: 13px;
    font-weight: 800;
    color: #1D4ED8;
    text-decoration: none;
    font-family: 'Courier New', monospace;
    transition: color 0.2s ease;
    word-break: break-all;
}

.reference-link:hover {
    color: #2563EB;
    text-decoration: underline;
}

html.dark-mode .reference-link { color: #60A5FA; }
html.dark-mode .reference-link:hover { color: #93C5FD; }

.reference-date {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
}

.reference-date i { font-size: 10px; }

.reference-empty {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-light);
    font-style: italic;
}

/* ============================================================
   FINANCIAL GRID - SOFT BACKGROUND
   ============================================================ */
.financial-grid-soft {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px 22px;
}

.financial-item-soft {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    border: 1.5px solid transparent;
    transition: all 0.25s ease;
    min-width: 0;
}

.financial-item-soft:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}

/* Morning */
.financial-item-morning {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.financial-item-morning .financial-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.financial-item-morning .financial-value-soft { color: #B45309; }

/* Evening */
.financial-item-evening {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.financial-item-evening .financial-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.financial-item-evening .financial-value-soft { color: #6D28D9; }

/* Difference */
.financial-item-diff.positive {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.financial-item-diff.positive .financial-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.financial-item-diff.positive .financial-value-soft { color: #047857; }

.financial-item-diff.negative {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.financial-item-diff.negative .financial-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.financial-item-diff.negative .financial-value-soft { color: #B91C1C; }

/* Deposit (Income) */
.financial-item-deposit {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.financial-item-deposit .financial-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.financial-item-deposit .financial-value-soft { color: #047857; }

/* Withdraw (Expense) */
.financial-item-withdraw {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.financial-item-withdraw .financial-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.financial-item-withdraw .financial-value-soft { color: #B91C1C; }

/* Current */
.financial-item-current {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.financial-item-current .financial-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.financial-item-current .financial-value-soft { color: #1D4ED8; }

/* Cash */
.financial-item-cash {
    background: rgba(16, 185, 129, 0.08);
    border-color: rgba(16, 185, 129, 0.2);
}
.financial-item-cash .financial-icon-soft {
    background: rgba(16, 185, 129, 0.15);
    color: #10B981;
    border: 1.5px solid rgba(16, 185, 129, 0.3);
}
.financial-item-cash .financial-value-soft { color: #047857; }

/* Net Profit */
.financial-item-net.positive {
    background: rgba(5, 150, 105, 0.1);
    border-color: rgba(5, 150, 105, 0.3);
}
.financial-item-net.positive .financial-icon-soft {
    background: rgba(5, 150, 105, 0.2);
    color: #047857;
    border: 1.5px solid rgba(5, 150, 105, 0.4);
}
.financial-item-net.positive .financial-value-soft { color: #047857; }

.financial-item-net.negative {
    background: rgba(220, 38, 38, 0.1);
    border-color: rgba(220, 38, 38, 0.3);
}
.financial-item-net.negative .financial-icon-soft {
    background: rgba(220, 38, 38, 0.2);
    color: #B91C1C;
    border: 1.5px solid rgba(220, 38, 38, 0.4);
}
.financial-item-net.negative .financial-value-soft { color: #B91C1C; }

/* Dark mode */
html.dark-mode .financial-item-morning,
html.dark-mode .financial-item-evening,
html.dark-mode .financial-item-diff.positive,
html.dark-mode .financial-item-diff.negative,
html.dark-mode .financial-item-deposit,
html.dark-mode .financial-item-withdraw,
html.dark-mode .financial-item-current,
html.dark-mode .financial-item-cash,
html.dark-mode .financial-item-net.positive,
html.dark-mode .financial-item-net.negative {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.1);
}

html.dark-mode .financial-item-morning .financial-value-soft { color: #FBBF24; }
html.dark-mode .financial-item-evening .financial-value-soft { color: #C4B5FD; }
html.dark-mode .financial-item-diff.positive .financial-value-soft { color: #34D399; }
html.dark-mode .financial-item-diff.negative .financial-value-soft { color: #FCA5A5; }
html.dark-mode .financial-item-deposit .financial-value-soft { color: #34D399; }
html.dark-mode .financial-item-withdraw .financial-value-soft { color: #FCA5A5; }
html.dark-mode .financial-item-current .financial-value-soft { color: #60A5FA; }
html.dark-mode .financial-item-cash .financial-value-soft { color: #34D399; }
html.dark-mode .financial-item-net.positive .financial-value-soft { color: #34D399; }
html.dark-mode .financial-item-net.negative .financial-value-soft { color: #FCA5A5; }

.financial-icon-soft {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.financial-item-soft:hover .financial-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.financial-content-soft {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}

.financial-label-soft {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.financial-value-soft {
    font-size: 15px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}

/* ============================================================
   CAPITAL GRID - SOFT BACKGROUND
   ============================================================ */
.capital-grid-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding: 16px 22px;
}

.capital-item-soft {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 18px;
    border-radius: 12px;
    border: 1.5px solid transparent;
    transition: all 0.25s ease;
    min-width: 0;
}

.capital-item-soft:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}

.capital-item-opening {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.capital-item-opening .capital-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.capital-item-opening .capital-value-soft { color: #1D4ED8; }

.capital-item-additional {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.capital-item-additional .capital-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.capital-item-additional .capital-value-soft { color: #047857; }

.capital-item-profit {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.capital-item-profit .capital-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.capital-item-profit .capital-value-soft { color: #6D28D9; }

.capital-item-current {
    background: rgba(187, 4, 4, 0.08);
    border-color: rgba(187, 4, 4, 0.3);
    border-width: 2px;
}
.capital-item-current .capital-icon-soft {
    background: rgba(187, 4, 4, 0.15);
    color: #bb0404;
    border: 1.5px solid rgba(187, 4, 4, 0.3);
}
.capital-item-current .capital-value-soft { color: #bb0404; }

html.dark-mode .capital-item-opening,
html.dark-mode .capital-item-additional,
html.dark-mode .capital-item-profit,
html.dark-mode .capital-item-current {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.1);
}

html.dark-mode .capital-item-opening .capital-value-soft { color: #60A5FA; }
html.dark-mode .capital-item-additional .capital-value-soft { color: #34D399; }
html.dark-mode .capital-item-profit .capital-value-soft { color: #C4B5FD; }
html.dark-mode .capital-item-current .capital-value-soft { color: #FCA5A5; }

.capital-icon-soft {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.capital-item-soft:hover .capital-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.capital-content-soft {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}

.capital-label-soft {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.capital-value-soft {
    font-size: 16px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}

.capital-value-lg {
    font-size: 20px;
}

/* ============================================================
   NOTES
   ============================================================ */
.notes-content {
    padding: 20px 24px;
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.notes-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid #FCD34D;
}

html.dark-mode .notes-icon {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FBBF24;
    border-color: #F59E0B;
}

.notes-text {
    flex: 1;
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.7;
    font-weight: 500;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .info-grid,
    .financial-grid-soft { grid-template-columns: repeat(2, 1fr); }
    .references-grid { grid-template-columns: repeat(2, 1fr); }
    .capital-grid-soft { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 1200px) {
    .stats-grid-soft { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .report-hero-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
    }
    
    .report-hero-number { font-size: 20px; }
    .report-hero-icon { width: 56px; height: 56px; font-size: 22px; }
    .report-hero-right { width: 100%; }
    .hero-stat-box { flex: 1; min-width: 0; }
    
    .stats-grid-soft { grid-template-columns: 1fr; }
    .info-grid,
    .financial-grid-soft,
    .references-grid,
    .capital-grid-soft { grid-template-columns: 1fr; }
    
    .action-bar { flex-direction: column; align-items: stretch; }
    .action-bar-left { justify-content: center; }
    .action-bar-right { width: 100%; }
    .btn-action { flex: 1; justify-content: center; }
}

@media (max-width: 480px) {
    .report-hero-number { font-size: 16px; }
    .report-meta-item { font-size: 10px; padding: 3px 9px; }
    .hero-stat-value { font-size: 15px; }
    .hero-stat-box { padding: 10px 14px; min-width: 0; }
    .stat-value-soft { font-size: 16px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .financial-value-soft { font-size: 14px; }
    .capital-value-soft { font-size: 14px; }
    .capital-value-lg { font-size: 16px; }
}

/* ============================================================
   PRINT
   ============================================================ */
@media print {
    .action-bar,
    .btn-action,
    .report-hero-card::before,
    .report-hero-card::after { display: none !important; }
    
    .info-card,
    .stat-card-soft,
    .financial-item-soft,
    .capital-item-soft,
    .reference-item { 
        box-shadow: none; 
        border: 1px solid #ccc; 
        break-inside: avoid;
    }
    
    body { background: #FFFFFF !important; color: #000000 !important; }
    .report-hero-card { color: #000000 !important; background: #F3F4F6 !important; }
    .report-hero-number { color: #000000 !important; }
    .report-meta-item { color: #000000 !important; background: #E5E7EB !important; border-color: #D1D5DB !important; }
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