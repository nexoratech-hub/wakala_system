<?php
// ================================================================
// FILE: modules/expenses/index_employee.php
// WAKALA FINANCIAL SYSTEM - EXPENSES (EMPLOYEE)
// ✅ RED theme only
// ✅ Employee sees ONLY THEIR OWN expenses
// ✅ NEW: Time Filter (Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom)
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'employee') {
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = intval($employee['branch_id'] ?? 0);
$branch_name = 'My Branch';
$branch_code = '';
$branch_location = '';

if ($employee_branch_id > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$employee_branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// ============================================================
// ✅ TIME FILTER LOGIC
// ============================================================
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$custom_from = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$custom_to = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$today = date('Y-m-d');

switch ($filter) {
    case 'all':
        $from_date = '';
        $to_date = '';
        break;
    case 'today':
        $from_date = $today;
        $to_date = $today;
        break;
    case '1d':
        $from_date = date('Y-m-d', strtotime('-1 day'));
        $to_date = $today;
        break;
    case '1w':
        $from_date = date('Y-m-d', strtotime('-7 days'));
        $to_date = $today;
        break;
    case '1m':
        $from_date = date('Y-m-d', strtotime('-1 month'));
        $to_date = $today;
        break;
    case '3m':
        $from_date = date('Y-m-d', strtotime('-3 months'));
        $to_date = $today;
        break;
    case '6m':
        $from_date = date('Y-m-d', strtotime('-6 months'));
        $to_date = $today;
        break;
    case '1y':
        $from_date = date('Y-m-d', strtotime('-1 year'));
        $to_date = $today;
        break;
    case 'custom':
        $from_date = !empty($custom_from) ? $custom_from : date('Y-m-01');
        $to_date = !empty($custom_to) ? $custom_to : $today;
        break;
    default:
        $from_date = '';
        $to_date = '';
}

// ============================================================
// GET MY EXPENSES (with time filter)
// ============================================================
$sql = "
    SELECT e.*, b.branch_name, b.branch_code
    FROM expenses e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.employee_id = ?
";

$params = [$user_id];

if (!empty($from_date)) {
    $sql .= " AND e.expense_date >= ?";
    $params[] = $from_date;
}
if (!empty($to_date)) {
    $sql .= " AND e.expense_date <= ?";
    $params[] = $to_date;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_count = count($expenses);
$total_amount = 0;
$business_amount = 0;
$personal_amount = 0;

foreach ($expenses as $e) {
    $amt = floatval($e['amount']);
    $total_amount += $amt;
    if ($e['is_business_expense'] == 1) $business_amount += $amt;
    else $personal_amount += $amt;
}

// ============================================================
// TODAY & MONTH (always all-time for reference)
// ============================================================
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE employee_id = ? AND DATE(expense_date) = ?");
$stmt->execute([$user_id, date('Y-m-d')]);
$today_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE employee_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$user_id, date('m'), date('Y')]);
$month_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">My Branch</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php if ($branch_code): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($branch_code); ?></span>
                <?php endif; ?>
                <?php if ($branch_location): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-status-date">
                <i class="far fa-calendar-alt"></i>
                <?php echo date('d M Y'); ?>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-receipt"></i> My Expenses</h2>
                <span class="record-count"><?php echo $total_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <a href="add_employee.php" class="btn btn-add-expense">
                    <i class="fas fa-plus-circle"></i> Add Expense
                </a>
            </div>
        </div>

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
             ✅ TIME FILTER BAR
             ============================================================ -->
        <div class="time-filter-bar">
            <div class="time-filter-left">
                <i class="fas fa-calendar-alt"></i>
                <span class="time-filter-label">Period:</span>
            </div>
            <div class="time-filter-buttons">
                <a href="?filter=all" 
                   class="time-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    All
                </a>
                <a href="?filter=today" 
                   class="time-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">
                    Today
                </a>
                <a href="?filter=1d" 
                   class="time-btn <?php echo $filter === '1d' ? 'active' : ''; ?>">
                    1D
                </a>
                <a href="?filter=1w" 
                   class="time-btn <?php echo $filter === '1w' ? 'active' : ''; ?>">
                    1W
                </a>
                <a href="?filter=1m" 
                   class="time-btn <?php echo $filter === '1m' ? 'active' : ''; ?>">
                    1M
                </a>
                <a href="?filter=3m" 
                   class="time-btn <?php echo $filter === '3m' ? 'active' : ''; ?>">
                    3M
                </a>
                <a href="?filter=6m" 
                   class="time-btn <?php echo $filter === '6m' ? 'active' : ''; ?>">
                    6M
                </a>
                <a href="?filter=1y" 
                   class="time-btn <?php echo $filter === '1y' ? 'active' : ''; ?>">
                    1Y
                </a>
                <a href="?filter=custom&from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-d'); ?>" 
                   class="time-btn time-btn-custom <?php echo $filter === 'custom' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i> Custom
                </a>
            </div>
        </div>

        <!-- ✅ CUSTOM DATE FILTER (only shown when filter=custom) -->
        <?php if ($filter === 'custom'): ?>
        <div class="custom-date-filter">
            <form method="GET" action="" class="custom-date-form">
                <input type="hidden" name="filter" value="custom">
                
                <div class="cdf-item">
                    <label><i class="fas fa-calendar-day"></i> From</label>
                    <input type="date" name="from_date" class="cdf-input" 
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                
                <div class="cdf-item">
                    <label><i class="fas fa-calendar-day"></i> To</label>
                    <input type="date" name="to_date" class="cdf-input" 
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                
                <div class="cdf-actions">
                    <button type="submit" class="cdf-btn cdf-btn-apply">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="?filter=all" class="cdf-btn cdf-btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- RED HERO CARD -->
        <div class="expense-hero-card">
            <div class="hero-header">
                <div class="hero-left">
                    <div class="hero-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="hero-info">
                        <span class="hero-title">My Expenses</span>
                        <span class="hero-subtitle">
                            <?php echo htmlspecialchars($employee['full_name']); ?>
                            <?php 
                            $period_labels = [
                                'all' => 'All Time',
                                'today' => 'Today',
                                '1d' => 'Last 1 Day',
                                '1w' => 'Last 1 Week',
                                '1m' => 'Last 1 Month',
                                '3m' => 'Last 3 Months',
                                '6m' => 'Last 6 Months',
                                '1y' => 'Last 1 Year',
                                'custom' => 'Custom Period'
                            ];
                            echo ' • ' . ($period_labels[$filter] ?? 'All Time');
                            ?>
                        </span>
                    </div>
                </div>
                <div class="hero-badge">
                    <i class="fas fa-coins"></i> Total: <?php echo formatCurrency($total_amount); ?>
                </div>
            </div>
            
            <div class="hero-grid">
                <div class="hero-part">
                    <div class="hp-header">
                        <div class="hp-icon"><i class="fas fa-list"></i></div>
                        <span class="hp-label">TOTAL</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($total_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-check-circle"></i> <?php echo $total_count; ?> records</div>
                </div>
                
                <div class="hero-part">
                    <div class="hp-header">
                        <div class="hp-icon"><i class="fas fa-briefcase"></i></div>
                        <span class="hp-label">BUSINESS</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($business_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-info-circle"></i> Counted in profit</div>
                </div>
                
                <div class="hero-part">
                    <div class="hp-header">
                        <div class="hp-icon"><i class="fas fa-user"></i></div>
                        <span class="hp-label">PERSONAL</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($personal_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-info-circle"></i> Not in profit</div>
                </div>
            </div>
        </div>

        <!-- QUICK STATS -->
        <div class="quick-stats-row">
            <div class="quick-stat-item">
                <span class="quick-stat-icon"><i class="fas fa-calendar-day"></i></span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">TODAY</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_total); ?></span>
                </div>
            </div>
            <div class="quick-stat-item">
                <span class="quick-stat-icon"><i class="fas fa-calendar-alt"></i></span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">THIS MONTH</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($month_total); ?></span>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header-red-with-controls">
                <div class="thrc-left">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="expenseSearchInput" placeholder="Search..." oninput="filterExpenses(this)">
                        <button type="button" id="expenseSearchClear" onclick="clearExpenseSearch()" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count" id="expenseSearchCount" style="display:none;">0</span>
                    </div>
                </div>
                <div class="thrc-center">
                    <button type="button" class="scroll-btn" onclick="scrollExpenseTable('left')">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="scroll-label"><i class="fas fa-arrows-alt-h"></i> SCROLL</span>
                    <button type="button" class="scroll-btn" onclick="scrollExpenseTable('right')">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="thrc-right">
                    <span class="record-count-red">
                        <i class="fas fa-receipt"></i>
                        <?php echo $total_count; ?> records
                    </span>
                </div>
            </div>

            <?php if ($total_count > 0): ?>
                <div class="table-responsive" id="expenseTableWrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Expense No.</th>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th class="text-right">Amount</th>
                                <th>Type</th>
                                <th style="width:80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($expenses as $exp): 
                                $search_text = strtolower(
                                    $exp['expense_number'] . ' ' .
                                    $exp['expense_name'] . ' ' .
                                    $exp['category'] . ' ' .
                                    ($exp['description'] ?? '') . ' ' .
                                    $exp['expense_date']
                                );
                            ?>
                                <tr class="expense-row-item" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><span class="row-number"><?php echo $counter++; ?></span></td>
                                    <td><span class="reference-badge"><i class="fas fa-hashtag"></i><?php echo htmlspecialchars($exp['expense_number']); ?></span></td>
                                    <td><span class="date-cell"><i class="far fa-calendar"></i><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></span></td>
                                    <td><span class="expense-name-cell"><?php echo htmlspecialchars($exp['expense_name']); ?></span></td>
                                    <td><span class="category-badge"><i class="fas fa-tag"></i><?php echo htmlspecialchars($exp['category']); ?></span></td>
                                    <td class="text-right"><span class="amount-badge"><i class="fas fa-arrow-down"></i><?php echo formatCurrency($exp['amount']); ?></span></td>
                                    <td>
                                        <span class="type-badge-cell <?php echo $exp['is_business_expense'] == 1 ? 'business' : 'personal'; ?>">
                                            <i class="fas <?php echo $exp['is_business_expense'] == 1 ? 'fa-briefcase' : 'fa-user'; ?>"></i>
                                            <?php echo $exp['is_business_expense'] == 1 ? 'Business' : 'Personal'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $exp['id']; ?>" class="btn-action btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No expenses match your search</p>
                    <button type="button" class="btn btn-reset" onclick="clearExpenseSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No Expenses Found</h3>
                    <p>
                        <?php if ($filter !== 'all'): ?>
                            No expenses in this period. Try changing the filter.
                        <?php else: ?>
                            You haven't added any expense yet.
                        <?php endif; ?>
                    </p>
                    <a href="add_employee.php" class="btn btn-add-expense">
                        <i class="fas fa-plus-circle"></i> Add Expense
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
<?php include __DIR__ . '/_styles_employee.php'; ?>

/* ============================================================
   ✅ TIME FILTER BAR
   ============================================================ */
.time-filter-bar {
    background: var(--exp-card-bg);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    border: 1.5px solid var(--exp-border);
    box-shadow: 0 2px 8px var(--exp-shadow);
}

.time-filter-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--exp-text-secondary);
    text-transform: uppercase;
    letter-spacing: 1px;
    flex-shrink: 0;
}

.time-filter-left i {
    color: #DC2626;
    font-size: 14px;
}

.time-filter-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    flex: 1;
}

.time-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--exp-hover);
    color: var(--exp-text-secondary);
    border: 1.5px solid var(--exp-border);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.time-btn:hover {
    background: var(--exp-card-bg);
    border-color: #DC2626;
    color: #DC2626;
    transform: translateY(-1px);
}

.time-btn.active {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-color: #B91C1C;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
    transform: translateY(-1px);
}

.time-btn-custom {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    border-color: #6D28D9;
}

.time-btn-custom:hover {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    color: #FFFFFF;
    border-color: #5B21B6;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}

.time-btn-custom.active {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    border-color: #5B21B6;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.45);
}

/* ============================================================
   ✅ CUSTOM DATE FILTER
   ============================================================ */
.custom-date-filter {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border: 2px solid #A78BFA;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 14px;
    animation: slideDown 0.3s ease forwards;
}

html.dark-mode .custom-date-filter {
    background: linear-gradient(135deg, #2D1B5F 0%, #1E1B4B 100%);
    border-color: #7C3AED;
}

.custom-date-form {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.cdf-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
    flex: 1;
}

.cdf-item label {
    font-size: 11px;
    font-weight: 800;
    color: #5B21B6;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

html.dark-mode .cdf-item label {
    color: #C4B5FD;
}

.cdf-item label i {
    font-size: 11px;
}

.cdf-input {
    padding: 11px 14px;
    border: 1.5px solid #A78BFA;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--exp-text);
    background: #FFFFFF;
    font-family: 'Inter', sans-serif;
    transition: all 0.25s ease;
    width: 100%;
}

html.dark-mode .cdf-input {
    background: #1E293B;
    border-color: #7C3AED;
    color: #F1F5F9;
}

.cdf-input:focus {
    outline: none;
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
}

.cdf-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    flex-shrink: 0;
}

.cdf-btn {
    padding: 11px 22px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: none;
}

.cdf-btn-apply {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}

.cdf-btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
    color: #FFFFFF;
}

.cdf-btn-reset {
    background: var(--exp-card-bg);
    color: var(--exp-text-secondary);
    border: 1.5px solid var(--exp-border);
}

.cdf-btn-reset:hover {
    background: var(--exp-hover);
    color: var(--exp-text);
    transform: translateY(-2px);
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   RESPONSIVE - TIME FILTER
   ============================================================ */
@media (max-width: 1024px) {
    .time-filter-bar { flex-direction: column; align-items: stretch; gap: 10px; }
    .time-filter-left { justify-content: center; }
    .time-filter-buttons { justify-content: center; }
    .time-btn { flex: 1; min-width: 60px; }
    .custom-date-form { flex-direction: column; }
    .cdf-item { min-width: 100%; }
    .cdf-actions { width: 100%; }
    .cdf-btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .time-btn { font-size: 10px; padding: 6px 10px; }
}
</style>

<script>
function filterExpenses(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.expense-row-item');
    const clearBtn = document.getElementById('expenseSearchClear');
    const countBadge = document.getElementById('expenseSearchCount');
    const noResults = document.getElementById('noResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        let idx = 1;
        rows.forEach(row => {
            const nc = row.querySelector('.row-number');
            if (nc) nc.textContent = idx++;
        });
        return;
    }
    
    let matchCount = 0;
    rows.forEach(row => {
        const sd = row.getAttribute('data-search') || '';
        if (sd.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    let vIdx = 1;
    rows.forEach(row => {
        if (!row.classList.contains('hidden-by-search')) {
            const nc = row.querySelector('.row-number');
            if (nc) nc.textContent = vIdx++;
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
}

function clearExpenseSearch() {
    const input = document.getElementById('expenseSearchInput');
    if (input) {
        input.value = '';
        filterExpenses(input);
        input.focus();
    }
}

function scrollExpenseTable(dir) {
    const wrapper = document.getElementById('expenseTableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({ left: dir === 'left' ? -400 : 400, behavior: 'smooth' });
}

document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var sa = document.querySelector('.alert-success');
    if (sa) setTimeout(function() { sa.style.display = 'none'; }, 5000);
    var ea = document.querySelector('.alert-danger');
    if (ea) setTimeout(function() { ea.style.display = 'none'; }, 8000);
});
</script>
</body>
</html>