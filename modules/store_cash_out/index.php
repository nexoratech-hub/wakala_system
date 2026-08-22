<?php
// ================================================================
// FILE: modules/store_cash_out/index.php
// WAKALA FINANCIAL SYSTEM - STORE CASH OUT LIST
// WITH FULL DARK MODE SUPPORT
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
// GET BRANCHES FOR FILTER
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER HANDLING
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

if (isset($_GET['branch'])) {
    $_SESSION['selected_branch'] = $selected_branch;
} elseif (isset($_SESSION['selected_branch']) && !isset($_GET['branch'])) {
    $selected_branch = $_SESSION['selected_branch'];
}

$selected_branch = $selected_branch ?? 0;

// Build branch filter for SQL
$branch_filter = '';
$branch_params = [];

if ($selected_branch > 0) {
    $branch_filter = " AND sco.branch_id = ? ";
    $branch_params[] = $selected_branch;
}

// Get branch name for display
$branch_name = 'All Branches';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// ============================================================
// GET CASH OUT SUMMARIES
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// TODAY CASH OUT
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE DATE(cashout_date) = ? AND branch_id = ? AND status IN ('approved', 'pending')";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE DATE(cashout_date) = ? AND status IN ('approved', 'pending')";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_cashout = $result['total'] ?? 0;

// THIS MONTH CASH OUT
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ? AND branch_id = ? AND status IN ('approved', 'pending')";
    $params = [$month, $year, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ? AND status IN ('approved', 'pending')";
    $params = [$month, $year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$this_month_cashout = $result['total'] ?? 0;

// TOTAL CASH OUT (All time - approved only)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE branch_id = ? AND status = 'approved'";
    $params = [$selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out WHERE status = 'approved'";
    $params = [];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_cashout_all = $result['total'] ?? 0;

// ============================================================
// GET CASH OUT LIST
// ============================================================
$sql = "SELECT 
            sco.id,
            sco.cashout_number,
            sco.cashout_date,
            sco.amount,
            sco.reason,
            sco.taken_by,
            sco.approved_by,
            sco.approved_date,
            sco.description,
            sco.receipt_path,
            sco.status,
            sco.created_at,
            sco.notes,
            emp.full_name as employee_name,
            b.branch_name as branch_name,
            b.id as branch_id
        FROM store_cash_out sco
        LEFT JOIN employees emp ON sco.employee_id = emp.id
        LEFT JOIN branches b ON sco.branch_id = b.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY sco.cashout_date DESC, sco.id DESC";

$params = $branch_params;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cashouts = $stmt->fetchAll();

// Count cashouts
$cashout_count = count($cashouts);

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
        
        <!-- ===== PAGE HEADER WITH ADD BUTTON ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-money-bill-wave"></i> Store Cash Out</h2>
                <span class="record-count"><?php echo $cashout_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <!-- ADD Button - FIRST -->
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Cash Out
                    </a>
                    
                    <!-- Export Dropdown - SECOND -->
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

        <!-- ===== BRANCH FILTER ===== -->
        <div class="branch-filter-bar">
            <div class="branch-filter-left">
                <i class="fas fa-store-alt"></i>
                <span>Branch:</span>
                <select id="branchFilter" onchange="window.location.href='?branch='+this.value">
                    <option value="0">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_branch > 0): ?>
                    <span class="branch-badge"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php endif; ?>
            </div>
            <div class="branch-filter-right">
                <span class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('d M Y'); ?></span>
            </div>
        </div>

        <!-- ============================================================
        SUMMARIES CARDS - TODAY, THIS MONTH, TOTAL
        ============================================================ -->
        <div class="summaries-grid-three">
            <!-- TODAY CASH OUT - Red -->
            <div class="summary-card card-today">
                <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY CASH OUT</div>
                    <div class="summary-value"><?php echo formatCurrency($today_cashout); ?></div>
                    <div class="summary-sub"><?php echo date('d M Y'); ?></div>
                </div>
            </div>

            <!-- THIS MONTH CASH OUT - Orange -->
            <div class="summary-card card-month">
                <div class="summary-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">THIS MONTH</div>
                    <div class="summary-value"><?php echo formatCurrency($this_month_cashout); ?></div>
                    <div class="summary-sub"><?php echo date('F Y'); ?></div>
                </div>
            </div>

            <!-- TOTAL CASH OUT - Maroon -->
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL CASH OUT</div>
                    <div class="summary-value"><?php echo formatCurrency($total_cashout_all); ?></div>
                    <div class="summary-sub">All Time (Approved)</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - CASH OUT LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Cash Out Records</h3>
                <div class="table-actions">
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search cash out..." class="search-input">
                </div>
            </div>

            <?php if (empty($cashouts)): ?>
                <div class="empty-state">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>No Cash Out Records Found</h3>
                    <p>Start by adding your first cash out record.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Cash Out
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="cashoutTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cash Out No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($cashouts as $cashout): 
                                // Status colors
                                $status = ucfirst($cashout['status'] ?? 'pending');
                                $status_colors = [
                                    'pending' => 'status-pending',
                                    'approved' => 'status-approved',
                                    'rejected' => 'status-rejected',
                                    'cancelled' => 'status-cancelled'
                                ];
                                $status_class = $status_colors[strtolower($status)] ?? 'status-pending';
                                
                                // Amount color based on status
                                $amount_class = 'amount';
                                if (strtolower($status) == 'approved') {
                                    $amount_class .= ' approved';
                                } elseif (strtolower($status) == 'rejected' || strtolower($status) == 'cancelled') {
                                    $amount_class .= ' cancelled';
                                } else {
                                    $amount_class .= ' pending';
                                }
                            ?>
                                <tr data-status="<?php echo strtolower(htmlspecialchars($cashout['status'] ?? 'pending')); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="cashout-number">
                                            <?php echo htmlspecialchars($cashout['cashout_number']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($cashout['cashout_date'])); ?></td>
                                    <td>
                                        <span class="employee-name">
                                            <?php echo htmlspecialchars($cashout['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($cashout['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo $amount_class; ?>">
                                            <?php echo formatCurrency($cashout['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="reason-text" title="<?php echo htmlspecialchars($cashout['reason']); ?>">
                                            <?php echo htmlspecialchars(substr($cashout['reason'], 0, 30)) . (strlen($cashout['reason']) > 30 ? '...' : ''); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $cashout['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (strtolower($cashout['status'] ?? '') == 'pending'): ?>
                                                <a href="edit.php?id=<?php echo $cashout['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="delete.php?id=<?php echo $cashout['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this cash out record?')">
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
DASHBOARD STYLES WITH FULL DARK MODE
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --cashout-bg: #FFFFFF;
    --cashout-text: #1F2937;
    --cashout-text-secondary: #6B7280;
    --cashout-text-light: #9CA3AF;
    --cashout-border: #E5E7EB;
    --cashout-card-bg: #FFFFFF;
    --cashout-card-header: #FAFBFC;
    --cashout-input-bg: #F9FAFB;
    --cashout-hover: #F3F4F6;
    --cashout-shadow: rgba(0,0,0,0.06);
    --cashout-shadow-lg: rgba(0,0,0,0.12);
    --cashout-dropdown-bg: #FFFFFF;
    --cashout-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --cashout-bg: #1F2937;
    --cashout-text: #F9FAFB;
    --cashout-text-secondary: #9CA3AF;
    --cashout-text-light: #6B7280;
    --cashout-border: #374151;
    --cashout-card-bg: #1F2937;
    --cashout-card-header: #374151;
    --cashout-input-bg: #374151;
    --cashout-hover: #374151;
    --cashout-shadow: rgba(0,0,0,0.3);
    --cashout-shadow-lg: rgba(0,0,0,0.4);
    --cashout-dropdown-bg: #1F2937;
    --cashout-dropdown-border: #374151;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--cashout-bg) !important;
    color: var(--cashout-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--cashout-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--cashout-bg) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   PAGE HEADER - DARK MODE
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
    color: var(--cashout-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--cashout-text-secondary);
    background: var(--cashout-hover);
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
   ADD BUTTON - RED
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

/* ============================================================
   EMPTY STATE ADD BUTTON - RED
   ============================================================ */
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

/* ============================================================
   EXPORT BUTTON - BLUE
   ============================================================ */
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
    background: var(--cashout-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--cashout-shadow-lg);
    border: 1px solid var(--cashout-dropdown-border);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
    transition: all 0.3s ease;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    text-decoration: none;
    color: var(--cashout-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--cashout-hover);
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
   BRANCH FILTER BAR - DARK MODE
   ============================================================ */
.branch-filter-bar {
    background: var(--cashout-card-bg);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px var(--cashout-shadow);
    border: 1px solid var(--cashout-border);
    transition: all 0.3s ease;
}

.branch-filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--cashout-text);
}

.branch-filter-left i {
    color: #DC2626;
    font-size: 16px;
}

.branch-filter-left select {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--cashout-border);
    background: var(--cashout-input-bg);
    font-size: 13px;
    color: var(--cashout-text);
    outline: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.branch-filter-left select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.branch-filter-left select option {
    background: var(--cashout-dropdown-bg);
    color: var(--cashout-text);
}

.branch-badge {
    background: #DC2626;
    color: white;
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.branch-filter-right .date-display {
    font-size: 13px;
    color: var(--cashout-text-secondary);
}

.branch-filter-right .date-display i {
    color: #DC2626;
}

/* ============================================================
   SUMMARIES GRID - 3 CARDS - DARK MODE
   ============================================================ */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: var(--cashout-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--cashout-shadow);
    border: 1px solid var(--cashout-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--cashout-shadow-lg);
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
    color: var(--cashout-text-secondary);
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--cashout-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--cashout-text-light);
    font-weight: 500;
}

/* Card Colors */
.card-today .summary-icon { background: #FEE2E2; color: #DC2626; }
.card-today { border-left: 4px solid #DC2626; }

.card-month .summary-icon { background: #FEF3C7; color: #D97706; }
.card-month { border-left: 4px solid #D97706; }

.card-total .summary-icon { background: #FECACA; color: #7F1D1D; }
.card-total { border-left: 4px solid #7F1D1D; }

/* ============================================================
   TABLE CONTAINER - DARK MODE
   ============================================================ */
.table-container {
    background: var(--cashout-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--cashout-shadow);
    border: 1px solid var(--cashout-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--cashout-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--cashout-text);
    margin: 0;
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
    border: 1px solid var(--cashout-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--cashout-input-bg);
    color: var(--cashout-text);
}

.search-input::placeholder {
    color: var(--cashout-text-light);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--cashout-border);
    font-size: 13px;
    outline: none;
    background: var(--cashout-input-bg);
    color: var(--cashout-text);
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select option {
    background: var(--cashout-dropdown-bg);
    color: var(--cashout-text);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

/* ============================================================
   TABLE HEADER - RED BACKGROUND (Stays Red)
   ============================================================ */
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
    border-bottom: 1px solid var(--cashout-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--cashout-hover);
}

.data-table tbody td {
    padding: 12px 16px;
    color: var(--cashout-text);
    transition: color 0.3s ease;
}

/* Cash Out Number */
.cashout-number {
    font-weight: 600;
    color: #7F1D1D;
    font-size: 12px;
}

/* Employee Name */
.employee-name {
    font-weight: 500;
    color: var(--cashout-text);
}

/* Branch Name */
.branch-name {
    background: var(--cashout-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--cashout-text-secondary);
    transition: all 0.3s ease;
}

/* Amount */
.amount {
    font-weight: 600;
}

.amount.approved {
    color: #059669;
}

.amount.pending {
    color: #D97706;
}

.amount.cancelled {
    color: var(--cashout-text-light);
    text-decoration: line-through;
}

/* Reason Text */
.reason-text {
    font-size: 12px;
    color: var(--cashout-text-secondary);
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-approved {
    background: #D1FAE5;
    color: #065F46;
}

.status-pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-rejected {
    background: #FEE2E2;
    color: #991B1B;
}

.status-cancelled {
    background: #F3F4F6;
    color: #6B7280;
}

/* Action Buttons */
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

/* ============================================================
   EMPTY STATE - DARK MODE
   ============================================================ */
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
    color: var(--cashout-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--cashout-text-secondary);
    font-size: 14px;
    margin: 0 0 24px 0;
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
    
    .branch-filter-bar {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
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
</style>

<script>
// ============================================================
// DROPDOWN TOGGLE
// ============================================================
function toggleDropdown() {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    var dropdown = document.getElementById('exportDropdown');
    var button = document.querySelector('.dropdown-toggle');
    if (button && !button.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// ============================================================
// EXPORT FUNCTIONS
// ============================================================
function exportData(format) {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.remove('show');
    
    var table = document.getElementById('cashoutTable');
    if (!table) {
        alert('No data to export!');
        return;
    }
    
    var rows = table.querySelectorAll('tbody tr');
    var headers = [];
    var headerCells = table.querySelectorAll('thead th');
    
    // Get headers (skip Actions column)
    for (var i = 0; i < headerCells.length - 1; i++) {
        headers.push(headerCells[i].textContent.trim());
    }
    
    // Get data
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
        exportCSV(headers, data);
    } else if (format === 'excel') {
        exportExcel(headers, data);
    } else if (format === 'pdf') {
        exportPDF(headers, data);
    } else if (format === 'print') {
        window.print();
    }
}

// ============================================================
// EXPORT CSV
// ============================================================
function exportCSV(headers, data) {
    var csv = headers.join(',') + '\n';
    data.forEach(function(row) {
        csv += row.join(',') + '\n';
    });
    
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'cashout_export_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT EXCEL
// ============================================================
function exportExcel(headers, data) {
    var html = '<html><head><meta charset="UTF-8"><title>Cash Out Export</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    html += 'h1 { color: #7F1D1D; }';
    html += 'table { width: 100%; border-collapse: collapse; }';
    html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
    html += '</style>';
    html += '</head><body>';
    html += '<h1>Store Cash Out Report</h1>';
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
    a.download = 'cashout_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT PDF
// ============================================================
function exportPDF(headers, data) {
    var printContent = '<html><head><title>Cash Out Export</title>';
    printContent += '<style>';
    printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    printContent += 'h1 { color: #7F1D1D; }';
    printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    printContent += '</style>';
    printContent += '</head><body>';
    printContent += '<h1>Store Cash Out Report</h1>';
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
}

// ============================================================
// FILTER BY STATUS
// ============================================================
function filterByStatus(status) {
    var rows = document.querySelectorAll('#cashoutTable tbody tr');
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

// ============================================================
// SEARCH FUNCTIONALITY
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#cashoutTable tbody tr');
            
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
    
    // ============================================================
    // DARK MODE SYNC
    // ============================================================
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
});
</script>

</body>
</html>