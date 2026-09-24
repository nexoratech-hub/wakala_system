<?php
// ================================================================
// FILE: modules/evening_stock/view_employee.php
// EVENING STOCK - VIEW (EMPLOYEE)
// ✅ Employee can only VIEW
// ✅ No edit/delete/status update
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Get employee info
$stmt = $db->prepare("SELECT branch_id, branch, full_name FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = $emp['branch_id'] ?? 0;

$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    header('Location: index_employee.php');
    exit();
}

// Get stock data (employee can only see own branch)
try {
    $sql = "SELECT es.*, 
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            dr.report_number as daily_report_number
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
            WHERE es.id = ? AND es.branch_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$stock_id, $employee_branch_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stock) {
        $_SESSION['error_message'] = 'Evening stock not found or access denied.';
        header('Location: index_employee.php');
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error loading evening stock.';
    header('Location: index_employee.php');
    exit();
}

// Get providers
$stmt = $db->prepare("
    SELECT esp.*, 
           p.icon_class as provider_icon,
           p.color_code as provider_color
    FROM evening_stock_providers esp
    LEFT JOIN providers p ON esp.provider_id = p.id
    WHERE esp.evening_stock_id = ?
    ORDER BY esp.id ASC
");
$stmt->execute([$stock_id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_opening_float = 0;
$total_opening_cash = 0;
$total_closing_float = 0;
$total_closing_cash = 0;
$total_deposits = 0;
$total_withdrawals = 0;

foreach ($providers as $p) {
    $total_opening_float += floatval($p['opening_float']);
    $total_opening_cash += floatval($p['opening_cash']);
    $total_closing_float += floatval($p['closing_float']);
    $total_closing_cash += floatval($p['closing_cash']);
    $total_deposits += floatval($p['total_deposits']);
    $total_withdrawals += floatval($p['total_withdrawals']);
}

$grand_total = $total_closing_float + $total_closing_cash;

$status_labels = [
    'waiting' => ['label' => 'Waiting', 'icon' => 'fa-clock', 'color' => 'orange'],
    'approved' => ['label' => 'Approved', 'icon' => 'fa-check-circle', 'color' => 'green'],
    'adjusted' => ['label' => 'Adjusted', 'icon' => 'fa-sliders-h', 'color' => 'blue'],
    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red']
];

$status_info = $status_labels[$stock['status']] ?? ['label' => $stock['status'], 'icon' => 'fa-circle', 'color' => 'gray'];

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- Branch Card -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Evening Stock</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?></span>
                <?php if (!empty($stock['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                <?php endif; ?>
                <span class="branch-status-date">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                </span>
            </div>
            <a href="index_employee.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-invoice" style="color:#7C3AED;"></i> Evening Stock Details</h2>
                <p class="text-muted">Reference: <strong><?php echo htmlspecialchars($stock['stock_number']); ?></strong></p>
            </div>
            <div class="header-right">
                <span class="view-only-badge">
                    <i class="fas fa-eye"></i> View Only
                </span>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>

        <!-- Hero Card -->
        <div class="hero-card hero-<?php echo $status_info['color']; ?>">
            <div class="hero-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="hero-content">
                <span class="hero-label">Grand Total</span>
                <span class="hero-amount"><?php echo formatCurrency($grand_total); ?></span>
                <span class="hero-type">
                    <span class="hero-type-badge">
                        <i class="fas <?php echo $status_info['icon']; ?>"></i>
                        <?php echo $status_info['label']; ?>
                    </span>
                </span>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <span class="hmi-label">Float</span>
                    <span class="hmi-value"><i class="fas fa-university"></i> <?php echo formatCurrency($total_closing_float); ?></span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Cash</span>
                    <span class="hmi-value"><i class="fas fa-money-bill-wave"></i> <?php echo formatCurrency($total_closing_cash); ?></span>
                </div>
                <div class="hero-meta-item">
                    <span class="hmi-label">Providers</span>
                    <span class="hmi-value"><i class="fas fa-list"></i> <?php echo count($providers); ?></span>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="sc-icon sc-icon-blue"><i class="fas fa-university"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Total Float</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_float); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="sc-icon sc-icon-green"><i class="fas fa-money-bill-wave"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Total Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($total_closing_cash); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="sc-icon sc-icon-teal"><i class="fas fa-arrow-down"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Deposits</span>
                    <span class="sc-value text-success">+<?php echo formatCurrency($total_deposits); ?></span>
                </div>
            </div>
            <div class="summary-card">
                <div class="sc-icon sc-icon-red"><i class="fas fa-arrow-up"></i></div>
                <div class="sc-content">
                    <span class="sc-label">Withdrawals</span>
                    <span class="sc-value text-danger">-<?php echo formatCurrency($total_withdrawals); ?></span>
                </div>
            </div>
        </div>

        <!-- Stock Info -->
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-info-circle"></i> Stock Information</h3>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Stock Number</span>
                    <span class="detail-value detail-code"><?php echo htmlspecialchars($stock['stock_number']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-calendar"></i> Stock Date</span>
                    <span class="detail-value"><?php echo date('l, d M Y', strtotime($stock['stock_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-store-alt"></i> Branch</span>
                    <span class="detail-value">
                        <?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?>
                        <?php if (!empty($stock['branch_code'])): ?>
                            <span class="code-pill"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-clipboard-check"></i> Daily Report</span>
                    <span class="detail-value">
                        <?php if (!empty($stock['daily_report_number'])): ?>
                            <span class="code-pill"><?php echo htmlspecialchars($stock['daily_report_number']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-user"></i> Employee</span>
                    <span class="detail-value"><?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-clock"></i> Submitted</span>
                    <span class="detail-value"><?php echo date('d M Y, h:i A', strtotime($stock['submitted_at'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-tasks"></i> Status</span>
                    <span class="detail-value">
                        <span class="status-pill status-<?php echo $status_info['color']; ?>">
                            <i class="fas <?php echo $status_info['icon']; ?>"></i>
                            <?php echo $status_info['label']; ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Providers -->
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-university"></i> Provider Breakdown</h3>
                <span class="section-badge"><?php echo count($providers); ?> Providers</span>
            </div>
            <div class="providers-table-wrapper">
                <table class="providers-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Provider</th>
                            <th class="text-right">Opening Float</th>
                            <th class="text-right">Opening Cash</th>
                            <th class="text-right">Deposits</th>
                            <th class="text-right">Withdrawals</th>
                            <th class="text-right">Closing Float</th>
                            <th class="text-right">Closing Cash</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($providers as $p): 
                            $p_total = floatval($p['closing_float']) + floatval($p['closing_cash']);
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($p['provider_color'] ?? '#7C3AED'); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <div class="provider-info-cell">
                                            <span class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            <span class="provider-code"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right"><span class="amount-readonly"><?php echo formatCurrency($p['opening_float']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly"><?php echo formatCurrency($p['opening_cash']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly text-success">+<?php echo formatCurrency($p['total_deposits']); ?></span></td>
                                <td class="text-right"><span class="amount-readonly text-danger">-<?php echo formatCurrency($p['total_withdrawals']); ?></span></td>
                                <td class="text-right"><span class="amount-highlight"><?php echo formatCurrency($p['closing_float']); ?></span></td>
                                <td class="text-right"><span class="amount-highlight"><?php echo formatCurrency($p['closing_cash']); ?></span></td>
                                <td class="text-right"><span class="amount-total"><?php echo formatCurrency($p_total); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="2" class="text-right"><strong>TOTALS</strong></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_opening_float); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_opening_cash); ?></span></td>
                            <td class="text-right"><span class="total-value text-success">+<?php echo formatCurrency($total_deposits); ?></span></td>
                            <td class="text-right"><span class="total-value text-danger">-<?php echo formatCurrency($total_withdrawals); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_closing_float); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($total_closing_cash); ?></span></td>
                            <td class="text-right"><span class="total-value"><?php echo formatCurrency($grand_total); ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Notes -->
        <?php if (!empty($stock['notes'])): ?>
        <div class="details-card">
            <div class="section-header">
                <h3><i class="fas fa-sticky-note"></i> Notes</h3>
            </div>
            <div class="notes-section">
                <div class="note-block">
                    <div class="note-content"><?php echo nl2br(htmlspecialchars($stock['notes'])); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Actions (View Only) -->
        <div class="bottom-actions">
            <a href="index_employee.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <button onclick="window.print()" class="btn btn-print">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
/* ===== Same base CSS as view.php but adapted for employee ===== */
:root {
    --ev-bg: #F3F4F6;
    --ev-text: #1F2937;
    --ev-text-secondary: #6B7280;
    --ev-text-light: #9CA3AF;
    --ev-border: #E5E7EB;
    --ev-card-bg: #FFFFFF;
    --ev-input-bg: #F9FAFB;
    --ev-hover: #F3F4F6;
    --ev-shadow: rgba(0,0,0,0.06);
}
html.dark-mode {
    --ev-bg: #0F172A;
    --ev-text: #F9FAFB;
    --ev-text-secondary: #9CA3AF;
    --ev-text-light: #6B7280;
    --ev-border: #334155;
    --ev-card-bg: #1E293B;
    --ev-input-bg: #334155;
    --ev-hover: #334155;
    --ev-shadow: rgba(0,0,0,0.3);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
body { background: var(--ev-bg) !important; color: var(--ev-text); }
.main-wrapper { background: var(--ev-bg) !important; }
.main-content { background: var(--ev-bg) !important; padding: 16px 20px !important; }

/* Reuse all the CSS from view.php */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(124, 58, 237, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
}
.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
    position: relative;
    z-index: 1;
}
.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
    position: relative;
    z-index: 1;
}
.branch-status-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name { font-size: 18px; font-weight: 800; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.branch-status-date {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    font-weight: 600;
}
.btn-back-card {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; transform: translateX(-3px); }

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 16px;
    flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px;
    font-weight: 800;
    color: var(--ev-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted { font-size: 13px; color: var(--ev-text-secondary); margin: 4px 0 0 0; }
.header-left .text-muted strong { color: #7C3AED; font-family: 'Courier New', monospace; font-weight: 800; }

.view-only-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #6D28D9;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    border: 1.5px solid #C4B5FD;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
html.dark-mode .view-only-badge {
    background: linear-gradient(135deg, #2D1B5F, #3B1D6E);
    color: #A78BFA;
    border-color: #7C3AED;
}

.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert i { font-size: 20px; }

.hero-card {
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}
.hero-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 400px; height: 400px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
}
.hero-blue { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.hero-green { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.hero-purple { background: linear-gradient(135deg, #7C3AED 0%, #A855F7 100%); }
.hero-red { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.hero-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.hero-gray { background: linear-gradient(135deg, #4B5563 0%, #6B7280 100%); }
.hero-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative;
    z-index: 1;
}
.hero-content {
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    z-index: 1;
    min-width: 0;
}
.hero-label {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
.hero-amount {
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 900;
    color: #FFFFFF;
    font-family: 'Inter', 'Courier New', monospace;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    word-break: break-all;
}
.hero-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.hero-meta {
    display: flex;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.hero-meta-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    min-width: 120px;
}
.hmi-label {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.hmi-value {
    font-size: 13px;
    font-weight: 700;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.hmi-value i { font-size: 12px; color: #FCD34D; }

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: var(--ev-card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--ev-border);
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--ev-shadow);
    transition: all 0.3s ease;
}
.summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
.sc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    color: #FFFFFF;
}
.sc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); }
.sc-icon-green { background: linear-gradient(135deg, #10B981, #059669); }
.sc-icon-teal { background: linear-gradient(135deg, #14B8A6, #0D9488); }
.sc-icon-red { background: linear-gradient(135deg, #DC2626, #B91C1C); }
.sc-content { display: flex; flex-direction: column; gap: 3px; flex: 1; }
.sc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.sc-value {
    font-size: 16px;
    font-weight: 900;
    color: var(--ev-text);
    font-family: 'Inter', 'Courier New', monospace;
}
.text-success { color: #10B981; }
.text-danger { color: #DC2626; }
.text-muted { color: var(--ev-text-light); }

.details-card {
    background: var(--ev-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.section-header {
    padding: 16px 24px;
    background: var(--ev-hover);
    border-bottom: 1px solid var(--ev-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--ev-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i { color: #7C3AED; font-size: 15px; }
.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    background: var(--ev-card-bg);
    padding: 4px 14px;
    border-radius: 12px;
    border: 1px solid var(--ev-border);
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
}
.detail-item {
    padding: 16px 24px;
    border-bottom: 1px solid var(--ev-border);
    border-right: 1px solid var(--ev-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.detail-item:nth-child(2n) { border-right: none; }
.detail-item:nth-last-child(-n+2) { border-bottom: none; }
.detail-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--ev-text-light);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.detail-label i { color: #7C3AED; font-size: 12px; }
.detail-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--ev-text);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.detail-code {
    font-family: 'Courier New', monospace;
    color: #7C3AED;
    font-size: 14px;
}
.code-pill {
    font-size: 11px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 3px 10px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.status-orange { background: #FEF3C7; color: #92400E; }
.status-green { background: #D1FAE5; color: #065F46; }
.status-blue { background: #DBEAFE; color: #1D4ED8; }
.status-red { background: #FEE2E2; color: #991B1B; }

.providers-table-wrapper { overflow-x: auto; background: var(--ev-card-bg); }
.providers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}
.providers-table thead { background: linear-gradient(135deg, #7C3AED, #6D28D9); }
.providers-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody tr { border-bottom: 1px solid var(--ev-border); }
.providers-table tbody tr:hover { background: var(--ev-hover); }
.providers-table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ev-text);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }

.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon-sm {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.provider-info-cell { display: flex; flex-direction: column; gap: 2px; }
.provider-name { font-size: 12px; font-weight: 700; color: var(--ev-text); }
.provider-code {
    font-size: 9px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 1px 6px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}

.amount-readonly {
    font-size: 12px;
    font-weight: 700;
    color: var(--ev-text-secondary);
    font-family: 'Courier New', monospace;
}
.amount-readonly.text-success { color: #10B981; }
.amount-readonly.text-danger { color: #DC2626; }
.amount-highlight {
    font-size: 13px;
    font-weight: 800;
    color: #7C3AED;
    font-family: 'Courier New', monospace;
}
.amount-total {
    font-size: 14px;
    font-weight: 900;
    color: #059669;
    font-family: 'Courier New', monospace;
}
.providers-table tfoot { background: var(--ev-hover); }
.providers-table tfoot td {
    padding: 14px;
    border-top: 2px solid var(--ev-border);
}
.totals-row strong {
    font-size: 12px;
    letter-spacing: 1px;
    color: #7C3AED;
    text-transform: uppercase;
}
.total-value {
    font-size: 14px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #7C3AED;
}
.total-value.text-success { color: #10B981; }
.total-value.text-danger { color: #DC2626; }

.notes-section { padding: 20px 24px; }
.note-block {
    background: var(--ev-hover);
    border-radius: 10px;
    border-left: 4px solid #7C3AED;
    padding: 14px 18px;
}
.note-content {
    font-size: 14px;
    color: var(--ev-text);
    line-height: 1.6;
    font-weight: 500;
}

.bottom-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    padding: 20px;
    background: var(--ev-card-bg);
    border-radius: 12px;
    border: 1.5px solid var(--ev-border);
    box-shadow: 0 2px 8px var(--ev-shadow);
}
.bottom-actions .btn { padding: 12px 24px; font-size: 14px; }
.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-secondary {
    background: var(--ev-card-bg);
    color: var(--ev-text-secondary);
    border: 1.5px solid var(--ev-border);
}
.btn-secondary:hover { background: var(--ev-hover); color: var(--ev-text); }
.btn-print { background: #3B82F6; color: white; }
.btn-print:hover { background: #2563EB; transform: translateY(-2px); color: white; }

@media (max-width: 1200px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .hero-card { grid-template-columns: 1fr; text-align: center; gap: 20px; }
    .hero-icon { margin: 0 auto; }
    .hero-meta { justify-content: center; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .summary-grid { grid-template-columns: 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .detail-item { border-right: none !important; }
    .bottom-actions { flex-direction: column; }
    .bottom-actions .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .hero-icon { width: 56px; height: 56px; font-size: 24px; }
    .hero-amount { font-size: clamp(22px, 7vw, 32px); }
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