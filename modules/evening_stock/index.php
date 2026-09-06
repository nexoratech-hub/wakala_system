<?php
// ================================================================
// FILE: modules/evening_stock/index.php
// WAKALA FINANCIAL SYSTEM - EVENING STOCK LIST
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
    $branch_filter = " AND es.branch_id = ? ";
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
// GET TODAY'S SUMMARIES FROM EVENING STOCK
// ============================================================
$today = date('Y-m-d');

// TODAY FLOAT (cumm_total from evening_stock)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(cumm_total) as total FROM evening_stocks WHERE stock_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(cumm_total) as total FROM evening_stocks WHERE stock_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_float = $result['total'] ?? 0;

// TODAY CASH (cash_balance from evening_stock)
if ($selected_branch > 0) {
    $sql = "SELECT SUM(cash_balance) as total FROM evening_stocks WHERE stock_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(cash_balance) as total FROM evening_stocks WHERE stock_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_cash = $result['total'] ?? 0;

// TODAY STOCK = FLOAT + CASH
$today_stock = $today_float + $today_cash;

// ============================================================
// GET EVENING STOCKS LIST
// ============================================================
$sql = "SELECT 
            es.id,
            es.stock_number,
            es.stock_date,
            es.cash_balance,
            es.cumm_total,
            es.status,
            es.submitted_at,
            es.notes,
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.id as branch_id,
            es.provider_data
        FROM evening_stocks es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY es.stock_date DESC, es.id DESC";

$params = $branch_params;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

// Count stocks
$stock_count = count($stocks);

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
        
        <!-- ===== BRANCH CARD ===== -->
        <div class="branch-card">
            <i class="fas fa-store-alt"></i>
            <span class="branch-label">Current Branch:</span>
            <span class="branch-name"><?php echo htmlspecialchars($branch_name); ?></span>
        </div>

        <!-- ===== PAGE HEADER WITH ADD BUTTON ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-moon"></i> Evening Stocks</h2>
                <span class="record-count"><?php echo $stock_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="add.php" class="btn btn-add">
                        <i class="fas fa-plus-circle"></i> Add Evening Stock
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
        SUMMARIES CARDS - TODAY STOCK, TODAY FLOAT, TODAY CASH
        ============================================================ -->
        <div class="summaries-grid-three">
            <div class="summary-card card-stock">
                <div class="summary-icon"><i class="fas fa-boxes"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY STOCK</div>
                    <div class="summary-value"><?php echo formatCurrency($today_stock); ?></div>
                    <div class="summary-sub">Float + Cash (Evening Stock)</div>
                </div>
            </div>

            <div class="summary-card card-float">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY FLOAT</div>
                    <div class="summary-value"><?php echo formatCurrency($today_float); ?></div>
                    <div class="summary-sub">Today's Evening Stock</div>
                </div>
            </div>

            <div class="summary-card card-cash">
                <div class="summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TODAY CASH</div>
                    <div class="summary-value"><?php echo formatCurrency($today_cash); ?></div>
                    <div class="summary-sub">Today's Evening Stock</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABLE - EVENING STOCKS LIST
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> All Evening Stocks</h3>
                <div class="table-actions">
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="waiting">Waiting</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="adjusted">Adjusted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search stocks..." class="search-input">
                </div>
            </div>

            <?php if (empty($stocks)): ?>
                <div class="empty-state">
                    <i class="fas fa-moon"></i>
                    <h3>No Evening Stocks Found</h3>
                    <p>Start by adding your first evening stock for today.</p>
                    <a href="add.php" class="btn btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Evening Stock
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="stocksTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Stock No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Providers</th>
                                <th>Cash</th>
                                <th>Float</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($stocks as $stock): 
                                $provider_data = json_decode($stock['provider_data'] ?? '{}', true);
                                $provider_count = count($provider_data);
                                
                                $status = ucfirst($stock['status'] ?? 'pending');
                                $status_colors = [
                                    'waiting' => 'status-waiting',
                                    'pending' => 'status-pending',
                                    'approved' => 'status-approved',
                                    'adjusted' => 'status-adjusted',
                                    'rejected' => 'status-rejected'
                                ];
                                $status_class = $status_colors[strtolower($status)] ?? 'status-pending';
                            ?>
                                <tr data-status="<?php echo strtolower($stock['status'] ?? 'pending'); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="stock-number">
                                            <?php echo htmlspecialchars($stock['stock_number']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></td>
                                    <td>
                                        <span class="employee-name">
                                            <?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-name">
                                            <?php echo htmlspecialchars($stock['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="provider-count">
                                            <i class="fas fa-building"></i>
                                            <?php echo $provider_count; ?> providers
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount cash">
                                            <?php echo formatCurrency($stock['cash_balance']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount float">
                                            <?php echo formatCurrency($stock['cumm_total']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $stock['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $stock['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $stock['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirmDelete(<?php echo $stock['id']; ?>)">
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
    --evening-bg: #FFFFFF;
    --evening-text: #1F2937;
    --evening-text-secondary: #6B7280;
    --evening-text-light: #9CA3AF;
    --evening-border: #E5E7EB;
    --evening-card-bg: #FFFFFF;
    --evening-input-bg: #F9FAFB;
    --evening-hover: #F3F4F6;
    --evening-shadow: rgba(0,0,0,0.06);
    --evening-shadow-lg: rgba(0,0,0,0.12);
    --evening-dropdown-bg: #FFFFFF;
    --evening-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --evening-bg: #1F2937;
    --evening-text: #F9FAFB;
    --evening-text-secondary: #9CA3AF;
    --evening-text-light: #6B7280;
    --evening-border: #374151;
    --evening-card-bg: #1F2937;
    --evening-input-bg: #374151;
    --evening-hover: #374151;
    --evening-shadow: rgba(0,0,0,0.3);
    --evening-shadow-lg: rgba(0,0,0,0.4);
    --evening-dropdown-bg: #1F2937;
    --evening-dropdown-border: #374151;
}

body {
    background: var(--evening-bg) !important;
    color: var(--evening-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--evening-bg) !important; }
.main-content { background: var(--evening-bg) !important; }

/* ============================================================
   BRANCH CARD - RED CARD
   ============================================================ */
.branch-card {
    background: #bb0404;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.3);
}

.branch-card i {
    font-size: 18px;
}

.branch-card .branch-label {
    font-weight: 500;
    font-size: 13px;
    opacity: 0.9;
}

.branch-card .branch-name {
    font-weight: 700;
    font-size: 15px;
}

/* Dark mode support for branch card */
html.dark-mode .branch-card {
    background: #bb0404;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.5);
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
    color: var(--evening-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--evening-text-secondary);
    background: var(--evening-hover);
    padding: 2px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

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
    background: var(--evening-dropdown-bg);
    min-width: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--evening-shadow-lg);
    border: 1px solid var(--evening-dropdown-border);
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
    color: var(--evening-text);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s ease;
}

.dropdown-menu a:hover {
    background: var(--evening-hover);
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
   ALERT MESSAGES
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
    background: var(--evening-card-bg);
    border-radius: 10px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--evening-shadow);
    border: 1px solid var(--evening-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: 110px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--evening-shadow-lg);
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
    color: var(--evening-text-secondary);
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--evening-text);
    margin: 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.3s ease;
}

.summary-sub {
    font-size: 11px;
    color: var(--evening-text-light);
    font-weight: 500;
}

.card-stock .summary-icon { background: #DBEAFE; color: #1E40AF; }
.card-stock { border-left: 4px solid #1E40AF; }

.card-float .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-float { border-left: 4px solid #3B82F6; }

.card-cash .summary-icon { background: #D1FAE5; color: #065F46; }
.card-cash { border-left: 4px solid #10B981; }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--evening-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--evening-shadow);
    border: 1px solid var(--evening-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--evening-border);
    flex-wrap: wrap;
    gap: 10px;
    transition: all 0.3s ease;
}

.table-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--evening-text);
    margin: 0;
}

.table-header h3 i {
    color: #3B82F6;
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
    border: 1px solid var(--evening-border);
    font-size: 13px;
    outline: none;
    width: 200px;
    transition: all 0.3s ease;
    background: var(--evening-input-bg);
    color: var(--evening-text);
}

.search-input::placeholder {
    color: var(--evening-text-light);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--evening-border);
    font-size: 13px;
    outline: none;
    background: var(--evening-input-bg);
    color: var(--evening-text);
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.filter-select option {
    background: var(--evening-dropdown-bg);
    color: var(--evening-text);
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
    border-bottom: 1px solid var(--evening-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--evening-hover);
}

.data-table tbody td {
    padding: 12px 16px;
    color: var(--evening-text);
    transition: color 0.3s ease;
}

.stock-number {
    font-weight: 600;
    color: #3B82F6;
    font-size: 12px;
}

.employee-name {
    font-weight: 500;
    color: var(--evening-text);
}

.branch-name {
    background: var(--evening-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--evening-text-secondary);
    transition: all 0.3s ease;
}

.provider-count {
    font-size: 12px;
    color: var(--evening-text-secondary);
}

.provider-count i {
    color: #3B82F6;
    margin-right: 4px;
}

.amount { font-weight: 600; }
.amount.cash { color: #059669; }
.amount.float { color: #1D4ED8; }

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

.status-waiting {
    background: #DBEAFE;
    color: #1E40AF;
}

.status-adjusted {
    background: #EDE9FE;
    color: #5B21B6;
}

.status-rejected {
    background: #FEE2E2;
    color: #991B1B;
}

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

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 60px;
    color: #3B82F6;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--evening-text);
    margin: 0 0 8px 0;
}

.empty-state p {
    color: var(--evening-text-secondary);
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
    .branch-card {
        padding: 10px 16px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    
    .branch-card .branch-name {
        font-size: 14px;
    }
    
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
}

@media (max-width: 480px) {
    .branch-card {
        flex-direction: column;
        text-align: center;
        gap: 4px;
    }
    
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
    
    var table = document.getElementById('stocksTable');
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
        a.download = 'evening_stocks_export_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    } else if (format === 'excel') {
        var html = '<html><head><meta charset="UTF-8"><title>Evening Stocks Export</title>';
        html += '<style>';
        html += 'body { font-family: Arial, sans-serif; padding: 20px; }';
        html += 'h1 { color: #3B82F6; }';
        html += 'table { width: 100%; border-collapse: collapse; }';
        html += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
        html += 'td { padding: 8px 10px; border: 1px solid #E5E7EB; }';
        html += '</style>';
        html += '</head><body>';
        html += '<h1>Evening Stocks Report</h1>';
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
        a.download = 'evening_stocks_export_' + new Date().toISOString().slice(0,10) + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    } else if (format === 'pdf') {
        var printContent = '<html><head><title>Evening Stocks Export</title>';
        printContent += '<style>';
        printContent += 'body { font-family: Arial, sans-serif; padding: 20px; }';
        printContent += 'h1 { color: #3B82F6; }';
        printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
        printContent += 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
        printContent += 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
        printContent += '</style>';
        printContent += '</head><body>';
        printContent += '<h1>Evening Stocks Report</h1>';
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
    var rows = document.querySelectorAll('#stocksTable tbody tr');
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
    return confirm('Are you sure you want to delete this evening stock record? This action cannot be undone.');
}

document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#stocksTable tbody tr');
            
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