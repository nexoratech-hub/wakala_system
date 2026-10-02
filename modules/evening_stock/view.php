<?php
// ================================================================
// FILE: modules/evening_stock/view.php
// WAKALA FINANCIAL SYSTEM - VIEW EVENING STOCK (ADMIN)
// 
// ✅ BLUE THEME (matching with index.php)
// ✅ GREEN THEME providers cards (soft green background)
// ✅ 3 Providers per row (grid layout)
// ✅ Cash + Grand Total at bottom
// ✅ Full English UI
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
$role    = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid evening stock.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH EVENING STOCK
// ============================================================
$stmt = $db->prepare("
    SELECT 
        es.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        e.email AS employee_email,
        e.phone AS employee_phone,
        e.profile_pic AS employee_avatar,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location,
        dr.report_number AS daily_report_number,
        dr.report_date AS daily_report_date
    FROM evening_stocks es
    LEFT JOIN employees e ON es.employee_id = e.id
    LEFT JOIN branches b ON es.branch_id = b.id
    LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
    WHERE es.id = ?
");
$stmt->execute([$id]);
$stock = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stock) {
    $_SESSION['error_message'] = 'Evening stock not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        esp.*,
        p.icon_class, p.color_code, p.provider_type
    FROM evening_stock_providers esp
    LEFT JOIN providers p ON esp.provider_id = p.id
    WHERE esp.evening_stock_id = ?
    ORDER BY p.display_order, esp.provider_name
");
$stmt->execute([$id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_float = 0;
$total_opening_float = 0;
foreach ($providers as $p) {
    $total_float += floatval(str_replace(',', '', $p['closing_float']));
    $total_opening_float += floatval(str_replace(',', '', $p['opening_float']));
}

// ✅ CASH inatoka evening_stocks.cash_balance
$cash_balance = floatval(str_replace(',', '', $stock['cash_balance'] ?? 0));
$grand_total = $total_float + $cash_balance;

$employee_initial = strtoupper(substr($stock['employee_name'] ?? 'N', 0, 1));
$employee_avatar = $stock['employee_avatar'] ?? '';

// Status info
$status_labels = [
    'waiting'  => ['label' => 'Waiting',  'icon' => 'fa-clock',        'color' => 'orange', 'badge' => 'badge-waiting'],
    'approved' => ['label' => 'Approved', 'icon' => 'fa-check-circle', 'color' => 'green',  'badge' => 'badge-approved'],
    'adjusted' => ['label' => 'Adjusted', 'icon' => 'fa-sliders-h',    'color' => 'blue',   'badge' => 'badge-adjusted'],
    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red',    'badge' => 'badge-rejected']
];
$status_info = $status_labels[$stock['status']] ?? ['label' => $stock['status'], 'icon' => 'fa-circle', 'color' => 'gray', 'badge' => 'badge-open'];

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH INDICATOR - BLUE
        ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($stock['branch_display_name'] ?? 'N/A'); ?></span>
                    <?php if (!empty($stock['branch_display_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($stock['branch_display_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($stock['branch_location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($stock['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <a href="index.php?branch_id=<?php echo $stock['branch_id']; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Stocks</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-moon" style="color:#1E40AF;"></i> Evening Stock Details</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($stock['stock_number']); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Print
                </button>
                <button onclick="deleteStock(<?php echo $id; ?>, '<?php echo addslashes($stock['stock_number']); ?>')" class="btn btn-delete">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        MAIN CARD (Blue Theme)
        ============================================================ -->
        <div class="main-card">
            <div class="main-card-icon">
                <i class="fas fa-moon"></i>
            </div>
            <div class="main-card-content">
                <div class="main-card-label">Evening Stock</div>
                <div class="main-card-number"><?php echo htmlspecialchars($stock['stock_number']); ?></div>
                <div class="main-card-desc">
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                    <?php if (!empty($stock['daily_report_number'])): ?>
                        · Linked to <?php echo htmlspecialchars($stock['daily_report_number']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="main-card-badge">
                <span class="badge <?php echo $status_info['badge']; ?>">
                    <i class="fas <?php echo $status_info['icon']; ?>"></i> 
                    <?php echo $status_info['label']; ?>
                </span>
            </div>
        </div>

        <!-- ============================================================
        TOTALS SUMMARY (3 cards)
        ============================================================ -->
        <div class="totals-grid">
            <div class="total-card total-float">
                <div class="total-icon"><i class="fas fa-coins"></i></div>
                <div class="total-info">
                    <span class="total-label">Total Float (Closing)</span>
                    <span class="total-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
            </div>
            <div class="total-card total-cash">
                <div class="total-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="total-info">
                    <span class="total-label">Cash Balance</span>
                    <span class="total-value"><?php echo formatCurrency($cash_balance); ?></span>
                </div>
            </div>
            <div class="total-card total-cumm">
                <div class="total-icon"><i class="fas fa-chart-line"></i></div>
                <div class="total-info">
                    <span class="total-label">Grand Total</span>
                    <span class="total-value"><?php echo formatCurrency($grand_total); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DETAILS GRID
        ============================================================ -->
        <div class="details-grid">
            
            <!-- STOCK INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-icon" style="background: linear-gradient(135deg, #1E40AF, #2563EB);">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <h3>Stock Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label">Stock Number</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($stock['stock_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Stock Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Submitted At</span>
                        <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($stock['submitted_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <span class="badge <?php echo $status_info['badge']; ?>">
                                <i class="fas <?php echo $status_info['icon']; ?>"></i> 
                                <?php echo $status_info['label']; ?>
                            </span>
                        </span>
                    </div>
                    <?php if (!empty($stock['daily_report_number'])): ?>
                    <div class="info-row">
                        <span class="info-label">Daily Report</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($stock['daily_report_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Daily Report Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($stock['daily_report_date'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- EMPLOYEE INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-icon" style="background: linear-gradient(135deg, #059669, #10B981);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Employee Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="employee-box">
                        <?php if ($employee_avatar && file_exists('../../' . $employee_avatar)): ?>
                            <img src="../../<?php echo htmlspecialchars($employee_avatar); ?>" 
                                 alt="" class="employee-img">
                        <?php else: ?>
                            <div class="employee-avatar"><?php echo $employee_initial; ?></div>
                        <?php endif; ?>
                        <div class="employee-info">
                            <span class="employee-name"><?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?></span>
                            <span class="employee-code"><?php echo htmlspecialchars($stock['employee_code'] ?? '-'); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($stock['employee_email'])): ?>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($stock['employee_email']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($stock['employee_phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($stock['employee_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
        PROVIDERS - GREEN THEME CARDS (3 PER ROW)
        ============================================================ -->
        <div class="providers-section">
            
            <!-- Section Header - Blue -->
            <div class="section-header">
                <div class="section-header-left">
                    <div class="section-header-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <h3>Provider Closing Balances</h3>
                        <p><?php echo count($providers); ?> providers in this stock</p>
                    </div>
                </div>
                <span class="section-count-badge"><?php echo count($providers); ?></span>
            </div>

            <?php if (count($providers) > 0): ?>
                
                <!-- ✅ Providers Grid - 3 per row - GREEN THEME -->
                <div class="providers-grid">
                    <?php $pi = 1; foreach ($providers as $p): 
                        $p_color = $p['color_code'] ?? '#059669';
                        $p_icon = $p['icon_class'] ?? 'fas fa-university';
                        $p_type = $p['provider_type'] ?? 'bank';
                        $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                        $p_code = $p['provider_code'] ?? '-';
                        $p_name = $p['provider_name'] ?? 'N/A';
                        $p_opening = floatval(str_replace(',', '', $p['opening_float']));
                        $p_closing = floatval(str_replace(',', '', $p['closing_float']));
                        $p_cash = floatval(str_replace(',', '', $p['closing_cash']));
                    ?>
                        <div class="provider-card provider-card-green">
                            
                            <!-- Card Header - GREEN -->
                            <div class="provider-card-header">
                                <div class="provider-card-number"><?php echo $pi++; ?></div>
                                <div class="provider-card-icon" style="background: <?php echo htmlspecialchars($p_color); ?>;">
                                    <i class="<?php echo htmlspecialchars($p_icon); ?>"></i>
                                </div>
                                <div class="provider-card-type">
                                    <span class="provider-type-badge type-<?php echo htmlspecialchars($p_type); ?>">
                                        <i class="fas fa-<?php echo $p_type === 'mobile_money' ? 'mobile-alt' : ($p_type === 'bank' ? 'university' : 'wallet'); ?>"></i>
                                        <?php echo htmlspecialchars($p_type_label); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Card Body - GREEN TEXT -->
                            <div class="provider-card-body">
                                <div class="provider-card-name">
                                    <?php echo htmlspecialchars($p_name); ?>
                                </div>
                                <div class="provider-card-code">
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($p_code); ?>
                                </div>
                            </div>
                            
                            <!-- Card Footer - Closing Float (GREEN) -->
                            <div class="provider-card-footer">
                                <span class="provider-card-footer-label">
                                    <i class="fas fa-coins"></i> Closing Float
                                </span>
                                <span class="provider-card-footer-value">
                                    <?php echo formatCurrency($p_closing); ?>
                                </span>
                            </div>

                            <!-- ✅ Extra Row: Opening → Closing indicator -->
                            <div class="provider-card-extra">
                                <div class="pce-item">
                                    <span class="pce-label">Opening:</span>
                                    <span class="pce-value"><?php echo formatCurrency($p_opening); ?></span>
                                </div>
                                <?php if ($p_cash > 0): ?>
                                <div class="pce-item pce-item-cash">
                                    <span class="pce-label">Cash:</span>
                                    <span class="pce-value"><?php echo formatCurrency($p_cash); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ============================================================
                     CASH SUMMARY (BELOW PROVIDERS) - Blue Theme
                     ============================================================ -->
                <div class="cash-summary-section">
                    
                    <!-- Total Float Card -->
                    <div class="cash-summary-card float-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($total_float); ?></span>
                        </div>
                    </div>

                    <!-- Cash Card -->
                    <div class="cash-summary-card cash-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Cash Balance</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($cash_balance); ?></span>
                        </div>
                    </div>

                    <!-- Grand Total Card -->
                    <div class="cash-summary-card grand-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float + Cash</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($grand_total); ?></span>
                        </div>
                    </div>

                </div>

            <?php else: ?>
                <div class="empty-providers">
                    <i class="fas fa-university"></i>
                    <p>No providers in this stock.</p>
                </div>
            <?php endif; ?>

        </div>

        <!-- ============================================================
        NOTES
        ============================================================ -->
        <?php if (!empty($stock['notes'])): ?>
        <div class="notes-card">
            <div class="notes-card-header">
                <i class="fas fa-sticky-note"></i>
                <h3>Notes</h3>
            </div>
            <div class="notes-card-body">
                <p><?php echo nl2br(htmlspecialchars($stock['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        ACTIONS
        ============================================================ -->
        <div class="actions-card">
            <a href="index.php?branch_id=<?php echo $stock['branch_id']; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-edit-lg">
                <i class="fas fa-edit"></i> Edit Stock
            </a>
            <button onclick="window.print()" class="btn btn-print-lg">
                <i class="fas fa-print"></i> Print Stock
            </button>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   VARIABLES - BLUE THEME
   ============================================================ */
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-table-even: #f8fafc;
    --bg-input: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(30, 64, 175, 0.08);
    --shadow-hover: rgba(30, 64, 175, 0.15);
    
    --blue-primary: #1e40af;
    --blue-dark: #1e3a8a;
    --blue-mid: #2563eb;
    --blue-light: #3b82f6;
    --blue-lighter: #dbeafe;
    --blue-lightest: #eff6ff;
    
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --green-light: #10b981;
    --green-lighter: #d1fae5;
    --green-lightest: #ecfdf5;
    --green-accent: #34d399;
    
    --orange-primary: #d97706;
    --orange-light: #f59e0b;
    --red-primary: #bb0404;
    --purple-primary: #7c3aed;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-table-even: #1a2332;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --blue-lighter: #1e3a5f;
    --blue-lightest: #1e293b;
    --green-lighter: #065f46;
    --green-lightest: #064e3b;
}

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   BRANCH INDICATOR - BLUE
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFF; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,0.3); }
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; color: #FFF; }
.branch-indicator-name { font-weight: 800; font-size: 16px; color: #FFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
.branch-indicator-code { font-size: 11px; font-weight: 700; color: #FFF; padding: 3px 12px; background: rgba(255,255,255,0.2); border-radius: 12px; border: 1px solid rgba(255,255,255,0.25); }
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(255,255,255,0.9); padding: 4px 12px; background: rgba(255,255,255,0.12); border-radius: 12px; white-space: nowrap; }
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    color: #FFF; text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary); }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0; display: flex; align-items: center; gap: 6px; font-family: 'Courier New', monospace; font-weight: 600; }
.page-header .header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px var(--shadow-color); }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

/* ============================================================
   MAIN CARD - BLUE
   ============================================================ */
.main-card {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 18px;
    display: flex; align-items: center; gap: 24px;
    color: #FFF; box-shadow: 0 8px 32px rgba(30, 64, 175, 0.25);
    position: relative; overflow: hidden;
}
.main-card::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; pointer-events: none; }
.main-card-icon {
    width: 88px; height: 88px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.main-card-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.main-card-label { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.9); margin-bottom: 6px; }
.main-card-number { font-size: clamp(22px, 2.5vw, 32px); font-weight: 900; font-family: 'Inter', 'Courier New', monospace; color: #FFF; word-break: break-all; line-height: 1.2; text-shadow: 0 3px 12px rgba(0,0,0,0.2); margin-bottom: 6px; }
.main-card-desc { font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.85); }
.main-card-badge { position: relative; z-index: 1; }

.badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; border: 1.5px solid; }
.badge-locked, .badge-rejected { background: #FEE2E2; color: #991B1B; border-color: #FECACA; }
.badge-open, .badge-approved { background: #D1FAE5; color: #059669; border-color: #A7F3D0; }
.badge-waiting { background: #FEF3C7; color: #D97706; border-color: #FDE68A; }
.badge-adjusted { background: #DBEAFE; color: #1D4ED8; border-color: #BFDBFE; }
.badge-auto { background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD; }
.badge-manual { background: #FEF3C7; color: #D97706; border-color: #FDE68A; }
html.dark-mode .badge-locked, html.dark-mode .badge-rejected { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .badge-open, html.dark-mode .badge-approved { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .badge-waiting { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .badge-adjusted { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
.main-card .badge { background: rgba(255,255,255,0.25); color: #FFF; border-color: rgba(255,255,255,0.4); backdrop-filter: blur(8px); }

/* ============================================================
   TOTALS GRID - 3 CARDS
   ============================================================ */
.totals-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 18px; }
.total-card { display: flex; align-items: center; gap: 16px; padding: 20px 24px; border-radius: 14px; color: #FFF; box-shadow: 0 4px 16px rgba(0,0,0,0.15); position: relative; overflow: hidden; }
.total-card::before { content: ''; position: absolute; top: -50%; right: -20%; width: 140px; height: 140px; background: rgba(255,255,255,0.1); border-radius: 50%; }
.total-float { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.total-cash { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.total-cumm { background: linear-gradient(135deg, #7C3AED 0%, #8B5CF6 100%); }
.total-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,0.25); position: relative; z-index: 1; }
.total-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; position: relative; z-index: 1; }
.total-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
.total-value { font-size: clamp(15px, 1.5vw, 20px); font-weight: 900; font-family: 'Inter', 'Courier New', monospace; word-break: break-all; line-height: 1.2; }

/* ============================================================
   DETAILS GRID
   ============================================================ */
.details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 18px; }
.detail-card { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); }
.detail-card-header { padding: 16px 20px; background: var(--bg-table-even); border-bottom: 1.5px solid var(--border-color); display: flex; align-items: center; gap: 14px; }
.detail-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #FFF; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.detail-card-header h3 { font-size: 15px; font-weight: 800; color: var(--text-primary); margin: 0; }
.detail-card-body { padding: 18px 20px; }

.info-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px dashed var(--border-color); gap: 12px; flex-wrap: wrap; }
.info-row:last-child { border-bottom: none; }
.info-label { font-size: 12px; font-weight: 600; color: var(--text-muted); white-space: nowrap; }
.info-value { font-size: 13px; font-weight: 700; color: var(--text-primary); text-align: right; word-break: break-word; }
.info-value.mono { font-family: 'Courier New', monospace; color: #1E40AF; }
html.dark-mode .info-value.mono { color: #93c5fd; }

.employee-box { display: flex; align-items: center; gap: 14px; padding: 16px; background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border: 2px solid #A7F3D0; border-radius: 12px; margin-bottom: 16px; }
html.dark-mode .employee-box { background: linear-gradient(135deg, #065F46, #047857); border-color: #10B981; }
.employee-img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #10B981; flex-shrink: 0; }
.employee-avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #059669, #10B981); color: #FFF; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 24px; flex-shrink: 0; border: 3px solid #10B981; }
.employee-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.employee-name { font-size: 16px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.employee-code { display: inline-block; padding: 2px 10px; background: #059669; color: #FFF; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 11px; font-weight: 700; align-self: flex-start; }

/* ============================================================
   PROVIDERS SECTION - BLUE HEADER
   ============================================================ */
.providers-section {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 18px;
    overflow: hidden;
}

.section-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.section-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.section-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}
.section-header-icon {
    width: 46px; height: 46px;
    background: rgba(255,255,255,0.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF;
    border: 1px solid rgba(255,255,255,0.25);
}
.section-header h3 {
    font-size: 17px;
    font-weight: 800;
    margin: 0 0 2px 0;
    color: #FFFFFF;
}
.section-header p {
    font-size: 12px;
    margin: 0;
    color: rgba(255,255,255,0.85);
    font-weight: 500;
}
.section-count-badge {
    background: rgba(255,255,255,0.22);
    color: #FFFFFF;
    padding: 6px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    position: relative;
    z-index: 1;
}

/* ============================================================
   PROVIDERS GRID - GREEN THEME - 3 PER ROW
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    padding: 20px;
}

.provider-card {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
    position: relative;
    min-width: 0;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.12);
}
.provider-card::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 120px; height: 120px;
    background: rgba(16, 185, 129, 0.15);
    border-radius: 50%;
    pointer-events: none;
}
html.dark-mode .provider-card {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
}
.provider-card:hover {
    border-color: #059669;
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(5, 150, 105, 0.3);
}

.provider-card-header {
    padding: 14px 16px;
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.2);
    position: relative;
    z-index: 1;
}
html.dark-mode .provider-card-header {
    background: rgba(15, 23, 42, 0.3);
    border-bottom-color: rgba(16, 185, 129, 0.3);
}
.provider-card-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 9px;
    background: linear-gradient(135deg, #059669, #10B981);
    color: #FFFFFF;
    font-size: 12px;
    font-weight: 800;
    border: 2px solid rgba(255, 255, 255, 0.5);
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(5, 150, 105, 0.3);
}
.provider-card-icon {
    width: 42px; height: 42px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 17px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.4);
}
.provider-card-type { margin-left: auto; flex-shrink: 0; }

.provider-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1px solid;
}
.provider-type-badge i { font-size: 10px; }
.type-bank { background: rgba(5, 150, 105, 0.15); color: #047857; border-color: rgba(5, 150, 105, 0.3); }
.type-mobile_money { background: rgba(124, 58, 237, 0.15); color: #6D28D9; border-color: rgba(124, 58, 237, 0.3); }
.type-other { background: rgba(217, 119, 6, 0.15); color: #B45309; border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .type-bank { background: rgba(16, 185, 129, 0.25); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.4); }
html.dark-mode .type-mobile_money { background: rgba(167, 139, 250, 0.25); color: #C4B5FD; border-color: rgba(139, 92, 246, 0.4); }
html.dark-mode .type-other { background: rgba(251, 191, 36, 0.25); color: #FCD34D; border-color: rgba(245, 158, 11, 0.4); }

.provider-card-body {
    padding: 18px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    position: relative;
    z-index: 1;
}
.provider-card-name {
    font-size: 15px;
    font-weight: 800;
    color: #065F46;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
    letter-spacing: -0.2px;
}
html.dark-mode .provider-card-name { color: #D1FAE5; }

.provider-card-code {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    color: #047857;
    background: rgba(255, 255, 255, 0.7);
    padding: 5px 12px;
    border-radius: 8px;
    align-self: flex-start;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    box-shadow: 0 2px 6px rgba(5, 150, 105, 0.1);
}
.provider-card-code i { font-size: 10px; color: #059669; }
html.dark-mode .provider-card-code {
    background: rgba(15, 23, 42, 0.4);
    color: #6EE7B7;
    border-color: rgba(16, 185, 129, 0.4);
}
html.dark-mode .provider-card-code i { color: #34D399; }

.provider-card-footer {
    padding: 16px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    border-top: 2px solid #065F46;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    z-index: 1;
    box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.1);
}
.provider-card-footer-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(255, 255, 255, 0.85);
    display: flex;
    align-items: center;
    gap: 6px;
}
.provider-card-footer-label i { font-size: 11px; color: #FCD34D; }
.provider-card-footer-value {
    font-size: 20px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #FFFFFF;
    letter-spacing: -0.5px;
    line-height: 1.2;
    word-break: break-all;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

/* ✅ Extra Row: Opening + Cash */
.provider-card-extra {
    padding: 10px 16px;
    background: rgba(255, 255, 255, 0.5);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid rgba(5, 150, 105, 0.15);
    position: relative;
    z-index: 1;
}
html.dark-mode .provider-card-extra {
    background: rgba(15, 23, 42, 0.3);
    border-top-color: rgba(16, 185, 129, 0.2);
}
.pce-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 700;
    color: #047857;
    white-space: nowrap;
}
html.dark-mode .pce-item { color: #6EE7B7; }
.pce-label {
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.85;
}
.pce-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: 11px;
}
.pce-item-cash { color: #059669; }
html.dark-mode .pce-item-cash { color: #34D399; }

/* ============================================================
   CASH SUMMARY SECTION (BELOW PROVIDERS) - 3 CARDS
   ============================================================ */
.cash-summary-section {
    padding: 0 20px 20px 20px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.cash-summary-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 22px;
    border-radius: 14px;
    color: #FFFFFF;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    position: relative;
    overflow: hidden;
    min-width: 0;
}
.cash-summary-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 140px; height: 140px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    pointer-events: none;
}
.float-card { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.cash-card { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.grand-card { background: linear-gradient(135deg, #7C3AED 0%, #8B5CF6 100%); }
.cash-summary-icon {
    width: 52px; height: 52px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.25);
    position: relative;
    z-index: 1;
}
.cash-summary-info {
    display: flex; flex-direction: column;
    gap: 4px; min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.cash-summary-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    opacity: 0.9;
}
.cash-summary-value {
    font-size: clamp(15px, 1.5vw, 20px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    line-height: 1.2;
    letter-spacing: -0.3px;
}

/* ============================================================
   EMPTY PROVIDERS
   ============================================================ */
.empty-providers {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-providers i {
    font-size: 56px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-providers p {
    font-size: 14px;
    margin: 0;
    font-weight: 600;
}

/* ============================================================
   NOTES
   ============================================================ */
.notes-card { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); margin-bottom: 18px; }
.notes-card-header { padding: 14px 20px; background: linear-gradient(135deg, #FEF3C7, #FDE68A); border-bottom: 1.5px solid #FCD34D; display: flex; align-items: center; gap: 10px; }
html.dark-mode .notes-card-header { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #D97706; }
.notes-card-header i { font-size: 18px; color: #D97706; }
html.dark-mode .notes-card-header i { color: #FBBF24; }
.notes-card-header h3 { font-size: 15px; font-weight: 800; color: #78350F; margin: 0; }
html.dark-mode .notes-card-header h3 { color: #FDE68A; }
.notes-card-body { padding: 18px 20px; }
.notes-card-body p { font-size: 14px; line-height: 1.7; color: var(--text-primary); margin: 0; }

/* ============================================================
   ACTIONS
   ============================================================ */
.actions-card { display: flex; gap: 12px; padding: 20px 24px; background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); box-shadow: 0 2px 8px var(--shadow-color); flex-wrap: wrap; }
.btn { padding: 12px 22px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-family: 'Inter', sans-serif; white-space: nowrap; }
.btn-secondary { background: var(--bg-table-even); color: var(--text-secondary); border: 1.5px solid var(--border-color); }
.btn-secondary:hover { background: var(--bg-table-even); color: var(--text-primary); transform: translateY(-2px); }
.btn-edit { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFF; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
.btn-edit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(217, 119, 6, 0.4); color: #FFF; }
.btn-print { background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3); }
.btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4); color: #FFF; }
.btn-delete { background: linear-gradient(135deg, #DC2626, #B91C1C); color: #FFF; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); }
.btn-delete:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4); color: #FFF; }
.btn-edit-lg { flex: 1; justify-content: center; min-width: 180px; background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFF; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
.btn-edit-lg:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(217, 119, 6, 0.4); color: #FFF; }
.btn-print-lg { flex: 1; justify-content: center; min-width: 180px; background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3); }
.btn-print-lg:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4); color: #FFF; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .totals-grid { grid-template-columns: 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .providers-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cash-summary-section { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }
    .main-card { flex-direction: column; text-align: center; padding: 22px 20px; }
    .main-card-icon { width: 72px; height: 72px; font-size: 32px; }
    .main-card-badge { width: 100%; }
    .actions-card { flex-direction: column; }
    .actions-card .btn { width: 100%; justify-content: center; }
    .info-row { flex-direction: column; align-items: flex-start; gap: 4px; }
    .info-value { text-align: left; }
    .providers-grid { grid-template-columns: 1fr; }
    .cash-summary-section { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .provider-card-footer-value { font-size: 18px; }
    .cash-summary-value { font-size: 15px; }
    .provider-card-icon { width: 38px; height: 38px; font-size: 15px; }
    .cash-summary-icon { width: 46px; height: 46px; font-size: 18px; }
}

@media print {
    .branch-indicator, .page-header, .actions-card, .alert { display: none !important; }
    .main-wrapper, .main-content { background: #FFF !important; padding: 0 !important; }
    .main-card, .total-card, .section-header, .cash-summary-card,
    .provider-card, .provider-card-header, .provider-card-footer,
    .provider-card-extra {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .providers-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>

<script>
function deleteStock(id, number) {
    if (confirm('Delete evening stock "' + number + '"?\n\nThis action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 8000);
    }

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