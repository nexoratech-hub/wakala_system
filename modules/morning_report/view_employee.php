<?php
// ================================================================
// FILE: modules/morning_report/view_employee.php
// WAKALA FINANCIAL SYSTEM - VIEW MORNING REPORT (EMPLOYEE)
// ✅ BLUE THEME (matching na Admin & Add Evening Stock)
// ✅ 3 Providers per row (grid layout)
// ✅ Cash + Grand Total chini
// ✅ Employee anaona reports za branch yake tu
// ✅ Branch selector imefichwa kwenye topbar
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid morning report.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET EMPLOYEE BRANCH
// ============================================================
$stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = intval($emp['branch_id'] ?? 0);

if ($employee_branch_id <= 0) {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// FETCH REPORT (only from employee's branch)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mr.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        e.email AS employee_email,
        e.phone AS employee_phone,
        e.profile_pic AS employee_avatar,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location,
        es.stock_number AS source_stock_number,
        es.stock_date AS source_stock_date
    FROM morning_reports mr
    LEFT JOIN employees e ON mr.employee_id = e.id
    LEFT JOIN branches b ON mr.branch_id = b.id
    LEFT JOIN evening_stocks es ON mr.source_evening_stock_id = es.id
    WHERE mr.id = ? AND mr.branch_id = ?
");
$stmt->execute([$id, $employee_branch_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error_message'] = 'Morning report not found or you do not have permission to view it.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// FETCH PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mrp.*,
        p.icon_class, p.color_code, p.provider_type
    FROM morning_report_providers mrp
    LEFT JOIN providers p ON mrp.provider_id = p.id
    WHERE mrp.report_id = ?
    ORDER BY p.display_order, mrp.provider_name
");
$stmt->execute([$id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_float = 0;
foreach ($providers as $p) {
    $total_float += floatval(str_replace(',', '', $p['float_balance']));
}
$cash_balance = floatval(str_replace(',', '', $report['cash_balance']));
$cumm_total = $total_float + $cash_balance;

$employee_initial = strtoupper(substr($report['employee_name'] ?? 'N', 0, 1));
$employee_avatar = $report['employee_avatar'] ?? '';

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
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
     HIDE BRANCH SELECTOR FROM TOPBAR (EMPLOYEE VIEW)
     ============================================================ -->
<style id="employee-topbar-fix">
    .topbar .branch-selector,
    .topbar .topbar-branch,
    .topbar .branch-dropdown,
    .topbar .topbar-branch-selector,
    .topbar .branch-switcher,
    .topbar .branch-select-wrapper,
    .topbar .header-branch-selector,
    .topbar .branch-filter,
    .topbar .branch-filter-wrapper,
    .topbar .branch-picker,
    .topbar .navbar-branch,
    .topbar .branch-select,
    .topbar .branch-choice,
    .topbar .topbar-branch-wrapper,
    .topbar select[name="branch_id"],
    .topbar select[name="branch"],
    .topbar #branchSelector,
    .topbar #branchSwitcher,
    .topbar .topbar-right .branch-selector,
    .topbar .topbar-right .branch-dropdown,
    .topbar .topbar-right select[name="branch_id"],
    header.topbar .branch-selector,
    header.topbar .branch-dropdown,
    header.topbar select[name="branch_id"],
    .admin-topbar .branch-selector,
    .admin-topbar .topbar-branch,
    .admin-topbar .branch-dropdown,
    .admin-topbar .branch-switcher,
    .admin-topbar select[name="branch_id"],
    nav.topbar .branch-selector,
    nav.topbar .branch-dropdown {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        height: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR - BLUE -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($report['branch_display_name'] ?? 'N/A'); ?></span>
                    <?php if (!empty($report['branch_display_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($report['branch_display_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($report['branch_location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($report['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <a href="index_employee.php" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Reports</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-sun" style="color:#2563EB;"></i> Morning Report Details</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($report['report_number']); ?>
                </p>
            </div>
            <div class="header-right">
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- ALERTS -->
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

        <!-- MAIN CARD - BLUE -->
        <div class="main-card">
            <div class="main-card-icon">
                <i class="fas fa-sun"></i>
            </div>
            <div class="main-card-content">
                <div class="main-card-label">Morning Report</div>
                <div class="main-card-number"><?php echo htmlspecialchars($report['report_number']); ?></div>
                <div class="main-card-desc">
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                    <?php if (!empty($report['source_stock_number'])): ?>
                        · Generated from <?php echo htmlspecialchars($report['source_stock_number']); ?>
                    <?php else: ?>
                        · Generated from Capital Management
                    <?php endif; ?>
                </div>
            </div>
            <div class="main-card-badge">
                <?php if (intval($report['is_locked']) === 1): ?>
                    <span class="badge badge-locked">
                        <i class="fas fa-lock"></i> Locked
                    </span>
                <?php else: ?>
                    <span class="badge badge-open">
                        <i class="fas fa-lock-open"></i> Open
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- TOTALS GRID - 3 CARDS -->
        <div class="totals-grid">
            <div class="total-card total-float">
                <div class="total-icon"><i class="fas fa-coins"></i></div>
                <div class="total-info">
                    <span class="total-label">Total Float</span>
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
                    <span class="total-value"><?php echo formatCurrency($cumm_total); ?></span>
                </div>
            </div>
        </div>

        <!-- DETAILS GRID -->
        <div class="details-grid">
            
            <!-- REPORT INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-icon" style="background: linear-gradient(135deg, #2563EB, #1D4ED8);">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <h3>Report Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="info-row">
                        <span class="info-label">Report Number</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['report_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Report Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($report['report_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Submitted At</span>
                        <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($report['submitted_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Source Type</span>
                        <span class="info-value">
                            <?php if ($report['source_type'] === 'auto_from_evening'): ?>
                                <span class="badge badge-auto">
                                    <i class="fas fa-magic"></i> Auto from Evening Stock
                                </span>
                            <?php else: ?>
                                <span class="badge badge-manual">
                                    <i class="fas fa-hand-paper"></i> Manual
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($report['source_stock_number'])): ?>
                    <div class="info-row">
                        <span class="info-label">Source Stock</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['source_stock_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Source Stock Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($report['source_stock_date'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <?php if (intval($report['is_locked']) === 1): ?>
                                <span class="badge badge-locked"><i class="fas fa-lock"></i> Locked</span>
                            <?php else: ?>
                                <span class="badge badge-open"><i class="fas fa-lock-open"></i> Open</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- EMPLOYEE INFO -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="detail-icon" style="background: linear-gradient(135deg, #059669, #10B981);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Prepared By</h3>
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
                            <span class="employee-name"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                            <span class="employee-code"><?php echo htmlspecialchars($report['employee_code'] ?? '-'); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($report['employee_email'])): ?>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['employee_email']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($report['employee_phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['employee_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
             PROVIDERS - CARDS (3 KWA ROW) - Blue Theme
             ============================================================ -->
        <div class="providers-section">
            
            <!-- Section Header - BLUE -->
            <div class="section-header">
                <div class="section-header-left">
                    <div class="section-header-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <h3>Provider Floats</h3>
                        <p><?php echo count($providers); ?> providers in this report</p>
                    </div>
                </div>
                <span class="section-count-badge"><?php echo count($providers); ?></span>
            </div>

            <?php if (count($providers) > 0): ?>
                
                <!-- Providers Grid - 3 kwa row -->
                <div class="providers-grid">
                    <?php $pi = 1; foreach ($providers as $p): 
                        $p_color = $p['color_code'] ?? '#2563EB';
                        $p_icon = $p['icon_class'] ?? 'fas fa-university';
                        $p_type = $p['provider_type'] ?? 'bank';
                        $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                        $p_code = $p['provider_code'] ?? '-';
                        $p_name = $p['provider_name'] ?? 'N/A';
                        $p_float = floatval(str_replace(',', '', $p['float_balance']));
                    ?>
                        <div class="provider-card">
                            
                            <!-- Card Header -->
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
                            
                            <!-- Card Body -->
                            <div class="provider-card-body">
                                <div class="provider-card-name">
                                    <?php echo htmlspecialchars($p_name); ?>
                                </div>
                                <div class="provider-card-code">
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($p_code); ?>
                                </div>
                            </div>
                            
                            <!-- Card Footer - Float (BLUE) -->
                            <div class="provider-card-footer">
                                <span class="provider-card-footer-label">
                                    <i class="fas fa-coins"></i> Float Balance
                                </span>
                                <span class="provider-card-footer-value">
                                    <?php echo formatCurrency($p_float); ?>
                                </span>
                            </div>
                            
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- CASH SUMMARY - 3 CARDS (CHINI YA PROVIDERS) -->
                <div class="cash-summary-section">
                    
                    <div class="cash-summary-card float-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($total_float); ?></span>
                        </div>
                    </div>

                    <div class="cash-summary-card cash-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Cash Balance</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($cash_balance); ?></span>
                        </div>
                    </div>

                    <div class="cash-summary-card grand-card">
                        <div class="cash-summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float + Cash</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($cumm_total); ?></span>
                        </div>
                    </div>

                </div>

            <?php else: ?>
                <div class="empty-providers">
                    <i class="fas fa-university"></i>
                    <p>No providers in this report.</p>
                </div>
            <?php endif; ?>

        </div>

        <!-- NOTES -->
        <?php if (!empty($report['notes'])): ?>
        <div class="notes-card">
            <div class="notes-card-header">
                <i class="fas fa-sticky-note"></i>
                <h3>Notes</h3>
            </div>
            <div class="notes-card-body">
                <p><?php echo nl2br(htmlspecialchars($report['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACTIONS -->
        <div class="actions-card">
            <a href="index_employee.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="btn btn-print-lg">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
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
    --blue-accent: #60a5fa;
    
    --green-primary: #059669;
    --green-light: #10b981;
    --orange-primary: #d97706;
    --red-primary: #bb0404;
    --purple-primary: #7c3aed;
    
    --sidebar-width: 220px;
    --topbar-height: 70px;
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
}

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; margin-left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)) !important; padding-top: var(--topbar-height); min-height: 100vh; background: var(--bg-body); transition: margin-left 0.3s ease; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

@media (max-width: 1024px) {
    .main-wrapper { margin-left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)) !important; padding-top: var(--topbar-height); }
    .main-content { padding: 16px 18px !important; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0 !important; width: 100% !important; padding-top: 62px; }
    .main-content { padding: 16px 14px !important; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 58px; width: 100% !important; }
    .main-content { padding: 12px 10px !important; width: 100%; }
}

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
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; transform: translateX(-3px); }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
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
.badge-locked { background: #FEE2E2; color: #991B1B; border-color: #FECACA; }
.badge-open { background: #D1FAE5; color: #059669; border-color: #A7F3D0; }
.badge-auto { background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD; }
.badge-manual { background: #FEF3C7; color: #D97706; border-color: #FDE68A; }
html.dark-mode .badge-locked { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .badge-open { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .badge-auto { background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6; }
html.dark-mode .badge-manual { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
.main-card .badge-locked, .main-card .badge-open { background: rgba(255,255,255,0.25); color: #FFF; border-color: rgba(255,255,255,0.4); backdrop-filter: blur(8px); }

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
.info-value.mono { font-family: 'Courier New', monospace; color: #2563EB; }
html.dark-mode .info-value.mono { color: #93c5fd; }

.employee-box { display: flex; align-items: center; gap: 14px; padding: 16px; background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border: 2px solid #A7F3D0; border-radius: 12px; margin-bottom: 16px; }
html.dark-mode .employee-box { background: linear-gradient(135deg, #065F46, #047857); border-color: #10B981; }
.employee-img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #10B981; flex-shrink: 0; }
.employee-avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #059669, #10B981); color: #FFF; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 24px; flex-shrink: 0; border: 3px solid #10B981; }
.employee-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.employee-name { font-size: 16px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.employee-code { display: inline-block; padding: 2px 10px; background: #059669; color: #FFF; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 11px; font-weight: 700; align-self: flex-start; }

/* ============================================================
   PROVIDERS SECTION - BLUE
   ============================================================ */
.providers-section {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 18px;
    overflow: hidden;
}

/* Section Header - BLUE THEME */
.section-header {
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
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

/* Providers Grid - 3 KWA ROW */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    padding: 20px;
}

.provider-card {
    background: var(--bg-card);
    border: 2px solid var(--border-color);
    border-radius: 14px;
    overflow: hidden;
    transition: all 0.3s ease;
    position: relative;
    min-width: 0;
}
.provider-card:hover {
    border-color: #2563EB;
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(37, 99, 235, 0.2);
}

.provider-card-header {
    padding: 14px 16px;
    background: var(--bg-table-even);
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1.5px solid var(--border-color);
    position: relative;
}
.provider-card-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--blue-lighter);
    color: var(--blue-primary);
    font-size: 12px;
    font-weight: 800;
    border: 1px solid #BFDBFE;
    flex-shrink: 0;
}
html.dark-mode .provider-card-number { background: #1e3a5f; color: #93c5fd; border-color: #3b82f6; }
.provider-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 17px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
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
}
.provider-type-badge i { font-size: 10px; }
.type-bank { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
.type-mobile_money { background: #EDE9FE; color: #7C3AED; border: 1px solid #C4B5FD; }
.type-other { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
html.dark-mode .type-bank { background: #1e3a5f; color: #93c5fd; border-color: #3b82f6; }
html.dark-mode .type-mobile_money { background: #4c1d95; color: #ddd6fe; border-color: #8b5cf6; }
html.dark-mode .type-other { background: #5f3a1e; color: #fbbf24; border-color: #d97706; }

.provider-card-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.provider-card-name {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
}
.provider-card-code {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    color: #D97706;
    background: #FEF3C7;
    padding: 4px 10px;
    border-radius: 8px;
    align-self: flex-start;
    border: 1px solid #FDE68A;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.provider-card-code i { font-size: 10px; }
html.dark-mode .provider-card-code { background: #5f3a1e; color: #fbbf24; border-color: #92400e; }

/* Provider Card Footer - Float (BLUE) */
.provider-card-footer {
    padding: 14px 16px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-top: 1.5px solid #BFDBFE;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
html.dark-mode .provider-card-footer {
    background: linear-gradient(135deg, #1e3a5f, #1e40af);
    border-top-color: #3b82f6;
}
.provider-card-footer-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #1E40AF;
    display: flex;
    align-items: center;
    gap: 5px;
}
.provider-card-footer-label i { font-size: 11px; }
html.dark-mode .provider-card-footer-label { color: #93c5fd; }
.provider-card-footer-value {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #1E3A8A;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-all;
}
html.dark-mode .provider-card-footer-value { color: #60a5fa; }

/* ============================================================
   CASH SUMMARY - 3 CARDS (CHINI YA PROVIDERS)
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
    width: 52px;
    height: 52px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.25);
    position: relative;
    z-index: 1;
}
.cash-summary-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}
.cash-summary-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
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
.btn-print { background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3); }
.btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4); color: #FFF; }
.btn-print-lg { flex: 1; justify-content: center; min-width: 180px; background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3); }
.btn-print-lg:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4); color: #FFF; }

/* ============================================================
   EMPLOYEE FOOTER FIX
   ============================================================ */
.employee-footer {
    margin-left: 0 !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    background: #ffffff !important;
    border-top: 1px solid var(--border-color) !important;
    padding: 10px 20px !important;
}
html.dark-mode .employee-footer { background: #1e293b !important; border-color: #334155 !important; }
.employee-footer .footer-content { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #6b7280; flex-wrap: wrap; gap: 8px; }
html.dark-mode .employee-footer .footer-content { color: #94a3b8; }
.employee-footer .footer-version { font-weight: 600; color: #bb0404; }

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

    .employee-footer { padding: 10px 14px !important; }
    .employee-footer .footer-content { font-size: 11px; flex-direction: column; text-align: center; gap: 4px; }
}
@media (max-width: 480px) {
    .provider-card-footer-value { font-size: 16px; }
    .cash-summary-value { font-size: 15px; }
    .provider-card-icon { width: 38px; height: 38px; font-size: 15px; }
    .cash-summary-icon { width: 46px; height: 46px; font-size: 18px; }
    .employee-footer { padding: 8px 12px !important; }
    .employee-footer .footer-content { font-size: 10px; }
}

@media print {
    .branch-indicator, .page-header, .actions-card, .alert { display: none !important; }
    .main-wrapper, .main-content {
        background: #FFF !important;
        padding: 0 !important;
        margin-left: 0 !important;
        width: 100% !important;
        padding-top: 0 !important;
    }
    .main-card, .total-card, .section-header, .cash-summary-card, .provider-card-footer {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .providers-grid { grid-template-columns: repeat(3, 1fr); }
    .employee-footer { display: none !important; }
}
</style>

<script>
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