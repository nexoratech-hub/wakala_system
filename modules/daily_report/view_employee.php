<?php
// ================================================================
// FILE: modules/daily_report/view_employee.php
// DAILY REPORT - VIEW (EMPLOYEE) - BEAUTIFUL CARDS
// ✅ FIXED: total_float inahesabiwa kutoka LATEST record per provider
// ✅ FIXED: total_cash inachukuliwa kutoka report.current_cash
// ✅ FIXED: grand_total = total_float + total_cash
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

// Get employee branch
$stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = intval($emp['branch_id'] ?? 0);

if ($employee_branch_id <= 0) {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid daily report.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// ✅ HELPER: Calculate TOTAL FLOAT from LATEST record per provider
// ============================================================
function calculateTotalFloatFromReport($db, $daily_report_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(latest.current_float), 0) as total_float
        FROM (
            SELECT drp1.provider_id, drp1.current_float
            FROM daily_report_providers drp1
            INNER JOIN (
                SELECT provider_id, MAX(id) as max_id
                FROM daily_report_providers
                WHERE daily_report_id = ?
                GROUP BY provider_id
            ) drp2 ON drp1.id = drp2.max_id
        ) latest
    ");
    $stmt->execute([$daily_report_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return floatval($result['total_float'] ?? 0);
}

// ============================================================
// FETCH DAILY REPORT
// ============================================================
$stmt = $db->prepare("
    SELECT 
        dr.*,
        mr.report_number AS morning_report_number,
        mr.cash_balance AS morning_cash,
        mr.cumm_total AS morning_cumm,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        e.email AS employee_email,
        e.phone AS employee_phone,
        e.profile_pic AS employee_avatar,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location
    FROM daily_reports dr
    LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
    LEFT JOIN employees e ON dr.employee_id = e.id
    LEFT JOIN branches b ON dr.branch_id = b.id
    WHERE dr.id = ? AND dr.branch_id = ?
");
$stmt->execute([$id, $employee_branch_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error_message'] = 'Daily report not found or access denied.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// FETCH PROVIDERS (LATEST per provider - avoid duplicates)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        drp.*,
        p.icon_class, p.color_code, p.provider_type
    FROM daily_report_providers drp
    INNER JOIN (
        SELECT provider_id, MAX(id) as max_id
        FROM daily_report_providers
        WHERE daily_report_id = ?
        GROUP BY provider_id
    ) latest ON drp.id = latest.max_id
    LEFT JOIN providers p ON drp.provider_id = p.id
    ORDER BY p.display_order, drp.provider_name
");
$stmt->execute([$id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// ✅ CALCULATE TOTALS - FIXED!
// ============================================================
$total_float = calculateTotalFloatFromReport($db, $id);
$total_cash = floatval(str_replace(',', '', $report['current_cash'] ?? 0));
$grand_total = $total_float + $total_cash;

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

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<!-- ✅ JETBRAINS MONO FONT -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
                <h2><i class="fas fa-file-invoice" style="color:#2563EB;"></i> Daily Report</h2>
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

        <!-- MAIN CARD -->
        <div class="main-card">
            <div class="main-card-icon">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="main-card-content">
                <div class="main-card-label">Daily Report</div>
                <div class="main-card-number"><?php echo htmlspecialchars($report['report_number']); ?></div>
                <div class="main-card-desc">
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                    <?php if (!empty($report['morning_report_number'])): ?>
                        · From Morning Report <?php echo htmlspecialchars($report['morning_report_number']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="main-card-badge">
                <span class="badge badge-open">
                    <i class="fas fa-check-circle"></i> Active
                </span>
            </div>
        </div>

        <!-- TOTALS GRID - 4 CARDS -->
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
                    <span class="total-value"><?php echo formatCurrency($total_cash); ?></span>
                </div>
            </div>
            <div class="total-card total-cumm">
                <div class="total-icon"><i class="fas fa-chart-line"></i></div>
                <div class="total-info">
                    <span class="total-label">Grand Total</span>
                    <span class="total-value"><?php echo formatCurrency($grand_total); ?></span>
                </div>
            </div>
            <div class="total-card total-capital">
                <div class="total-icon"><i class="fas fa-vault"></i></div>
                <div class="total-info">
                    <span class="total-label">Total Capital</span>
                    <span class="total-value"><?php echo formatCurrency($report['current_capital'] ?? 0); ?></span>
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
                        <span class="info-label">Created At</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($report['created_at'])); ?></span>
                    </div>
                    <?php if (!empty($report['morning_report_number'])): ?>
                    <div class="info-row">
                        <span class="info-label">Morning Report</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['morning_report_number']); ?></span>
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
                    <h3>Created By</h3>
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

        <!-- PROVIDERS SECTION - GREEN THEME -->
        <div class="providers-section">
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
                <div class="providers-grid">
                    <?php $pi = 1; foreach ($providers as $p): 
                        $p_color = $p['color_code'] ?? '#059669';
                        $p_icon = $p['icon_class'] ?? 'fas fa-university';
                        $p_type = $p['provider_type'] ?? 'bank';
                        $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                        $p_code = $p['provider_code'] ?? '-';
                        $p_name = $p['provider_name'] ?? 'N/A';
                        $p_float = floatval(str_replace(',', '', $p['current_float'] ?? 0));
                    ?>
                        <div class="provider-card">
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
                            <div class="provider-card-body">
                                <div class="provider-card-name"><?php echo htmlspecialchars($p_name); ?></div>
                                <div class="provider-card-code">
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($p_code); ?>
                                </div>
                            </div>
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

                <!-- CASH SUMMARY - 3 CARDS -->
                <div class="cash-summary-section">
                    <div class="cash-summary-card float-card">
                        <div class="cash-summary-icon"><i class="fas fa-coins"></i></div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($total_float); ?></span>
                        </div>
                    </div>
                    <div class="cash-summary-card cash-card">
                        <div class="cash-summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Cash Balance</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($total_cash); ?></span>
                        </div>
                    </div>
                    <div class="cash-summary-card grand-card">
                        <div class="cash-summary-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="cash-summary-info">
                            <span class="cash-summary-label">Total Float + Cash</span>
                            <span class="cash-summary-value"><?php echo formatCurrency($grand_total); ?></span>
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
   ✅ JETBRAINS MONO FONT - VARIABLES
   ============================================================ */
:root {
    --font-mono: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
    --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-table-even: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(30, 64, 175, 0.08);
    --blue-primary: #1e40af;
    --blue-mid: #2563eb;
    --blue-light: #3b82f6;
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --sidebar-width: 220px;
    --topbar-height: 70px;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --border-color: #334155;
}

*, *::before, *::after { box-sizing: border-box; }
html, body { 
    overflow-x: hidden !important; 
    max-width: 100vw !important; 
    font-family: var(--font-sans);
}
.main-wrapper { 
    margin-left: var(--sidebar-width) !important; 
    width: calc(100% - var(--sidebar-width)) !important; 
    padding-top: var(--topbar-height); 
    min-height: 100vh; 
    background: var(--bg-body); 
}
.main-content { padding: 16px 20px !important; max-width: 100% !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }

/* ============================================================
   ✅ APPLY JETBRAINS MONO KWA:
   - Report numbers (main-card-number)
   - Info values za mono
   - Total values (money)
   - Provider codes
   - Badges (employee codes, provider codes)
   - Cash summary values
   ============================================================ */

/* Branch indicator */
.branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap; gap: 12px; color: #FFF;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1; }
.branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFF; border: 1.5px solid rgba(255,255,255,0.3); }
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; }
.branch-indicator-name { font-weight: 800; font-size: 16px; }
.branch-indicator-code { 
    font-size: 11px; font-weight: 700; padding: 3px 12px; 
    background: rgba(255,255,255,0.2); border-radius: 12px; 
    border: 1px solid rgba(255,255,255,0.25);
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    letter-spacing: 0.3px;
}
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(255,255,255,0.9); padding: 4px 12px; background: rgba(255,255,255,0.12); border-radius: 12px; }
.btn-back-card { display: flex; align-items: center; gap: 6px; padding: 8px 16px; background: rgba(255,255,255,0.12); border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); color: #FFF; text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.3s ease; }
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left .text-muted { 
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    letter-spacing: 0.3px;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; font-size: 13px; }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert-close { background: transparent; border: none; font-size: 22px; cursor: pointer; opacity: 0.6; }

/* Main card */
.main-card { background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%); border-radius: 16px; padding: 28px 32px; margin-bottom: 18px; display: flex; align-items: center; gap: 24px; color: #FFF; box-shadow: 0 8px 32px rgba(30, 64, 175, 0.25); position: relative; overflow: hidden; }
.main-card::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; }
.main-card-icon { width: 88px; height: 88px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; flex-shrink: 0; border: 2px solid rgba(255,255,255,0.3); position: relative; z-index: 1; }
.main-card-content { flex: 1; position: relative; z-index: 1; }
.main-card-label { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.9); margin-bottom: 6px; }
.main-card-number { 
    font-size: clamp(22px, 2.5vw, 32px); 
    font-weight: 900; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    margin-bottom: 6px; 
    letter-spacing: -0.5px;
    word-break: break-all;
}
.main-card-desc { font-size: 13px; color: rgba(255,255,255,0.85); }
.main-card-badge { position: relative; z-index: 1; }
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; border: 1.5px solid; }
.badge-open { background: rgba(255,255,255,0.25); color: #FFF; border-color: rgba(255,255,255,0.4); backdrop-filter: blur(8px); }

/* Totals grid */
.totals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
.total-card { display: flex; align-items: center; gap: 16px; padding: 20px 24px; border-radius: 14px; color: #FFF; box-shadow: 0 4px 16px rgba(0,0,0,0.15); position: relative; overflow: hidden; }
.total-card::before { content: ''; position: absolute; top: -50%; right: -20%; width: 140px; height: 140px; background: rgba(255,255,255,0.1); border-radius: 50%; }
.total-float { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.total-cash { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.total-cumm { background: linear-gradient(135deg, #7C3AED 0%, #8B5CF6 100%); }
.total-capital { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.total-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; position: relative; z-index: 1; }
.total-info { display: flex; flex-direction: column; gap: 4px; flex: 1; position: relative; z-index: 1; }
.total-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
.total-value { 
    font-size: clamp(15px, 1.5vw, 20px); 
    font-weight: 900; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    letter-spacing: -0.3px;
    word-break: break-all;
}

/* Details grid */
.details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 18px; }
.detail-card { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); }
.detail-card-header { padding: 16px 20px; background: var(--bg-table-even); border-bottom: 1.5px solid var(--border-color); display: flex; align-items: center; gap: 14px; }
.detail-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #FFF; flex-shrink: 0; }
.detail-card-header h3 { font-size: 15px; font-weight: 800; margin: 0; }
.detail-card-body { padding: 18px 20px; }
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px dashed var(--border-color); gap: 12px; flex-wrap: wrap; }
.info-row:last-child { border-bottom: none; }
.info-label { font-size: 12px; font-weight: 600; color: var(--text-muted); }
.info-value { font-size: 13px; font-weight: 700; color: var(--text-primary); text-align: right; }
.info-value.mono { 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    color: #2563EB;
    letter-spacing: 0.3px;
}
html.dark-mode .info-value.mono { color: #93c5fd; }

.employee-box { display: flex; align-items: center; gap: 14px; padding: 16px; background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border: 2px solid #A7F3D0; border-radius: 12px; margin-bottom: 16px; }
html.dark-mode .employee-box { background: linear-gradient(135deg, #065F46, #047857); }
.employee-img, .employee-avatar { width: 64px; height: 64px; border-radius: 50%; border: 3px solid #10B981; flex-shrink: 0; }
.employee-avatar { background: linear-gradient(135deg, #059669, #10B981); color: #FFF; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 24px; font-family: var(--font-mono); }
.employee-info { display: flex; flex-direction: column; gap: 4px; }
.employee-name { font-size: 16px; font-weight: 800; color: var(--text-primary); }
.employee-code { 
    display: inline-block; padding: 2px 10px; background: #059669; color: #FFF; 
    border-radius: 8px; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    font-size: 11px; font-weight: 700; align-self: flex-start; 
    letter-spacing: 0.3px;
}

/* Providers section - BLUE HEADER */
.providers-section { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); box-shadow: 0 2px 8px var(--shadow-color); margin-bottom: 18px; overflow: hidden; }
.section-header { background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%); padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; color: #FFFFFF; }
.section-header-left { display: flex; align-items: center; gap: 14px; }
.section-header-icon { width: 46px; height: 46px; background: rgba(255,255,255,0.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; border: 1px solid rgba(255,255,255,0.25); }
.section-header h3 { font-size: 17px; font-weight: 800; margin: 0 0 2px 0; }
.section-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); }
.section-count-badge { background: rgba(255,255,255,0.22); padding: 6px 18px; border-radius: 12px; font-size: 13px; font-weight: 800; border: 1px solid rgba(255,255,255,0.3); }

/* ✅ PROVIDERS GRID - GREEN THEME */
.providers-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; padding: 20px; }
.provider-card { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%); border: 2px solid #6EE7B7; border-radius: 16px; overflow: hidden; transition: all 0.3s ease; position: relative; box-shadow: 0 4px 16px rgba(5, 150, 105, 0.12); }
.provider-card::before { content: ''; position: absolute; top: -40px; right: -40px; width: 120px; height: 120px; background: rgba(16, 185, 129, 0.15); border-radius: 50%; pointer-events: none; }
html.dark-mode .provider-card { background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%); border-color: #10B981; }
.provider-card:hover { border-color: #059669; transform: translateY(-6px); box-shadow: 0 12px 32px rgba(5, 150, 105, 0.3); }

.provider-card-header { padding: 14px 16px; background: rgba(255,255,255,0.6); backdrop-filter: blur(10px); display: flex; align-items: center; gap: 12px; border-bottom: 1.5px solid rgba(5,150,105,0.2); position: relative; z-index: 1; }
.provider-card-number { 
    width: 28px; height: 28px; border-radius: 9px; 
    background: linear-gradient(135deg, #059669, #10B981); color: #FFF; 
    display: flex; align-items: center; justify-content: center; 
    font-size: 12px; font-weight: 800; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    border: 2px solid rgba(255,255,255,0.5); 
    box-shadow: 0 3px 10px rgba(5,150,105,0.3); 
}
.provider-card-icon { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #FFF; font-size: 17px; border: 2px solid rgba(255,255,255,0.4); }
.provider-card-type { margin-left: auto; }
.provider-type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 8px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid; }
.type-bank { background: rgba(5,150,105,0.15); color: #047857; border-color: rgba(5,150,105,0.3); }
.type-mobile_money { background: rgba(124,58,237,0.15); color: #6D28D9; border-color: rgba(124,58,237,0.3); }
.type-other { background: rgba(217,119,6,0.15); color: #B45309; border-color: rgba(217,119,6,0.3); }

.provider-card-body { padding: 18px 16px; display: flex; flex-direction: column; gap: 12px; position: relative; z-index: 1; }
.provider-card-name { font-size: 15px; font-weight: 800; color: #065F46; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
html.dark-mode .provider-card-name { color: #D1FAE5; }
.provider-card-code { 
    display: inline-flex; align-items: center; gap: 6px; 
    font-size: 11px; font-weight: 800; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    color: #047857; 
    background: rgba(255,255,255,0.7); 
    padding: 5px 12px; border-radius: 8px; 
    align-self: flex-start; 
    border: 1.5px solid rgba(5,150,105,0.3); 
    box-shadow: 0 2px 6px rgba(5,150,105,0.1); 
    letter-spacing: 0.3px;
}

.provider-card-footer { padding: 16px; background: linear-gradient(135deg, #059669 0%, #047857 100%); border-top: 2px solid #065F46; display: flex; flex-direction: column; gap: 6px; position: relative; z-index: 1; }
.provider-card-footer-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255,255,255,0.85); display: flex; align-items: center; gap: 6px; }
.provider-card-footer-value { 
    font-size: 20px; 
    font-weight: 900; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    color: #FFFFFF; 
    letter-spacing: -0.5px;
    word-break: break-all;
    text-shadow: 0 2px 8px rgba(0,0,0,0.25); 
}

/* Cash summary */
.cash-summary-section { padding: 0 20px 20px 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.cash-summary-card { display: flex; align-items: center; gap: 16px; padding: 20px 22px; border-radius: 14px; color: #FFF; position: relative; overflow: hidden; }
.cash-summary-card::before { content: ''; position: absolute; top: -50%; right: -20%; width: 140px; height: 140px; background: rgba(255,255,255,0.1); border-radius: 50%; }
.float-card { background: linear-gradient(135deg, #1E40AF, #2563EB); }
.cash-card { background: linear-gradient(135deg, #059669, #10B981); }
.grand-card { background: linear-gradient(135deg, #7C3AED, #8B5CF6); }
.cash-summary-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; position: relative; z-index: 1; }
.cash-summary-info { display: flex; flex-direction: column; gap: 4px; flex: 1; position: relative; z-index: 1; }
.cash-summary-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
.cash-summary-value { 
    font-size: clamp(15px, 1.5vw, 20px); 
    font-weight: 900; 
    font-family: var(--font-mono);  /* ✅ JetBrains Mono */
    letter-spacing: -0.3px;
    word-break: break-all;
}

/* Empty providers */
.empty-providers { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-providers i { font-size: 56px; opacity: 0.4; display: block; margin-bottom: 16px; }

/* Notes card */
.notes-card { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); margin-bottom: 18px; }
.notes-card-header { padding: 14px 20px; background: linear-gradient(135deg, #FEF3C7, #FDE68A); border-bottom: 1.5px solid #FCD34D; display: flex; align-items: center; gap: 10px; }
.notes-card-header i { font-size: 18px; color: #D97706; }
.notes-card-header h3 { font-size: 15px; font-weight: 800; color: #78350F; margin: 0; }
.notes-card-body { padding: 18px 20px; }
.notes-card-body p { font-size: 14px; line-height: 1.7; margin: 0; }

/* Actions */
.actions-card { display: flex; gap: 12px; padding: 20px 24px; background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); flex-wrap: wrap; }
.btn { padding: 12px 22px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-family: var(--font-sans); }
.btn-secondary { background: var(--bg-table-even); color: var(--text-secondary); border: 1.5px solid var(--border-color); }
.btn-secondary:hover { background: var(--bg-table-even); transform: translateY(-2px); }
.btn-print { background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3); }
.btn-print:hover { transform: translateY(-2px); color: #FFF; }
.btn-print-lg { flex: 1; justify-content: center; min-width: 180px; background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; }
.btn-print-lg:hover { transform: translateY(-2px); color: #FFF; }

/* Employee footer */
.employee-footer { margin-left: 0 !important; margin-top: auto !important; width: 100% !important; background: #ffffff !important; border-top: 1px solid var(--border-color) !important; padding: 10px 20px !important; }
html.dark-mode .employee-footer { background: #1e293b !important; border-color: #334155 !important; }
.employee-footer .footer-content { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #6b7280; flex-wrap: wrap; gap: 8px; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .totals-grid { grid-template-columns: repeat(2, 1fr); }
    .providers-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0 !important; width: 100% !important; padding-top: 62px; }
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    .main-card { flex-direction: column; text-align: center; padding: 22px 20px; }
    .main-card-icon { width: 72px; height: 72px; font-size: 32px; }
    .totals-grid { grid-template-columns: 1fr; }
    .details-grid { grid-template-columns: 1fr; }
    .providers-grid { grid-template-columns: 1fr; }
    .cash-summary-section { grid-template-columns: 1fr; }
    .actions-card { flex-direction: column; }
    .actions-card .btn { width: 100%; justify-content: center; }
    .info-row { flex-direction: column; align-items: flex-start; }
    .info-value { text-align: left; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .provider-card-footer-value { font-size: 18px; }
    .cash-summary-value { font-size: 15px; }
    .main-card-number { font-size: 18px; }
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
    .main-card, .total-card, .section-header, .cash-summary-card,
    .provider-card, .provider-card-header, .provider-card-footer {
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