<?php
// ================================================================
// FILE: modules/morning_report/index_employee.php
// WAKALA FINANCIAL SYSTEM - MORNING REPORTS (EMPLOYEE) - FINAL
// BLUE THEME + Report Header + Provider Rows
// ✅ Summary cards: 2x2 grid
// ✅ Nzuri text colors (matching na Admin)
// ✅ Report info in HEADER
// ✅ View button in HEADER
// ✅ Body: #, Provider, Code, Type, Float
// ✅ Last rows: Total Float + Cash + Grand Total
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

// ============================================================
// GET EMPLOYEE BRANCH
// ============================================================
$stmt = $db->prepare("SELECT branch_id, branch FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp || $emp['branch_id'] <= 0) {
    $_SESSION['error_message'] = 'You are not assigned to any branch.';
    header('Location: ../dashboard/employee.php');
    exit();
}

$selected_branch = intval($emp['branch_id']);

// ============================================================
// BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$selected_branch]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

$branch_name = $branch['branch_name'] ?? 'Unknown';
$branch_code = $branch['branch_code'] ?? '';
$branch_location = $branch['location'] ?? '';

// ============================================================
// FILTERS
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date   = isset($_GET['to_date'])   ? $_GET['to_date']   : date('Y-m-d');
$search    = isset($_GET['search'])    ? trim($_GET['search']) : '';

// ============================================================
// FETCH MORNING REPORTS
// ============================================================
$reports = [];
$total_reports = 0;
$total_float = 0;
$total_cash = 0;

try {
    $sql = "
        SELECT 
            mr.*,
            e.full_name AS employee_name,
            e.employee_id AS employee_code,
            e.profile_pic AS employee_avatar,
            b.branch_name AS branch_display_name,
            b.branch_code AS branch_display_code,
            es.stock_number AS source_stock_number,
            es.stock_date AS source_stock_date
        FROM morning_reports mr
        LEFT JOIN employees e ON mr.employee_id = e.id
        LEFT JOIN branches b ON mr.branch_id = b.id
        LEFT JOIN evening_stocks es ON mr.source_evening_stock_id = es.id
        WHERE mr.branch_id = ?
          AND mr.report_date BETWEEN ? AND ?
    ";
    $params = [$selected_branch, $from_date, $to_date];

    if ($search !== '') {
        $sql .= " AND (mr.report_number LIKE ? OR e.full_name LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY mr.report_date DESC, mr.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch providers for each report
    foreach ($reports as &$rpt) {
        $stmt_p = $db->prepare("
            SELECT 
                mrp.*,
                p.icon_class, p.color_code, p.provider_type, p.display_order
            FROM morning_report_providers mrp
            LEFT JOIN providers p ON mrp.provider_id = p.id
            WHERE mrp.report_id = ?
            ORDER BY p.display_order, mrp.provider_name
        ");
        $stmt_p->execute([$rpt['id']]);
        $rpt['providers'] = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

        $rpt['total_float'] = 0;
        foreach ($rpt['providers'] as $p) {
            $rpt['total_float'] += floatval($p['float_balance']);
        }
        $rpt['provider_count'] = count($rpt['providers']);
    }
    unset($rpt);

    $total_reports = count($reports);
    foreach ($reports as $r) {
        $total_float += $r['total_float'];
        $total_cash  += floatval(str_replace(',', '', $r['cash_balance'] ?? 0));
    }
} catch (Exception $e) {
    $reports = [];
}

// ============================================================
// SESSION MESSAGES
// ============================================================
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

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Current Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($branch_location): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch_location); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-sun" style="color:#1E40AF;"></i> Morning Reports</h2>
                <p class="text-muted">Auto-generated from Evening Stock / Capital Management</p>
            </div>
            <div class="header-right">
                <a href="add_employee.php" class="btn-new-report">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Morning Report</span>
                </a>
                <a href="export.php?branch_id=<?php echo $selected_branch; ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>" 
                   class="btn-export">
                    <i class="fas fa-file-csv"></i>
                    <span>Export CSV</span>
                </a>
                <a href="../dashboard/employee.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
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
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             SUMMARY CARDS - 2x2 (Nzuri text colors kama Admin)
             ============================================================ -->
        <div class="summary-cards">
            
            <div class="summary-card summary-blue">
                <div class="sc-icon sc-icon-blue">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="sc-info">
                    <span class="sc-label">Total Reports</span>
                    <span class="sc-value"><?php echo number_format($total_reports); ?></span>
                </div>
            </div>

            <div class="summary-card summary-green">
                <div class="sc-icon sc-icon-green">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="sc-info">
                    <span class="sc-label">Total Float</span>
                    <span class="sc-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
            </div>

            <div class="summary-card summary-orange">
                <div class="sc-icon sc-icon-orange">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="sc-info">
                    <span class="sc-label">Total Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($total_cash); ?></span>
                </div>
            </div>

            <div class="summary-card summary-purple">
                <div class="sc-icon sc-icon-purple">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="sc-info">
                    <span class="sc-label">Grand Total</span>
                    <span class="sc-value"><?php echo formatCurrency($total_float + $total_cash); ?></span>
                </div>
            </div>

        </div>

        <!-- FILTERS -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Report #, employee...">
                </div>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index_employee.php" class="btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
             MAIN REPORT CARDS (Per Report)
             ============================================================ -->
        <?php if (count($reports) > 0): ?>
            
            <?php foreach ($reports as $report): 
                $rid = intval($report['id']);
                $emp_initial = strtoupper(substr($report['employee_name'] ?? 'N', 0, 1));
                $avatar = $report['employee_avatar'] ?? '';
                $providers = $report['providers'] ?? [];
                $provider_count = count($providers);
                $report_float = floatval($report['total_float']);
                $report_cash = floatval(str_replace(',', '', $report['cash_balance'] ?? 0));
                $report_total = $report_float + $report_cash;
            ?>
                
                <div class="report-card">
                    
                    <!-- REPORT HEADER -->
                    <div class="report-header">
                        
                        <!-- LEFT: Report Info -->
                        <div class="report-header-info">
                            <div class="report-header-icon">
                                <i class="fas fa-sun"></i>
                            </div>
                            <div class="report-header-text">
                                <div class="report-header-number">
                                    <?php echo htmlspecialchars($report['report_number']); ?>
                                </div>
                                <div class="report-header-meta">
                                    <span class="report-header-meta-item">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                                    </span>
                                    <?php if (!empty($report['source_stock_number'])): ?>
                                        <span class="report-header-meta-item source-evening">
                                            <i class="fas fa-moon"></i>
                                            <?php echo htmlspecialchars($report['source_stock_number']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="report-header-meta-item source-capital">
                                            <i class="fas fa-coins"></i>
                                            Capital
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- CENTER: Employee -->
                        <div class="report-header-employee">
                            <div class="employee-cell">
                                <?php if ($avatar && file_exists('../../' . $avatar)): ?>
                                    <img src="../../<?php echo htmlspecialchars($avatar); ?>" alt="" class="emp-avatar-img">
                                <?php else: ?>
                                    <div class="emp-avatar"><?php echo $emp_initial; ?></div>
                                <?php endif; ?>
                                <div class="emp-info">
                                    <span class="emp-name"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                                    <span class="emp-code"><?php echo htmlspecialchars($report['employee_code'] ?? '-'); ?></span>
                                </div>
                            </div>
                            <div class="employee-cash">
                                <i class="fas fa-money-bill-wave"></i>
                                Cash: <strong><?php echo formatCurrency($report_cash); ?></strong>
                            </div>
                            <div class="employee-status">
                                <?php if (intval($report['is_locked']) === 1): ?>
                                    <span class="status-badge status-locked">
                                        <i class="fas fa-lock"></i> Locked
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-open">
                                        <i class="fas fa-lock-open"></i> Open
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- RIGHT: View Button -->
                        <div class="report-header-actions">
                            <a href="view_employee.php?id=<?php echo $rid; ?>" 
                               class="btn-view-report">
                                <i class="fas fa-eye"></i>
                                <span>View Report</span>
                            </a>
                        </div>
                        
                    </div>
                    
                    <!-- REPORT BODY (Provider Table) -->
                    <div class="report-body">
                        <?php if ($provider_count > 0): ?>
                            <table class="provider-table">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">#</th>
                                        <th>Provider</th>
                                        <th style="width:150px;">Code</th>
                                        <th style="width:150px;">Type</th>
                                        <th class="text-right" style="width:180px;">Float Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $pi = 1; foreach ($providers as $p): 
                                        $p_color = $p['color_code'] ?? '#1e40af';
                                        $p_icon = $p['icon_class'] ?? 'fas fa-university';
                                        $p_type = $p['provider_type'] ?? 'bank';
                                        $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                                        $p_code = $p['provider_code'] ?? '-';
                                        $p_name = $p['provider_name'] ?? 'N/A';
                                        $p_float = floatval($p['float_balance']);
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="provider-row-number"><?php echo $pi++; ?></span>
                                            </td>
                                            <td>
                                                <div class="provider-cell">
                                                    <div class="provider-icon" style="background: <?php echo htmlspecialchars($p_color); ?>;">
                                                        <i class="<?php echo htmlspecialchars($p_icon); ?>"></i>
                                                    </div>
                                                    <span class="provider-name">
                                                        <?php echo htmlspecialchars($p_name); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="code-badge">
                                                    <?php echo htmlspecialchars($p_code); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="provider-type-badge type-<?php echo htmlspecialchars($p_type); ?>">
                                                    <i class="fas fa-<?php echo $p_type === 'mobile_money' ? 'mobile-alt' : ($p_type === 'bank' ? 'university' : 'wallet'); ?>"></i>
                                                    <?php echo htmlspecialchars($p_type_label); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-badge amount-float">
                                                    <?php echo formatCurrency($p_float); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <!-- TOTAL FLOAT ROW -->
                                    <tr class="total-float-row">
                                        <td colspan="4" class="text-right">
                                            <span class="tfoot-label">
                                                <i class="fas fa-coins"></i> TOTAL FLOAT
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-badge amount-total">
                                                <?php echo formatCurrency($report_float); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <!-- CASH ROW -->
                                    <tr class="cash-row">
                                        <td colspan="4" class="text-right">
                                            <span class="tfoot-label">
                                                <i class="fas fa-money-bill-wave"></i> CASH BALANCE
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-badge amount-cash">
                                                <?php echo formatCurrency($report_cash); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <!-- GRAND TOTAL ROW -->
                                    <tr class="grand-total-row">
                                        <td colspan="4" class="text-right">
                                            <span class="tfoot-label grand">
                                                <i class="fas fa-chart-line"></i> TOTAL FLOAT + CASH
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-badge amount-grand">
                                                <?php echo formatCurrency($report_total); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <div class="no-providers">
                                <i class="fas fa-exclamation-circle"></i>
                                <p>No providers in this report</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
                
            <?php endforeach; ?>
            
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-sun"></i>
                <h3>No Morning Reports</h3>
                <p>Hakuna morning reports katika kipindi hiki kwa branch yako.</p>
                <a href="add_employee.php" class="btn-new-report">
                    <i class="fas fa-plus-circle"></i> Create First Report
                </a>
            </div>
        <?php endif; ?>

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
    --bg-table-hover: #eff6ff;
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
    --orange-light: #f59e0b;
    --red-primary: #bb0404;
    --red-dark: #8a0303;
    --purple-primary: #7c3aed;
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
    --blue-lighter: #1e3a5f;
    --blue-lightest: #1e293b;
}

/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; min-height: 100vh; background: var(--bg-body); transition: margin-left 0.3s ease; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px !important; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px !important; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 44px; width: 100%; }
    .main-content { padding: 12px 10px !important; width: 100%; }
}

/* ============================================================
   BRANCH INDICATOR
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 14px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap; gap: 12px;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: wrap; min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    backdrop-filter: blur(8px);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700; color: #FFFFFF;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 12px;
    color: rgba(255,255,255,0.9); padding: 4px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; position: relative; z-index: 1;
}
.branch-indicator-right .date-display {
    font-size: 12px; color: rgba(255,255,255,0.95);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap; font-weight: 600;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0;
    color: var(--text-primary);
}
.page-header .header-left h2 i { margin-right: 10px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0; font-weight: 500;
}
.page-header .header-right {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-new-report {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    padding: 11px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.35);
    white-space: nowrap;
}
.btn-new-report:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(30, 64, 175, 0.5);
    color: #FFFFFF;
}
.btn-export {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: #FFFFFF;
    padding: 11px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
    white-space: nowrap;
}
.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(5, 150, 105, 0.5);
    color: #FFFFFF;
}
.btn-back {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    text-decoration: none;
    white-space: nowrap;
}
.btn-back:hover {
    background: var(--blue-lightest);
    color: var(--blue-primary);
    border-color: var(--blue-light);
    transform: translateY(-2px);
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
html.dark-mode .alert-success { background: #065f46; color: #d1fae5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close {
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   ✅ SUMMARY CARDS - 2x2 na NZURI TEXT COLORS
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
    width: 100%;
}

.summary-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 3px 10px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}

/* Colored left border per card */
.summary-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 5px;
    height: 100%;
    border-radius: 14px 0 0 14px;
}

.summary-blue::before  { background: linear-gradient(180deg, #1e40af, #3b82f6); }
.summary-green::before { background: linear-gradient(180deg, #059669, #10b981); }
.summary-orange::before{ background: linear-gradient(180deg, #d97706, #f59e0b); }
.summary-purple::before{ background: linear-gradient(180deg, #7c3aed, #8b5cf6); }

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px var(--shadow-hover);
    border-color: var(--blue-light);
}

.sc-icon {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.sc-icon-blue {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border: 1.5px solid #93c5fd;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
}
.sc-icon-green {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #059669;
    border: 1.5px solid #6ee7b7;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
}
.sc-icon-orange {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #d97706;
    border: 1.5px solid #fcd34d;
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.15);
}
.sc-icon-purple {
    background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
    color: #7c3aed;
    border: 1.5px solid #c4b5fd;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
}

html.dark-mode .sc-icon-blue { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
html.dark-mode .sc-icon-green { background: #065f46; color: #34d399; border-color: #10b981; }
html.dark-mode .sc-icon-orange { background: #5f3a1e; color: #fbbf24; border-color: #d97706; }
html.dark-mode .sc-icon-purple { background: #4c1d95; color: #ddd6fe; border-color: #8b5cf6; }

.sc-info {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    flex: 1;
    overflow: hidden;
}

.sc-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 800;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sc-value {
    font-size: 24px;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    line-height: 1.1;
    letter-spacing: -0.5px;
    word-break: break-word;
    overflow-wrap: anywhere;
    display: block;
    max-width: 100%;
}

/* ✅ NZURI TEXT COLORS - Matching na rangi ya card */
.summary-blue .sc-value   { color: #1e40af; }
.summary-green .sc-value  { color: #059669; }
.summary-orange .sc-value { color: #d97706; }
.summary-purple .sc-value { color: #7c3aed; }

html.dark-mode .summary-blue .sc-value   { color: #60a5fa; }
html.dark-mode .summary-green .sc-value  { color: #34d399; }
html.dark-mode .summary-orange .sc-value { color: #fbbf24; }
html.dark-mode .summary-purple .sc-value { color: #a78bfa; }

/* Labels pia zenye rangi */
.summary-blue .sc-label   { color: #1e40af; opacity: 0.75; }
.summary-green .sc-label  { color: #059669; opacity: 0.75; }
.summary-orange .sc-label { color: #d97706; opacity: 0.75; }
.summary-purple .sc-label { color: #7c3aed; opacity: 0.75; }

html.dark-mode .summary-blue .sc-label   { color: #93c5fd; opacity: 0.9; }
html.dark-mode .summary-green .sc-label  { color: #6ee7b7; opacity: 0.9; }
html.dark-mode .summary-orange .sc-label { color: #fcd34d; opacity: 0.9; }
html.dark-mode .summary-purple .sc-label { color: #c4b5fd; opacity: 0.9; }

/* ============================================================
   FILTERS
   ============================================================ */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px; max-width: 100%;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label {
    font-size: 11px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.filter-actions { flex-direction: row; align-items: flex-end; gap: 8px; }
.form-control {
    padding: 9px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px; color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    min-width: 150px;
    font-family: 'Inter', sans-serif;
}
.form-control:focus {
    outline: none;
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    background: var(--bg-card);
}
.btn-filter {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF; padding: 9px 20px;
    border: none; border-radius: 8px;
    font-weight: 700; font-size: 12px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(30, 64, 175, 0.25);
    white-space: nowrap;
}
.btn-filter:hover { transform: translateY(-2px); box-shadow: 0 5px 16px rgba(30, 64, 175, 0.4); }
.btn-reset {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color); padding: 9px 18px;
    border-radius: 8px; font-weight: 600; font-size: 12px;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease; text-decoration: none; white-space: nowrap;
}
.btn-reset:hover { background: var(--blue-lightest); color: var(--blue-primary); border-color: var(--blue-light); }

/* ============================================================
   REPORT CARD
   ============================================================ */
.report-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    margin-bottom: 20px;
    transition: all 0.3s ease;
}
.report-card:hover {
    box-shadow: 0 8px 24px var(--shadow-hover);
    border-color: var(--blue-light);
}

/* ============================================================
   REPORT HEADER
   ============================================================ */
.report-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    padding: 20px 24px;
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 20px;
    align-items: center;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.report-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}

.report-header-info {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 1;
    min-width: 0;
}
.report-header-icon {
    width: 54px; height: 54px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(8px);
}
.report-header-text { min-width: 0; }
.report-header-number {
    font-family: 'Courier New', monospace;
    font-size: 16px;
    font-weight: 900;
    color: #FFFFFF;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
    word-break: break-all;
}
.report-header-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.report-header-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    padding: 4px 10px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
}
.report-header-meta-item i { font-size: 10px; }
.report-header-meta-item.source-evening {
    background: rgba(124, 58, 237, 0.35);
    border-color: rgba(167, 139, 250, 0.5);
    color: #FFFFFF;
}
.report-header-meta-item.source-capital {
    background: rgba(217, 119, 6, 0.35);
    border-color: rgba(252, 211, 77, 0.5);
    color: #FFFFFF;
}

.report-header-employee {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
    z-index: 1;
    padding: 0 20px;
    border-left: 2px solid rgba(255, 255, 255, 0.2);
    border-right: 2px solid rgba(255, 255, 255, 0.2);
}
.employee-cell { display: flex; align-items: center; gap: 12px; }
.emp-avatar-img {
    width: 46px; height: 46px;
    border-radius: 50%; object-fit: cover;
    border: 3px solid #FFFFFF;
    flex-shrink: 0; background: #f3f4f6;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}
.emp-avatar {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 18px;
    flex-shrink: 0;
    border: 3px solid #FFFFFF;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(8px);
}
.emp-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.emp-name {
    font-size: 14px; font-weight: 800;
    color: #FFFFFF;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 180px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}
.emp-code {
    font-size: 10px; font-weight: 700;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 8px; border-radius: 6px;
    align-self: flex-start;
    border: 1px solid rgba(255, 255, 255, 0.25);
}

.employee-cash {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.95);
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    font-weight: 600;
}
.employee-cash i { color: #FCD34D; }
.employee-cash strong {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #FFFFFF;
    font-size: 13px;
}

.employee-status { display: flex; align-items: center; }

.report-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}
.btn-view-report {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: #FFFFFF;
    color: #1e40af;
    border-radius: 10px;
    font-weight: 800;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
    white-space: nowrap;
    border: 2px solid #FFFFFF;
}
.btn-view-report:hover {
    transform: translateY(-2px);
    background: #EFF6FF;
    color: #1e3a8a;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}
.btn-view-report i { font-size: 14px; }

.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 20px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1.5px solid;
}
.status-open {
    background: rgba(209, 250, 229, 0.25);
    color: #FFFFFF;
    border-color: rgba(167, 243, 208, 0.5);
}
.status-locked {
    background: rgba(254, 226, 226, 0.25);
    color: #FFFFFF;
    border-color: rgba(254, 202, 202, 0.5);
}

/* ============================================================
   REPORT BODY - PROVIDER TABLE
   ============================================================ */
.report-body {
    padding: 0;
    background: var(--bg-card);
}

.provider-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.provider-table thead tr {
    background: var(--bg-table-even);
    border-bottom: 2px solid var(--border-color);
}
.provider-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.provider-table thead th.text-right { text-align: right; }

.provider-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.provider-table tbody tr:hover {
    background: var(--bg-table-hover);
}
.provider-table tbody tr:nth-child(even) {
    background: var(--bg-table-even);
}
.provider-table tbody td {
    padding: 14px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.provider-table tbody td.text-right { text-align: right; }

.provider-row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 8px;
    background: var(--blue-lighter);
    color: var(--blue-primary);
    font-size: 12px;
    font-weight: 800;
    border: 1px solid #BFDBFE;
}
html.dark-mode .provider-row-number {
    background: #1e3a5f; color: #93c5fd; border-color: #3b82f6;
}

.provider-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.provider-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.provider-name {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
}

.code-badge {
    display: inline-block;
    padding: 5px 12px;
    background: #FEF3C7;
    color: #D97706;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1px solid #FDE68A;
}
html.dark-mode .code-badge { background: #5f3a1e; color: #fbbf24; border-color: #92400e; }

.provider-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.provider-type-badge i { font-size: 11px; }
.type-bank { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
.type-mobile_money { background: #EDE9FE; color: #7C3AED; border: 1px solid #C4B5FD; }
.type-other { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
html.dark-mode .type-bank { background: #1e3a5f; color: #93c5fd; border-color: #3b82f6; }
html.dark-mode .type-mobile_money { background: #4c1d95; color: #ddd6fe; border-color: #8b5cf6; }
html.dark-mode .type-other { background: #5f3a1e; color: #fbbf24; border-color: #d97706; }

.amount-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 13px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.amount-float {
    background: #DBEAFE;
    color: #1E40AF;
    border: 1px solid #BFDBFE;
}
.amount-cash {
    background: #D1FAE5;
    color: #059669;
    border: 1px solid #A7F3D0;
}
.amount-total {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E3A8A;
    border: 2px solid #2563EB;
    font-size: 14px;
}
.amount-grand {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #78350F;
    border: 2px solid #F59E0B;
    font-size: 15px;
    font-weight: 900;
}
html.dark-mode .amount-float { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
html.dark-mode .amount-cash { background: #065f46; color: #34d399; border-color: #10b981; }
html.dark-mode .amount-total { background: linear-gradient(135deg, #1e3a5f, #1e40af); color: #93c5fd; border-color: #3b82f6; }
html.dark-mode .amount-grand { background: linear-gradient(135deg, #5f3a1e, #78350f); color: #fcd34d; border-color: #d97706; }

.provider-table tfoot tr {
    border-top: 1px solid var(--border-color);
}
.provider-table tfoot td {
    padding: 14px 16px;
    font-weight: 800;
}
.provider-table tfoot td.text-right { text-align: right; }

.tfoot-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.tfoot-label i { font-size: 14px; }
.tfoot-label.grand { font-size: 13px; }

.total-float-row {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%) !important;
}
.total-float-row .tfoot-label { color: #1E40AF; }
.total-float-row .tfoot-label i { color: #2563EB; }
html.dark-mode .total-float-row { background: linear-gradient(135deg, #1e3a5f, #1e40af) !important; }
html.dark-mode .total-float-row .tfoot-label { color: #93c5fd; }

.cash-row {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%) !important;
}
.cash-row .tfoot-label { color: #059669; }
.cash-row .tfoot-label i { color: #10B981; }
html.dark-mode .cash-row { background: linear-gradient(135deg, #065f46, #047857) !important; }
html.dark-mode .cash-row .tfoot-label { color: #34d399; }

.grand-total-row {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%) !important;
    border-top: 2px solid #F59E0B !important;
}
.grand-total-row .tfoot-label { color: #78350F; font-weight: 900; }
.grand-total-row .tfoot-label i { color: #D97706; }
html.dark-mode .grand-total-row { background: linear-gradient(135deg, #5f3a1e, #78350f) !important; }
html.dark-mode .grand-total-row .tfoot-label { color: #fcd34d; }

.no-providers {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}
.no-providers i {
    font-size: 42px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-providers p {
    font-size: 13px;
    margin: 0;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
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
    margin: 0 0 20px 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .report-header {
        grid-template-columns: 1fr auto;
        gap: 16px;
    }
    .report-header-employee {
        border-left: none;
        padding-left: 0;
        border-right: 2px solid rgba(255, 255, 255, 0.2);
    }
}
@media (max-width: 900px) {
    .report-header {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .report-header-employee {
        border-left: none;
        border-right: none;
        padding: 0;
        padding-top: 16px;
        border-top: 2px solid rgba(255, 255, 255, 0.2);
        flex-wrap: wrap;
    }
    .report-header-actions { justify-content: stretch; }
    .btn-view-report { width: 100%; justify-content: center; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn-new-report,
    .page-header .header-right .btn-export,
    .page-header .header-right .btn-back {
        flex: 1; justify-content: center;
    }

    .summary-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .summary-card { padding: 16px 16px; gap: 12px; }
    .sc-icon { width: 46px; height: 46px; font-size: 18px; border-radius: 12px; }
    .sc-value { font-size: 16px; letter-spacing: -0.3px; }
    .sc-label { font-size: 9px; letter-spacing: 0.8px; }

    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .filter-actions { flex-direction: row; width: 100%; }
    .filter-actions .btn-filter,
    .filter-actions .btn-reset { flex: 1; justify-content: center; }

    .report-header { padding: 16px; }
    .report-header-info { flex-direction: column; align-items: flex-start; gap: 12px; }
    .report-header-icon { width: 46px; height: 46px; font-size: 18px; }
    .report-header-number { font-size: 14px; }

    .report-header-employee { flex-direction: column; align-items: flex-start; gap: 12px; }
    .emp-avatar-img, .emp-avatar { width: 40px; height: 40px; font-size: 16px; }
    .emp-name { font-size: 13px; max-width: 150px; }

    .provider-table { font-size: 12px; min-width: 600px; }
    .provider-table thead th { padding: 10px 12px; font-size: 9px; }
    .provider-table tbody td { padding: 12px 12px; }
    .provider-icon { width: 34px; height: 34px; font-size: 14px; }
    .provider-name { font-size: 12px; }
    .amount-badge { padding: 5px 10px; font-size: 12px; }
    .amount-total, .amount-grand { font-size: 13px; }
}
@media (max-width: 480px) {
    .summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .summary-card {
        padding: 12px 12px; gap: 8px;
        flex-direction: column; align-items: flex-start;
    }
    .sc-icon { width: 40px; height: 40px; font-size: 16px; }
    .sc-value { font-size: 14px; letter-spacing: -0.2px; }
    .sc-label { font-size: 8px; }
    .report-header-number { font-size: 12px; }
    .btn-view-report { padding: 10px 16px; font-size: 12px; }
    .provider-table { min-width: 500px; }
    .provider-table thead th { padding: 8px 10px; font-size: 8px; }
    .provider-table tbody td { padding: 10px 10px; }
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