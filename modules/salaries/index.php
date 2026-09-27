<?php
// ================================================================
// FILE: modules/salaries/index.php
// WAKALA FINANCIAL SYSTEM - SALARIES ADMIN INDEX
// ✅ Monthly summary table + summary cards + bulk pay
// ✅ NEW: Export dropdown (PDF, CSV, Excel, Print)
// ✅ NEW: Delete month records action
// BLUE THEME
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/salary_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// ✅ HANDLE DELETE MONTH ACTION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_month') {
    try {
        $delete_month = trim($_POST['month_key'] ?? '');
        $delete_branch = intval($_POST['branch_id'] ?? 0);
        
        if (empty($delete_month)) {
            throw new Exception('Invalid month.');
        }
        
        // Validate month format (YYYY-MM)
        if (!preg_match('/^\d{4}-\d{2}$/', $delete_month)) {
            throw new Exception('Invalid month format.');
        }
        
        $month_start = $delete_month . '-01';
        
        // Build query
        $sql = "DELETE FROM employee_salaries 
                WHERE DATE_FORMAT(salary_month, '%Y-%m') = ?";
        $params = [$delete_month];
        
        if ($delete_branch > 0) {
            $sql .= " AND branch_id = ?";
            $params[] = $delete_branch;
        }
        
        // Count first
        $count_sql = "SELECT COUNT(*) FROM employee_salaries 
                      WHERE DATE_FORMAT(salary_month, '%Y-%m') = ?";
        $count_params = [$delete_month];
        if ($delete_branch > 0) {
            $count_sql .= " AND branch_id = ?";
            $count_params[] = $delete_branch;
        }
        $stmt = $db->prepare($count_sql);
        $stmt->execute($count_params);
        $delete_count = intval($stmt->fetchColumn());
        
        if ($delete_count === 0) {
            throw new Exception('No records found for this month.');
        }
        
        // Execute delete
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Log activity
        logActivity(
            $user_id,
            'Delete Month Salaries',
            'Salaries',
            0,
            '',
            'Deleted ' . $delete_count . ' salary records for ' . date('F Y', strtotime($month_start))
        );
        
        $_SESSION['success_message'] = 'Successfully deleted ' . $delete_count . ' salary record(s) for ' . date('F Y', strtotime($month_start)) . '.';
        header('Location: index.php' . ($delete_branch > 0 ? '?branch_id=' . $delete_branch : ''));
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error deleting: ' . $e->getMessage();
        header('Location: index.php' . ($delete_branch > 0 ? '?branch_id=' . $delete_branch : ''));
        exit();
    }
}

// ============================================================
// AUTO-GENERATE SALARIES (INVISIBLE)
// ============================================================
checkAndGenerateSalaries($db);

// ============================================================
// FILTERS
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}

// Get branches
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Branch info
$branch_name = 'All Branches';
$branch_code = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
    }
}

// ============================================================
// GET DATA
// ============================================================
$summary_cards = getSalarySummaryCards($db, $selected_branch);
$monthly_summary = getMonthlySalarySummary($db, $selected_branch, 36);

// Total active employees
if ($selected_branch > 0) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM employees 
        WHERE is_active = 1 AND employment_status = 'active'
        AND base_salary > 0 AND branch_id = ?
    ");
    $stmt->execute([$selected_branch]);
} else {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM employees 
        WHERE is_active = 1 AND employment_status = 'active'
        AND base_salary > 0
    ");
    $stmt->execute();
}
$total_active_employees = intval($stmt->fetchColumn());

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
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Salaries Module</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
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
                <h2><i class="fas fa-money-check-alt" style="color:#1E40AF;"></i> Salary Management</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Auto-generated salaries — <?php echo $total_active_employees; ?> active employees
                </p>
            </div>
            <div class="header-right">
                <a href="settings.php" class="btn btn-settings">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="generate.php" class="btn btn-generate-manual">
                    <i class="fas fa-sync-alt"></i> Generate Now
                </a>
                
                <!-- ✅ EXPORT DROPDOWN -->
                <div class="dropdown export-dropdown" id="exportDropdown">
                    <button type="button" class="btn btn-export dropdown-toggle" onclick="toggleExportDropdown(event)">
                        <i class="fas fa-file-export"></i>
                        <span>Export</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    <div class="dropdown-menu">
                        <a href="#" onclick="exportData('pdf'); return false;">
                            <i class="fas fa-file-pdf" style="color:#DC2626;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as PDF</span>
                                <span class="dropdown-item-desc">Print-ready document</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('csv'); return false;">
                            <i class="fas fa-file-csv" style="color:#059669;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as CSV</span>
                                <span class="dropdown-item-desc">Excel compatible</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('excel'); return false;">
                            <i class="fas fa-file-excel" style="color:#10B981;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as Excel</span>
                                <span class="dropdown-item-desc">Spreadsheet format</span>
                            </div>
                        </a>
                        <a href="#" onclick="window.print(); return false;">
                            <i class="fas fa-print" style="color:#6B7280;"></i>
                            <div>
                                <span class="dropdown-item-title">Print</span>
                                <span class="dropdown-item-desc">Print current view</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
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

        <!-- INFO BANNER -->
        <div class="info-banner">
            <div class="info-banner-icon">
                <i class="fas fa-magic"></i>
            </div>
            <div class="info-banner-content">
                <h4>Auto-Generation Active</h4>
                <p>
                    <strong>Day 21-27:</strong> UPCOMING salaries • 
                    <strong>Day 28-20:</strong> WAITING salaries (ready to pay)
                </p>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards-soft">
            <div class="summary-card-soft summary-card-soft-green">
                <div class="summary-icon-soft">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Paid</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($summary_cards['total_paid']); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary_cards['paid_count']); ?> salaries
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-orange">
                <div class="summary-icon-soft">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Waiting (Ready to Pay)</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($summary_cards['total_waiting']); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary_cards['waiting_count']); ?> salaries
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-blue">
                <div class="summary-icon-soft">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Upcoming</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($summary_cards['total_upcoming']); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($summary_cards['upcoming_count']); ?> salaries
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-cyan">
                <div class="summary-icon-soft">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Active Employees</span>
                    <span class="summary-value-soft"><?php echo number_format($total_active_employees); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-store"></i>
                        <?php echo $selected_branch > 0 ? 'In this branch' : 'All branches'; ?>
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-store-alt"></i> Filter by Branch</label>
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_branch > 0): ?>
                    <a href="index.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- MONTHLY TABLE -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-header-left">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>Monthly Salary Summary</h3>
                    <span class="count-badge"><?php echo count($monthly_summary); ?></span>
                </div>
            </div>
            
            <?php if (count($monthly_summary) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Month</th>
                                <th class="text-center">Employees</th>
                                <th class="text-right">Total Amount</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Waiting</th>
                                <th class="text-right">Upcoming</th>
                                <th class="text-center">Status</th>
                                <th style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($monthly_summary as $m): 
                                if ($m['upcoming_count'] > 0) {
                                    $overall_status = 'upcoming';
                                    $status_label = 'Upcoming';
                                    $status_icon = 'fa-hourglass-half';
                                } elseif ($m['waiting_count'] > 0) {
                                    $overall_status = 'waiting';
                                    $status_label = 'Waiting';
                                    $status_icon = 'fa-clock';
                                } elseif ($m['paid_count'] > 0 && $m['cancelled_count'] == 0) {
                                    $overall_status = 'paid';
                                    $status_label = 'Paid';
                                    $status_icon = 'fa-check-circle';
                                } else {
                                    $overall_status = 'mixed';
                                    $status_label = 'Mixed';
                                    $status_icon = 'fa-random';
                                }
                            ?>
                                <tr>
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <div class="month-cell">
                                            <span class="month-name"><?php echo htmlspecialchars($m['month_label']); ?></span>
                                            <span class="month-date"><?php echo date('F Y', strtotime($m['month_date'])); ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-count"><?php echo number_format($m['total_employees']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-total"><?php echo formatCurrency($m['total_amount']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($m['paid_amount'] > 0): ?>
                                            <span class="amount-paid">
                                                <?php echo formatCurrency($m['paid_amount']); ?>
                                            </span>
                                            <span class="count-sub"><?php echo $m['paid_count']; ?> emp</span>
                                        <?php else: ?>
                                            <span class="amount-zero">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($m['waiting_amount'] > 0): ?>
                                            <span class="amount-waiting">
                                                <?php echo formatCurrency($m['waiting_amount']); ?>
                                            </span>
                                            <span class="count-sub"><?php echo $m['waiting_count']; ?> emp</span>
                                        <?php else: ?>
                                            <span class="amount-zero">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($m['upcoming_amount'] > 0): ?>
                                            <span class="amount-upcoming">
                                                <?php echo formatCurrency($m['upcoming_amount']); ?>
                                            </span>
                                            <span class="count-sub"><?php echo $m['upcoming_count']; ?> emp</span>
                                        <?php else: ?>
                                            <span class="amount-zero">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge status-<?php echo $overall_status; ?>">
                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                            <?php echo $status_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="view_month.php?month=<?php echo $m['month_key']; ?>&branch_id=<?php echo $selected_branch; ?>" 
                                               class="btn-action btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </a>
                                            
                                            <!-- ✅ DELETE BUTTON -->
                                            <button type="button" 
                                                    class="btn-action btn-delete" 
                                                    title="Delete all records for this month"
                                                    onclick="confirmDeleteMonth(
                                                        '<?php echo htmlspecialchars($m['month_key']); ?>',
                                                        '<?php echo addslashes($m['month_label']); ?>',
                                                        <?php echo intval($m['total_employees']); ?>,
                                                        <?php echo $selected_branch; ?>
                                                    )">
                                                <i class="fas fa-trash"></i>
                                                <span>Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-money-check-alt"></i>
                    <h3>No Salaries Yet</h3>
                    <p>
                        Salaries will be auto-generated on Day 21-27 (upcoming) 
                        and Day 28+ (waiting).
                    </p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 12px;">
                        <i class="fas fa-info-circle"></i>
                        Today is <?php echo date('d M Y'); ?> — Day <?php echo date('d'); ?>
                    </p>
                    <a href="generate.php" class="btn btn-primary" style="margin-top: 16px;">
                        <i class="fas fa-magic"></i> Generate Salaries Now (Manual)
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ✅ HIDDEN DELETE FORM -->
<form method="POST" action="" id="deleteMonthForm" style="display:none;">
    <input type="hidden" name="action" value="delete_month">
    <input type="hidden" name="month_key" id="deleteMonthKey">
    <input type="hidden" name="branch_id" id="deleteBranchId">
</form>

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
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH INDICATOR - BLUE */
.branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%; width: 300px; height: 300px;
    background: rgba(255,255,255,0.08); border-radius: 50%;
    pointer-events: none;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1; position: relative; z-index: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px; background: rgba(255, 255, 255, 0.2);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-indicator-name { font-weight: 800; font-size: 16px; }
.branch-indicator-code {
    font-size: 11px; font-weight: 700; padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2); border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: 'Courier New', monospace;
}
.branch-indicator-right { position: relative; z-index: 1; }
.date-display {
    font-size: 13px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center; gap: 6px; font-weight: 600;
}

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary); }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

.btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-settings {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-settings:hover { background: var(--blue-lightest); color: var(--blue-primary); border-color: var(--blue-light); transform: translateY(-2px); }
.btn-generate-manual {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.btn-generate-manual:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
    color: #FFFFFF;
}
.btn-primary {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    color: #FFFFFF; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.btn-primary:hover { transform: translateY(-2px); color: #FFFFFF; }
.btn-reset {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color); padding: 10px 18px;
}
.btn-reset:hover { background: var(--bg-card); color: var(--text-primary); }

/* ✅ EXPORT BUTTON + DROPDOWN */
.btn-export {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}
.btn-export:hover {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
}
.dropdown {
    position: relative;
    display: inline-block;
}
.dropdown-arrow {
    font-size: 11px;
    transition: transform 0.3s ease;
    margin-left: 2px;
}
.dropdown.open .dropdown-arrow {
    transform: rotate(180deg);
}
.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    min-width: 260px;
    background: var(--bg-card);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    z-index: 1000;
    animation: dropdownFadeIn 0.2s ease forwards;
}
.dropdown.open .dropdown-menu { display: block; }
@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}
.dropdown-menu a:last-child { border-bottom: none; }
.dropdown-menu a:hover {
    background: var(--bg-table-hover);
    padding-left: 22px;
}
.dropdown-menu a::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    width: 0; height: 100%;
    background: linear-gradient(90deg, #7C3AED, #6D28D9);
    transition: width 0.2s ease;
}
.dropdown-menu a:hover::before { width: 4px; }
.dropdown-menu a > i {
    font-size: 24px;
    width: 30px;
    text-align: center;
    flex-shrink: 0;
}
.dropdown-menu a > div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}
.dropdown-item-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
}
.dropdown-item-desc {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-muted);
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; opacity: 0.6;
}
.alert-close:hover { opacity: 1; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* INFO BANNER - BLUE */
.info-banner {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #93c5fd; border-radius: 12px;
    padding: 16px 20px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 14px;
}
html.dark-mode .info-banner {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.info-banner-icon {
    width: 50px; height: 50px; border-radius: 50%;
    background: rgba(30, 64, 175, 0.15);
    color: #1e40af; display: flex; align-items: center;
    justify-content: center; font-size: 22px; flex-shrink: 0;
    border: 2px solid rgba(30, 64, 175, 0.3);
}
html.dark-mode .info-banner-icon { background: rgba(96, 165, 250, 0.25); color: #93c5fd; }
.info-banner-content h4 {
    font-size: 14px; font-weight: 800; margin: 0 0 3px 0;
    color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;
}
html.dark-mode .info-banner-content h4 { color: #93c5fd; }
.info-banner-content p { font-size: 12px; margin: 0; color: var(--text-muted); line-height: 1.5; }
.info-banner-content strong { color: #1e40af; }
html.dark-mode .info-banner-content strong { color: #60a5fa; }

/* SUMMARY CARDS */
.summary-cards-soft {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.summary-card-soft {
    position: relative; border-radius: 14px; padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0; overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.summary-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}
.summary-card-soft-green {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.summary-card-soft-green .summary-icon-soft {
    background: rgba(5, 150, 105, 0.15); color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.summary-card-soft-green .summary-value-soft { color: #047857; }
.summary-card-soft-orange {
    background: rgba(217, 119, 6, 0.08);
    border-color: rgba(217, 119, 6, 0.2);
}
.summary-card-soft-orange .summary-icon-soft {
    background: rgba(217, 119, 6, 0.15); color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.summary-card-soft-orange .summary-value-soft { color: #B45309; }
.summary-card-soft-blue {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.summary-card-soft-blue .summary-icon-soft {
    background: rgba(37, 99, 235, 0.15); color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.summary-card-soft-blue .summary-value-soft { color: #1D4ED8; }
.summary-card-soft-cyan {
    background: rgba(8, 145, 178, 0.08);
    border-color: rgba(8, 145, 178, 0.2);
}
.summary-card-soft-cyan .summary-icon-soft {
    background: rgba(8, 145, 178, 0.15); color: #0891b2;
    border: 1.5px solid rgba(8, 145, 178, 0.3);
}
.summary-card-soft-cyan .summary-value-soft { color: #0e7490; }
html.dark-mode .summary-card-soft-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-card-soft-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .summary-card-soft-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-card-soft-cyan { background: rgba(8, 145, 178, 0.15); border-color: rgba(8, 145, 178, 0.3); }
html.dark-mode .summary-card-soft-green .summary-value-soft { color: #34D399; }
html.dark-mode .summary-card-soft-orange .summary-value-soft { color: #FBBF24; }
html.dark-mode .summary-card-soft-blue .summary-value-soft { color: #60A5FA; }
html.dark-mode .summary-card-soft-cyan .summary-value-soft { color: #67e8f9; }
.summary-icon-soft {
    width: 50px; height: 50px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    transition: all 0.3s ease;
}
.summary-card-soft:hover .summary-icon-soft { transform: scale(1.08) rotate(-4deg); }
.summary-info-soft {
    display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px;
}
.summary-label-soft {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.summary-value-soft {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2; word-break: break-word;
}
.summary-sub-soft {
    font-size: 10px; font-weight: 600; color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;
}
.summary-sub-soft i { font-size: 9px; color: var(--text-light); }
.summary-decoration-soft {
    position: absolute; top: -30px; right: -30px;
    width: 100px; height: 100px; border-radius: 50%;
    background: rgba(255, 255, 255, 0.15); pointer-events: none;
}

/* FILTER BAR */
.filter-bar {
    background: var(--bg-card); border-radius: 12px;
    padding: 14px 18px; margin-bottom: 16px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}
.filter-form {
    display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
}
.filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 250px; }
.filter-group label {
    font-size: 11px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 6px;
}
.filter-group label i { color: #1e40af; }
.form-control {
    padding: 10px 14px; border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 13px;
    color: var(--text-primary); background: var(--bg-input);
    transition: all 0.3s ease; font-family: 'Inter', sans-serif;
    width: 100%;
}
.form-control:focus {
    outline: none; border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}

/* TABLE */
.table-container {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color); overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.table-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    padding: 18px 24px; display: flex;
    justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 10px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.table-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%; width: 200px; height: 200px;
    background: rgba(255,255,255,0.08); border-radius: 50%;
    pointer-events: none;
}
.table-header-left { display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.table-header-left i {
    font-size: 22px; background: rgba(255,255,255,0.18);
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
}
.table-header h3 { font-size: 17px; font-weight: 800; margin: 0; }
.count-badge {
    background: rgba(255,255,255,0.22); padding: 5px 16px;
    border-radius: 12px; font-size: 12px; font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
}
.table-wrapper { overflow-x: auto; max-width: 100%; }
.data-table {
    width: 100%; border-collapse: collapse;
    font-size: 13px; min-width: 1100px;
}
.data-table thead tr { background: var(--bg-table-even); }
.data-table thead th {
    padding: 14px 16px; text-align: left; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase;
    font-size: 11px; letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.data-table thead th.text-center { text-align: center; }
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody td {
    padding: 14px 16px; color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody td.text-center { text-align: center; }
.data-table tbody td.text-right { text-align: right; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg-input); font-size: 12px; font-weight: 800;
    color: var(--text-secondary); border: 1.5px solid var(--border-color);
}
.month-cell { display: flex; flex-direction: column; gap: 2px; }
.month-name {
    font-size: 14px; font-weight: 800; color: var(--text-primary);
}
.month-date { font-size: 11px; color: var(--text-muted); font-weight: 600; }

.badge-count {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 4px 12px; background: #DBEAFE; color: #1D4ED8;
    border-radius: 8px; font-size: 12px; font-weight: 800;
    border: 1.5px solid #BFDBFE;
    font-family: 'Courier New', monospace;
}
html.dark-mode .badge-count { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.amount-total, .amount-paid, .amount-waiting, .amount-upcoming {
    display: block; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 13px;
}
.amount-total { color: var(--text-primary); }
.amount-paid { color: #059669; }
.amount-waiting { color: #D97706; }
.amount-upcoming { color: #2563EB; }
html.dark-mode .amount-paid { color: #34D399; }
html.dark-mode .amount-waiting { color: #FBBF24; }
html.dark-mode .amount-upcoming { color: #60A5FA; }
.amount-zero { color: var(--text-light); font-size: 14px; }
.count-sub {
    display: block; font-size: 10px; font-weight: 600;
    color: var(--text-muted); margin-top: 2px;
}

.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 14px; border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.status-paid { background: #D1FAE5; color: #065F46; border: 1.5px solid #6EE7B7; }
.status-waiting { background: #FEF3C7; color: #92400E; border: 1.5px solid #FCD34D; }
.status-upcoming { background: #DBEAFE; color: #1E40AF; border: 1.5px solid #93C5FD; }
.status-mixed { background: #E0E7FF; color: #3730A3; border: 1.5px solid #A5B4FC; }
html.dark-mode .status-paid { background: #065F46; color: #D1FAE5; }
html.dark-mode .status-waiting { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .status-upcoming { background: #1E3A5F; color: #93C5FD; }

.table-actions { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
.btn-action {
    padding: 6px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 5px;
    cursor: pointer; text-decoration: none;
    transition: all 0.2s ease; white-space: nowrap;
    border: 1px solid;
}
.btn-view {
    background: #DBEAFE; color: #1D4ED8;
    border-color: #BFDBFE;
}
.btn-view:hover {
    background: #1D4ED8; color: #FFFFFF;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
}

/* ✅ DELETE BUTTON */
.btn-delete {
    background: #FEE2E2;
    color: #991B1B;
    border-color: #FECACA;
}
.btn-delete:hover {
    background: #DC2626;
    color: #FFFFFF;
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}
html.dark-mode .btn-delete { 
    background: #7F1D1D; 
    color: #FCA5A5; 
    border-color: #991B1B;
}
html.dark-mode .btn-delete:hover {
    background: #DC2626;
    color: #FFFFFF;
}

.empty-state {
    text-align: center; padding: 60px 20px;
}
.empty-state i {
    font-size: 64px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 20px; color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p { color: var(--text-muted); font-size: 14px; margin: 0; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .summary-cards-soft { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn,
    .header-right .dropdown { flex: 1; }
    .header-right .dropdown .btn { width: 100%; justify-content: center; }
    .summary-cards-soft { grid-template-columns: 1fr; }
    .info-banner { flex-direction: column; text-align: center; }
    .filter-form { flex-direction: column; }
    .filter-group { min-width: 100%; }
    .table-wrapper { overflow-x: scroll; }
    .dropdown-menu { 
        position: fixed; 
        left: 16px; right: 16px; 
        width: auto; 
    }
}
@media (max-width: 480px) {
    .summary-value-soft { font-size: 16px; }
    .summary-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .btn-action span { display: none; }
    .btn-action { padding: 6px 10px; }
}

@media print {
    .branch-indicator, .page-header, .info-banner, .filter-bar, 
    .header-right, .table-actions, .dropdown { display: none !important; }
    .main-wrapper, .main-content { background: #FFF !important; padding: 0 !important; margin-left: 0 !important; }
}
</style>

<script>
// ============================================================
// ✅ EXPORT DROPDOWN
// ============================================================
function toggleExportDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('exportDropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
    }
});

function exportData(format) {
    const dropdown = document.getElementById('exportDropdown');
    if (dropdown) dropdown.classList.remove('open');
    
    const urlParams = new URLSearchParams(window.location.search);
    const branchId = urlParams.get('branch_id') || '<?php echo $selected_branch; ?>';
    
    const exportUrl = `export.php?format=${format}&branch_id=${branchId}`;
    
    const formatLabels = { 'pdf': 'PDF', 'csv': 'CSV', 'excel': 'Excel' };
    const label = formatLabels[format] || format.toUpperCase();
    
    const msg = `Export Salaries as ${label}?\n\n` +
                `Branch: <?php echo htmlspecialchars($branch_name); ?>\n` +
                `Months: <?php echo count($monthly_summary); ?>\n\n` +
                `Continue?`;
    
    if (confirm(msg)) {
        window.location.href = exportUrl;
    }
}

// ============================================================
// ✅ DELETE MONTH CONFIRMATION
// ============================================================
function confirmDeleteMonth(monthKey, monthLabel, recordCount, branchId) {
    // Level 1 confirmation
    const msg1 = 
        '⚠️ DELETE ALL RECORDS FOR ' + monthLabel.toUpperCase() + '?\n\n' +
        'This will DELETE ' + recordCount + ' salary record(s).\n\n' +
        '⚠️ WARNING: This action CANNOT be undone!\n\n' +
        'Continue?';
    
    if (!confirm(msg1)) return;
    
    // Level 2 confirmation (stronger)
    const msg2 = 
        '🚨 FINAL CONFIRMATION 🚨\n\n' +
        'Are you ABSOLUTELY SURE you want to delete ALL ' + recordCount + ' records\n' +
        'for ' + monthLabel + '?\n\n' +
        'Type OK to confirm.\n' +
        'This will PERMANENTLY delete these records.';
    
    const input = prompt(msg2);
    
    if (input === null) return; // cancelled
    
    if (input.trim().toUpperCase() !== 'OK') {
        alert('Deletion cancelled. You must type "OK" to confirm.');
        return;
    }
    
    // Submit the hidden form
    document.getElementById('deleteMonthKey').value = monthKey;
    document.getElementById('deleteBranchId').value = branchId;
    document.getElementById('deleteMonthForm').submit();
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

// Close dropdown on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('exportDropdown');
        if (dropdown && dropdown.classList.contains('open')) {
            dropdown.classList.remove('open');
        }
    }
});
</script>

</body>
</html>