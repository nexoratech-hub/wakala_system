<?php
// ================================================================
// FILE: modules/evening_stock/index_employee.php
// EVENING STOCK - EMPLOYEE VIEW ONLY
// ✅ Inafanana na admin index.php
// ✅ Cards ni BLUE (sio purple)
// ✅ Button ya Add Evening Stock imewekwa
// ✅ Employee anaona branch yake pekee
// ✅ Content haifichwi na sidebar wala topbar
// ✅ Footer inakaa CHINI kabisa
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
$role    = $_SESSION['role'] ?? 'employee';

// ============================================================
// GET EMPLOYEE INFO
// ============================================================
$stmt = $db->prepare("SELECT branch_id, branch, full_name FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = $emp['branch_id'] ?? 0;
$employee_branch    = $emp['branch'] ?? 'Main';
$employee_name      = $emp['full_name'] ?? 'Employee';

// ============================================================
// GET FILTERS
// ============================================================
$from_date     = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date       = isset($_GET['to_date'])   ? $_GET['to_date']   : date('Y-m-d');
$status_filter = isset($_GET['status'])    ? $_GET['status']    : '';

// Employee anaona branch yake pekee
$selected_branch = $employee_branch_id;

// ============================================================
// GET BRANCH INFO (for filter card display)
// ============================================================
$filter_branch_name     = $employee_branch;
$filter_branch_code     = '';
$filter_branch_location = '';

try {
    if ($selected_branch > 0) {
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$selected_branch]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $filter_branch_name     = $b['branch_name'];
            $filter_branch_code     = $b['branch_code'] ?? '';
            $filter_branch_location = $b['location'] ?? '';
        }
    }
} catch (PDOException $e) {
    // fallback
}

// ============================================================
// BUILD QUERY (employee = branch yake pekee)
// ============================================================
try {
    $sql = "SELECT es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            dr.report_number as daily_report_number,
            (SELECT COUNT(*) FROM evening_stock_providers WHERE evening_stock_id = es.id) as providers_count
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
            WHERE es.stock_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND es.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($status_filter)) {
        $sql .= " AND es.status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY es.stock_date DESC, es.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $all_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $all_stocks = [];
}

// ============================================================
// BUILD FLAT LIST + TOTALS
// ============================================================
$flat_stocks = [];
$grand_totals = [
    'count'          => 0,
    'waiting'        => 0,
    'approved'       => 0,
    'adjusted'       => 0,
    'rejected'       => 0,
    'total_float'    => 0,
    'total_cash'     => 0,
    'grand_total'    => 0,
    'branches_count' => 0
];

$previous_branch_id = null;
$branch_ids_seen    = [];

foreach ($all_stocks as $s) {
    $b_id = $s['branch_id'] ?? 0;
    $is_new_branch = ($previous_branch_id !== null && $previous_branch_id != $b_id);

    if (!in_array($b_id, $branch_ids_seen)) {
        $branch_ids_seen[] = $b_id;
    }

    $grand_totals['count']++;
    $grand_totals[$s['status']] = ($grand_totals[$s['status']] ?? 0) + 1;
    $grand_totals['total_float'] += floatval($s['cumm_total']   ?? 0);
    $grand_totals['total_cash']  += floatval($s['cash_balance'] ?? 0);
    $grand_totals['grand_total'] += floatval($s['cumm_total'] ?? 0) + floatval($s['cash_balance'] ?? 0);

    $flat_stocks[] = [
        'stock'         => $s,
        'is_new_branch' => $is_new_branch
    ];

    $previous_branch_id = $b_id;
}

$grand_totals['branches_count'] = count($branch_ids_seen);

// Status labels
$status_labels = [
    'waiting'  => ['label' => 'Waiting',  'icon' => 'fa-clock',        'color' => 'orange'],
    'approved' => ['label' => 'Approved', 'icon' => 'fa-check-circle', 'color' => 'green'],
    'adjusted' => ['label' => 'Adjusted', 'icon' => 'fa-sliders-h',    'color' => 'blue'],
    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red']
];

// Success/error messages
$success_message = '';
$error_message   = '';
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

        <!-- ============================================================
        BRANCH FILTER CARD
        ============================================================ -->
        <div class="branch-filter-card filter-active">
            <div class="filter-left">
                <i class="fas fa-store-alt"></i>
                <span class="filter-label">Showing:</span>
                <span class="filter-name"><?php echo htmlspecialchars($filter_branch_name); ?></span>
                <?php if ($filter_branch_code): ?>
                    <span class="filter-code">(<?php echo htmlspecialchars($filter_branch_code); ?>)</span>
                <?php endif; ?>
                <span class="filter-view-only">
                    <i class="fas fa-eye"></i> View Only
                </span>
            </div>
            <div class="filter-right">
                <a href="add_employee.php?branch=<?php echo $selected_branch; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-add">
                    <i class="fas fa-plus"></i> New Evening Stock
                </a>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-moon" style="color:#2563EB;"></i> Evening Stock</h2>
                <p class="text-muted">
                    Evening stock reports for <strong><?php echo htmlspecialchars($filter_branch_name); ?></strong>
                </p>
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
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        STATS CARDS - BLUE THEME
        ============================================================ -->
        <div class="stats-wrapper">
            <div class="stats-header">
                <div class="sh-left">
                    <div class="sh-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="sh-info">
                        <span class="sh-title">Evening Stock Summary</span>
                        <span class="sh-subtitle">
                            <?php echo htmlspecialchars($filter_branch_name); ?>
                            • <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                        </span>
                    </div>
                </div>
                <div class="sh-badge">
                    <i class="fas fa-database"></i>
                    <?php echo $grand_totals['count']; ?> Records
                </div>
            </div>

            <div class="stats-grid">
                <!-- TOTAL RECORDS -->
                <div class="stat-card card-total">
                    <div class="sc-icon sc-icon-blue">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Total Stocks</span>
                        <span class="sc-value"><?php echo $grand_totals['count']; ?></span>
                        <span class="sc-sub"><?php echo $grand_totals['branches_count']; ?> branch</span>
                    </div>
                </div>

                <!-- WAITING -->
                <div class="stat-card card-waiting">
                    <div class="sc-icon sc-icon-orange">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Waiting</span>
                        <span class="sc-value"><?php echo $grand_totals['waiting']; ?></span>
                        <span class="sc-sub">Pending approval</span>
                    </div>
                </div>

                <!-- APPROVED -->
                <div class="stat-card card-approved">
                    <div class="sc-icon sc-icon-green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Approved</span>
                        <span class="sc-value"><?php echo $grand_totals['approved']; ?></span>
                        <span class="sc-sub">Confirmed</span>
                    </div>
                </div>

                <!-- TOTAL FLOAT -->
                <div class="stat-card card-float">
                    <div class="sc-icon sc-icon-sky">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Total Float</span>
                        <span class="sc-value"><?php echo formatCurrency($grand_totals['total_float']); ?></span>
                        <span class="sc-sub">Provider float</span>
                    </div>
                </div>

                <!-- TOTAL CASH -->
                <div class="stat-card card-cash">
                    <div class="sc-icon sc-icon-teal">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Total Cash</span>
                        <span class="sc-value"><?php echo formatCurrency($grand_totals['total_cash']); ?></span>
                        <span class="sc-sub">Branch cash</span>
                    </div>
                </div>

                <!-- GRAND TOTAL -->
                <div class="stat-card card-grand">
                    <div class="sc-icon sc-icon-cyan">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="sc-content">
                        <span class="sc-label">Grand Total</span>
                        <span class="sc-value"><?php echo formatCurrency($grand_totals['grand_total']); ?></span>
                        <span class="sc-sub">Float + Cash</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FILTERS BAR
        ============================================================ -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <?php foreach ($status_labels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $status_filter == $key ? 'selected' : ''; ?>>
                                <?php echo $label['label']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group filter-buttons">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index_employee.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        MAIN TABLE
        ============================================================ -->
        <?php if (count($flat_stocks) > 0): ?>

            <div class="table-container-main">

                <!-- RED HEADER (Blue for employee) -->
                <div class="table-header-blue">

                    <div class="thb-left">
                        <i class="fas fa-list"></i>
                        <h3>Evening Stock Reports</h3>
                        <span class="thb-count"><?php echo $grand_totals['count']; ?> records</span>
                    </div>

                    <div class="thb-center">
                        <button type="button" class="scroll-btn-header" onclick="scrollTableMain('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>

                        <div class="search-wrapper-main">
                            <i class="fas fa-search"></i>
                            <input type="text"
                                   id="globalSearchInput"
                                   placeholder="Search..."
                                   oninput="onGlobalSearch(this)">
                            <button type="button" id="globalSearchClear" onclick="clearGlobalSearch()" style="display:none;">
                                <i class="fas fa-times"></i>
                            </button>
                            <span class="search-count-main" id="globalSearchCount" style="display:none;">0</span>
                        </div>

                        <button type="button" class="scroll-btn-header" onclick="scrollTableMain('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>

                    <div class="thb-right">
                        <span class="thb-branches-badge">
                            <i class="fas fa-eye"></i>
                            View Only
                        </span>
                    </div>
                </div>

                <!-- TABLE -->
                <div class="table-responsive-main" id="tableWrapperMain">
                    <table class="data-table-main" id="stockTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 120px;">Branch</th>
                                <th>Stock No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Provider(s)</th>
                                <th class="text-right">Float</th>
                                <th class="text-right">Cash</th>
                                <th class="text-right">Total</th>
                                <th>Status</th>
                                <th style="width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $global_counter = 1;
                            foreach ($flat_stocks as $item):
                                $s = $item['stock'];
                                $is_new_branch = $item['is_new_branch'];

                                $status_info = $status_labels[$s['status']] ?? ['label' => $s['status'], 'icon' => 'fa-circle', 'color' => 'gray'];
                                $total = floatval($s['cumm_total'] ?? 0) + floatval($s['cash_balance'] ?? 0);

                                $search_data = strtolower(
                                    $s['stock_number'] . ' ' .
                                    ($s['employee_name'] ?? '') . ' ' .
                                    ($s['branch_name'] ?? '') . ' ' .
                                    ($s['branch_code'] ?? '') . ' ' .
                                    ($s['daily_report_number'] ?? '') . ' ' .
                                    $status_info['label']
                                );
                            ?>
                                <?php if ($is_new_branch && $global_counter > 1): ?>
                                    <tr class="branch-separator-row">
                                        <td colspan="11">
                                            <div class="branch-separator-line"></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <tr class="stock-row"
                                    data-branch-id="<?php echo $s['branch_id']; ?>"
                                    data-search="<?php echo htmlspecialchars($search_data); ?>">
                                    <td class="row-number"><?php echo $global_counter++; ?></td>
                                    <td>
                                        <span class="branch-cell-badge">
                                            <i class="fas fa-store-alt"></i>
                                            <?php echo htmlspecialchars($s['branch_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view_employee.php?id=<?php echo $s['id']; ?>" class="stock-link">
                                            <?php echo htmlspecialchars($s['stock_number']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="date-display">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('d M Y', strtotime($s['stock_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-display">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="providers-count">
                                            <i class="fas fa-university"></i>
                                            <?php echo intval($s['providers_count'] ?? 0); ?> Providers
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-display">
                                            <?php echo formatCurrency($s['cumm_total']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-display">
                                            <?php echo formatCurrency($s['cash_balance']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-total-display">
                                            <?php echo formatCurrency($total); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo $status_info['color']; ?>">
                                            <i class="fas <?php echo $status_info['icon']; ?>"></i>
                                            <?php echo $status_info['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view_employee.php?id=<?php echo $s['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="no-results-main" id="noResultsMain" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No records found</h3>
                    <p>No evening stocks match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearGlobalSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-moon"></i>
                <h3>No Evening Stocks</h3>
                <p>No evening stock reports found for the selected period.</p>
                <a href="add_employee.php?branch=<?php echo $selected_branch; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add First Evening Stock
                </a>
            </div>
        <?php endif; ?>

    </div><!-- /.main-content -->

    <?php include_once '../../includes/employee_footer.php'; ?>

</div><!-- /.main-wrapper -->

<style>
/* ============================================================
   CSS VARIABLES — BLUE THEME
   ============================================================ */
:root {
    --es-bg: #F3F4F6;
    --es-text: #1F2937;
    --es-text-secondary: #6B7280;
    --es-text-light: #9CA3AF;
    --es-border: #E5E7EB;
    --es-card-bg: #FFFFFF;
    --es-input-bg: #F9FAFB;
    --es-hover: #F3F4F6;
    --es-shadow: rgba(0,0,0,0.06);
    --es-shadow-md: rgba(0,0,0,0.1);

    /* Blue theme colors */
    --es-blue-900: #1E3A8A;
    --es-blue-700: #1D4ED8;
    --es-blue-600: #2563EB;
    --es-blue-500: #3B82F6;
    --es-blue-100: #DBEAFE;

    --sidebar-width: 220px;
    --topbar-height: 70px;
}

html.dark-mode {
    --es-bg: #0F172A;
    --es-text: #F9FAFB;
    --es-text-secondary: #9CA3AF;
    --es-text-light: #6B7280;
    --es-border: #334155;
    --es-card-bg: #1E293B;
    --es-input-bg: #334155;
    --es-hover: #334155;
    --es-shadow: rgba(0,0,0,0.3);
    --es-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }

html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--es-bg) !important;
    color: var(--es-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    padding-top: 0 !important;
    margin: 0 !important;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ============================================================
   MAIN WRAPPER
   ============================================================ */
.main-wrapper {
    background: var(--es-bg) !important;
    margin-left: var(--sidebar-width) !important;
    padding-top: var(--topbar-height);
    width: calc(100% - var(--sidebar-width)) !important;
    max-width: calc(100% - var(--sidebar-width)) !important;
    min-height: 100vh;
    overflow-x: hidden !important;
    display: flex;
    flex-direction: column;
}

.main-content {
    background: var(--es-bg) !important;
    padding: 16px 20px !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    flex: 1;
}

/* ============================================================
   FOOTER
   ============================================================ */
.employee-footer {
    margin-left: 0 !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    background: #ffffff !important;
    border-top: 1px solid var(--es-border) !important;
    padding: 10px 20px !important;
    transition: background 0.3s ease, border-color 0.3s ease;
}
html.dark-mode .employee-footer {
    background: #1e293b !important;
    border-color: #334155 !important;
}
.employee-footer .footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #6b7280;
    flex-wrap: wrap;
    gap: 8px;
}
html.dark-mode .employee-footer .footer-content { color: #94a3b8; }
.employee-footer .footer-version { font-weight: 600; color: #bb0404; }

/* ============================================================
   BRANCH FILTER CARD — BLUE
   ============================================================ */
.branch-filter-card {
    border-radius: 12px;
    padding: 16px 22px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    box-shadow: 0 4px 16px rgba(37, 99, 235, 0.25);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-filter-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    pointer-events: none;
}
.branch-filter-card.filter-active {
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
}
.branch-filter-card.filter-all {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
}
.filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.filter-left > i { font-size: 20px; opacity: 0.9; color: #FCD34D; }
.filter-label { font-weight: 500; opacity: 0.8; }
.filter-name { font-weight: 800; font-size: 17px; }
.filter-code {
    font-size: 12px;
    font-weight: 700;
    opacity: 0.9;
    padding: 3px 12px;
    background: rgba(255,255,255,0.18);
    border-radius: 10px;
    font-family: 'Courier New', monospace;
}
.filter-view-only {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    color: #FFFFFF;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.filter-right { display: flex; gap: 8px; flex-wrap: wrap; position: relative; z-index: 1; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--es-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--es-text-secondary);
    margin: 4px 0 0 0;
}
.page-header .header-left .text-muted strong {
    color: var(--es-blue-600);
    font-weight: 800;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    font-size: 13px;
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   STATS WRAPPER — BLUE THEME
   ============================================================ */
.stats-wrapper {
    background: linear-gradient(135deg, #2563EB 0%, #1E40AF 100%);
    border-radius: 16px;
    padding: 0;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(37, 99, 235, 0.35);
    position: relative;
}
.stats-wrapper::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    pointer-events: none;
}
.stats-header {
    padding: 18px 24px;
    background: rgba(255,255,255,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.sh-left { display: flex; align-items: center; gap: 14px; }
.sh-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.sh-info { display: flex; flex-direction: column; gap: 3px; }
.sh-title {
    font-size: 16px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.sh-subtitle {
    font-size: 12px;
    font-weight: 500;
    color: rgba(255,255,255,0.75);
}
.sh-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
    padding: 20px 24px;
    position: relative;
    z-index: 1;
}

.stat-card {
    background: rgba(255,255,255,0.1);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1.5px solid rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.stat-card:hover {
    background: rgba(255,255,255,0.18);
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.card-total::before { background: #93C5FD; }
.card-waiting::before { background: #FCD34D; }
.card-approved::before { background: #86EFAC; }
.card-float::before { background: #60A5FA; }
.card-cash::before { background: #5EEAD4; }
.card-grand::before { background: #67E8F9; }

.sc-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
/* ⭐ Blue theme icons */
.sc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); }
.sc-icon-sky  { background: linear-gradient(135deg, #0EA5E9, #0284C7); }
.sc-icon-cyan { background: linear-gradient(135deg, #06B6D4, #0891B2); }
.sc-icon-orange { background: linear-gradient(135deg, #F59E0B, #D97706); }
.sc-icon-green  { background: linear-gradient(135deg, #10B981, #059669); }
.sc-icon-teal   { background: linear-gradient(135deg, #14B8A6, #0D9488); }

.sc-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    flex: 1;
}
.sc-label {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sc-value {
    font-size: 16px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #FFFFFF;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
.sc-sub {
    font-size: 9px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ============================================================
   FILTERS BAR
   ============================================================ */
.filters-bar {
    background: var(--es-card-bg);
    padding: 16px 20px;
    border-radius: 10px;
    border: 1px solid var(--es-border);
    margin-bottom: 20px;
    box-shadow: 0 1px 3px var(--es-shadow);
}
.filters-form {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label {
    font-size: 11px;
    font-weight: 600;
    color: var(--es-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-group.filter-buttons { flex-direction: row; gap: 8px; }

.form-control {
    padding: 8px 12px;
    border: 1.5px solid var(--es-border);
    border-radius: 6px;
    font-size: 13px;
    color: var(--es-text);
    background: var(--es-input-bg);
    transition: all 0.3s ease;
    min-width: 150px;
    font-family: 'Inter', sans-serif;
}
.form-control:focus {
    outline: none;
    border-color: var(--es-blue-600);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 8px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-primary { background: #2563EB; color: white; }
.btn-primary:hover { background: #1D4ED8; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.3); color: white; }
.btn-add {
    background: #FFFFFF;
    color: #2563EB;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.btn-add:hover {
    background: #FCD34D;
    color: #78350F;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(252, 211, 77, 0.45);
}
.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; transform: translateY(-1px); color: white; }
.btn-filter { background: #2563EB; color: white; }
.btn-filter:hover { background: #1D4ED8; color: white; }
.btn-reset {
    background: var(--es-hover);
    color: var(--es-text-secondary);
    border: 1px solid var(--es-border);
}
.btn-reset:hover { background: var(--es-border); color: var(--es-text); }
.btn-secondary {
    background: var(--es-hover);
    color: var(--es-text-secondary);
    border: 1px solid var(--es-border);
}
.btn-secondary:hover { background: var(--es-border); color: var(--es-text); }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container-main {
    background: var(--es-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--es-border);
    overflow: hidden;
    box-shadow: 0 4px 16px var(--es-shadow);
    width: 100%;
    max-width: 100%;
}

/* ⭐ Blue table header (badala ya purple) */
.table-header-blue {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.table-header-blue::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.thb-left {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.thb-left > i {
    font-size: 20px;
    color: #FCD34D;
    background: rgba(255, 255, 255, 0.15);
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
}
.thb-left h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.thb-count {
    font-size: 11px;
    font-weight: 800;
    color: #FCD34D;
    padding: 4px 14px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    white-space: nowrap;
}

.thb-center {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.scroll-btn-header {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #2563EB;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 800;
    transition: all 0.25s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn-header:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn-header:active {
    transform: translateY(0);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}
.scroll-btn-header i {
    font-size: 15px;
    display: block;
    line-height: 1;
}

.search-wrapper-main {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    padding: 7px 14px;
    width: 300px;
    max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.search-wrapper-main:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.search-wrapper-main > i {
    color: #2563EB;
    font-size: 13px;
    flex-shrink: 0;
}
.search-wrapper-main input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 12px;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-family: 'Inter', sans-serif;
}
.search-wrapper-main input::placeholder {
    color: #9CA3AF;
    font-size: 11px;
}
.search-wrapper-main button {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #DBEAFE;
    color: #2563EB;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.search-wrapper-main button:hover {
    background: #2563EB;
    color: #FFFFFF;
}
.search-count-main {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 10px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    flex-shrink: 0;
    white-space: nowrap;
}

.thb-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    position: relative;
    z-index: 1;
}
.thb-branches-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 7px 16px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
}
.thb-branches-badge i {
    font-size: 11px;
    color: #FCD34D;
}

.table-responsive-main {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive-main::-webkit-scrollbar { height: 8px; }
.table-responsive-main::-webkit-scrollbar-track {
    background: var(--es-hover);
    border-radius: 4px;
}
.table-responsive-main::-webkit-scrollbar-thumb {
    background: #2563EB;
    border-radius: 4px;
}
.table-responsive-main::-webkit-scrollbar-thumb:hover { background: #1D4ED8; }

.data-table-main {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1200px;
}
.data-table-main thead {
    background: #2563EB;
    position: sticky;
    top: 0;
    z-index: 5;
}
.data-table-main thead th {
    padding: 13px 16px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    border-bottom: 2px solid #1E40AF;
    white-space: nowrap;
}
.data-table-main thead th.text-right { text-align: right; }
.data-table-main tbody tr {
    border-bottom: 1px solid var(--es-border);
    transition: background 0.2s ease;
}
.data-table-main tbody tr:hover { background: var(--es-hover); }
.data-table-main tbody td {
    padding: 13px 16px;
    color: var(--es-text);
    vertical-align: middle;
}
.data-table-main tbody td.text-right { text-align: right; }

/* ============================================================
   BRANCH SEPARATOR
   ============================================================ */
.branch-separator-row {
    background: transparent !important;
    border: none !important;
    height: 0;
}
.branch-separator-row td {
    padding: 0 !important;
    border: none !important;
    height: 0;
    background: transparent !important;
}
.branch-separator-line {
    height: 5px;
    background: linear-gradient(90deg, #2563EB 0%, #1D4ED8 50%, #2563EB 100%);
    box-shadow: 0 2px 12px rgba(37, 99, 235, 0.5);
    border-radius: 3px;
    margin: 10px 0;
    position: relative;
}
.branch-separator-line::before {
    content: '';
    position: absolute;
    top: -3px; left: 0; right: 0;
    height: 1px;
    background: rgba(37, 99, 235, 0.4);
}
.branch-separator-line::after {
    content: '';
    position: absolute;
    bottom: -3px; left: 0; right: 0;
    height: 1px;
    background: rgba(37, 99, 235, 0.4);
}

.stock-row.hidden-by-search { display: none !important; }
.branch-separator-row.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--es-hover);
    font-size: 12px;
    font-weight: 800;
    color: var(--es-text);
    border: 1.5px solid var(--es-border);
    font-family: 'Inter', monospace;
}

.branch-cell-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E40AF;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
}
.branch-cell-badge i {
    color: #2563EB;
    font-size: 10px;
}
html.dark-mode .branch-cell-badge {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD;
    border-color: #3B82F6;
}
html.dark-mode .branch-cell-badge i { color: #60A5FA; }

.stock-link {
    font-weight: 700;
    color: #2563EB;
    text-decoration: none;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    transition: color 0.2s ease;
    white-space: nowrap;
}
.stock-link:hover { color: #1D4ED8; text-decoration: underline; }
html.dark-mode .stock-link { color: #60A5FA; }

.date-display {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: var(--es-text-secondary);
    white-space: nowrap;
}
.date-display i {
    color: #2563EB;
    font-size: 11px;
}

.employee-display {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: var(--es-text-secondary);
    white-space: nowrap;
}
.employee-display i {
    color: #2563EB;
    font-size: 11px;
}

.providers-count {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E40AF;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
.providers-count i {
    color: #2563EB;
    font-size: 10px;
}
html.dark-mode .providers-count {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD;
    border-color: #3B82F6;
}

.amount-display {
    font-size: 13px;
    font-weight: 800;
    white-space: nowrap;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--es-text);
    letter-spacing: -0.2px;
}
.amount-total-display {
    font-size: 14px;
    font-weight: 900;
    white-space: nowrap;
    font-family: 'Inter', 'Courier New', monospace;
    color: #2563EB;
    letter-spacing: -0.2px;
}
html.dark-mode .amount-total-display { color: #60A5FA; }

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}
.type-badge.type-blue { background: #DBEAFE; color: #1D4ED8; }
.type-badge.type-green { background: #D1FAE5; color: #065F46; }
.type-badge.type-purple { background: #EDE9FE; color: #6D28D9; }
.type-badge.type-red { background: #FEE2E2; color: #991B1B; }
.type-badge.type-orange { background: #FEF3C7; color: #92400E; }
.type-badge.type-gray { background: #F3F4F6; color: #6B7280; }
html.dark-mode .type-badge.type-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .type-badge.type-green { background: #065F46; color: #34D399; }
html.dark-mode .type-badge.type-purple { background: #2D1B5F; color: #A78BFA; }
html.dark-mode .type-badge.type-red { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .type-badge.type-orange { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .type-badge.type-gray { background: #374151; color: #9CA3AF; }

.action-buttons {
    display: flex;
    gap: 5px;
    justify-content: center;
}
.btn-action {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 13px;
}
.btn-view {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.btn-view:hover {
    background: linear-gradient(135deg, #1D4ED8, #2563EB);
    color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.4);
}
html.dark-mode .btn-view { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }

.no-results-main {
    text-align: center;
    padding: 60px 20px;
    background: var(--es-hover);
}
.no-results-main i {
    font-size: 56px;
    color: var(--es-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.no-results-main h3 {
    font-size: 18px;
    color: var(--es-text);
    margin: 0 0 8px 0;
}
.no-results-main p {
    font-size: 14px;
    color: var(--es-text-secondary);
    margin: 0 0 20px 0;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: var(--es-card-bg);
    border-radius: 12px;
    border: 1px solid var(--es-border);
}
.empty-state i {
    font-size: 60px;
    color: var(--es-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 20px;
    color: var(--es-text);
    margin: 0 0 8px 0;
}
.empty-state p {
    color: var(--es-text-secondary);
    font-size: 14px;
    margin: 0 0 20px 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .stats-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1024px) {
    .main-content { padding: 16px 18px !important; }
    .stats-grid { grid-template-columns: repeat(3, 1fr); }
    .sc-value { font-size: 14px; }
    .table-header-blue {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .thb-left, .thb-center, .thb-right {
        justify-content: center;
        width: 100%;
    }
    .search-wrapper-main { width: 100%; }
    .scroll-btn-header { width: 40px; height: 40px; }
}
@media (max-width: 768px) {
    .main-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-top: 62px;
        min-height: 100vh;
    }
    .main-content { padding: 12px !important; }

    .employee-footer {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 10px 14px !important;
    }
    .employee-footer .footer-content {
        font-size: 11px;
        flex-direction: column;
        text-align: center;
        gap: 4px;
    }

    .branch-filter-card { flex-direction: column; align-items: flex-start; padding: 14px 18px; }
    .filter-right { width: 100%; }
    .filter-right .btn { flex: 1; justify-content: center; }

    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .stats-header { flex-direction: column; align-items: flex-start; }
    .sh-badge { align-self: flex-start; }

    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .filter-group.filter-buttons { flex-direction: column; }
    .filter-group.filter-buttons .btn { width: 100%; justify-content: center; }

    .table-header-blue { padding: 14px 16px; }
    .thb-left h3 { font-size: 14px; }
    .scroll-btn-header { width: 38px; height: 38px; font-size: 14px; }
    .scroll-btn-header i { font-size: 13px; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 58px; }
    .main-content { padding: 12px !important; }
    .employee-footer { padding: 8px 12px !important; }
    .employee-footer .footer-content { font-size: 10px; }

    .stats-grid { grid-template-columns: 1fr; }
    .sc-value { font-size: 16px; }
    .stat-card { padding: 14px 16px; }
    .sc-icon { width: 40px; height: 40px; font-size: 16px; }

    .data-table-main thead th,
    .data-table-main tbody td { padding: 10px 12px; font-size: 12px; }
    .branch-cell-badge { font-size: 10px; padding: 4px 8px; }
    .amount-display { font-size: 11px; }
    .amount-total-display { font-size: 12px; }

    .thb-center { gap: 8px; }
    .scroll-btn-header { width: 34px; height: 34px; font-size: 13px; }
    .scroll-btn-header i { font-size: 12px; }
}
</style>

<script>
// ============================================================
// SCROLL TABLE MAIN (Horizontal)
// ============================================================
function scrollTableMain(direction) {
    const wrapper = document.getElementById('tableWrapperMain');
    if (!wrapper) return;
    const scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// GLOBAL SEARCH
// ============================================================
function onGlobalSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.stock-row');
    const separatorRows = document.querySelectorAll('.branch-separator-row');
    const clearBtn = document.getElementById('globalSearchClear');
    const countBadge = document.getElementById('globalSearchCount');
    const noResults = document.getElementById('noResultsMain');

    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';

    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        separatorRows.forEach(row => row.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';

        let idx = 1;
        rows.forEach(row => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx++;
        });
        return;
    }

    let matchCount = 0;
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });

    separatorRows.forEach(row => row.classList.add('hidden-by-search'));

    let visibleIdx = 1;
    rows.forEach(row => {
        if (!row.classList.contains('hidden-by-search')) {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = visibleIdx++;
        }
    });

    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }

    if (noResults) {
        noResults.style.display = matchCount === 0 ? 'block' : 'none';
    }
}

function clearGlobalSearch() {
    const input = document.getElementById('globalSearchInput');
    if (input) {
        input.value = '';
        onGlobalSearch(input);
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const input = document.getElementById('globalSearchInput');
        if (input) { input.focus(); input.select(); }
    }
    if (e.key === 'Escape') {
        const input = document.getElementById('globalSearchInput');
        if (input && input.value.length > 0 && document.activeElement === input) {
            clearGlobalSearch();
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'ArrowLeft') {
        e.preventDefault();
        scrollTableMain('left');
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'ArrowRight') {
        e.preventDefault();
        scrollTableMain('right');
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

    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() { if (successAlert.parentElement) successAlert.remove(); }, 400);
        }, 5000);
    }

    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.transition = 'opacity 0.4s ease';
            errorAlert.style.opacity = '0';
            setTimeout(function() { if (errorAlert.parentElement) errorAlert.remove(); }, 400);
        }, 10000);
    }
});
</script>

</body>
</html>