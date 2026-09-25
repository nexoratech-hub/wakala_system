<?php
// ================================================================
// FILE: modules/daily_report/view_provider.php
// VIEW DAILY REPORT PROVIDER DETAILS
// ✅ FIXED: Modern design na soft background cards
// ✅ FIXED: Dark mode inatumia html.dark-mode
// ✅ FIXED: Consistent styling na view_provider_transactions.php
// ✅ NEW: Hero card na stats zenye soft background
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
            p.provider_name as provider_full_name,
            p.provider_code as main_code,
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
        SELECT 
            drt.*,
            e.full_name as employee_name
        FROM daily_report_transactions drt
        LEFT JOIN employees e ON drt.created_by = e.id
        WHERE drt.daily_report_id = ?
        AND drt.provider_id = ?
        ORDER BY drt.id ASC
    ");
    $stmt->execute([$provider['daily_report_id'], $provider['provider_id']]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// Calculate net change
$net_change = floatval($provider['total_deposits']) - floatval($provider['total_withdrawals']);

// Provider display name (tumia provider_full_name kama ipo, vinginevyo drp.provider_name)
$provider_display_name = $provider['provider_full_name'] ?? $provider['provider_name'];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PROVIDER HERO CARD
        ============================================================ -->
        <div class="provider-hero-card" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($provider['color_code'] ?? '#3B82F6'); ?> 0%, <?php echo htmlspecialchars($provider['color_code'] ?? '#3B82F6'); ?>dd 100%);">
            <div class="provider-hero-left">
                <div class="provider-hero-icon">
                    <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                </div>
                <div class="provider-hero-info">
                    <span class="provider-hero-label">Provider Details</span>
                    <h1 class="provider-hero-name"><?php echo htmlspecialchars($provider_display_name); ?></h1>
                    <div class="provider-hero-meta">
                        <span class="provider-meta-item">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($provider['provider_code']); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-layer-group"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $provider['provider_type'] ?? 'Bank')); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($provider['branch_name'] ?? 'Main'); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y', strtotime($provider['report_date'])); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="provider-hero-right">
                <div class="hero-stat-box">
                    <span class="hero-stat-label">Current Float</span>
                    <span class="hero-stat-value hero-float-value"><?php echo formatCurrency($provider['current_float']); ?></span>
                </div>
                <div class="hero-stat-box">
                    <span class="hero-stat-label">Net Change</span>
                    <span class="hero-stat-value <?php echo $net_change >= 0 ? 'hero-positive' : 'hero-negative'; ?>">
                        <?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        ACTION BUTTONS
        ============================================================ -->
        <div class="action-bar">
            <div class="action-bar-left">
                <span class="action-bar-info">
                    <i class="fas fa-file-alt"></i>
                    Report: <strong><?php echo htmlspecialchars($provider['report_number']); ?></strong>
                </span>
                <span class="action-bar-info">
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars($provider['employee_name'] ?? 'N/A'); ?>
                </span>
            </div>
            <div class="action-bar-right">
                <a href="edit_provider.php?id=<?php echo $id; ?>&branch_id=<?php echo $provider['branch_id']; ?>" class="btn-action btn-action-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button type="button" class="btn-action btn-action-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="view.php?id=<?php echo $provider['daily_report_id']; ?>" class="btn-action btn-action-view">
                    <i class="fas fa-file-alt"></i> View Report
                </a>
                <a href="index.php?branch_id=<?php echo $provider['branch_id']; ?>" class="btn-action btn-action-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - SOFT BACKGROUND
        ============================================================ -->
        <div class="stats-grid-soft">
            <!-- Morning Float -->
            <div class="stat-card-soft stat-card-soft-morning">
                <div class="stat-icon-soft">
                    <i class="fas fa-sun"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Morning Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider['morning_float']); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-clock"></i>
                        Asubuhi
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Current Float -->
            <div class="stat-card-soft stat-card-soft-current">
                <div class="stat-icon-soft">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Current Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider['current_float']); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-clock"></i>
                        Sasa
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Deposits -->
            <div class="stat-card-soft stat-card-soft-deposit">
                <div class="stat-icon-soft">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Deposits</span>
                    <span class="stat-value-soft">+ <?php echo formatCurrency($provider['total_deposits']); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo count(array_filter($transactions, function($t) { return $t['transaction_type'] === 'deposit'; })); ?> txn
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Withdrawals -->
            <div class="stat-card-soft stat-card-soft-withdraw">
                <div class="stat-icon-soft">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Withdrawals</span>
                    <span class="stat-value-soft">- <?php echo formatCurrency($provider['total_withdrawals']); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo count(array_filter($transactions, function($t) { return $t['transaction_type'] !== 'deposit'; })); ?> txn
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
        </div>

        <!-- ============================================================
        PROVIDER DETAILS
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    Provider Information
                </h3>
                <span class="info-badge">
                    <i class="fas fa-lock"></i> Read-only
                </span>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon info-icon-blue">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Provider Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($provider_display_name); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-purple">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Provider Code</span>
                        <span class="info-value code-value"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-orange">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Provider Type</span>
                        <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $provider['provider_type'] ?? 'Bank')); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-green">
                        <i class="fas fa-hashtag"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Report Number</span>
                        <span class="info-value">
                            <a href="view.php?id=<?php echo $provider['daily_report_id']; ?>" class="info-link">
                                <?php echo htmlspecialchars($provider['report_number']); ?>
                            </a>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-blue">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Report Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($provider['report_date'])); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-purple">
                        <i class="fas fa-store-alt"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Branch</span>
                        <span class="info-value"><?php echo htmlspecialchars($provider['branch_name'] ?? 'Main'); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-green">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Employee</span>
                        <span class="info-value"><?php echo htmlspecialchars($provider['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon info-icon-orange">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="info-content">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($provider['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FINANCIAL BREAKDOWN
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>
                    <i class="fas fa-coins"></i>
                    Financial Breakdown
                </h3>
            </div>
            <div class="financial-grid-soft">
                <!-- Morning Float -->
                <div class="financial-item-soft financial-item-morning">
                    <div class="financial-icon-soft">
                        <i class="fas fa-sun"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Morning Float</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($provider['morning_float']); ?></span>
                    </div>
                </div>
                
                <!-- Current Float -->
                <div class="financial-item-soft financial-item-current">
                    <div class="financial-icon-soft">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Current Float</span>
                        <span class="financial-value-soft"><?php echo formatCurrency($provider['current_float']); ?></span>
                    </div>
                </div>
                
                <!-- Total Deposits -->
                <div class="financial-item-soft financial-item-deposit">
                    <div class="financial-icon-soft">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Deposits</span>
                        <span class="financial-value-soft">+ <?php echo formatCurrency($provider['total_deposits']); ?></span>
                    </div>
                </div>
                
                <!-- Total Withdrawals -->
                <div class="financial-item-soft financial-item-withdraw">
                    <div class="financial-icon-soft">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Total Withdrawals</span>
                        <span class="financial-value-soft">- <?php echo formatCurrency($provider['total_withdrawals']); ?></span>
                    </div>
                </div>
                
                <!-- Net Change -->
                <div class="financial-item-soft financial-item-net <?php echo $net_change >= 0 ? 'net-positive' : 'net-negative'; ?>">
                    <div class="financial-icon-soft">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="financial-content-soft">
                        <span class="financial-label-soft">Net Change</span>
                        <span class="financial-value-soft">
                            <?php echo ($net_change >= 0 ? '+' : '') . formatCurrency($net_change); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TRANSACTIONS
        ============================================================ -->
        <?php if (!empty($transactions)): ?>
        <div class="transactions-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-exchange-alt"></i>
                    Transactions
                    <span class="section-count"><?php echo count($transactions); ?></span>
                </h2>
                <a href="view_provider_transactions.php?provider_id=<?php echo $provider['provider_id']; ?>&branch_id=<?php echo $provider['branch_id']; ?>&report_id=<?php echo $provider['daily_report_id']; ?>&report_date=<?php echo $provider['report_date']; ?>" 
                   class="btn-view-all">
                    <i class="fas fa-eye"></i> View All Transactions
                </a>
            </div>
            
            <div class="transactions-table-wrapper">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Reference</th>
                            <th>Employee</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($transactions as $t): 
                            $is_deposit = ($t['transaction_type'] === 'deposit');
                        ?>
                            <tr>
                                <td class="row-number"><?php echo $i++; ?></td>
                                <td>
                                    <span class="date-cell">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($t['transaction_date'])); ?>
                                    </span>
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
                                    <span class="description-cell"><?php echo htmlspecialchars($t['description'] ?? '-'); ?></span>
                                </td>
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
   PROVIDER HERO CARD
   ============================================================ */
.provider-hero-card {
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.provider-hero-card::before {
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

.provider-hero-card::after {
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

.provider-hero-left {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.provider-hero-icon {
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

.provider-hero-info {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.provider-hero-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}

.provider-hero-name {
    font-size: 26px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.3px;
    line-height: 1.1;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}

.provider-hero-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.provider-meta-item {
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

.provider-meta-item i { font-size: 11px; opacity: 0.9; }

.provider-hero-right {
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
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.hero-float-value { color: #FFFFFF; }
.hero-positive { color: #86EFAC; }
.hero-negative { color: #FCA5A5; }

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
    color: #3B82F6;
    font-size: 12px;
}

.action-bar-info strong {
    color: #1D4ED8;
    font-family: 'Courier New', monospace;
    font-weight: 800;
}

html.dark-mode .action-bar-info strong { color: #60A5FA; }

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

.btn-action-view {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-action-view:hover {
    background: var(--bg-body);
    color: var(--text-primary);
    transform: translateY(-2px);
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

/* SOFT ORANGE - Morning Float */
.stat-card-soft-morning {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.stat-card-soft-morning .stat-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.stat-card-soft-morning .stat-value-soft { color: #B45309; }

/* SOFT BLUE - Current Float */
.stat-card-soft-current {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.stat-card-soft-current .stat-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.stat-card-soft-current .stat-value-soft { color: #1D4ED8; }

/* SOFT GREEN - Deposits */
.stat-card-soft-deposit {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.stat-card-soft-deposit .stat-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.stat-card-soft-deposit .stat-value-soft { color: #047857; }

/* SOFT RED - Withdrawals */
.stat-card-soft-withdraw {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.stat-card-soft-withdraw .stat-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.stat-card-soft-withdraw .stat-value-soft { color: #B91C1C; }

/* Dark mode */
html.dark-mode .stat-card-soft-morning { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
html.dark-mode .stat-card-soft-current { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-deposit { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-withdraw { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .stat-card-soft-morning .stat-value-soft { color: #FBBF24; }
html.dark-mode .stat-card-soft-current .stat-value-soft { color: #60A5FA; }
html.dark-mode .stat-card-soft-deposit .stat-value-soft { color: #34D399; }
html.dark-mode .stat-card-soft-withdraw .stat-value-soft { color: #FCA5A5; }

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
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-sub-soft i {
    font-size: 9px;
    color: var(--text-light);
    flex-shrink: 0;
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
    color: #2563EB;
    font-size: 16px;
}

.info-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(37, 99, 235, 0.15);
    color: #1D4ED8;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}

html.dark-mode .info-badge {
    background: rgba(96, 165, 250, 0.2);
    color: #93C5FD;
    border-color: rgba(96, 165, 250, 0.4);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
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
    border-color: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
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

.info-icon-blue {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border-color: #93C5FD;
}

.info-icon-purple {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border-color: #C4B5FD;
}

.info-icon-orange {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border-color: #FCD34D;
}

.info-icon-green {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border-color: #6EE7B7;
}

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
    color: #7C3AED;
    background: #EDE9FE;
    padding: 2px 10px;
    border-radius: 6px;
    align-self: flex-start;
    font-size: 12px;
}

html.dark-mode .info-value.code-value {
    background: #2D1B5F;
    color: #C4B5FD;
}

.info-link {
    color: #1D4ED8;
    text-decoration: none;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: 12px;
    background: #DBEAFE;
    padding: 2px 10px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: inline-block;
}

.info-link:hover {
    background: #1D4ED8;
    color: #FFFFFF;
}

html.dark-mode .info-link { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .info-link:hover { background: #2563EB; color: #FFFFFF; }

/* ============================================================
   FINANCIAL GRID - SOFT BACKGROUND
   ============================================================ */
.financial-grid-soft {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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

.financial-item-net {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.financial-item-net .financial-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.financial-item-net.net-positive .financial-value-soft { color: #047857; }
.financial-item-net.net-negative .financial-value-soft { color: #B91C1C; }

/* Dark mode */
html.dark-mode .financial-item-morning { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
html.dark-mode .financial-item-current { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .financial-item-deposit { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .financial-item-withdraw { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .financial-item-net { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .financial-item-morning .financial-value-soft { color: #FBBF24; }
html.dark-mode .financial-item-current .financial-value-soft { color: #60A5FA; }
html.dark-mode .financial-item-deposit .financial-value-soft { color: #34D399; }
html.dark-mode .financial-item-withdraw .financial-value-soft { color: #FCA5A5; }
html.dark-mode .financial-item-net .financial-value-soft { color: #C4B5FD; }

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
   TRANSACTIONS SECTION
   ============================================================ */
.transactions-section {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 20px 22px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.section-header h2 {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-header h2 i { color: #2563EB; font-size: 17px; }

.section-count {
    font-size: 12px;
    font-weight: 800;
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 4px 12px;
    border-radius: 12px;
    margin-left: 6px;
}

html.dark-mode .section-count { background: #1E3A5F; color: #60A5FA; }

.btn-view-all {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    transition: all 0.25s ease;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    white-space: nowrap;
}

.btn-view-all:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
    color: #FFFFFF;
}

.transactions-table-wrapper {
    overflow-x: auto;
    max-width: 100%;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
}

.transactions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 800px;
}

.transactions-table thead {
    background: var(--bg-input);
}

.transactions-table thead th {
    padding: 12px 14px;
    text-align: left;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
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
.transactions-table tbody tr:last-child { border-bottom: none; }

.transactions-table tbody td {
    padding: 12px 14px;
    color: var(--text-primary);
    vertical-align: middle;
}

.transactions-table tbody td.text-right { text-align: right; }

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

.date-cell {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
}

.date-cell i { color: #2563EB; font-size: 10px; }

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

.description-cell {
    font-size: 11px;
    color: var(--text-muted);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.text-success { color: #059669 !important; font-weight: 800; }
.text-danger { color: #DC2626 !important; font-weight: 800; }
html.dark-mode .text-success { color: #34D399 !important; }
html.dark-mode .text-danger { color: #FCA5A5 !important; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .info-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 1200px) {
    .stats-grid-soft { grid-template-columns: repeat(2, 1fr); }
    .financial-grid-soft { grid-template-columns: repeat(3, 1fr); }
    .info-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .provider-hero-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
    }
    
    .provider-hero-name { font-size: 20px; }
    .provider-hero-icon { width: 56px; height: 56px; font-size: 22px; }
    .provider-hero-right { width: 100%; }
    .hero-stat-box { flex: 1; min-width: 0; }
    
    .stats-grid-soft { grid-template-columns: 1fr; }
    .financial-grid-soft { grid-template-columns: 1fr 1fr; }
    .info-grid { grid-template-columns: 1fr; }
    
    .action-bar { flex-direction: column; align-items: stretch; }
    .action-bar-left { justify-content: center; }
    .action-bar-right { width: 100%; }
    .btn-action { flex: 1; justify-content: center; }
    
    .section-header { flex-direction: column; align-items: flex-start; }
    .btn-view-all { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .provider-hero-name { font-size: 17px; }
    .provider-meta-item { font-size: 10px; padding: 3px 9px; }
    .hero-stat-value { font-size: 15px; }
    .hero-stat-box { padding: 10px 14px; min-width: 0; }
    .stat-value-soft { font-size: 16px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .financial-grid-soft { grid-template-columns: 1fr; }
    .financial-value-soft { font-size: 14px; }
}

/* ============================================================
   PRINT
   ============================================================ */
@media print {
    .action-bar,
    .btn-action,
    .provider-hero-card::before,
    .provider-hero-card::after { display: none !important; }
    
    .info-card,
    .stat-card-soft,
    .transactions-section,
    .financial-item-soft { 
        box-shadow: none; 
        border: 1px solid #ccc; 
        break-inside: avoid;
    }
    
    body { background: #FFFFFF !important; color: #000000 !important; }
    .provider-hero-card { color: #000000 !important; background: #F3F4F6 !important; }
    .provider-hero-name { color: #000000 !important; }
    .provider-meta-item { color: #000000 !important; background: #E5E7EB !important; border-color: #D1D5DB !important; }
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