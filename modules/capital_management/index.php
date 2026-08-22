<?php
// ================================================================
// FILE: modules/capital_management/index.php
// WAKALA SYSTEM - CAPITAL MANAGEMENT LIST
// WITH DARK MODE SUPPORT
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
// BRANCH FILTER
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

// ============================================================
// TRANSACTION TYPE FILTER
// ============================================================
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// ============================================================
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// ============================================================
// GET CAPITAL TRANSACTIONS WITH FILTERS
// ============================================================
$sql = "SELECT cm.*, 
        e.full_name as employee_name, 
        b.branch_name as branch_name
        FROM capital_management cm
        LEFT JOIN employees e ON cm.employee_id = e.id
        LEFT JOIN branches b ON cm.branch_id = b.id
        WHERE cm.transaction_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql .= " AND cm.branch_id = ?";
    $params[] = $selected_branch;
}

if (!empty($type_filter)) {
    $sql .= " AND cm.transaction_type = ?";
    $params[] = $type_filter;
}

// If employee, show only their transactions
if ($role == 'employee') {
    $sql .= " AND cm.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY cm.transaction_date DESC, cm.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// ============================================================
// GET CAPITAL SUMMARY
// ============================================================
// Get current capital (opening + additions + profit - cashout - adjustments)
$sql_capital = "SELECT 
        SUM(CASE WHEN transaction_type IN ('opening', 'additional', 'profit_allocation') THEN amount ELSE 0 END) as total_in,
        SUM(CASE WHEN transaction_type IN ('cash_out', 'adjustment') THEN amount ELSE 0 END) as total_out,
        (SELECT amount FROM capital_management 
         WHERE transaction_type = 'opening' 
         ORDER BY transaction_date ASC LIMIT 1) as opening_capital
        FROM capital_management
        WHERE transaction_date BETWEEN ? AND ?";

$params_capital = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql_capital .= " AND branch_id = ?";
    $params_capital[] = $selected_branch;
}

$stmt = $db->prepare($sql_capital);
$stmt->execute($params_capital);
$capital_summary = $stmt->fetch();

// Calculate current capital
$opening = $capital_summary['opening_capital'] ?? 0;
$total_in = $capital_summary['total_in'] ?? 0;
$total_out = $capital_summary['total_out'] ?? 0;
$current_capital = $opening + $total_in - $total_out;

// ============================================================
// GET SUMMARY BY TYPE
// ============================================================
$sql_types = "SELECT 
        transaction_type,
        COUNT(*) as count,
        SUM(amount) as total
        FROM capital_management
        WHERE transaction_date BETWEEN ? AND ?";

$params_types = [$from_date, $to_date];

if ($selected_branch > 0) {
    $sql_types .= " AND branch_id = ?";
    $params_types[] = $selected_branch;
}

$sql_types .= " GROUP BY transaction_type";

$stmt = $db->prepare($sql_types);
$stmt->execute($params_types);
$type_summaries = $stmt->fetchAll();

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
PAGE CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== DARK MODE TOGGLE ===== -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Capital Management</h2>
                <p class="text-muted">Manage and view all capital transactions</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Transaction
                </a>
                <a href="history.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Full History
                </a>
                <!-- ===== EXPORT DROPDOWN ===== -->
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" type="button" id="exportDropdown" onclick="toggleDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down" style="margin-left: 6px; font-size: 11px;"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a class="dropdown-item" href="#" onclick="exportData('csv')">
                            <i class="fas fa-file-csv" style="color: #2D9CDB;"></i> Export as CSV
                        </a>
                        <a class="dropdown-item" href="#" onclick="exportData('excel')">
                            <i class="fas fa-file-excel" style="color: #27AE60;"></i> Export as Excel
                        </a>
                        <a class="dropdown-item" href="#" onclick="exportData('pdf')">
                            <i class="fas fa-file-pdf" style="color: #E74C3C;"></i> Export as PDF
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" onclick="printData()">
                            <i class="fas fa-print" style="color: #6B7280;"></i> Print
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== CAPITAL SUMMARY CARD ===== -->
        <div class="capital-summary-card">
            <div class="capital-summary-content">
                <div class="capital-summary-item opening">
                    <span class="capital-summary-label">Opening Capital</span>
                    <span class="capital-summary-value"><?php echo formatCurrency($opening); ?></span>
                </div>
                <div class="capital-summary-item incoming">
                    <span class="capital-summary-label">Total Incoming</span>
                    <span class="capital-summary-value text-success">+ <?php echo formatCurrency($total_in); ?></span>
                </div>
                <div class="capital-summary-item outgoing">
                    <span class="capital-summary-label">Total Outgoing</span>
                    <span class="capital-summary-value text-danger">- <?php echo formatCurrency($total_out); ?></span>
                </div>
                <div class="capital-summary-item current">
                    <span class="capital-summary-label">Current Capital</span>
                    <span class="capital-summary-value current-value"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>

        <!-- ===== TYPE SUMMARY CARDS ===== -->
        <div class="type-summary-cards">
            <?php 
            $type_labels = [
                'opening' => ['label' => 'Opening', 'icon' => 'fa-play', 'color' => 'blue'],
                'additional' => ['label' => 'Additional', 'icon' => 'fa-plus-circle', 'color' => 'green'],
                'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
                'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
                'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
            ];
            
            foreach ($type_summaries as $type_summary):
                $type = $type_summary['transaction_type'];
                $info = $type_labels[$type] ?? ['label' => ucfirst($type), 'icon' => 'fa-circle', 'color' => 'gray'];
            ?>
                <div class="type-card type-<?php echo $info['color']; ?>">
                    <div class="type-icon"><i class="fas <?php echo $info['icon']; ?>"></i></div>
                    <div class="type-info">
                        <span class="type-label"><?php echo $info['label']; ?></span>
                        <span class="type-value"><?php echo formatCurrency($type_summary['total'] ?? 0); ?></span>
                        <span class="type-count"><?php echo $type_summary['count']; ?> transactions</span>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (count($type_summaries) == 0): ?>
                <div class="type-card type-gray" style="grid-column: span 5;">
                    <div class="type-info" style="text-align:center;">
                        <span class="type-label">No capital transactions found</span>
                        <span class="type-value" style="font-size:14px;color:var(--text-muted);">Start by adding a transaction</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== FILTERS ===== -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form" id="filterForm">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="form-control">
                </div>
                <?php if ($role == 'admin' || $role == 'super_admin'): ?>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <option value="opening" <?php echo $type_filter == 'opening' ? 'selected' : ''; ?>>Opening</option>
                        <option value="additional" <?php echo $type_filter == 'additional' ? 'selected' : ''; ?>>Additional</option>
                        <option value="profit_allocation" <?php echo $type_filter == 'profit_allocation' ? 'selected' : ''; ?>>Profit Allocation</option>
                        <option value="cash_out" <?php echo $type_filter == 'cash_out' ? 'selected' : ''; ?>>Cash Out</option>
                        <option value="adjustment" <?php echo $type_filter == 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ===== TRANSACTIONS TABLE ===== -->
        <div class="table-container" id="printableArea">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Capital Transactions</h4>
                <span class="record-count"><?php echo count($transactions); ?> records found</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="dataTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Capital Number</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($transactions) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php 
                                    $type_labels = [
                                        'opening' => ['label' => 'Opening', 'class' => 'type-opening'],
                                        'additional' => ['label' => 'Additional', 'class' => 'type-additional'],
                                        'profit_allocation' => ['label' => 'Profit Allocation', 'class' => 'type-profit'],
                                        'cash_out' => ['label' => 'Cash Out', 'class' => 'type-cashout'],
                                        'adjustment' => ['label' => 'Adjustment', 'class' => 'type-adjustment']
                                    ];
                                    $type_info = $type_labels[$transaction['transaction_type']] ?? ['label' => $transaction['transaction_type'], 'class' => 'type-other'];
                                    
                                    $amount_class = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'text-danger' : 'text-success';
                                    $amount_sign = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? '-' : '+';
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="capital-number"><?php echo htmlspecialchars($transaction['capital_number']); ?></span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($transaction['branch_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="type-badge <?php echo $type_info['class']; ?>">
                                            <?php echo $type_info['label']; ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo $amount_class; ?> font-bold">
                                        <?php echo $amount_sign . ' ' . formatCurrency($transaction['amount'] ?? 0); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $transaction['id']; ?>" class="btn-action view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $transaction['id']; ?>" class="btn-action edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button onclick="deleteTransaction(<?php echo $transaction['id']; ?>)" class="btn-action delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No capital transactions found for the selected filters</p>
                                    <a href="add.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus"></i> Add First Transaction
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --bg-primary: #f3f4f6;
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-card-hover: #f9fafb;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --bg-empty: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
    --dropdown-bg: #ffffff;
    --dropdown-hover: #f3f4f6;
    --card-bg: #ffffff;
}

/* Dark Mode - Full Page */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-card-hover: #334155;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --bg-empty: #1a2332;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
    --dropdown-bg: #1e293b;
    --dropdown-hover: #334155;
    --card-bg: #1e293b;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   DARK MODE TOGGLE BUTTON
   ============================================================ */
.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.dark-mode-btn:hover {
    background: var(--bg-card-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.dark-mode-btn i {
    font-size: 16px;
}

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
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.page-header .header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-primary {
    background: #bb0404;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
    color: #ffffff;
}

.btn-info {
    background: #3B82F6;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-info:hover {
    background: #2563EB;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59,130,246,0.3);
    color: #ffffff;
}

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.export-dropdown {
    position: relative;
    display: inline-block;
}

.btn-export {
    background: #10B981;
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    color: #ffffff;
}

.btn-export i {
    font-size: 14px;
}

.export-dropdown .dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--dropdown-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 10px 30px var(--shadow-hover);
    min-width: 200px;
    z-index: 1000;
    padding: 6px 0;
    overflow: hidden;
}

.export-dropdown .dropdown-menu.show {
    display: block;
    animation: slideDown 0.2s ease forwards;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.export-dropdown .dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    color: var(--text-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}

.export-dropdown .dropdown-item:hover {
    background: var(--dropdown-hover);
    color: #bb0404;
}

.export-dropdown .dropdown-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.export-dropdown .dropdown-divider {
    height: 1px;
    background: var(--border-color);
    margin: 4px 0;
}

/* ============================================================
   CAPITAL SUMMARY CARD
   ============================================================ */
.capital-summary-card {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
}

.capital-summary-content {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.capital-summary-item {
    text-align: center;
    padding: 8px 12px;
}

.capital-summary-item .capital-summary-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    letter-spacing: 1px;
    font-weight: 600;
}

.capital-summary-item .capital-summary-value {
    display: block;
    font-size: 22px;
    font-weight: 700;
    color: #ffffff;
    margin-top: 4px;
}

.capital-summary-item .capital-summary-value.text-success {
    color: #6EE7B7;
}

.capital-summary-item .capital-summary-value.text-danger {
    color: #FCA5A5;
}

.capital-summary-item.current .capital-summary-value {
    font-size: 26px;
    color: #FCD34D;
}

.capital-summary-item.opening {
    border-right: 1px solid rgba(255,255,255,0.1);
}
.capital-summary-item.incoming {
    border-right: 1px solid rgba(255,255,255,0.1);
}
.capital-summary-item.outgoing {
    border-right: 1px solid rgba(255,255,255,0.1);
}

/* ============================================================
   TYPE SUMMARY CARDS
   ============================================================ */
.type-summary-cards {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.type-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.type-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
}

.type-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.type-info {
    flex: 1;
}

.type-label {
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--text-muted);
    display: block;
}

.type-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: block;
}

.type-count {
    font-size: 10px;
    color: var(--text-light);
}

/* Type Colors */
.type-blue .type-icon { background: #DBEAFE; color: #1D4ED8; }
.type-blue { border-left: 4px solid #3B82F6; }

.type-green .type-icon { background: #D1FAE5; color: #065F46; }
.type-green { border-left: 4px solid #10B981; }

.type-purple .type-icon { background: #EDE9FE; color: #6D28D9; }
.type-purple { border-left: 4px solid #8B5CF6; }

.type-red .type-icon { background: #FEE2E2; color: #991B1B; }
.type-red { border-left: 4px solid #DC2626; }

.type-orange .type-icon { background: #FEF3C7; color: #92400E; }
.type-orange { border-left: 4px solid #F59E0B; }

.type-gray .type-icon { background: #F3F4F6; color: #6B7280; }
.type-gray { border-left: 4px solid #9CA3AF; }

/* ============================================================
   FILTERS BAR
   ============================================================ */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.filters-form {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    min-width: 150px;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.btn-filter {
    background: #bb0404;
    color: #ffffff;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #8a0303;
}

.btn-reset {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--bg-table-hover);
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.table-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.table-header h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.record-count {
    font-size: 12px;
    color: var(--text-muted);
}

.table-responsive {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

/* ============================================================
   TABLE HEADER - RED BACKGROUND
   ============================================================ */
.table thead th {
    background: #bb0404 !important;
    color: #ffffff !important;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.table thead th:first-child {
    border-radius: 6px 0 0 0;
}

.table thead th:last-child {
    border-radius: 0 6px 0 0;
}

.table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.table tbody tr:hover {
    background: var(--bg-table-hover);
}

.table tbody tr:nth-child(even) {
    background: var(--bg-table-even);
}

.table tbody tr:nth-child(even):hover {
    background: var(--bg-table-hover);
}

.table tbody .no-data {
    padding: 40px 20px;
}

.capital-number {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 12px;
}

.branch-badge {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.text-success {
    color: #10B981;
    font-weight: 600;
}

.text-danger {
    color: #DC2626;
    font-weight: 600;
}

.font-bold {
    font-weight: 700;
}

.text-muted {
    color: var(--text-muted);
}

/* ============================================================
   TYPE BADGES
   ============================================================ */
.type-badge {
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.type-opening {
    background: #DBEAFE;
    color: #1D4ED8;
}

.type-additional {
    background: #D1FAE5;
    color: #065F46;
}

.type-profit {
    background: #EDE9FE;
    color: #6D28D9;
}

.type-cashout {
    background: #FEE2E2;
    color: #991B1B;
}

.type-adjustment {
    background: #FEF3C7;
    color: #92400E;
}

.type-other {
    background: #F3F4F6;
    color: #6B7280;
}

/* ============================================================
   ACTION BUTTONS
   ============================================================ */
.action-buttons {
    display: flex;
    gap: 4px;
}

.btn-action {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
}

.btn-action.view {
    background: #DBEAFE;
    color: #1D4ED8;
}
.btn-action.view:hover {
    background: #1D4ED8;
    color: #ffffff;
}

.btn-action.edit {
    background: #D1FAE5;
    color: #065F46;
}
.btn-action.edit:hover {
    background: #065F46;
    color: #ffffff;
}

.btn-action.delete {
    background: #FEE2E2;
    color: #991B1B;
}
.btn-action.delete:hover {
    background: #991B1B;
    color: #ffffff;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .type-summary-cards {
        grid-template-columns: repeat(3, 1fr);
    }
    .capital-summary-content {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .capital-summary-item.opening,
    .capital-summary-item.incoming {
        border-right: none;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-header .header-right {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .page-header .header-right .btn,
    .page-header .header-right .export-dropdown {
        flex: 1;
        min-width: 100px;
    }
    
    .page-header .header-right .btn {
        justify-content: center;
    }
    
    .btn-export {
        width: 100%;
        justify-content: center;
    }
    
    .export-dropdown .dropdown-menu {
        right: 0;
        left: auto;
        min-width: 180px;
    }
    
    .filters-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-group .form-control {
        width: 100%;
    }
    
    .type-summary-cards {
        grid-template-columns: 1fr 1fr;
    }
    
    .capital-summary-content {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .capital-summary-item .capital-summary-value {
        font-size: 18px;
    }
    
    .capital-summary-item.current .capital-summary-value {
        font-size: 20px;
    }
    
    .table-container {
        padding: 12px 14px;
    }
    
    .table thead th,
    .table tbody td {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    .table thead th {
        font-size: 10px;
        padding: 6px 8px;
    }
}

@media (max-width: 480px) {
    .page-header .header-right .btn,
    .page-header .header-right .export-dropdown {
        flex: 1 1 100%;
    }
    
    .export-dropdown .dropdown-menu {
        left: 0;
        right: auto;
        min-width: 160px;
    }
    
    .type-summary-cards {
        grid-template-columns: 1fr;
    }
    
    .capital-summary-content {
        grid-template-columns: 1fr;
    }
    
    .capital-summary-item {
        border-right: none !important;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding: 6px 0;
    }
    
    .capital-summary-item:last-child {
        border-bottom: none;
    }
    
    .action-buttons {
        flex-wrap: wrap;
    }
    
    .table thead th {
        font-size: 9px;
        padding: 4px 6px;
    }
    
    .table tbody td {
        font-size: 10px;
        padding: 4px 6px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.capital-summary-card {
    animation: fadeInUp 0.3s ease forwards;
}

.type-card {
    animation: fadeInUp 0.3s ease forwards;
}
.type-card:nth-child(1) { animation-delay: 0.05s; }
.type-card:nth-child(2) { animation-delay: 0.10s; }
.type-card:nth-child(3) { animation-delay: 0.15s; }
.type-card:nth-child(4) { animation-delay: 0.20s; }
.type-card:nth-child(5) { animation-delay: 0.25s; }
</style>

<!-- ============================================================
JAVASCRIPT
============================================================ -->
<script>
// ============================================================
// DARK MODE TOGGLE
// ============================================================
function toggleDarkMode() {
    const body = document.body;
    const btn = document.getElementById('darkModeToggle');
    const icon = btn.querySelector('i');
    const text = btn.querySelector('span');
    
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
        icon.className = 'fas fa-sun';
        text.textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        icon.className = 'fas fa-moon';
        text.textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

// Check for saved dark mode preference
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = localStorage.getItem('darkMode');
    const btn = document.getElementById('darkModeToggle');
    const icon = btn?.querySelector('i');
    const text = btn?.querySelector('span');
    
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        if (icon) icon.className = 'fas fa-sun';
        if (text) text.textContent = 'Light Mode';
    }
});

// ============================================================
// EXPORT DROPDOWN TOGGLE
// ============================================================
function toggleDropdown() {
    var menu = document.getElementById('exportMenu');
    menu.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dropdown = document.querySelector('.export-dropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        var menu = document.getElementById('exportMenu');
        if (menu) {
            menu.classList.remove('show');
        }
    }
});

// ============================================================
// EXPORT FUNCTIONS
// ============================================================
function getFilterParams() {
    var fromDate = document.querySelector('input[name="from_date"]')?.value || '';
    var toDate = document.querySelector('input[name="to_date"]')?.value || '';
    var branch = document.querySelector('select[name="branch"]')?.value || '0';
    var type = document.querySelector('select[name="type"]')?.value || '';
    return { from_date: fromDate, to_date: toDate, branch: branch, type: type };
}

function exportData(format) {
    var params = getFilterParams();
    var url = 'export.php?format=' + format + 
              '&from_date=' + params.from_date + 
              '&to_date=' + params.to_date + 
              '&branch=' + params.branch + 
              '&type=' + params.type;
    window.location.href = url;
}

function printData() {
    var table = document.getElementById('dataTable');
    var title = 'Capital Management Transactions';
    var dateRange = document.querySelector('input[name="from_date"]')?.value + ' to ' + document.querySelector('input[name="to_date"]')?.value || '';
    
    var printWindow = window.open('', '_blank', 'width=1000,height=600');
    printWindow.document.write('<html><head><title>Capital Management</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(`
        body { font-family: Arial, sans-serif; padding: 20px; }
        h2 { color: #1E40AF; margin-bottom: 5px; }
        .subtitle { color: #6B7280; font-size: 14px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #bb0404; color: white; padding: 8px 12px; text-align: left; }
        td { padding: 8px 12px; border-bottom: 1px solid #E5E7EB; }
        tr:nth-child(even) { background: #FAFAFA; }
        .type-opening { color: #1D4ED8; }
        .type-additional { color: #065F46; }
        .type-profit { color: #6D28D9; }
        .type-cashout { color: #991B1B; }
        .type-adjustment { color: #92400E; }
        .text-success { color: #10B981; }
        .text-danger { color: #DC2626; }
        .footer { margin-top: 20px; font-size: 11px; color: #9CA3AF; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 10px; }
        .print-date { float: right; color: #6B7280; font-size: 12px; }
        .branch-badge { background: #DBEAFE; color: #1D4ED8; padding: 2px 8px; border-radius: 4px; }
        .type-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    `);
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2><i class="fas fa-building"></i> Capital Management Transactions</h2>');
    printWindow.document.write('<div class="subtitle">Date Range: ' + dateRange + '</div>');
    printWindow.document.write('<div class="print-date">Printed: ' + new Date().toLocaleString() + '</div>');
    printWindow.document.write(table.outerHTML);
    printWindow.document.write('<div class="footer">Wakala System - Capital Management</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// ============================================================
// DELETE FUNCTION
// ============================================================
function deleteTransaction(id) {
    if (confirm('Are you sure you want to delete this capital transaction? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}
</script>

</body>
</html>