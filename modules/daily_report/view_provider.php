<?php
// ================================================================
// FILE: modules/daily_report/view_provider.php
// VIEW DAILY REPORT PROVIDER DETAILS
// ✅ FIXED: Ondoa Cash cards (Cash ni ya report nzima)
// ✅ Shows provider details + transactions
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
// GET PROVIDER ROW WITH REPORT INFO
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT 
            drp.*,
            dr.report_number,
            dr.report_date,
            dr.branch_id,
            dr.branch,
            dr.employee_id,
            dr.created_at as report_created_at,
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            p.provider_type,
            p.icon_class,
            p.color_code
        FROM daily_report_providers drp
        INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
        LEFT JOIN employees e ON dr.employee_id = e.id
        LEFT JOIN branches b ON dr.branch_id = b.id
        LEFT JOIN providers p ON drp.provider_id = p.id
        WHERE drp.id = ?
    ");
    $stmt->execute([$id]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching provider: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$provider) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET RELATED TRANSACTIONS
// ============================================================
$transactions = [];
try {
    $stmt = $db->prepare("
        SELECT *
        FROM daily_report_transactions
        WHERE daily_report_id = ?
        AND provider_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$provider['daily_report_id'], $provider['provider_id']]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// Calculate net change
$net_change = floatval($provider['total_deposits']) - floatval($provider['total_withdrawals']);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- Branch Card -->
        <div class="branch-card">
            <div class="branch-card-left">
                <div class="branch-icon">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-label">Branch</span>
                    <span class="branch-name"><?php echo htmlspecialchars($provider['branch_name'] ?? 'Main'); ?></span>
                    <?php if ($provider['branch_code']): ?>
                        <span class="branch-code"><?php echo htmlspecialchars($provider['branch_code']); ?></span>
                    <?php endif; ?>
                    <?php if ($provider['branch_location']): ?>
                        <span class="branch-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($provider['branch_location']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?branch_id=<?php echo $provider['branch_id']; ?>" class="branch-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-university" style="color:#3B82F6;"></i> Provider Details</h2>
                <p class="text-muted">
                    Report: <strong><?php echo htmlspecialchars($provider['report_number']); ?></strong>
                    • Date: <?php echo date('d M Y', strtotime($provider['report_date'])); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="edit_provider.php?id=<?php echo $id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="#" class="btn btn-info" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="view.php?id=<?php echo $provider['daily_report_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-file-alt"></i> View Report
                </a>
            </div>
        </div>

        <!-- ============================================================
        PROVIDER HERO CARD (WITHOUT CASH)
        ============================================================ -->
        <div class="provider-hero">
            <div class="provider-hero-icon" style="background: <?php echo htmlspecialchars($provider['color_code'] ?? '#3B82F6'); ?>;">
                <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
            </div>
            <div class="provider-hero-info">
                <h3><?php echo htmlspecialchars($provider['provider_name']); ?></h3>
                <div class="provider-hero-meta">
                    <span class="code-badge"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                    <span class="type-badge">
                        <i class="fas fa-tag"></i>
                        <?php echo ucfirst(str_replace('_', ' ', $provider['provider_type'] ?? 'Bank')); ?>
                    </span>
                </div>
            </div>
            <div class="provider-hero-stats">
                <div class="hero-stat">
                    <span class="hero-stat-label">Current Float</span>
                    <span class="hero-stat-value float-color"><?php echo formatCurrency($provider['current_float']); ?></span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-label">Net Change</span>
                    <span class="hero-stat-value <?php echo $net_change >= 0 ? 'net-positive' : 'net-negative'; ?>">
                        <?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS (WITHOUT CASH) - 4 CARDS
        ============================================================ -->
        <div class="summary-cards">
            <div class="summary-card card-float">
                <div class="summary-icon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Morning Float</span>
                    <span class="summary-value"><?php echo formatCurrency($provider['morning_float']); ?></span>
                </div>
            </div>
            
            <div class="summary-card card-current-float">
                <div class="summary-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Current Float</span>
                    <span class="summary-value"><?php echo formatCurrency($provider['current_float']); ?></span>
                </div>
            </div>
            
            <div class="summary-card card-deposit">
                <div class="summary-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Deposits</span>
                    <span class="summary-value">+ <?php echo formatCurrency($provider['total_deposits']); ?></span>
                </div>
            </div>
            
            <div class="summary-card card-withdrawal">
                <div class="summary-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Withdrawals</span>
                    <span class="summary-value">- <?php echo formatCurrency($provider['total_withdrawals']); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILS GRID
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-info-circle"></i> Provider Details</h3>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">Provider Name</span>
                    <span class="detail-value"><strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Provider Code</span>
                    <span class="detail-value">
                        <span class="code-badge"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Report Number</span>
                    <span class="detail-value">
                        <a href="view.php?id=<?php echo $provider['daily_report_id']; ?>" class="link">
                            <?php echo htmlspecialchars($provider['report_number']); ?>
                        </a>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Report Date</span>
                    <span class="detail-value"><?php echo date('d M Y', strtotime($provider['report_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Branch</span>
                    <span class="detail-value"><?php echo htmlspecialchars($provider['branch_name'] ?? 'Main'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Employee</span>
                    <span class="detail-value"><?php echo htmlspecialchars($provider['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Created At</span>
                    <span class="detail-value"><?php echo date('d M Y H:i', strtotime($provider['created_at'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Last Updated</span>
                    <span class="detail-value"><?php echo date('d M Y H:i', strtotime($provider['updated_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FINANCIAL BREAKDOWN (WITHOUT CASH)
        ============================================================ -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="fas fa-coins"></i> Financial Breakdown</h3>
            </div>
            <div class="financial-grid">
                <div class="financial-item">
                    <span class="financial-label">Morning Float</span>
                    <span class="financial-value amount-float"><?php echo formatCurrency($provider['morning_float']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Current Float</span>
                    <span class="financial-value amount-float-bold"><?php echo formatCurrency($provider['current_float']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Deposits</span>
                    <span class="financial-value amount-deposit">+ <?php echo formatCurrency($provider['total_deposits']); ?></span>
                </div>
                <div class="financial-item">
                    <span class="financial-label">Total Withdrawals</span>
                    <span class="financial-value amount-withdrawal">- <?php echo formatCurrency($provider['total_withdrawals']); ?></span>
                </div>
                <div class="financial-item highlight-strong">
                    <span class="financial-label">Net Change</span>
                    <span class="financial-value <?php echo $net_change >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TRANSACTIONS
        ============================================================ -->
        <?php if (!empty($transactions)): ?>
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-exchange-alt"></i>
                    Transactions
                    <span class="section-count"><?php echo count($transactions); ?></span>
                </h3>
            </div>
            <div class="transactions-table-wrapper">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Reference</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($transactions as $t): 
                            $is_deposit = ($t['transaction_type'] === 'deposit');
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo date('d M Y', strtotime($t['transaction_date'])); ?></td>
                                <td>
                                    <span class="type-badge <?php echo $is_deposit ? 'type-deposit' : 'type-withdrawal'; ?>">
                                        <i class="fas <?php echo $is_deposit ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
                                        <?php echo ucfirst($t['transaction_type']); ?>
                                    </span>
                                </td>
                                <td class="text-right <?php echo $is_deposit ? 'text-success' : 'text-danger'; ?>">
                                    <strong><?php echo ($is_deposit ? '+' : '-') . formatCurrency($t['amount']); ?></strong>
                                </td>
                                <td><span class="ref-badge"><?php echo htmlspecialchars($t['reference_number'] ?? '-'); ?></span></td>
                                <td><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; }

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
}
.main-wrapper { background: var(--bg-body) !important; }
.main-content { background: var(--bg-body) !important; padding: 16px 20px !important; }

/* Branch Card */
.branch-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px; padding: 12px 20px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 10px; color: #FFFFFF;
}
.branch-card-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.branch-icon {
    width: 38px; height: 38px; background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 16px;
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.branch-label {
    font-size: 10px; font-weight: 500; opacity: 0.7;
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-name { font-weight: 700; font-size: 14px; }
.branch-code {
    font-size: 10px; font-weight: 600;
    padding: 2px 10px; background: rgba(255, 255, 255, 0.15); border-radius: 12px;
}
.branch-location {
    font-size: 11px; opacity: 0.85;
    display: inline-flex; align-items: center; gap: 4px;
}
.branch-back {
    background: rgba(255,255,255,0.15); color: #FFFFFF;
    text-decoration: none; padding: 6px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
}
.branch-back:hover { background: rgba(255,255,255,0.25); color: #FFFFFF; }

/* Page Header */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 20px; font-weight: 700; margin: 0; }
.page-header .header-left .text-muted {
    font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.btn {
    padding: 8px 18px; border: none; border-radius: 8px;
    font-weight: 600; font-size: 13px; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center;
    gap: 6px; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-warning { background: #F59E0B; color: white; }
.btn-warning:hover { background: #D97706; color: white; transform: translateY(-1px); }
.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; color: white; transform: translateY(-1px); }
.btn-secondary {
    background: var(--bg-table-even); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); color: var(--text-primary); }

/* Provider Hero */
.provider-hero {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
    border-radius: 12px; padding: 20px 24px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 20px;
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
}
.provider-hero-icon {
    width: 64px; height: 64px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 26px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    border: 2px solid rgba(255,255,255,0.2);
}
.provider-hero-info { flex: 1; min-width: 0; }
.provider-hero-info h3 {
    font-size: 22px; font-weight: 800; color: #FFFFFF;
    margin: 0 0 6px 0; letter-spacing: 0.3px;
}
.provider-hero-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.provider-hero-meta .code-badge {
    background: rgba(255,255,255,0.25); color: #FFFFFF;
    border-color: rgba(255,255,255,0.3);
}
.provider-hero-meta .type-badge {
    font-size: 11px; font-weight: 600; padding: 4px 12px;
    background: rgba(255,255,255,0.15); color: #FFFFFF;
    border-radius: 12px; display: inline-flex;
    align-items: center; gap: 5px;
    border: 1px solid rgba(255,255,255,0.2);
}
.provider-hero-stats {
    display: flex; gap: 12px; flex-wrap: wrap;
}
.hero-stat {
    display: flex; flex-direction: column; align-items: center;
    padding: 8px 16px; background: rgba(255,255,255,0.15);
    border-radius: 10px; border: 1px solid rgba(255,255,255,0.2);
    min-width: 130px;
}
.hero-stat-label {
    font-size: 9px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 3px;
}
.hero-stat-value {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.3px;
}
.hero-stat .float-color { color: #BFDBFE; }
.hero-stat .net-positive { color: #86EFAC; }
.hero-stat .net-negative { color: #FCA5A5; }

/* Summary Cards - 4 Cards */
.summary-cards {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin-bottom: 16px;
}
.summary-card {
    background: var(--bg-card); border-radius: 10px; padding: 14px 18px;
    border: 1px solid var(--border-color); display: flex;
    align-items: center; gap: 14px; transition: all 0.3s ease;
    position: relative; overflow: hidden;
}
.summary-card::before {
    content: ''; position: absolute;
    top: 0; left: 0; width: 4px; height: 100%;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
}
.card-float::before { background: #3B82F6; }
.card-current-float::before { background: #6366F1; }
.card-deposit::before { background: #16A34A; }
.card-withdrawal::before { background: #DC2626; }

.summary-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.card-float .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-current-float .summary-icon { background: #E0E7FF; color: #4338CA; }
.card-deposit .summary-icon { background: #DCFCE7; color: #15803D; }
.card-withdrawal .summary-icon { background: #FEE2E2; color: #991B1B; }

html.dark-mode .card-float .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-current-float .summary-icon { background: #312E81; color: #A5B4FC; }
html.dark-mode .card-deposit .summary-icon { background: #14532D; color: #4ADE80; }
html.dark-mode .card-withdrawal .summary-icon { background: #7F1D1D; color: #FCA5A5; }

.summary-info { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.summary-label {
    font-size: 10px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    font-weight: 700; margin-bottom: 3px;
}
.summary-value {
    font-size: 16px; font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    word-break: break-word;
}

/* Section Card */
.section-card {
    background: var(--bg-card); border-radius: 12px;
    border: 1px solid var(--border-color); margin-bottom: 16px;
    overflow: hidden; box-shadow: 0 1px 3px var(--shadow-color);
}
.section-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border-color);
    background: var(--bg-table-even);
}
.section-header h3 {
    font-size: 14px; font-weight: 700; color: var(--text-primary);
    margin: 0; display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap;
}
.section-header h3 i { color: #3B82F6; }
.section-count {
    margin-left: auto; font-size: 11px; font-weight: 600;
    padding: 3px 12px; background: #3B82F6;
    color: #FFFFFF; border-radius: 12px;
}

/* Details Grid */
.details-grid {
    display: grid; grid-template-columns: repeat(2, 1fr);
    gap: 0;
}
.detail-item {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    display: flex; flex-direction: column; gap: 4px;
}
.detail-item:nth-child(2n) { border-right: none; }
.detail-item:nth-last-child(-n+2) { border-bottom: none; }

.detail-label {
    font-size: 10px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.detail-value {
    font-size: 14px; color: var(--text-primary);
}
.detail-value strong { color: var(--text-primary); font-weight: 700; }

.code-badge {
    display: inline-block; padding: 3px 10px;
    background: #DBEAFE; color: #1D4ED8; border-radius: 8px;
    font-size: 10px; font-weight: 700;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; }

.link {
    color: #3B82F6; text-decoration: none; font-weight: 700;
    font-family: 'Courier New', monospace; font-size: 12px;
}
.link:hover { text-decoration: underline; }

/* Financial Grid - 5 items */
.financial-grid {
    display: grid; grid-template-columns: repeat(5, 1fr);
    gap: 0;
}
.financial-item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    display: flex; flex-direction: column; gap: 6px;
    text-align: center;
}
.financial-item:nth-child(5n) { border-right: none; }
.financial-item:nth-last-child(-n+5) { border-bottom: none; }
.financial-item.highlight-strong {
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.15), rgba(252, 211, 77, 0.05));
    border-top: 2px solid #FCD34D;
}

.financial-label {
    font-size: 10px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.financial-value {
    font-size: 16px; font-weight: 800;
    font-family: 'Courier New', monospace;
}

/* Themed Amounts */
.amount-float {
    display: inline-block; padding: 3px 10px;
    background: #DBEAFE; color: #1D4ED8;
    border-radius: 6px; font-weight: 700; font-size: 14px;
    font-family: 'Courier New', monospace;
    border: 1px solid #BFDBFE;
}
.amount-float-bold {
    display: inline-block; padding: 3px 10px;
    background: #BFDBFE; color: #1E40AF;
    border-radius: 6px; font-weight: 800; font-size: 15px;
    font-family: 'Courier New', monospace;
    border: 1px solid #93C5FD;
}
.amount-deposit {
    display: inline-block; padding: 3px 10px;
    background: #DCFCE7; color: #15803D;
    border-radius: 6px; font-weight: 800; font-size: 15px;
    font-family: 'Courier New', monospace;
    border: 1px solid #BBF7D0;
}
.amount-withdrawal {
    display: inline-block; padding: 3px 10px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 6px; font-weight: 800; font-size: 15px;
    font-family: 'Courier New', monospace;
    border: 1px solid #FECACA;
}

html.dark-mode .amount-float { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .amount-float-bold { background: #1E40AF; color: #BFDBFE; }
html.dark-mode .amount-deposit { background: #14532D; color: #4ADE80; }
html.dark-mode .amount-withdrawal { background: #7F1D1D; color: #FCA5A5; }

.text-success { color: #10B981 !important; font-weight: 800; }
.text-danger { color: #DC2626 !important; font-weight: 800; }

/* Transactions Table */
.transactions-table-wrapper {
    overflow-x: auto; max-width: 100%;
}
.transactions-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
    min-width: 700px;
}
.transactions-table thead {
    background: var(--bg-table-even);
}
.transactions-table thead th {
    padding: 11px 14px; text-align: left;
    color: var(--text-muted); font-size: 10px;
    font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.transactions-table thead th.text-right { text-align: right; }
.transactions-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.transactions-table tbody tr:hover { background: var(--bg-table-hover); }
.transactions-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.transactions-table tbody td {
    padding: 11px 14px; color: var(--text-secondary);
    vertical-align: middle;
}
.transactions-table tbody td.text-right { text-align: right; }

.type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 10px;
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
}
.type-deposit { background: #D1FAE5; color: #065F46; }
.type-withdrawal { background: #FEE2E2; color: #991B1B; }
html.dark-mode .type-deposit { background: #065F46; color: #34D399; }
html.dark-mode .type-withdrawal { background: #7F1D1D; color: #FCA5A5; }

.ref-badge {
    background: var(--bg-table-even); padding: 3px 10px;
    border-radius: 6px; font-size: 11px; font-weight: 600;
    color: var(--text-muted); font-family: 'Courier New', monospace;
}

/* Responsive */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, 1fr); }
    .financial-grid { grid-template-columns: repeat(3, 1fr); }
    .financial-item:nth-child(5n) { border-right: 1px solid var(--border-color); }
    .financial-item:nth-child(3n) { border-right: none; }
    .financial-item:nth-last-child(-n+5) { border-bottom: 1px solid var(--border-color); }
    .financial-item:nth-last-child(-n+3) { border-bottom: none; }
}

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .provider-hero { flex-direction: column; text-align: center; }
    .provider-hero-stats { width: 100%; justify-content: center; }
    .hero-stat { flex: 1; min-width: 0; }
    
    .summary-cards { grid-template-columns: 1fr 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .detail-item { border-right: none !important; }
    .financial-grid { grid-template-columns: repeat(2, 1fr); }
    .financial-item { border-right: none !important; }
    
    .branch-card { flex-direction: column; align-items: flex-start; }
    .branch-back { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .provider-hero-icon { width: 52px; height: 52px; font-size: 22px; }
    .provider-hero-info h3 { font-size: 18px; }
    .summary-cards { grid-template-columns: 1fr; }
    .summary-value { font-size: 14px; }
    .financial-grid { grid-template-columns: 1fr; }
}

@media print {
    .header-right, .branch-back, .btn { display: none !important; }
    .section-card, .summary-card, .provider-hero { box-shadow: none; border: 1px solid #ccc; }
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