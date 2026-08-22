<?php
// ================================================================
// FILE: modules/commissions/index.php
// WAKALA FINANCIAL SYSTEM - COMMISSIONS LIST
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
    $branch_filter = " AND c.branch_id = ? ";
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
// GET COMMISSION SUMMARIES
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// TODAY COMMISSION
if ($selected_branch > 0) {
    $sql = "SELECT SUM(total_commission) as total FROM commissions WHERE DATE(commission_date) = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(total_commission) as total FROM commissions WHERE DATE(commission_date) = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_commission = $result['total'] ?? 0;

// THIS MONTH COMMISSION
if ($selected_branch > 0) {
    $sql = "SELECT SUM(total_commission) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ? AND branch_id = ?";
    $params = [$month, $year, $selected_branch];
} else {
    $sql = "SELECT SUM(total_commission) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?";
    $params = [$month, $year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$this_month_commission = $result['total'] ?? 0;

// TOTAL COMMISSION (All time)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(total_commission) as total FROM commissions WHERE branch_id = ?";
    $params = [$selected_branch];
} else {
    $sql = "SELECT SUM(total_commission) as total FROM commissions";
    $params = [];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_commission_all = $result['total'] ?? 0;

// ============================================================
// GET COMMISSIONS LIST
// ============================================================
$sql = "SELECT 
            c.id,
            c.commission_number,
            c.commission_date,
            c.provider_data,
            c.total_commission,
            c.other_income,
            c.total_business_income,
            c.allocate_to_capital,
            c.allocated_amount,
            c.created_at,
            c.notes,
            emp.full_name as employee_name,
            b.branch_name as branch_name,
            b.id as branch_id
        FROM commissions c
        LEFT JOIN employees emp ON c.employee_id = emp.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY c.commission_date DESC, c.id DESC";

$params = $branch_params;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$commissions = $stmt->fetchAll();

// Count commissions
$commission_count = count($commissions);

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
                <h2><i class="fas fa-hand-holding-usd"></i> Commissions</h2>
                <span class="record-count"><?php echo $commission_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <!-- ADD Button - FIRST -->
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Commission
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
            <!-- TODAY COMMISSION - Green -->
            <div class="summary-card card-today">
                <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY COMMISSION</div>
                    <div class="summary-value"><?php echo formatCurrency($today_commission); ?></div>
                    <div class="summary-sub"><?php echo date('d M Y'); ?></div>
                </div>
            </div>

            <!-- THIS MONTH COMMISSION - Teal -->
            <div class="summary-card card-month">
                <div class="summary-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">THIS MONTH</div>
                    <div class="summary-value"><?php echo formatCurrency($this_month_commission); ?></div>
                    <div class="summary-sub"><?php echo date('F Y'); ?></div>
                </div>
            </div>

            <!-- TOTAL COMMISSION - Green Dark -->
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL COMMISSION</div>
                    <div class="summary-value"><?php echo formatCurrency($total_commission_all); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - COMMISSIONS LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Commissions</h3>
                <div class="table-actions">
                    <input type="text" id="searchInput" placeholder="Search commissions..." class="search-input">
                </div>
            </div>

            <?php if (empty($commissions)): ?>
                <div class="empty-state">
                    <i class="fas fa-hand-holding-usd"></i>
                    <h3>No Commissions Found</h3>
                    <p>Start by adding your first commission record.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Commission
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="commissionsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Commission No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Providers</th>
                                <th>Commission</th>
                                <th>Other Income</th>
                                <th>Total Income</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($commissions as $commission): 
                                // Get providers count
                                $provider_data = json_decode($commission['provider_data'] ?? '{}', true);
                                $provider_count = count($provider_data);
                                
                                // Allocation status
                                $allocation = $commission['allocate_to_capital'] ?? 'yes';
                                $allocated_amount = floatval($commission['allocated_amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="commission-number">
                                            <?php echo htmlspecialchars($commission['commission_number']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($commission['commission_date'])); ?></td>
                                    <td>
                                        <span class="employee-name">
                                            <?php echo htmlspecialchars($commission['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($commission['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="provider-count">
                                            <i class="fas fa-building"></i>
                                            <?php echo $provider_count; ?> providers
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount commission">
                                            <?php echo formatCurrency($commission['total_commission']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount other-income">
                                            <?php echo formatCurrency($commission['other_income']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount total-income">
                                            <?php echo formatCurrency($commission['total_business_income']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this commission record?')">
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
DASHBOARD STYLES - WITH FULL DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --commission-bg: #FFFFFF;
    --commission-text: #1F2937;
    --commission-text-secondary: #6B7280;
    --commission-text-light: #9CA3AF;
    --commission-border: #E5E7EB;
    --commission-card-bg: #FFFFFF;
    --commission-card-header: #FAFBFC;
    --commission-input-bg: #F9FAFB;
    --commission-hover: #F3F4F6;
    --commission-shadow: rgba(0,0,0,0.06);
    --commission-shadow-lg: rgba(0,0,0,0.12);
    --commission-dropdown-bg: #FFFFFF;
    --commission-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --commission-bg: #1F2937;
    --commission-text: #F9FAFB;
    --commission-text-secondary: #9CA3AF;
    --commission-text-light: #6B7280;
    --commission-border: #374151;
    --commission-card-bg: #1F2937;
    --commission-card-header: #374151;
    --commission-input-bg: #374151;
    --commission-hover: #374151;
    --commission-shadow: rgba(0,0,0,0.3);
    --commission-shadow-lg: rgba(0,0,0,0.4);
    --commission-dropdown-bg: #1F2937;
    --commission-dropdown-border: #374151;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--commission-bg) !important;
    color: var(--commission-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--commission-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--commission-bg) !important;
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
    color: var(--commission-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #10B981;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--commission-text-secondary);
    background: var(--commission-hover);
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
    background: var(--commission-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--commission-shadow-lg);
    border: 1px solid var(--commission-dropdown-border);
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
    color: var(--commission-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--commission-hover);
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
    background: var(--commission-card-bg);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
}

.branch-filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--commission-text);
}

.branch-filter-left i {
    color: #DC2626;
    font-size: 16px;
}

.branch-filter-left select {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--commission-border);
    background: var(--commission-input-bg);
    font-size: 13px;
    color: var(--commission-text);
    outline: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.branch-filter-left select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.branch-filter-left select option {
    background: var(--commission-dropdown-bg);
    color: var(--commission-text);
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
    color: var(--commission-text-secondary);
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
    background: var(--commission-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--commission-shadow-lg);
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
    color: var(--commission-text-secondary);
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--commission-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--commission-text-light);
    font-weight: 500;
}

/* Card Colors */
.card-today .summary-icon { background: #D1FAE5; color: #059669; }
.card-today { border-left: 4px solid #10B981; }

.card-month .summary-icon { background: #D1FAE5; color: #047857; }
.card-month { border-left: 4px solid #059669; }

.card-total .summary-icon { background: #A7F3D0; color: #065F46; }
.card-total { border-left: 4px solid #047857; }

/* ============================================================
   TABLE CONTAINER - DARK MODE
   ============================================================ */
.table-container {
    background: var(--commission-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--commission-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--commission-text);
    margin: 0;
}

.table-header h3 i {
    color: #10B981;
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
    border: 1px solid var(--commission-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--commission-input-bg);
    color: var(--commission-text);
}

.search-input::placeholder {
    color: var(--commission-text-light);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
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
    border-bottom: 1px solid var(--commission-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--commission-hover);
}

.data-table tbody td {
    padding: 12px 16px;
    color: var(--commission-text);
    transition: color 0.3s ease;
}

/* Commission Number */
.commission-number {
    font-weight: 600;
    color: #10B981;
    font-size: 12px;
}

/* Employee Name */
.employee-name {
    font-weight: 500;
    color: var(--commission-text);
}

/* Branch Name */
.branch-name {
    background: var(--commission-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--commission-text-secondary);
    transition: all 0.3s ease;
}

/* Provider Count */
.provider-count {
    font-size: 12px;
    color: var(--commission-text-secondary);
}

.provider-count i {
    color: #3B82F6;
    margin-right: 4px;
}

/* Amounts */
.amount {
    font-weight: 600;
}

.amount.commission {
    color: #10B981;
}

.amount.other-income {
    color: #7C3AED;
}

.amount.total-income {
    color: #059669;
    font-weight: 700;
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
    color: #10B981;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--commission-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--commission-text-secondary);
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
    
    var table = document.getElementById('commissionsTable');
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
    a.download = 'commissions_export_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT EXCEL (HTML Table format)
// ============================================================
function exportExcel(headers, data) {
    var html = '<html><head><meta charset="UTF-8"><title>Commissions Export</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    html += 'h1 { color: #10B981; }';
    html += 'table { width: 100%; border-collapse: collapse; }';
    html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
    html += '</style>';
    html += '</head><body>';
    html += '<h1>Commissions Report</h1>';
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
    a.download = 'commissions_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================================
// EXPORT PDF
// ============================================================
function exportPDF(headers, data) {
    var printContent = '<html><head><title>Commissions Export</title>';
    printContent += '<style>';
    printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
    printContent += 'h1 { color: #10B981; }';
    printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    printContent += '.total { margin-top: 20px; font-weight: bold; font-size: 16px; }';
    printContent += '</style>';
    printContent += '</head><body>';
    printContent += '<h1>Commissions Report</h1>';
    printContent += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
    
    var totalCommission = 0;
    var totalOtherIncome = 0;
    var totalIncome = 0;
    
    printContent += '<table>';
    printContent += '<thead><tr>';
    headers.forEach(function(h) {
        printContent += '<th>' + h + '</th>';
    });
    printContent += '</tr></thead><tbody>';
    
    data.forEach(function(row) {
        printContent += '<tr>';
        row.forEach(function(cell, index) {
            // Commission column (index 6)
            if (index === 6) {
                var cleanAmount = cell.replace(/[^0-9,]/g, '');
                var numAmount = parseFloat(cleanAmount.replace(/,/g, ''));
                if (!isNaN(numAmount)) {
                    totalCommission += numAmount;
                }
            }
            // Other Income column (index 7)
            if (index === 7) {
                var cleanAmount = cell.replace(/[^0-9,]/g, '');
                var numAmount = parseFloat(cleanAmount.replace(/,/g, ''));
                if (!isNaN(numAmount)) {
                    totalOtherIncome += numAmount;
                }
            }
            // Total Income column (index 8)
            if (index === 8) {
                var cleanAmount = cell.replace(/[^0-9,]/g, '');
                var numAmount = parseFloat(cleanAmount.replace(/,/g, ''));
                if (!isNaN(numAmount)) {
                    totalIncome += numAmount;
                }
            }
            printContent += '<td>' + cell + '</td>';
        });
        printContent += '</tr>';
    });
    
    printContent += '</tbody></table>';
    printContent += '<div class="total">Total Commission: ' + formatNumber(totalCommission) + '</div>';
    printContent += '<div class="total">Total Other Income: ' + formatNumber(totalOtherIncome) + '</div>';
    printContent += '<div class="total">Total Business Income: ' + formatNumber(totalIncome) + '</div>';
    printContent += '</body></html>';
    
    var printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// ============================================================
// FORMAT NUMBER
// ============================================================
function formatNumber(num) {
    return 'TSh ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// SEARCH FUNCTIONALITY
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#commissionsTable tbody tr');
            
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