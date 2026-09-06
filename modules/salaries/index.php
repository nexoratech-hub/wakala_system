<?php
// ================================================================
// FILE: modules/salaries/index.php
// WAKALA FINANCIAL SYSTEM - SALARIES LIST
// WITH BRANCH INDICATOR AND DARK MODE SUPPORT
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET USER'S BRANCH
// ============================================================
$selected_branch = isset($_SESSION['user_branch_id']) ? intval($_SESSION['user_branch_id']) : 0;
if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
        $_SESSION['user_branch_id'] = $selected_branch;
    }
}

// Get branch name
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

// Build branch filter for SQL
$branch_filter = '';
$branch_params = [];

if ($selected_branch > 0) {
    $branch_filter = " AND s.branch_id = ? ";
    $branch_params[] = $selected_branch;
}

// ============================================================
// GET SALARY SUMMARIES
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// TODAY SALARIES (Paid today)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE DATE(payment_date) = ? AND branch_id = ? AND status = 'paid'";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE DATE(payment_date) = ? AND status = 'paid'";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_salaries = $result['total'] ?? 0;

// THIS MONTH SALARIES
if ($selected_branch > 0) {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ? AND branch_id = ? AND status = 'paid'";
    $params = [$month, $year, $selected_branch];
} else {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ? AND status = 'paid'";
    $params = [$month, $year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$this_month_salaries = $result['total'] ?? 0;

// TOTAL SALARIES (All time - paid only)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE branch_id = ? AND status = 'paid'";
    $params = [$selected_branch];
} else {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE status = 'paid'";
    $params = [];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_salaries_all = $result['total'] ?? 0;

// ============================================================
// GET SALARIES LIST
// ============================================================
$sql = "SELECT 
            s.id,
            s.salary_number,
            s.salary_month,
            s.base_salary,
            s.bonus,
            s.overtime_pay,
            s.allowances,
            s.total_gross,
            s.tax,
            s.deductions,
            s.net_pay,
            s.payment_date,
            s.payment_method,
            s.transaction_reference,
            s.description,
            s.status,
            s.created_at,
            s.notes,
            emp.full_name as employee_name,
            emp.employee_id as employee_code,
            b.branch_name as branch_name,
            b.id as branch_id,
            paid_by.full_name as paid_by_name,
            approved_by.full_name as approved_by_name
        FROM employee_salaries s
        LEFT JOIN employees emp ON s.employee_id = emp.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN employees paid_by ON s.paid_by = paid_by.id
        LEFT JOIN employees approved_by ON s.approved_by = approved_by.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY s.salary_month DESC, s.id DESC";

$params = $branch_params;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$salaries = $stmt->fetchAll();

// Count salaries
$salary_count = count($salaries);

// ============================================================
// HANDLE SUCCESS/ERROR MESSAGES
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

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
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
                <div class="branch-salary-count">
                    <i class="fas fa-wallet"></i>
                    <span><?php echo $salary_count; ?> Salaries</span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ===== PAGE HEADER WITH ADD BUTTON ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-wallet"></i> Salaries</h2>
                <span class="record-count"><?php echo $salary_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Salary
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                            <i class="fas fa-download"></i> Export
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu" id="exportDropdown">
                            <a href="#" onclick="exportData('csv')">
                                <i class="fas fa-file-csv"></i> Export as CSV
                            </a>
                            <a href="#" onclick="exportData('excel')">
                                <i class="fas fa-file-excel"></i> Export as Excel
                            </a>
                            <a href="#" onclick="exportData('pdf')">
                                <i class="fas fa-file-pdf"></i> Export as PDF
                            </a>
                            <a href="#" onclick="exportData('print')">
                                <i class="fas fa-print"></i> Print
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
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
        SUMMARIES CARDS - TODAY, THIS MONTH, TOTAL
        ============================================================ -->
        <div class="summaries-grid-three">
            <div class="summary-card card-today">
                <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY SALARIES</div>
                    <div class="summary-value"><?php echo formatCurrency($today_salaries); ?></div>
                    <div class="summary-sub"><?php echo date('d M Y'); ?></div>
                </div>
            </div>

            <div class="summary-card card-month">
                <div class="summary-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">THIS MONTH</div>
                    <div class="summary-value"><?php echo formatCurrency($this_month_salaries); ?></div>
                    <div class="summary-sub"><?php echo date('F Y'); ?></div>
                </div>
            </div>

            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL SALARIES</div>
                    <div class="summary-value"><?php echo formatCurrency($total_salaries_all); ?></div>
                    <div class="summary-sub">All Time (Paid)</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - SALARIES LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Salaries</h3>
                <div class="table-actions">
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="reversed">Reversed</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search salaries..." class="search-input">
                </div>
            </div>

            <?php if (empty($salaries)): ?>
                <div class="empty-state">
                    <i class="fas fa-wallet"></i>
                    <h3>No Salaries Found</h3>
                    <p>Start by adding your first salary record.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Salary
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="salariesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Salary No.</th>
                                <th>Employee</th>
                                <th>Month</th>
                                <th>Branch</th>
                                <th>Gross Pay</th>
                                <th>Tax</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($salaries as $salary): 
                                $status = ucfirst($salary['status'] ?? 'pending');
                                $status_colors = [
                                    'paid' => 'status-paid',
                                    'pending' => 'status-pending',
                                    'cancelled' => 'status-cancelled',
                                    'reversed' => 'status-reversed'
                                ];
                                $status_class = $status_colors[strtolower($status)] ?? 'status-pending';
                            ?>
                                <tr data-status="<?php echo strtolower($salary['status'] ?? 'pending'); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="salary-number">
                                            <?php echo htmlspecialchars($salary['salary_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-name">
                                            <?php echo htmlspecialchars($salary['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="salary-month">
                                            <?php echo date('M Y', strtotime($salary['salary_month'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($salary['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount gross">
                                            <?php echo formatCurrency($salary['total_gross']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount tax">
                                            <?php echo formatCurrency($salary['tax']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount deductions">
                                            <?php echo formatCurrency($salary['deductions']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount net-pay">
                                            <?php echo formatCurrency($salary['net_pay']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $salary['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $salary['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $salary['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirmDelete(<?php echo $salary['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES - WITH BRANCH INDICATOR AND DARK MODE
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES - LIGHT & DARK MODE
   ============================================================ */
:root {
    --salary-bg: #f3f4f6;
    --salary-text: #1F2937;
    --salary-text-secondary: #6B7280;
    --salary-text-light: #9CA3AF;
    --salary-border: #E5E7EB;
    --salary-card-bg: #FFFFFF;
    --salary-input-bg: #F9FAFB;
    --salary-hover: #F3F4F6;
    --salary-shadow: rgba(0,0,0,0.06);
    --salary-shadow-lg: rgba(0,0,0,0.12);
    --salary-dropdown-bg: #FFFFFF;
    --salary-dropdown-border: #E5E7EB;
    --salary-scrollbar: #DC2626;
    --salary-scrollbar-track: #F3F4F6;
}

html.dark-mode {
    --salary-bg: #0f172a;
    --salary-text: #F1F5F9;
    --salary-text-secondary: #94A3B8;
    --salary-text-light: #64748B;
    --salary-border: #334155;
    --salary-card-bg: #1E293B;
    --salary-input-bg: #334155;
    --salary-hover: #2D3A4F;
    --salary-shadow: rgba(0,0,0,0.4);
    --salary-shadow-lg: rgba(0,0,0,0.6);
    --salary-dropdown-bg: #1E293B;
    --salary-dropdown-border: #334155;
    --salary-scrollbar: #DC2626;
    --salary-scrollbar-track: #1E293B;
}

/* ============================================================
   BASE STYLES
   ============================================================ */
body {
    background: var(--salary-bg) !important;
    color: var(--salary-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--salary-bg) !important;
}

.main-content {
    background: var(--salary-bg) !important;
}

/* Scrollbar */
.main-content::-webkit-scrollbar {
    width: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: var(--salary-scrollbar-track);
}

.main-content::-webkit-scrollbar-thumb {
    background: var(--salary-scrollbar);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-thumb:hover {
    background: #8B0000;
}

/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 24px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
}

.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 30%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 13px;
    color: #FFFFFF;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-icon-wrapper {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.branch-indicator-label {
    font-size: 11px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-indicator-code {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.6;
    color: #FFFFFF;
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    opacity: 0.8;
    color: #FFFFFF;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location i {
    font-size: 12px;
}

.branch-salary-count {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #FFFFFF;
    padding: 4px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-salary-count i {
    font-size: 13px;
}

.branch-indicator-right {
    position: relative;
    z-index: 1;
}

.branch-indicator-right .date-display {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}

.branch-indicator-right .date-display i {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 0 4px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--salary-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--salary-text-secondary);
    background: var(--salary-hover);
    padding: 2px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-add {
    background: #DC2626;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-add:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
    color: white;
}

.btn-add-empty {
    background: #DC2626;
    color: white;
    padding: 12px 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-add-empty:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.4);
    color: white;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.btn-export:hover {
    background: #1D4ED8;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}

.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle i.fa-chevron-down {
    font-size: 11px;
    margin-left: 2px;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--salary-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--salary-shadow-lg);
    border: 1px solid var(--salary-dropdown-border);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
    transition: all 0.3s ease;
}

.dropdown-menu.show { display: block; }

.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    text-decoration: none;
    color: var(--salary-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--salary-hover);
}

.dropdown-menu a i {
    width: 18px;
    font-size: 15px;
}

.dropdown-menu a i.fa-file-csv { color: #0B5ED7; }
.dropdown-menu a i.fa-file-excel { color: #1D7D1D; }
.dropdown-menu a i.fa-file-pdf { color: #DC2626; }
.dropdown-menu a i.fa-print { color: #6B7280; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    position: relative;
    animation: slideDown 0.4s ease forwards;
    transition: all 0.3s ease;
}

.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}

.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

html.dark-mode .alert-success {
    background: #065F46;
    color: #D1FAE5;
    border: 1px solid #047857;
}

html.dark-mode .alert-danger {
    background: #7F1D1D;
    color: #FEE2E2;
    border: 1px solid #991B1B;
}

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
    transition: opacity 0.2s;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   SUMMARIES CARDS
   ============================================================ */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--salary-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--salary-shadow);
    border: 1px solid var(--salary-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--salary-shadow-lg);
}

.summary-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--salary-text-secondary);
    transition: color 0.3s ease;
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--salary-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--salary-text-light);
    font-weight: 500;
    transition: color 0.3s ease;
}

.card-today .summary-icon { background: #FECACA; color: #7F1D1D; }
.card-today { border-left: 4px solid #7F1D1D; }

.card-month .summary-icon { background: #FEF3C7; color: #D97706; }
.card-month { border-left: 4px solid #D97706; }

.card-total .summary-icon { background: #FECACA; color: #5C1313; }
.card-total { border-left: 4px solid #5C1313; }

html.dark-mode .card-today .summary-icon { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .card-month .summary-icon { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .card-total .summary-icon { background: #5C1313; color: #FCA5A5; }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--salary-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--salary-shadow);
    border: 1px solid var(--salary-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--salary-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--salary-text);
    margin: 0;
    transition: color 0.3s ease;
}

.table-header h3 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.table-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--salary-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--salary-input-bg);
    color: var(--salary-text);
}

.search-input::placeholder {
    color: var(--salary-text-light);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--salary-border);
    font-size: 13px;
    outline: none;
    background: var(--salary-input-bg);
    color: var(--salary-text);
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select option {
    background: var(--salary-dropdown-bg);
    color: var(--salary-text);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
}

.data-table thead th i {
    color: #FFFFFF;
    margin-right: 4px;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--salary-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--salary-hover);
}

.data-table tbody td {
    padding: 12px 16px;
    color: var(--salary-text);
    transition: color 0.3s ease;
}

.salary-number {
    font-weight: 600;
    color: #7F1D1D;
    font-size: 12px;
}

.employee-name {
    font-weight: 500;
    color: var(--salary-text);
    transition: color 0.3s ease;
}

.salary-month {
    font-weight: 500;
    color: var(--salary-text-secondary);
    font-size: 12px;
    transition: color 0.3s ease;
}

.branch-name {
    background: var(--salary-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--salary-text-secondary);
    transition: all 0.3s ease;
}

.amount { font-weight: 600; }
.amount.gross { color: #1D4ED8; }
.amount.tax { color: #DC2626; }
.amount.deductions { color: #D97706; }
.amount.net-pay { color: #059669; font-weight: 700; }

.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-paid {
    background: #D1FAE5;
    color: #065F46;
}

.status-pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-cancelled {
    background: #FEE2E2;
    color: #991B1B;
}

.status-reversed {
    background: #EDE9FE;
    color: #5B21B6;
}

html.dark-mode .status-paid {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-pending {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .status-cancelled {
    background: #7F1D1D;
    color: #FEE2E2;
}

html.dark-mode .status-reversed {
    background: #4C1D95;
    color: #A78BFA;
}

/* ============================================================
   ACTION BUTTONS
   ============================================================ */
.action-buttons {
    display: flex;
    gap: 6px;
}

.btn-action {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 13px;
}

.btn-view {
    background: #DBEAFE;
    color: #1D4ED8;
}

.btn-view:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
}

.btn-edit:hover {
    background: #A7F3D0;
    color: #047857;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
}

html.dark-mode .btn-view {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .btn-view:hover {
    background: #3B82F6;
    color: #FFFFFF;
}

html.dark-mode .btn-edit {
    background: #065F46;
    color: #34D399;
}

html.dark-mode .btn-edit:hover {
    background: #10B981;
    color: #FFFFFF;
}

html.dark-mode .btn-delete {
    background: #7F1D1D;
    color: #FCA5A5;
}

html.dark-mode .btn-delete:hover {
    background: #DC2626;
    color: #FFFFFF;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 60px;
    color: #7F1D1D;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--salary-text);
    margin: 0 0 8px 0;
    transition: color 0.3s ease;
}

.empty-state p {
    color: var(--salary-text-secondary);
    font-size: 14px;
    margin: 0 0 24px 0;
    transition: color 0.3s ease;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summaries-grid-three {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    
    .header-actions .btn-add,
    .header-actions .btn-export {
        justify-content: center;
        width: 100%;
    }
    
    .dropdown {
        width: 100%;
    }
    
    .dropdown-menu {
        width: 100%;
        right: auto;
        left: 0;
    }
    
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
    }
    
    .summaries-grid-three .summary-card:last-child {
        grid-column: span 2;
    }
    
    .table-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .table-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .search-input {
        width: 100%;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .summary-card {
        min-height: 100px;
        height: 100px;
        padding: 14px 16px;
    }
    
    .summary-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .summary-value {
        font-size: 19px;
    }
    
    .branch-indicator {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
        padding: 16px 18px;
    }
    
    .branch-indicator-left {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .branch-info {
        flex-wrap: wrap;
    }
    
    .branch-indicator-right {
        width: 100%;
    }
    
    .branch-indicator-right .date-display {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .summaries-grid-three .summary-card:last-child {
        grid-column: span 2;
    }
    
    .summary-card {
        padding: 12px 14px;
        min-height: 90px;
        height: 90px;
    }
    
    .summary-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .summary-value {
        font-size: 16px;
    }
    
    .summary-label {
        font-size: 9px;
    }
    
    .summary-sub {
        font-size: 9px;
    }
    
    .data-table thead th,
    .data-table tbody td {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }
    
    .btn-action {
        width: 28px;
        height: 28px;
        font-size: 11px;
    }
    
    .btn-add-empty {
        padding: 10px 20px;
        font-size: 13px;
        width: 100%;
        justify-content: center;
    }
    
    .branch-indicator-name {
        font-size: 14px;
    }
    
    .branch-location {
        font-size: 11px;
        padding: 3px 10px;
    }
    
    .branch-indicator-code {
        font-size: 10px;
    }
    
    .branch-icon-wrapper {
        width: 38px;
        height: 38px;
        font-size: 17px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.summary-card {
    animation: fadeInUp 0.4s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.05s; }
.summary-card:nth-child(2) { animation-delay: 0.10s; }
.summary-card:nth-child(3) { animation-delay: 0.15s; }

.table-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.20s;
}

.branch-indicator {
    animation: fadeInUp 0.3s ease forwards;
}
</style>

<script>
function toggleDropdown() {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(event) {
    var dropdown = document.getElementById('exportDropdown');
    var button = document.querySelector('.dropdown-toggle');
    if (button && !button.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

function exportData(format) {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.remove('show');
    
    var table = document.getElementById('salariesTable');
    if (!table) {
        alert('No data to export!');
        return;
    }
    
    var rows = table.querySelectorAll('tbody tr');
    var headers = [];
    var headerCells = table.querySelectorAll('thead th');
    
    for (var i = 0; i < headerCells.length - 1; i++) {
        headers.push(headerCells[i].textContent.trim());
    }
    
    var data = [];
    rows.forEach(function(row) {
        var rowData = [];
        var cells = row.querySelectorAll('td');
        for (var i = 0; i < cells.length - 1; i++) {
            rowData.push(cells[i].textContent.trim());
        }
        data.push(rowData);
    });
    
    if (data.length === 0) {
        alert('No data to export!');
        return;
    }
    
    if (format === 'csv') {
        var csv = headers.join(',') + '\n';
        data.forEach(function(row) {
            csv += row.join(',') + '\n';
        });
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'salaries_export_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    } else if (format === 'excel') {
        var html = '<html><head><meta charset="UTF-8"><title>Salaries Export</title>';
        html += '<style>';
        html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
        html += 'h1 { color: #7F1D1D; }';
        html += 'table { width: 100%; border-collapse: collapse; }';
        html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
        html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
        html += '</style>';
        html += '</head><body>';
        html += '<h1>Salaries Report</h1>';
        html += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
        html += '<table>';
        html += '<thead><tr>';
        headers.forEach(function(h) {
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';
        data.forEach(function(row) {
            html += '<tr>';
            row.forEach(function(cell) {
                html += '<td>' + cell + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '</body></html>';
        var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'salaries_export_' + new Date().toISOString().slice(0,10) + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    } else if (format === 'pdf') {
        var printContent = '<html><head><title>Salaries Export</title>';
        printContent += '<style>';
        printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
        printContent += 'h1 { color: #7F1D1D; }';
        printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
        printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
        printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
        printContent += '</style>';
        printContent += '</head><body>';
        printContent += '<h1>Salaries Report</h1>';
        printContent += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
        printContent += '<table>';
        printContent += '<thead><tr>';
        headers.forEach(function(h) {
            printContent += '<th>' + h + '</th>';
        });
        printContent += '</tr></thead><tbody>';
        data.forEach(function(row) {
            printContent += '<tr>';
            row.forEach(function(cell) {
                printContent += '<td>' + cell + '</td>';
            });
            printContent += '</tr>';
        });
        printContent += '</tbody></table>';
        printContent += '</body></html>';
        var printWindow = window.open('', '_blank');
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    } else if (format === 'print') {
        window.print();
    }
}

function filterByStatus(status) {
    var rows = document.querySelectorAll('#salariesTable tbody tr');
    var statusFilter = status.toLowerCase();
    
    rows.forEach(function(row) {
        var rowStatus = row.getAttribute('data-status');
        if (statusFilter === '' || rowStatus === statusFilter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function confirmDelete(id) {
    return confirm('Are you sure you want to delete this salary record? This action cannot be undone.');
}

document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#salariesTable tbody tr');
            
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                if (text.indexOf(filter) > -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) {
            html.classList.add('dark-mode');
        } else {
            html.classList.remove('dark-mode');
        }
    }
    
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    }
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    }
});
</script>
</body>
</html>