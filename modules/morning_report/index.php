<?php
// ================================================================
// FILE: modules/morning_report/index.php
// WAKALA FINANCIAL SYSTEM - MORNING REPORTS (ADMIN) - FINAL
// 
// ✅ BLUE THEME
// ✅ Summary Cards: 2x2 GRID + SOFT BACKGROUND (60% opacity)
// ✅ "AWAITING STOCK" badge for dates not yet reached (ENGLISH)
// ✅ Report Card Design
// ✅ View/Edit/Delete on Report Header
// ✅ Provider rows + Footer totals
// ✅ ALL INSTRUCTIONS IN ENGLISH
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
// ADMIN ONLY
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}

$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($selected_branch == 0 && count($branches) === 1) {
    $selected_branch = intval($branches[0]['id']);
}

// ============================================================
// BRANCH INFO
// ============================================================
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// ============================================================
// FILTERS
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date   = isset($_GET['to_date'])   ? $_GET['to_date']   : date('Y-m-d');
$search    = isset($_GET['search'])    ? trim($_GET['search']) : '';

// ============================================================
// TODAY - For "AWAITING STOCK" check
// ============================================================
$today_date = date('Y-m-d');
$yesterday_date = date('Y-m-d', strtotime('-1 day'));

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
        WHERE mr.report_date BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND mr.branch_id = ?";
        $params[] = $selected_branch;
    }

    if ($search !== '') {
        $sql .= " AND (mr.report_number LIKE ? OR e.full_name LIKE ? OR b.branch_name LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
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
// CHECK IF "AWAITING STOCK" TODAY
// ============================================================
$awaiting_today = true;
$awaiting_branch_name = '';

if ($selected_branch > 0) {
    // Check for today
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM morning_reports 
        WHERE branch_id = ? AND report_date = ?
    ");
    $stmt->execute([$selected_branch, $today_date]);
    if ($stmt->fetchColumn() > 0) {
        $awaiting_today = false;
    } else {
        // Check for yesterday
        $stmt->execute([$selected_branch, $yesterday_date]);
        if ($stmt->fetchColumn() > 0) {
            $awaiting_today = false;
        }
    }
    $awaiting_branch_name = $branch_name;
} else {
    // All branches - check if each branch has a report for today/yesterday
    $awaiting_branches = [];
    foreach ($branches as $b) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM morning_reports 
            WHERE branch_id = ? AND report_date IN (?, ?)
        ");
        $stmt->execute([$b['id'], $today_date, $yesterday_date]);
        if ($stmt->fetchColumn() == 0) {
            $awaiting_branches[] = $b['branch_name'];
        }
    }
    if (empty($awaiting_branches)) {
        $awaiting_today = false;
    } else {
        $awaiting_branch_name = implode(', ', $awaiting_branches);
    }
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

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR - BLUE THEME -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">
                        <?php echo $selected_branch > 0 ? 'Current Branch' : 'Showing'; ?>
                    </span>
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
                <button type="button" class="btn-add" onclick="openAddPicker()">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Morning Report</span>
                </button>
                <a href="export.php?branch_id=<?php echo $selected_branch; ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>" 
                   class="btn-export">
                    <i class="fas fa-file-csv"></i>
                    <span>Export CSV</span>
                </a>
                <a href="../daily_report/index.php?branch_id=<?php echo $selected_branch; ?>" class="btn-back">
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
             AWAITING STOCK BANNER — ENGLISH
             ============================================================ -->
        <?php if ($awaiting_today): ?>
            <div class="awaiting-banner">
                <div class="awaiting-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="awaiting-content">
                    <h4>AWAITING STOCK</h4>
                    <p>
                        <strong><?php echo htmlspecialchars($awaiting_branch_name); ?></strong> 
                        <?php echo $selected_branch > 0 ? 'does not have' : 'do not have'; ?> 
                        a morning report for today (<?php echo date('d M Y'); ?>). 
                        Please create an <strong>Evening Stock</strong> for yesterday or set 
                        <strong>Opening Capital</strong>.
                    </p>
                </div>
                <div class="awaiting-action">
                    <button type="button" class="btn-awaiting-add" onclick="openAddPicker()">
                        <i class="fas fa-plus-circle"></i> Add Now
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             SUMMARY CARDS - 2x2 GRID + SOFT BACKGROUND (60% opacity)
             ============================================================ -->
        <div class="summary-cards">
            
            <!-- CARD 1: TOTAL REPORTS (Blue) -->
            <div class="summary-card summary-blue">
                <div class="sc-bg-icon sc-bg-blue">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Reports</span>
                    <span class="sc-value"><?php echo number_format($total_reports); ?></span>
                </div>
            </div>

            <!-- CARD 2: TOTAL FLOAT (Green) -->
            <div class="summary-card summary-green">
                <div class="sc-bg-icon sc-bg-green">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Float</span>
                    <span class="sc-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
            </div>

            <!-- CARD 3: TOTAL CASH (Orange) -->
            <div class="summary-card summary-orange">
                <div class="sc-bg-icon sc-bg-orange">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Total Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($total_cash); ?></span>
                </div>
            </div>

            <!-- CARD 4: GRAND TOTAL (Purple) -->
            <div class="summary-card summary-purple">
                <div class="sc-bg-icon sc-bg-purple">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="sc-content">
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
                    <label>Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Report #, employee...">
                </div>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
             REPORT CARDS
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
                $is_locked = intval($report['is_locked']) === 1;
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
                                        <i class="fas fa-store-alt"></i>
                                        <?php echo htmlspecialchars($report['branch_display_name'] ?? 'N/A'); ?>
                                    </span>
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
                                <?php if ($is_locked): ?>
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
                        
                        <!-- RIGHT: Action Buttons (View + Edit + Delete) -->
                        <div class="report-header-actions">
                            <a href="view.php?id=<?php echo $rid; ?>" 
                               class="btn-action-header btn-view-header"
                               title="View Report">
                                <i class="fas fa-eye"></i>
                                <span>View</span>
                            </a>
                            
                            <?php if (!$is_locked): ?>
                                <a href="edit.php?id=<?php echo $rid; ?>" 
                                   class="btn-action-header btn-edit-header"
                                   title="Edit Report">
                                    <i class="fas fa-edit"></i>
                                    <span>Edit</span>
                                </a>
                                
                                <button onclick="deleteReport(<?php echo $rid; ?>, '<?php echo addslashes($report['report_number']); ?>')" 
                                        class="btn-action-header btn-delete-header"
                                        title="Delete Report">
                                    <i class="fas fa-trash"></i>
                                    <span>Delete</span>
                                </button>
                            <?php else: ?>
                                <span class="btn-action-header btn-locked-header" title="Locked">
                                    <i class="fas fa-lock"></i>
                                    <span>Locked</span>
                                </span>
                            <?php endif; ?>
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
                                    <!-- TOTAL FLOAT -->
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
                                    <!-- CASH -->
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
                                    <!-- GRAND TOTAL -->
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
                <p>No morning reports found in the selected period.</p>
                <button type="button" class="btn-add" onclick="openAddPicker()">
                    <i class="fas fa-plus-circle"></i> Create First Report
                </button>
            </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ADD BRANCH PICKER MODAL -->
<div class="modal-overlay" id="addPickerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add Morning Report</h3>
            <button class="modal-close" onclick="closeAddPicker()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="modal-intro">Select a branch to add a morning report:</p>
            <div class="branch-picker-grid">
                <?php foreach ($branches as $b): ?>
                    <a href="add.php?branch_id=<?php echo $b['id']; ?>&date=<?php echo date('Y-m-d'); ?>" 
                       class="branch-picker-card">
                        <div class="bpc-icon">
                            <i class="fas fa-store-alt"></i>
                        </div>
                        <div class="bpc-info">
                            <span class="bpc-name"><?php echo htmlspecialchars($b['branch_name']); ?></span>
                            <?php if (!empty($b['branch_code'])): ?>
                                <span class="bpc-code"><?php echo htmlspecialchars($b['branch_code']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($b['location'])): ?>
                                <span class="bpc-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($b['location']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <i class="fas fa-arrow-right bpc-arrow"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAddPicker()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<style>
/* ============================================================
   VARIABLES
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

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

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
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; position: relative; z-index: 1; }
.branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0; backdrop-filter: blur(8px); border: 1.5px solid rgba(255, 255, 255, 0.3); }
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF; }
.branch-indicator-name { font-weight: 800; font-size: 16px; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
.branch-indicator-code { font-size: 11px; font-weight: 700; color: #FFFFFF; padding: 3px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.25); }
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(255,255,255,0.9); padding: 4px 12px; background: rgba(255, 255, 255, 0.12); border-radius: 12px; white-space: nowrap; }
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; position: relative; z-index: 1; }
.branch-indicator-right .date-display { font-size: 12px; color: rgba(255,255,255,0.95); padding: 6px 14px; background: rgba(255, 255, 255, 0.15); border-radius: 16px; display: flex; align-items: center; gap: 6px; white-space: nowrap; font-weight: 600; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary); }
.page-header .header-left h2 i { margin-right: 10px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; font-weight: 500; }
.page-header .header-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-add { background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); color: #FFFFFF; padding: 11px 22px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; text-decoration: none; box-shadow: 0 4px 14px rgba(30, 64, 175, 0.35); white-space: nowrap; }
.btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 22px rgba(30, 64, 175, 0.5); color: #FFFFFF; }
.btn-export { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #FFFFFF; padding: 11px 22px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; text-decoration: none; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35); white-space: nowrap; }
.btn-export:hover { transform: translateY(-2px); box-shadow: 0 6px 22px rgba(5, 150, 105, 0.5); color: #FFFFFF; }
.btn-back { background: var(--bg-card); color: var(--text-secondary); border: 1.5px solid var(--border-color); padding: 10px 20px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s ease; text-decoration: none; white-space: nowrap; }
.btn-back:hover { background: var(--blue-lightest); color: var(--blue-primary); border-color: var(--blue-light); transform: translateY(-2px); }

/* ============================================================
   ALERTS
   ============================================================ */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px var(--shadow-color); animation: slideDown 0.4s ease forwards; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
html.dark-mode .alert-success { background: #065f46; color: #d1fae5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }
.alert-close:hover { opacity: 1; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* ============================================================
   AWAITING STOCK BANNER - ENGLISH
   ============================================================ */
.awaiting-banner {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 50%, #FCD34D 100%);
    border: 2px solid #F59E0B;
    border-left: 6px solid #D97706;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 18px;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.2);
    flex-wrap: wrap;
    animation: slideDown 0.5s ease;
    position: relative;
    overflow: hidden;
}
.awaiting-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 50%;
    pointer-events: none;
}
html.dark-mode .awaiting-banner {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 50%, #92400E 100%);
    border-color: #D97706;
    border-left-color: #FBBF24;
}
.awaiting-icon {
    width: 54px; height: 54px;
    background: rgba(255, 255, 255, 0.4);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #78350F;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.6);
    animation: pulse 2s ease-in-out infinite;
    position: relative;
    z-index: 1;
}
html.dark-mode .awaiting-icon { background: rgba(0,0,0,0.25); color: #FCD34D; border-color: rgba(251, 191, 36, 0.4); }
@keyframes pulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(217, 119, 6, 0.5); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 12px rgba(217, 119, 6, 0); }
}
.awaiting-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.awaiting-content h4 {
    font-size: 15px; font-weight: 900;
    color: #78350F;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 0 0 4px 0;
}
html.dark-mode .awaiting-content h4 { color: #FCD34D; }
.awaiting-content p {
    font-size: 13px; color: #78350F;
    margin: 0; line-height: 1.6;
}
html.dark-mode .awaiting-content p { color: #FDE68A; }
.awaiting-content strong {
    background: rgba(255, 255, 255, 0.5);
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 800;
}
html.dark-mode .awaiting-content strong { background: rgba(0,0,0,0.25); color: #FCD34D; }
.awaiting-action { position: relative; z-index: 1; flex-shrink: 0; }
.btn-awaiting-add {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
    padding: 11px 22px;
    border: none; border-radius: 10px;
    font-weight: 800; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 14px rgba(180, 83, 9, 0.4);
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
}
.btn-awaiting-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(180, 83, 9, 0.55);
}

/* ============================================================
   ✅ SUMMARY CARDS - SOFT BACKGROUND (60% opacity)
   ============================================================ */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
    width: 100%;
}

.summary-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    border-radius: 16px;
    border: 2px solid;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
    min-width: 0;
    overflow: hidden;
}

/* ✅ BACKGROUND COLORS - 60% OPACITY (soft, not full) */
.summary-blue {
    background: linear-gradient(135deg, rgba(219, 234, 254, 0.6) 0%, rgba(191, 219, 254, 0.6) 100%);
    border-color: rgba(59, 130, 246, 0.4);
}
.summary-green {
    background: linear-gradient(135deg, rgba(209, 250, 229, 0.6) 0%, rgba(167, 243, 208, 0.6) 100%);
    border-color: rgba(16, 185, 129, 0.4);
}
.summary-orange {
    background: linear-gradient(135deg, rgba(254, 243, 199, 0.6) 0%, rgba(253, 230, 138, 0.6) 100%);
    border-color: rgba(245, 158, 11, 0.4);
}
.summary-purple {
    background: linear-gradient(135deg, rgba(237, 233, 254, 0.6) 0%, rgba(221, 214, 254, 0.6) 100%);
    border-color: rgba(139, 92, 246, 0.4);
}

/* DARK MODE - 60% opacity */
html.dark-mode .summary-blue {
    background: linear-gradient(135deg, rgba(30, 58, 95, 0.6) 0%, rgba(30, 64, 175, 0.6) 100%);
    border-color: rgba(59, 130, 246, 0.5);
}
html.dark-mode .summary-green {
    background: linear-gradient(135deg, rgba(6, 95, 70, 0.6) 0%, rgba(4, 120, 87, 0.6) 100%);
    border-color: rgba(16, 185, 129, 0.5);
}
html.dark-mode .summary-orange {
    background: linear-gradient(135deg, rgba(95, 58, 30, 0.6) 0%, rgba(120, 53, 15, 0.6) 100%);
    border-color: rgba(217, 119, 6, 0.5);
}
html.dark-mode .summary-purple {
    background: linear-gradient(135deg, rgba(76, 29, 149, 0.6) 0%, rgba(91, 33, 182, 0.6) 100%);
    border-color: rgba(139, 92, 246, 0.5);
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
}

/* Background Icon (decorative, faded) */
.sc-bg-icon {
    position: absolute;
    right: -10px;
    bottom: -15px;
    font-size: 110px;
    opacity: 0.08;
    pointer-events: none;
    line-height: 1;
    transform: rotate(-15deg);
}
.sc-bg-blue { color: #1e40af; }
.sc-bg-green { color: #059669; }
.sc-bg-orange { color: #d97706; }
.sc-bg-purple { color: #7c3aed; }

html.dark-mode .sc-bg-icon { opacity: 0.12; }
html.dark-mode .sc-bg-blue { color: #60a5fa; }
html.dark-mode .sc-bg-green { color: #34d399; }
html.dark-mode .sc-bg-orange { color: #fbbf24; }
html.dark-mode .sc-bg-purple { color: #a78bfa; }

.sc-content {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.sc-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 800;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sc-value {
    font-size: 26px;
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -0.5px;
    word-break: break-word;
    overflow-wrap: anywhere;
    display: block;
    max-width: 100%;
    font-family: 'Inter', 'Courier New', monospace;
}

/* Text colors per card */
.summary-blue .sc-label { color: #1e40af; }
.summary-blue .sc-value { color: #1e3a8a; }
.summary-green .sc-label { color: #047857; }
.summary-green .sc-value { color: #065F46; }
.summary-orange .sc-label { color: #B45309; }
.summary-orange .sc-value { color: #78350F; }
.summary-purple .sc-label { color: #6D28D9; }
.summary-purple .sc-value { color: #4C1D95; }

html.dark-mode .summary-blue .sc-label { color: #93c5fd; }
html.dark-mode .summary-blue .sc-value { color: #DBEAFE; }
html.dark-mode .summary-green .sc-label { color: #6ee7b7; }
html.dark-mode .summary-green .sc-value { color: #D1FAE5; }
html.dark-mode .summary-orange .sc-label { color: #fcd34d; }
html.dark-mode .summary-orange .sc-value { color: #FEF3C7; }
html.dark-mode .summary-purple .sc-label { color: #c4b5fd; }
html.dark-mode .summary-purple .sc-value { color: #EDE9FE; }

/* ============================================================
   FILTERS
   ============================================================ */
.filters-bar { background: var(--bg-card); padding: 16px 20px; border-radius: 12px; border: 1.5px solid var(--border-color); margin-bottom: 18px; max-width: 100%; box-shadow: 0 2px 8px var(--shadow-color); }
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.filter-actions { flex-direction: row; align-items: flex-end; gap: 8px; }
.form-control { padding: 9px 14px; border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 12px; color: var(--text-primary); background: var(--bg-input); transition: all 0.3s ease; min-width: 150px; font-family: 'Inter', sans-serif; }
.form-control:focus { outline: none; border-color: var(--blue-primary); box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1); background: var(--bg-card); }
.btn-filter { background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); color: #FFFFFF; padding: 9px 20px; border: none; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s ease; box-shadow: 0 3px 10px rgba(30, 64, 175, 0.25); white-space: nowrap; }
.btn-filter:hover { transform: translateY(-2px); box-shadow: 0 5px 16px rgba(30, 64, 175, 0.4); }
.btn-reset { background: var(--bg-card); color: var(--text-secondary); border: 1.5px solid var(--border-color); padding: 9px 18px; border-radius: 8px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s ease; text-decoration: none; white-space: nowrap; }
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

.report-header-info { display: flex; align-items: center; gap: 16px; position: relative; z-index: 1; min-width: 0; }
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
.report-header-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
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
    gap: 8px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}

.btn-action-header {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 800;
    font-size: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    white-space: nowrap;
    border: 2px solid;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
}
.btn-action-header i { font-size: 13px; }
.btn-action-header span { display: inline-block; }

.btn-view-header {
    background: #FFFFFF;
    color: #1e40af;
    border-color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
}
.btn-view-header:hover {
    transform: translateY(-2px);
    background: #EFF6FF;
    color: #1e3a8a;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}

.btn-edit-header {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    box-shadow: 0 4px 14px rgba(252, 211, 77, 0.4);
}
.btn-edit-header:hover {
    transform: translateY(-2px);
    background: #FBBF24;
    color: #78350F;
    border-color: #FBBF24;
    box-shadow: 0 6px 20px rgba(251, 191, 36, 0.5);
}

.btn-delete-header {
    background: #DC2626;
    color: #FFFFFF;
    border-color: #DC2626;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.4);
}
.btn-delete-header:hover {
    transform: translateY(-2px);
    background: #B91C1C;
    color: #FFFFFF;
    border-color: #B91C1C;
    box-shadow: 0 6px 20px rgba(185, 28, 28, 0.5);
}

.btn-locked-header {
    background: rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.8);
    border-color: rgba(255, 255, 255, 0.3);
    cursor: not-allowed;
    opacity: 0.8;
}

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
.report-body { padding: 0; background: var(--bg-card); }

.provider-table { width: 100%; border-collapse: collapse; font-size: 13px; }

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
.provider-table tbody tr:hover { background: var(--bg-table-hover); }
.provider-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.provider-table tbody td { padding: 14px 16px; color: var(--text-primary); vertical-align: middle; }
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
html.dark-mode .provider-row-number { background: #1e3a5f; color: #93c5fd; border-color: #3b82f6; }

.provider-cell { display: flex; align-items: center; gap: 12px; }
.provider-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.provider-name { font-size: 13px; font-weight: 800; color: var(--text-primary); }

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
.amount-float { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
.amount-cash { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
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

.provider-table tfoot tr { border-top: 1px solid var(--border-color); }
.provider-table tfoot td { padding: 14px 16px; font-weight: 800; }
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

.total-float-row { background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%) !important; }
.total-float-row .tfoot-label { color: #1E40AF; }
.total-float-row .tfoot-label i { color: #2563EB; }
html.dark-mode .total-float-row { background: linear-gradient(135deg, #1e3a5f, #1e40af) !important; }
html.dark-mode .total-float-row .tfoot-label { color: #93c5fd; }

.cash-row { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%) !important; }
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

.no-providers { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.no-providers i { font-size: 42px; color: var(--text-light); opacity: 0.4; display: block; margin-bottom: 12px; }
.no-providers p { font-size: 13px; margin: 0; font-weight: 600; }

.empty-state {
    text-align: center; padding: 60px 20px;
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.empty-state i { font-size: 56px; color: var(--text-light); opacity: 0.4; display: block; margin-bottom: 16px; }
.empty-state h3 { font-size: 18px; color: var(--text-primary); margin: 0 0 8px 0; }
.empty-state p { color: var(--text-muted); font-size: 14px; margin: 0 0 20px 0; }

/* ============================================================
   MODAL
   ============================================================ */
.modal-overlay {
    display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999; align-items: center; justify-content: center;
    padding: 20px; backdrop-filter: blur(4px);
}
.modal-overlay.show { display: flex; }
.modal-content {
    background: var(--bg-card); border-radius: 16px;
    width: 100%; max-width: 720px;
    max-height: 90vh; overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: modalIn 0.3s ease;
    display: flex; flex-direction: column;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { margin: 0; font-size: 17px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
.modal-header h3 i { color: #FCD34D; }
.modal-close {
    background: rgba(255, 255, 255, 0.15);
    border: none; width: 32px; height: 32px;
    border-radius: 50%; color: #FFFFFF;
    font-size: 20px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
}
.modal-close:hover { background: rgba(255, 255, 255, 0.3); transform: rotate(90deg); }
.modal-body { padding: 24px; overflow-y: auto; flex: 1; }
.modal-intro { font-size: 13px; color: var(--text-muted); margin: 0 0 16px 0; font-weight: 500; }
.branch-picker-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.branch-picker-card {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px; text-decoration: none;
    transition: all 0.3s ease; cursor: pointer;
}
.branch-picker-card:hover {
    border-color: #2563eb;
    background: #eff6ff;
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
}
html.dark-mode .branch-picker-card:hover { background: #1e3a5f; }
.bpc-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(30, 64, 175, 0.3);
}
.bpc-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.bpc-name { font-size: 14px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bpc-code {
    font-size: 10px; font-weight: 700; color: #1e40af;
    background: #dbeafe; padding: 2px 8px; border-radius: 6px;
    align-self: flex-start; font-family: 'Courier New', monospace;
}
html.dark-mode .bpc-code { background: #1e3a5f; color: #93c5fd; }
.bpc-location { font-size: 10px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
.bpc-location i { color: #2563eb; font-size: 9px; }
.bpc-arrow { color: #94a3b8; font-size: 14px; transition: all 0.3s ease; }
.branch-picker-card:hover .bpc-arrow { color: #2563eb; transform: translateX(4px); }
.modal-footer {
    padding: 16px 24px;
    background: var(--bg-input);
    display: flex; gap: 10px; justify-content: flex-end;
    border-top: 1px solid var(--border-color);
}
.btn-secondary {
    background: var(--bg-table-even); color: var(--text-secondary);
    border: 1px solid var(--border-color); padding: 10px 18px;
    border-radius: 8px; font-weight: 600; cursor: pointer;
    font-size: 12px; text-decoration: none;
    display: inline-flex; align-items: center; gap: 5px;
    font-family: 'Inter', sans-serif;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .report-header { grid-template-columns: 1fr auto; }
    .report-header-employee {
        border-left: none; padding-left: 0;
        border-right: 2px solid rgba(255, 255, 255, 0.2);
    }
}
@media (max-width: 1200px) {
    .report-header { grid-template-columns: 1fr; gap: 16px; }
    .report-header-employee {
        border-left: none; border-right: none;
        padding: 0; padding-top: 16px;
        border-top: 2px solid rgba(255, 255, 255, 0.2);
        flex-wrap: wrap;
    }
    .report-header-actions { justify-content: flex-start; }
}
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn-add,
    .page-header .header-right .btn-export,
    .page-header .header-right .btn-back { flex: 1; justify-content: center; }

    .awaiting-banner { flex-direction: column; align-items: flex-start; text-align: left; }
    .awaiting-action { width: 100%; }
    .btn-awaiting-add { width: 100%; justify-content: center; }

    .summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .summary-card { padding: 16px 16px; gap: 12px; border-radius: 12px; }
    .sc-bg-icon { font-size: 80px; }
    .sc-value { font-size: 18px; letter-spacing: -0.3px; }
    .sc-label { font-size: 9px; letter-spacing: 0.8px; }

    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .filter-actions { flex-direction: row; width: 100%; }
    .filter-actions .btn-filter,
    .filter-actions .btn-reset { flex: 1; justify-content: center; }
    .branch-picker-grid { grid-template-columns: 1fr; }

    .report-header { padding: 16px; }
    .report-header-info { flex-direction: column; align-items: flex-start; gap: 12px; }
    .report-header-icon { width: 46px; height: 46px; font-size: 18px; }
    .report-header-number { font-size: 14px; }

    .report-header-employee { flex-direction: column; align-items: flex-start; gap: 12px; }
    .emp-avatar-img, .emp-avatar { width: 40px; height: 40px; font-size: 16px; }
    .emp-name { font-size: 13px; max-width: 150px; }

    .report-header-actions { flex-wrap: wrap; gap: 6px; width: 100%; }
    .btn-action-header { flex: 1; justify-content: center; padding: 10px 12px; font-size: 11px; }

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
        padding: 14px 12px; gap: 10px;
        flex-direction: column; align-items: flex-start;
    }
    .sc-bg-icon { font-size: 70px; right: -8px; bottom: -10px; }
    .sc-value { font-size: 15px; letter-spacing: -0.2px; }
    .sc-label { font-size: 8px; }
    .report-header-number { font-size: 12px; }
    .btn-action-header { padding: 8px 10px; font-size: 10px; }
    .btn-action-header span { font-size: 10px; }
    .provider-table { min-width: 500px; }
    .provider-table thead th { padding: 8px 10px; font-size: 8px; }
    .provider-table tbody td { padding: 10px 10px; }
}
</style>

<script>
// ============================================================
// ADD PICKER MODAL
// ============================================================
function openAddPicker() {
    document.getElementById('addPickerModal').classList.add('show');
}
function closeAddPicker() {
    document.getElementById('addPickerModal').classList.remove('show');
}
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('addPickerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeAddPicker();
        });
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAddPicker();
});

// ============================================================
// DELETE
// ============================================================
function deleteReport(id, number) {
    if (confirm('Delete morning report "' + number + '"?\n\nThis action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

// ============================================================
// INIT
// ============================================================
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