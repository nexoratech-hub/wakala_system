<?php
// ================================================================
// FILE: modules/daily_report/view_branch_cash.php
// WAKALA FINANCIAL SYSTEM - VIEW BRANCH CASH TRANSACTIONS
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

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';
$is_admin = ($role === 'admin' || $role === 'super_admin');

// ============================================================
// PARAMETERS
// ============================================================
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($report_id <= 0 || $branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// Employee can only see own branch
if (!$is_admin) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_branch = intval($emp['branch_id'] ?? 0);
    if ($user_branch != $branch_id) {
        header('Location: index.php');
        exit();
    }
}

// ============================================================
// GET REPORT INFO
// ============================================================
$stmt = $db->prepare("
    SELECT dr.*, 
           b.branch_name, b.branch_code, b.location as branch_location,
           e.full_name as employee_name
    FROM daily_reports dr
    LEFT JOIN branches b ON dr.branch_id = b.id
    LEFT JOIN employees e ON dr.employee_id = e.id
    WHERE dr.id = ? AND dr.branch_id = ?
");
$stmt->execute([$report_id, $branch_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET CASH TRANSACTIONS (deposits + withdrawals)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        drt.*,
        p.provider_name,
        p.icon_class,
        p.color_code,
        p.provider_type,
        e.full_name as employee_name,
        e.profile_pic as employee_avatar
    FROM daily_report_transactions drt
    LEFT JOIN providers p ON drt.provider_id = p.id
    LEFT JOIN employees e ON drt.created_by = e.id
    WHERE drt.daily_report_id = ?
    ORDER BY drt.created_at DESC, drt.id DESC
");
$stmt->execute([$report_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_deposits = 0;
$total_withdrawals = 0;
$deposit_count = 0;
$withdrawal_count = 0;

foreach ($transactions as $t) {
    if ($t['transaction_type'] === 'deposit') {
        $total_deposits += floatval($t['amount']);
        $deposit_count++;
    } elseif ($t['transaction_type'] === 'withdrawal') {
        $total_withdrawals += floatval($t['amount']);
        $withdrawal_count++;
    }
}

$net_cash = $total_deposits - $total_withdrawals;
$current_cash = floatval($report['current_cash'] ?? 0);

// ============================================================
// PROVIDER BREAKDOWN
// ============================================================
$stmt = $db->prepare("
    SELECT 
        drt.provider_id,
        p.provider_name,
        p.icon_class,
        p.color_code,
        COUNT(drt.id) as txn_count,
        COALESCE(SUM(CASE WHEN drt.transaction_type = 'deposit' THEN drt.amount ELSE 0 END), 0) as total_deposits,
        COALESCE(SUM(CASE WHEN drt.transaction_type = 'withdrawal' THEN drt.amount ELSE 0 END), 0) as total_withdrawals
    FROM daily_report_transactions drt
    LEFT JOIN providers p ON drt.provider_id = p.id
    WHERE drt.daily_report_id = ?
    GROUP BY drt.provider_id
    ORDER BY txn_count DESC
");
$stmt->execute([$report_id]);
$provider_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
             BREADCRUMB
             ============================================================ -->
        <div class="breadcrumb-bar">
            <a href="index.php" class="breadcrumb-link">
                <i class="fas fa-home"></i> Daily Reports
            </a>
            <i class="fas fa-chevron-right breadcrumb-sep"></i>
            <a href="index.php?filter=all" class="breadcrumb-link">
                <?php echo htmlspecialchars($report['report_number']); ?>
            </a>
            <i class="fas fa-chevron-right breadcrumb-sep"></i>
            <span class="breadcrumb-current">Branch Cash</span>
        </div>

        <!-- ============================================================
             BRANCH INDICATOR
             ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper" style="background: linear-gradient(135deg, #D97706, #B45309);">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch Cash</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($report['branch_name']); ?></span>
                    <?php if ($report['branch_code']): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($report['branch_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($report['branch_location']): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($report['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                </span>
            </div>
        </div>

        <!-- ============================================================
             CASH SUMMARY CARD
             ============================================================ -->
        <div class="cash-summary-card">
            <div class="cash-summary-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="cash-summary-content">
                <span class="cash-summary-label">Current Branch Cash</span>
                <span class="cash-summary-value"><?php echo formatCurrency($current_cash); ?></span>
                <span class="cash-summary-sub">
                    <i class="fas fa-info-circle"></i>
                    Cash inashirikiwa na providers wote wa branch hii
                </span>
            </div>
            <div class="cash-summary-stats">
                <div class="cash-stat-item">
                    <span class="cash-stat-label">Deposits</span>
                    <span class="cash-stat-value cash-stat-deposit">
                        <i class="fas fa-arrow-down"></i> +<?php echo formatCurrency($total_deposits); ?>
                    </span>
                    <span class="cash-stat-count"><?php echo number_format($deposit_count); ?> txn</span>
                </div>
                <div class="cash-stat-divider"></div>
                <div class="cash-stat-item">
                    <span class="cash-stat-label">Withdrawals</span>
                    <span class="cash-stat-value cash-stat-withdrawal">
                        <i class="fas fa-arrow-up"></i> -<?php echo formatCurrency($total_withdrawals); ?>
                    </span>
                    <span class="cash-stat-count"><?php echo number_format($withdrawal_count); ?> txn</span>
                </div>
                <div class="cash-stat-divider"></div>
                <div class="cash-stat-item">
                    <span class="cash-stat-label">Net Cash Flow</span>
                    <span class="cash-stat-value <?php echo $net_cash >= 0 ? 'cash-stat-positive' : 'cash-stat-negative'; ?>">
                        <?php echo ($net_cash >= 0 ? '+' : '') . formatCurrency($net_cash); ?>
                    </span>
                    <span class="cash-stat-count">Today's change</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
             ACTION BUTTONS
             ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas fa-list-alt" style="color:#D97706;"></i> 
                    Cash Transactions
                </h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                    •
                    <i class="fas fa-list"></i>
                    <?php echo number_format(count($transactions)); ?> transactions
                </p>
            </div>
            <div class="header-right">
                <a href="edit_branch_cash.php?report_id=<?php echo $report_id; ?>&branch_id=<?php echo $branch_id; ?>" 
                   class="btn-action-big btn-action-edit">
                    <i class="fas fa-edit"></i><span>Edit Cash</span>
                </a>
                <a href="index.php?filter=all&branch_id=<?php echo $branch_id; ?>" 
                   class="btn-action-big btn-action-back">
                    <i class="fas fa-arrow-left"></i><span>Back</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
             PROVIDER BREAKDOWN
             ============================================================ -->
        <?php if (count($provider_breakdown) > 0): ?>
        <div class="provider-breakdown-section">
            <div class="provider-breakdown-header">
                <div class="provider-breakdown-title">
                    <i class="fas fa-chart-pie"></i>
                    Provider Breakdown
                </div>
                <span class="provider-breakdown-count"><?php echo count($provider_breakdown); ?> providers</span>
            </div>
            <div class="provider-breakdown-grid">
                <?php foreach ($provider_breakdown as $pb): 
                    $p_color = $pb['color_code'] ?? '#0B5ED7';
                    $p_icon = $pb['icon_class'] ?? 'fas fa-university';
                ?>
                    <div class="provider-breakdown-card">
                        <div class="provider-breakdown-icon" style="background: <?php echo htmlspecialchars($p_color); ?>;">
                            <i class="<?php echo htmlspecialchars($p_icon); ?>"></i>
                        </div>
                        <div class="provider-breakdown-info">
                            <span class="provider-breakdown-name"><?php echo htmlspecialchars($pb['provider_name'] ?? 'N/A'); ?></span>
                            <div class="provider-breakdown-stats">
                                <span class="provider-stat-in">
                                    <i class="fas fa-arrow-down"></i>
                                    +<?php echo formatCurrency($pb['total_deposits']); ?>
                                </span>
                                <span class="provider-stat-out">
                                    <i class="fas fa-arrow-up"></i>
                                    -<?php echo formatCurrency($pb['total_withdrawals']); ?>
                                </span>
                            </div>
                        </div>
                        <span class="provider-breakdown-count-badge">
                            <?php echo intval($pb['txn_count']); ?> txn
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             TRANSACTIONS TABLE
             ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-header-left">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Cash Transactions</h3>
                    <span class="count-badge"><?php echo count($transactions); ?></span>
                </div>
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search transactions..."
                               oninput="performLiveSearch(this.value)"
                               autocomplete="off">
                        <button type="button" 
                                class="search-clear-btn" 
                                id="searchClearBtn" 
                                onclick="clearLiveSearch()" 
                                style="display:none;"
                                title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count-badge" 
                              id="searchCountBadge" 
                              style="display:none;">0</span>
                    </div>
                </div>
            </div>
            
            <?php if (count($transactions) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table" id="cashTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th class="text-right">Amount</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody id="cashTableBody">
                            <?php 
                            $i = 1;
                            foreach ($transactions as $t): 
                                $is_deposit = $t['transaction_type'] === 'deposit';
                                $txn_date = date('d M Y', strtotime($t['transaction_date']));
                                $txn_time = $t['transaction_time'] ? date('H:i', strtotime($t['transaction_time'])) : date('H:i', strtotime($t['created_at']));
                                $emp_initial = strtoupper(substr($t['employee_name'] ?? 'S', 0, 1));
                                $emp_avatar = $t['employee_avatar'] ?? '';
                                
                                $search_text = strtolower(
                                    ($t['provider_name'] ?? '') . ' ' . 
                                    ($t['provider_code'] ?? '') . ' ' . 
                                    ($t['reference_number'] ?? '') . ' ' .
                                    ($t['employee_name'] ?? '') . ' ' .
                                    ($t['description'] ?? '') . ' ' .
                                    $t['amount']
                                );
                            ?>
                                <tr class="txn-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date-main"><?php echo $txn_date; ?></span>
                                            <span class="time-sub"><?php echo $txn_time; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-badge <?php echo $is_deposit ? 'type-badge-deposit' : 'type-badge-withdrawal'; ?>">
                                            <i class="fas fa-arrow-<?php echo $is_deposit ? 'down' : 'up'; ?>"></i>
                                            <?php echo ucfirst($t['transaction_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon" style="background:<?php echo htmlspecialchars($t['color_code'] ?? '#0B5ED7'); ?>;">
                                                <i class="<?php echo htmlspecialchars($t['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                            </div>
                                            <span class="provider-name"><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="code-badge">
                                            <i class="fas fa-barcode"></i>
                                            <?php echo htmlspecialchars($t['provider_code'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-txn <?php echo $is_deposit ? 'amount-txn-deposit' : 'amount-txn-withdrawal'; ?>">
                                            <?php echo $is_deposit ? '+' : '-'; ?><?php echo formatCurrency($t['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ref-number">
                                            <?php echo htmlspecialchars($t['reference_number'] ?: '—'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                                                <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                                     alt="" class="employee-avatar">
                                            <?php else: ?>
                                                <div class="employee-avatar"><?php echo $emp_initial; ?></div>
                                            <?php endif; ?>
                                            <span class="employee-name-sm"><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- TOTALS ROW -->
                            <tr class="totals-row">
                                <td colspan="5" style="text-align:right;">
                                    TOTAL (<?php echo number_format(count($transactions)); ?> transactions)
                                </td>
                                <td class="text-right">
                                    <span style="color: #059669; font-weight: 900;">
                                        +<?php echo formatCurrency($total_deposits); ?>
                                    </span>
                                    <br>
                                    <span style="color: #DC2626; font-weight: 900;">
                                        -<?php echo formatCurrency($total_withdrawals); ?>
                                    </span>
                                </td>
                                <td colspan="2" style="text-align:right;">
                                    <span style="color: <?php echo $net_cash >= 0 ? '#059669' : '#DC2626'; ?>; font-weight: 900;">
                                        Net: <?php echo formatCurrency($net_cash); ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- NO RESULTS -->
                <div class="empty-state" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                    <p>No transactions match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Cash Transactions</h3>
                    <p>Hakuna cash transactions zilizorekodiwa kwa report hii.</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Cash inaonekana hapa deposit au withdrawal inapofanyika.
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after { box-sizing: border-box; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; }
.main-wrapper .main-content {
    padding: 16px 20px !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

:root {
    --bg-body: #f3f4f6; --bg-card: #ffffff;
    --bg-table-even: #fafafa; --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937; --text-secondary: #374151;
    --text-muted: #6b7280; --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}
html.dark-mode {
    --bg-body: #0f172a; --bg-card: #1e293b;
    --bg-table-even: #1a2332; --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9; --text-secondary: #cbd5e1;
    --text-muted: #94a3b8; --text-light: #64748b;
    --border-color: #334155;
}

/* ============================================================
   BREADCRUMB
   ============================================================ */
.breadcrumb-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--bg-card);
    border-radius: 10px;
    margin-bottom: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 6px var(--shadow-color);
    flex-wrap: wrap;
}
.breadcrumb-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #D97706;
    text-decoration: none;
    transition: all 0.2s ease;
    padding: 4px 10px;
    border-radius: 6px;
}
.breadcrumb-link:hover {
    background: #FEF3C7;
    color: #B45309;
}
.breadcrumb-link i { font-size: 11px; }
.breadcrumb-sep {
    font-size: 9px;
    color: var(--text-light);
}
.breadcrumb-current {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-primary);
    padding: 4px 10px;
    background: var(--bg-input);
    border-radius: 6px;
}

/* ============================================================
   BRANCH INDICATOR
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    border-radius: 12px;
    padding: 14px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.35);
    flex-wrap: wrap;
    gap: 12px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    flex: 1; position: relative; z-index: 1; min-width: 0;
}
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.9;
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-indicator-name { font-weight: 800; font-size: 16px; }
.branch-indicator-code {
    font-size: 11px; font-weight: 700; padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2); border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: 'Courier New', monospace;
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 11px;
    color: rgba(255,255,255,0.9); padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12); border-radius: 12px;
    white-space: nowrap;
}
.branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.date-display {
    font-size: 13px;
    color: rgba(255,255,255,0.95);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    display: flex; align-items: center; gap: 6px;
    font-weight: 600;
}

/* ============================================================
   CASH SUMMARY CARD
   ============================================================ */
.cash-summary-card {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 50%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    box-shadow: 0 8px 24px rgba(217, 119, 6, 0.15);
    position: relative;
    overflow: hidden;
}
.cash-summary-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.4) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.cash-summary-icon {
    width: 72px; height: 72px;
    border-radius: 20px;
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; flex-shrink: 0;
    box-shadow: 0 6px 20px rgba(217, 119, 6, 0.4);
    border: 3px solid rgba(255, 255, 255, 0.5);
    position: relative;
    z-index: 1;
}
.cash-summary-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
    z-index: 1;
}
.cash-summary-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #92400E;
    opacity: 0.85;
}
.cash-summary-value {
    font-size: 32px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    color: #78350F;
    line-height: 1.15;
    text-shadow: 0 2px 8px rgba(255, 255, 255, 0.5);
    word-break: break-word;
}
.cash-summary-sub {
    font-size: 11px;
    font-weight: 600;
    color: #92400E;
    opacity: 0.85;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.cash-summary-sub i { font-size: 10px; }

.cash-summary-stats {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    padding: 16px 24px;
    background: rgba(255, 255, 255, 0.6);
    border-radius: 14px;
    border: 1.5px solid rgba(217, 119, 6, 0.2);
    position: relative;
    z-index: 1;
    backdrop-filter: blur(8px);
}
.cash-stat-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 110px;
}
.cash-stat-label {
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #92400E;
    opacity: 0.75;
}
.cash-stat-value {
    font-size: 16px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.cash-stat-value i { font-size: 12px; }
.cash-stat-deposit { color: #059669; }
.cash-stat-withdrawal { color: #DC2626; }
.cash-stat-positive { color: #059669; }
.cash-stat-negative { color: #DC2626; }
.cash-stat-count {
    font-size: 9px;
    font-weight: 700;
    color: #92400E;
    opacity: 0.65;
}
.cash-stat-divider {
    width: 1px;
    height: 40px;
    background: rgba(217, 119, 6, 0.25);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary);
    display: flex; align-items: center; gap: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-action-big {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px; padding: 12px 22px; border: none; border-radius: 10px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    letter-spacing: 0.5px; text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.btn-action-edit {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
}
.btn-action-edit:hover {
    background: linear-gradient(135deg, #B45309 0%, #92400E 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(217, 119, 6, 0.4);
    color: #FFFFFF;
}
.btn-action-back {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-action-back:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
    transform: translateY(-2px);
}

/* ============================================================
   PROVIDER BREAKDOWN
   ============================================================ */
.provider-breakdown-section {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    padding: 18px 20px;
    margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.provider-breakdown-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
}
.provider-breakdown-title {
    font-size: 14px; font-weight: 800; color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.provider-breakdown-title i { color: #D97706; }
.provider-breakdown-count {
    font-size: 11px; font-weight: 800;
    padding: 4px 12px;
    background: #FEF3C7;
    color: #92400E;
    border-radius: 8px;
    border: 1.5px solid #FCD34D;
}
.provider-breakdown-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.provider-breakdown-card {
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
.provider-breakdown-card:hover {
    transform: translateY(-2px);
    border-color: #D97706;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.15);
}
.provider-breakdown-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 18px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.provider-breakdown-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.provider-breakdown-name {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.provider-breakdown-stats {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 10px;
    font-weight: 700;
}
.provider-stat-in {
    color: #059669;
    font-family: 'Courier New', monospace;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.provider-stat-out {
    color: #DC2626;
    font-family: 'Courier New', monospace;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.provider-breakdown-count-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 10px;
    background: #FEF3C7;
    color: #92400E;
    border-radius: 6px;
    border: 1.5px solid #FCD34D;
    white-space: nowrap;
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* ============================================================
   TABLE HEADER
   ============================================================ */
.table-header {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    padding: 16px 20px;
    color: #FFFFFF;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    overflow: hidden;
}
.table-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.table-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}
.table-header-left i {
    font-size: 22px;
    background: rgba(255,255,255,0.18);
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
    flex-shrink: 0;
}
.table-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    white-space: nowrap;
}
.count-badge {
    background: rgba(255,255,255,0.22);
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    white-space: nowrap;
}
.table-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
    justify-content: flex-end;
    min-width: 0;
}

/* LIVE SEARCH */
.table-search-live {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.98);
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 10px;
    padding: 8px 14px;
    min-width: 240px;
    max-width: 320px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.table-search-live:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
}
.table-search-live > i {
    color: #D97706;
    font-size: 13px;
    flex-shrink: 0;
}
.table-search-live input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1e293b;
    outline: none;
    min-width: 0;
    font-weight: 500;
}
.table-search-live input::placeholder {
    color: #94a3b8;
    font-size: 12px;
}
.search-clear-btn {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.search-clear-btn:hover {
    background: #DC2626;
    color: #FFFFFF;
}
.search-count-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* ============================================================
   DATA TABLE
   ============================================================ */
.table-wrapper {
    overflow-x: auto !important;
    max-width: 100% !important;
    width: 100% !important;
    -webkit-overflow-scrolling: touch;
    display: block;
    scroll-behavior: smooth;
}
.table-wrapper::-webkit-scrollbar { height: 8px; }
.table-wrapper::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 4px;
}
.table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #D97706, #B45309);
    border-radius: 4px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1100px;
}
.data-table thead tr { background: var(--bg-table-even); }
.data-table thead th {
    padding: 14px 12px;
    text-align: left;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody td {
    padding: 12px;
    color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

.data-table tbody tr.search-match {
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important;
    border-left: 4px solid #F59E0B;
}
.data-table tbody tr.search-hidden { display: none !important; }
.data-table mark {
    background: #FEF08A;
    color: #78350F;
    padding: 1px 3px;
    border-radius: 3px;
    font-weight: 800;
}

/* ROW NUMBER */
.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--bg-input);
    font-size: 11px;
    font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

/* DATE CELL */
.date-cell {
    display: inline-flex;
    flex-direction: column;
    gap: 2px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
}
.date-main {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 12px;
    font-weight: 800;
    color: #D97706;
}
.time-sub {
    font-size: 10px;
    color: var(--text-muted);
}

/* TYPE BADGE */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.type-badge-deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #86EFAC;
}
.type-badge-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .type-badge-deposit {
    background: #14532D;
    color: #4ADE80;
    border-color: #16A34A;
}
html.dark-mode .type-badge-withdrawal {
    background: #7F1D1D;
    color: #FCA5A5;
    border-color: #DC2626;
}

/* PROVIDER CELL */
.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.provider-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 160px;
}

/* CODE BADGE */
.code-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .code-badge {
    background: #1E3A5F;
    color: #60A5FA;
    border-color: #3B82F6;
}

/* AMOUNT */
.amount-txn {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 8px;
    font-weight: 900;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.amount-txn-deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #86EFAC;
}
.amount-txn-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .amount-txn-deposit {
    background: #14532D;
    color: #4ADE80;
    border-color: #16A34A;
}
html.dark-mode .amount-txn-withdrawal {
    background: #7F1D1D;
    color: #FCA5A5;
    border-color: #DC2626;
}

/* REFERENCE */
.ref-number {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    color: var(--text-secondary);
    white-space: nowrap;
}

/* EMPLOYEE CELL */
.employee-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.employee-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 12px;
    color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #D97706, #B45309);
    border: 2px solid #FCD34D;
    object-fit: cover;
}
.employee-name-sm {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 120px;
}

/* TOTALS ROW */
.data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%) !important;
    border-top: 3px solid #D97706;
    border-bottom: 3px solid #D97706;
}
html.dark-mode .data-table tbody tr.totals-row {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%) !important;
}
.data-table tbody tr.totals-row td {
    padding: 14px 12px;
    font-weight: 900;
    color: var(--text-primary);
    font-size: 12px;
}

/* EMPTY STATE */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}
.empty-state i {
    font-size: 56px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0 0 16px 0;
}

.btn {
    padding: 10px 22px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-secondary {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .cash-summary-value { font-size: 26px; }
}
@media (max-width: 900px) {
    .table-header { flex-direction: column; align-items: stretch; }
    .table-header-right { width: 100%; justify-content: space-between; }
    .table-search-live { min-width: 0; max-width: none; flex: 1; }
}
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .cash-summary-card { flex-direction: column; align-items: stretch; padding: 20px; gap: 16px; }
    .cash-summary-icon { width: 60px; height: 60px; font-size: 26px; align-self: flex-start; }
    .cash-summary-value { font-size: 24px; }
    .cash-summary-stats { flex-direction: column; gap: 12px; padding: 14px 18px; }
    .cash-stat-divider { width: 100%; height: 1px; }
    .cash-stat-item { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn-action-big { flex: 1; justify-content: center; }
    .provider-breakdown-grid { grid-template-columns: 1fr; }
    .data-table { min-width: 900px; }
}
@media (max-width: 480px) {
    .cash-summary-value { font-size: 20px; }
    .cash-summary-icon { width: 50px; height: 50px; font-size: 22px; }
    .table-search-live { width: 100%; }
    .breadcrumb-bar { padding: 8px 12px; gap: 5px; }
    .breadcrumb-link, .breadcrumb-current { font-size: 11px; padding: 3px 8px; }
}
</style>

<script>
// ============================================================
// LIVE SEARCH
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('cashTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.txn-row');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResults = document.getElementById('noSearchResults');
    const tableWrapper = document.querySelector('.table-wrapper');
    
    const term = searchTerm.trim();
    
    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    
    if (term.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeAllMarks(row);
        });
        if (countBadge) {
            countBadge.style.display = 'none';
            countBadge.textContent = '0';
        }
        if (noResults) noResults.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = '';
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    
    rows.forEach(row => {
        removeAllMarks(row);
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();
        
        if (searchText.includes(searchLower) || rowText.includes(searchLower)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatchesInRow(row, term);
            matchCount++;
        } else {
            row.classList.add('search-hidden');
            row.classList.remove('search-match');
        }
    });
    
    if (countBadge) {
        countBadge.textContent = matchCount;
        countBadge.style.display = matchCount > 0 ? 'inline-block' : 'none';
    }
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
    if (tableWrapper) tableWrapper.style.display = matchCount === 0 ? 'none' : '';
}

function removeAllMarks(row) {
    const marks = row.querySelectorAll('mark');
    if (marks.length === 0) return;
    marks.forEach(mark => {
        if (mark.parentNode) {
            const textNode = document.createTextNode(mark.textContent);
            mark.parentNode.replaceChild(textNode, mark);
        }
    });
    const cells = row.querySelectorAll('td');
    cells.forEach(cell => cell.normalize());
}

function highlightMatchesInRow(row, term) {
    if (!term || term.length === 0) return;
    const searchLower = term.toLowerCase();
    const termLength = term.length;
    const cells = row.querySelectorAll('td');
    
    cells.forEach(cell => {
        if (cell.querySelector('button')) return;
        if (cell.querySelector('img') && cell.textContent.trim() === '') return;
        
        const walker = document.createTreeWalker(
            cell,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                    if (node.parentNode.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        
        textNodes.forEach(textNode => {
            const text = textNode.textContent;
            const lowerText = text.toLowerCase();
            if (!lowerText.includes(searchLower)) return;
            
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let index = lowerText.indexOf(searchLower);
            
            while (index !== -1) {
                if (index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex, index)));
                }
                const mark = document.createElement('mark');
                mark.textContent = text.substring(index, index + termLength);
                fragment.appendChild(mark);
                lastIndex = index + termLength;
                index = lowerText.indexOf(searchLower, lastIndex);
            }
            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
            }
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    });
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) {
        input.value = '';
        performLiveSearch('');
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const searchInput = document.getElementById('liveSearchInput');
        if (searchInput && searchInput.value.length > 0) {
            clearLiveSearch();
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.getElementById('liveSearchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
});

// ============================================================
// DARK MODE SYNC
// ============================================================
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