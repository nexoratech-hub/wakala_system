<?php
// ================================================================
// FILE: modules/expenses/index.php
// WAKALA FINANCIAL SYSTEM - EXPENSES LIST (ADMIN)
// ✅ RED THEME ONLY (like commissions design)
// ✅ Branch filter, search, scroll, export
// ✅ Employee & admin support
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

// ============================================================
// HANDLE DELETE EXPENSE
// ============================================================
if (isset($_GET['delete_expense']) && !empty($_GET['delete_expense'])) {
    if ($role !== 'admin' && $role !== 'super_admin') {
        $_SESSION['error_message'] = 'You do not have permission to delete expenses.';
        header('Location: index.php');
        exit();
    }
    
    try {
        $delete_id = intval($_GET['delete_expense']);
        
        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
        $stmt->execute([$delete_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$expense) {
            throw new Exception('Expense not found.');
        }
        
        $delete_branch_id = intval($expense['branch_id']);
        
        $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        logActivity(
            $user_id,
            'Delete Expense',
            'Expenses',
            $delete_id,
            '',
            'Deleted expense: ' . $expense['expense_number'] . ' - ' . formatCurrency($expense['amount'])
        );
        
        $_SESSION['success_message'] = 'Expense deleted successfully!';
        header('Location: index.php' . ($delete_branch_id > 0 ? '?branch_id=' . $delete_branch_id : ''));
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        header('Location: index.php');
        exit();
    }
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

$expense_branch_name = 'All Branches';
$expense_branch_code = '';
if ($selected_branch > 0) {
    foreach ($all_branches as $b) {
        if ($b['id'] == $selected_branch) {
            $expense_branch_name = $b['branch_name'];
            $expense_branch_code = $b['branch_code'];
            break;
        }
    }
}

// ============================================================
// EMPLOYEE RESTRICTION
// ============================================================
$employee_filter = '';
$employee_params = [];
if ($role === 'employee') {
    $employee_filter = " AND e.branch_id = ? ";
    $employee_params[] = $user['branch_id'];
}

// ============================================================
// SEARCH
// ============================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_filter = '';
$search_params = [];

if (!empty($search)) {
    $search_filter = " AND (
        e.expense_number LIKE ? OR
        e.expense_name LIKE ? OR
        e.category LIKE ? OR
        e.description LIKE ? OR
        emp.full_name LIKE ?
    )";
    $search_like = '%' . $search . '%';
    $search_params = [$search_like, $search_like, $search_like, $search_like, $search_like];
}

// ============================================================
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$date_filter = '';
$date_params = [];
if (!empty($from_date)) {
    $date_filter .= " AND e.expense_date >= ? ";
    $date_params[] = $from_date;
}
if (!empty($to_date)) {
    $date_filter .= " AND e.expense_date <= ? ";
    $date_params[] = $to_date;
}

// ============================================================
// GET EXPENSES LIST
// ============================================================
$sql = "SELECT 
            e.*,
            emp.full_name as employee_name,
            emp.employee_id as employee_code,
            b.branch_name,
            b.branch_code
        FROM expenses e
        LEFT JOIN employees emp ON e.employee_id = emp.id
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE 1=1 ";

$params = [];

if ($selected_branch > 0) {
    $sql .= " AND e.branch_id = ? ";
    $params[] = $selected_branch;
}

$sql .= $employee_filter;
$params = array_merge($params, $employee_params);

$sql .= $search_filter;
$params = array_merge($params, $search_params);

$sql .= $date_filter;
$params = array_merge($params, $date_params);

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_count = count($expenses);

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_amount = 0;
$business_amount = 0;
$personal_amount = 0;

foreach ($expenses as $e) {
    $amt = floatval($e['amount']);
    $total_amount += $amt;
    if ($e['is_business_expense'] == 1) {
        $business_amount += $amt;
    } else {
        $personal_amount += $amt;
    }
}

// ============================================================
// TODAY & THIS MONTH
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

$sql_today = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE(expense_date) = ?";
$params_today = [$today];
if ($selected_branch > 0) { $sql_today .= " AND branch_id = ?"; $params_today[] = $selected_branch; }
if ($role === 'employee') { $sql_today .= " AND branch_id = ?"; $params_today[] = $user['branch_id']; }
$stmt = $db->prepare($sql_today);
$stmt->execute($params_today);
$today_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$sql_month = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$params_month = [$month, $year];
if ($selected_branch > 0) { $sql_month .= " AND branch_id = ?"; $params_month[] = $selected_branch; }
if ($role === 'employee') { $sql_month .= " AND branch_id = ?"; $params_month[] = $user['branch_id']; }
$stmt = $db->prepare($sql_month);
$stmt->execute($params_month);
$month_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ============================================================
// CATEGORY BREAKDOWN
// ============================================================
$sql_cat = "SELECT category, COALESCE(SUM(amount), 0) as total FROM expenses WHERE 1=1";
$params_cat = [];
if ($selected_branch > 0) { $sql_cat .= " AND branch_id = ?"; $params_cat[] = $selected_branch; }
if ($role === 'employee') { $sql_cat .= " AND branch_id = ?"; $params_cat[] = $user['branch_id']; }
$sql_cat .= " GROUP BY category ORDER BY total DESC LIMIT 5";
$stmt = $db->prepare($sql_cat);
$stmt->execute($params_cat);
$category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }

$branch_qs = ($selected_branch > 0) ? '?branch_id=' . $selected_branch : '';
$is_admin = ($role === 'admin' || $role === 'super_admin');

// ============================================================
// HEADER BASED ON ROLE
// ============================================================
if ($is_admin) {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
} else {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
}
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Expenses For' : 'Showing All Branches'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($expense_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($expense_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($expense_branch_code); ?></span>
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
                <h2><i class="fas fa-receipt"></i> Expenses</h2>
                <span class="record-count"><?php echo $total_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <?php if ($is_admin): ?>
                        <a href="categories.php" class="btn btn-categories">
                            <i class="fas fa-tags"></i> Categories
                        </a>
                    <?php endif; ?>
                    <a href="add.php<?php echo $branch_qs; ?>" class="btn btn-add-expense">
                        <i class="fas fa-plus-circle"></i> Add Expense
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                            <i class="fas fa-download"></i> Export
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu" id="exportDropdown">
                            <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> Export as CSV</a>
                            <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Export as Excel</a>
                            <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> Export as PDF</a>
                            <a href="#" onclick="exportData('print')"><i class="fas fa-print"></i> Print</a>
                        </div>
                    </div>
                </div>
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
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
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
                        <span class="hero-title">Expenses Overview</span>
                        <span class="hero-subtitle"><?php echo htmlspecialchars($expense_branch_name); ?></span>
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
                        <span class="hp-label">TOTAL EXPENSES</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($total_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-check-circle"></i> <?php echo $total_count; ?> records</div>
                </div>
                
                <div class="hero-part">
                    <div class="hp-header">
                        <div class="hp-icon"><i class="fas fa-briefcase"></i></div>
                        <span class="hp-label">BUSINESS EXPENSES</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($business_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-info-circle"></i> Counted in profit</div>
                </div>
                
                <div class="hero-part">
                    <div class="hp-header">
                        <div class="hp-icon"><i class="fas fa-user"></i></div>
                        <span class="hp-label">PERSONAL / OTHER</span>
                    </div>
                    <div class="hp-value"><?php echo formatCurrency($personal_amount); ?></div>
                    <div class="hp-sub"><i class="fas fa-info-circle"></i> Not in profit</div>
                </div>
            </div>
        </div>

        <!-- QUICK STATS -->
        <div class="quick-stats-row">
            <div class="quick-stat-item">
                <span class="quick-stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">TODAY</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_total); ?></span>
                </div>
            </div>
            <div class="quick-stat-item">
                <span class="quick-stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">THIS MONTH</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($month_total); ?></span>
                </div>
            </div>
        </div>

        <!-- CATEGORY BREAKDOWN -->
        <?php if (count($category_breakdown) > 0): ?>
            <div class="category-section">
                <div class="category-section-header">
                    <h3><i class="fas fa-tags"></i> Top Categories</h3>
                </div>
                <div class="category-chips">
                    <?php foreach ($category_breakdown as $cat): ?>
                        <div class="category-chip">
                            <span class="cc-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                            <span class="cc-amount"><?php echo formatCurrency($cat['total']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABLE CONTAINER -->
        <div class="table-container">
            
            <!-- RED HEADER with Search + Scroll + Count -->
            <div class="table-header-red-with-controls">
                <div class="thrc-left">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="expenseSearchInput" 
                               placeholder="Search expense..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               oninput="filterExpenses(this)">
                        <button type="button" id="expenseSearchClear" onclick="clearExpenseSearch()" style="display:<?php echo !empty($search) ? 'flex' : 'none'; ?>;">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count" id="expenseSearchCount" style="display:none;">0</span>
                    </div>
                </div>
                
                <div class="thrc-center">
                    <button type="button" class="scroll-btn" onclick="scrollExpenseTable('left')" title="Scroll Left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="scroll-label">
                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                    </span>
                    <button type="button" class="scroll-btn" onclick="scrollExpenseTable('right')" title="Scroll Right">
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

            <?php if (count($expenses) > 0): ?>
                <div class="table-responsive" id="expenseTableWrapper">
                    <table class="data-table" id="expensesTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Expense No.</th>
                                <th>Date</th>
                                <th>Expense Name</th>
                                <th>Category</th>
                                <th>Branch</th>
                                <th>Employee</th>
                                <th class="text-right">Amount</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($expenses as $exp): 
                                $search_text = strtolower(
                                    $exp['expense_number'] . ' ' .
                                    $exp['expense_name'] . ' ' .
                                    $exp['category'] . ' ' .
                                    ($exp['description'] ?? '') . ' ' .
                                    ($exp['employee_name'] ?? '') . ' ' .
                                    ($exp['branch_name'] ?? '') . ' ' .
                                    $exp['expense_date']
                                );
                            ?>
                                <tr class="expense-row-item" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td>
                                        <span class="row-number"><?php echo $counter++; ?></span>
                                    </td>
                                    <td>
                                        <span class="reference-badge">
                                            <i class="fas fa-hashtag"></i>
                                            <?php echo htmlspecialchars($exp['expense_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="date-cell">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date('d M Y', strtotime($exp['expense_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="expense-name-cell"><?php echo htmlspecialchars($exp['expense_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="category-badge">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars($exp['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="branch-badge-cell">
                                            <i class="fas fa-store-alt"></i>
                                            <?php echo htmlspecialchars($exp['branch_name'] ?? 'Main'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-badge">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars($exp['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge">
                                            <i class="fas fa-arrow-down"></i>
                                            <?php echo formatCurrency($exp['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $exp['id']; ?>" 
                                               class="btn-action btn-view" 
                                               title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $exp['id']; ?>" 
                                               class="btn-action btn-edit" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($is_admin): ?>
                                                <a href="index.php?delete_expense=<?php echo $exp['id']; ?>" 
                                                   class="btn-action btn-delete" 
                                                   onclick="return confirmDeleteExpense('<?php echo addslashes($exp['expense_number']); ?>', '<?php echo formatCurrency($exp['amount']); ?>')"
                                                   title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
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
                    <p>Start by adding your first expense.</p>
                    <a href="add.php<?php echo $branch_qs; ?>" class="btn btn-add-expense">
                        <i class="fas fa-plus-circle"></i> Add Expense
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php 
    if ($is_admin) {
        include_once '../../includes/admin_footer.php';
    } else {
        include_once '../../includes/employee_footer.php';
    }
    ?>
</div>

<style>
/* ============================================================
   RED THEME ONLY - EXPENSES MODULE
   ============================================================ */
:root {
    --exp-bg: #f3f4f6;
    --exp-card-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
    --exp-shadow-lg: rgba(0,0,0,0.12);
    --exp-dropdown-bg: #FFFFFF;
    --exp-dropdown-border: #E5E7EB;
    
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
    --red-light: #FEE2E2;
    --red-lighter: #FECACA;
    --red-text: #991B1B;
}

html.dark-mode {
    --exp-bg: #0f172a;
    --exp-card-bg: #1E293B;
    --exp-text: #F1F5F9;
    --exp-text-secondary: #94A3B8;
    --exp-text-light: #64748B;
    --exp-border: #334155;
    --exp-hover: #2D3A4F;
    --exp-shadow: rgba(0,0,0,0.3);
    --exp-shadow-lg: rgba(0,0,0,0.5);
    --exp-dropdown-bg: #1E293B;
    --exp-dropdown-border: #334155;
    
    --red-light: #7F1D1D;
    --red-lighter: #991B1B;
    --red-text: #FCA5A5;
}

* { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}
body {
    background: var(--exp-bg) !important;
    color: var(--exp-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper {
    background: var(--exp-bg) !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.main-content {
    background: var(--exp-bg) !important;
    width: 100% !important;
    max-width: 100% !important;
    padding: 16px 20px !important;
    margin: 0 !important;
    overflow-x: hidden !important;
}

/* RED BRANCH CARD */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
    color: #FFFFFF;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF;
    flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1;
    position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name {
    font-size: 18px; font-weight: 700;
    color: #FFFFFF; letter-spacing: 0.3px;
}
.branch-status-code {
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.branch-status-date {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: rgba(255, 255, 255, 0.9);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    flex-shrink: 0;
    position: relative; z-index: 1;
}

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding: 0 4px;
    flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 10px; }
.page-header-left h2 {
    font-size: 18px; font-weight: 700;
    color: var(--exp-text); margin: 0;
}
.page-header-left h2 i { color: var(--red-primary); margin-right: 6px; }
.record-count {
    font-size: 12px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 2px 10px; border-radius: 12px;
}
.header-actions {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}

/* BUTTONS */
.btn-add-expense {
    background: #DC2626; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
}
.btn-add-expense:hover {
    background: #B91C1C; transform: translateY(-1px);
    color: white;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}
.btn-categories {
    background: #FEE2E2; color: #991B1B;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease;
    border: 1px solid #FECACA;
    white-space: nowrap;
}
.btn-categories:hover {
    background: #FECACA; color: #7F1D1D;
    transform: translateY(-1px);
}
html.dark-mode .btn-categories {
    background: #7F1D1D; color: #FCA5A5;
    border-color: #991B1B;
}
.btn-export {
    background: #DC2626; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-export:hover { background: #B91C1C; transform: translateY(-1px); }
.btn-reset {
    background: var(--exp-hover); color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
    padding: 8px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    cursor: pointer; transition: all 0.3s ease;
    display: inline-flex; align-items: center; gap: 6px;
}

/* DROPDOWN */
.dropdown { position: relative; display: inline-block; }
.dropdown-toggle i.fa-chevron-down { font-size: 10px; margin-left: 2px; }
.dropdown-menu {
    display: none; position: absolute; right: 0; top: 100%;
    margin-top: 4px;
    background: var(--exp-dropdown-bg);
    min-width: 180px; border-radius: 8px;
    box-shadow: 0 4px 20px var(--exp-shadow-lg);
    border: 1px solid var(--exp-dropdown-border);
    z-index: 1000; overflow: hidden; padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 14px; text-decoration: none;
    color: var(--exp-text); font-size: 12px;
    font-weight: 500; transition: background 0.2s ease;
    white-space: nowrap;
}
.dropdown-menu a:hover { background: var(--exp-hover); }
.dropdown-menu a i { width: 16px; font-size: 13px; color: var(--red-primary); }

/* ALERTS */
.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 14px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 20px;
    color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6;
}

/* RED HERO CARD */
.expense-hero-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 16px;
    padding: 22px 26px;
    margin-bottom: 16px;
    box-shadow: 0 8px 28px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}
.expense-hero-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.hero-header {
    display: flex; justify-content: space-between; align-items: center;
    gap: 16px; margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    position: relative; z-index: 1;
    flex-wrap: wrap;
}
.hero-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.hero-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
}
.hero-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.hero-title { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.hero-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
}
.hero-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    position: relative; z-index: 1;
}
.hero-part {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex; flex-direction: column; gap: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0;
}
.hero-part:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-3px);
}
.hp-header { display: flex; align-items: center; gap: 10px; }
.hp-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF;
    flex-shrink: 0;
}
.hp-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.hp-value {
    font-size: clamp(18px, 1.6vw, 26px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
    color: #FCD34D;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.hp-sub {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    display: inline-flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.4px;
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

/* QUICK STATS */
.quick-stats-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
}
.quick-stat-item {
    background: var(--exp-card-bg);
    border-radius: 12px; padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    min-width: 0;
}
.quick-stat-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
html.dark-mode .quick-stat-icon {
    background: #7F1D1D; color: #FCA5A5;
}
.quick-stat-info {
    display: flex; flex-direction: column;
    gap: 3px; min-width: 0; flex: 1;
}
.quick-stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.5px; font-weight: 700;
    color: var(--exp-text-secondary);
}
.quick-stat-value {
    font-size: clamp(13px, 1vw, 16px);
    font-weight: 700; color: var(--exp-text);
    word-break: break-all;
}

/* CATEGORY CHIPS */
.category-section {
    background: var(--exp-card-bg);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 14px;
    border: 1px solid var(--exp-border);
    box-shadow: 0 1px 3px var(--exp-shadow);
}
.category-section-header {
    margin-bottom: 12px;
}
.category-section-header h3 {
    font-size: 13px; font-weight: 700;
    color: var(--exp-text);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.category-section-header h3 i {
    color: #DC2626;
    margin-right: 6px;
}
.category-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.category-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    background: #FEE2E2;
    border: 1.5px solid #FECACA;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #991B1B;
    transition: all 0.2s ease;
}
.category-chip:hover {
    background: #FECACA;
    transform: translateY(-1px);
}
html.dark-mode .category-chip {
    background: #7F1D1D;
    border-color: #991B1B;
    color: #FCA5A5;
}
.cc-name {
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 11px;
}
.cc-amount {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #DC2626;
    padding-left: 8px;
    border-left: 1px solid #FECACA;
}
html.dark-mode .cc-amount {
    color: #FCD34D;
    border-left-color: #991B1B;
}

/* TABLE */
.table-container {
    background: var(--exp-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}
.table-header-red-with-controls {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.table-header-red-with-controls::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.thrc-left { display: flex; align-items: center; justify-content: flex-start; position: relative; z-index: 1; }
.thrc-center { display: flex; align-items: center; justify-content: center; gap: 12px; position: relative; z-index: 1; }
.thrc-right { display: flex; align-items: center; justify-content: flex-end; position: relative; z-index: 1; }

.search-wrapper {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 12px;
    width: 300px; max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.search-wrapper i { color: #DC2626; font-size: 12px; flex-shrink: 0; }
.search-wrapper input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 12px;
    color: #1F2937; outline: none;
    min-width: 0; font-family: 'Inter', sans-serif;
}
.search-wrapper input::placeholder { color: #9CA3AF; font-size: 11px; }
.search-wrapper button {
    width: 20px; height: 20px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
}
.search-wrapper button:hover { background: #DC2626; color: white; }
.search-count {
    font-size: 10px; font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D; color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
}

.scroll-btn {
    width: 40px; height: 40px; border-radius: 10px;
    border: 2px solid #FFFFFF; background: #FFFFFF;
    color: #DC2626; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
}
.scroll-label {
    font-size: 11px; font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
.record-count-red {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px; border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.record-count-red i { font-size: 11px; color: #FCD34D; }

.table-responsive {
    overflow-x: auto;
    width: 100%; max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track { background: var(--exp-hover); }
.table-responsive::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.data-table {
    width: 100%; border-collapse: collapse;
    font-size: 12px;
}
.data-table thead { background: #DC2626; }
.data-table thead th {
    padding: 11px 14px; text-align: left;
    font-weight: 600; color: #FFFFFF;
    text-transform: uppercase; font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
}
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--exp-border);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--exp-hover); }
.data-table tbody td {
    padding: 11px 14px;
    color: var(--exp-text);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }
.expense-row-item.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--exp-hover);
    font-size: 11px; font-weight: 700;
    color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
}
.reference-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: #FEE2E2;
    color: #991B1B;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FECACA;
    white-space: nowrap;
}
html.dark-mode .reference-badge {
    background: #7F1D1D; color: #FCA5A5; border-color: #991B1B;
}
.date-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--exp-text-secondary);
    white-space: nowrap;
}
.date-cell i { color: #DC2626; font-size: 10px; }
.expense-name-cell {
    font-weight: 700;
    color: var(--exp-text);
    font-size: 12px;
}
.category-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: #FEE2E2;
    color: #991B1B;
    border-radius: 8px;
    font-size: 11px; font-weight: 700;
    border: 1px solid #FECACA;
    white-space: nowrap;
}
html.dark-mode .category-badge {
    background: #7F1D1D; color: #FCA5A5; border-color: #991B1B;
}
.branch-badge-cell {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: #FEF3C7;
    color: #92400E;
    border-radius: 8px;
    font-size: 11px; font-weight: 700;
    border: 1px solid #FDE68A;
    white-space: nowrap;
}
html.dark-mode .branch-badge-cell {
    background: #5F3A1E; color: #FCD34D; border-color: #F59E0B;
}
.employee-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    background: #F3F4F6;
    color: #374151;
    border-radius: 8px;
    font-size: 11px; font-weight: 600;
    border: 1px solid #E5E7EB;
    white-space: nowrap;
}
html.dark-mode .employee-badge {
    background: #334155; color: #CBD5E1; border-color: #475569;
}
.amount-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px;
    background: #FEE2E2;
    color: #991B1B;
    border-radius: 8px;
    font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FECACA;
    white-space: nowrap;
}
html.dark-mode .amount-badge {
    background: #7F1D1D; color: #FCA5A5; border-color: #991B1B;
}
.amount-badge i { font-size: 10px; }

.action-buttons {
    display: flex; gap: 5px;
    justify-content: center; align-items: center;
}
.btn-action {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: none;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.25s ease;
    text-decoration: none; font-size: 13px;
}
.btn-view {
    background: #FEE2E2; color: #DC2626;
    border: 1.5px solid #FECACA;
}
.btn-view:hover {
    background: #DC2626; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
}
.btn-edit {
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FCD34D;
}
.btn-edit:hover {
    background: #D97706; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
}
.btn-delete {
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
.btn-delete:hover {
    background: #991B1B; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
}
html.dark-mode .btn-view { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
html.dark-mode .btn-edit { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .btn-delete { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

/* NO RESULTS / EMPTY STATE */
.no-results {
    text-align: center; padding: 40px 20px;
    background: var(--exp-hover);
}
.no-results i {
    font-size: 44px; color: var(--exp-text-light);
    opacity: 0.4; display: block; margin-bottom: 12px;
}
.no-results p {
    font-size: 14px; color: var(--exp-text-secondary);
    margin: 0 0 16px 0;
}
.empty-state {
    text-align: center; padding: 50px 20px;
}
.empty-state i {
    font-size: 50px; color: #DC2626;
    opacity: 0.4; display: block; margin-bottom: 14px;
}
.empty-state h3 {
    font-size: 18px; color: var(--exp-text);
    margin: 0 0 6px 0;
}
.empty-state p {
    color: var(--exp-text-secondary);
    font-size: 13px; margin: 0 0 20px 0;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .hero-grid { grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .hp-value { font-size: clamp(16px, 1.6vw, 20px); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .branch-status-info { width: 100%; }
    .branch-status-date { align-self: flex-start; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-actions { width: 100%; flex-direction: column; }
    .header-actions .btn-add-expense,
    .header-actions .btn-categories,
    .header-actions .btn-export { justify-content: center; width: 100%; }
    .dropdown { width: 100%; }
    .dropdown-menu { width: 100%; right: auto; left: 0; }
    .hero-grid { grid-template-columns: 1fr; gap: 10px; }
    .quick-stats-row { grid-template-columns: 1fr; gap: 10px; }
    .table-header-red-with-controls { grid-template-columns: 1fr; gap: 12px; }
    .thrc-left, .thrc-center, .thrc-right { justify-content: center; width: 100%; }
    .search-wrapper { width: 100%; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-card { flex-direction: column; text-align: center; }
    .branch-status-info { justify-content: center; }
    .hp-value { font-size: 16px; }
    .data-table thead th,
    .data-table tbody td { padding: 8px 10px; font-size: 11px; }
}
</style>

<script>
function toggleDropdown() {
    var d = document.getElementById('exportDropdown');
    d.classList.toggle('show');
}
document.addEventListener('click', function(event) {
    var d = document.getElementById('exportDropdown');
    var b = document.querySelector('.dropdown-toggle');
    if (b && !b.contains(event.target) && !d.contains(event.target)) {
        d.classList.remove('show');
    }
});

function exportData(format) {
    document.getElementById('exportDropdown').classList.remove('show');
    var params = new URLSearchParams();
    params.set('format', format);
    var sb = '<?php echo $selected_branch; ?>';
    if (sb && sb !== '0') params.set('branch_id', sb);
    var si = document.getElementById('expenseSearchInput');
    if (si && si.value.trim()) params.set('search', si.value.trim());
    window.location.href = 'export.php?' + params.toString();
}

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
        const searchData = row.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
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
    wrapper.scrollBy({
        left: dir === 'left' ? -400 : 400,
        behavior: 'smooth'
    });
}

function confirmDeleteExpense(ref, amount) {
    return confirm('Are you sure you want to DELETE this expense?\n\nReference: ' + ref + '\nAmount: ' + amount + '\n\nThis action cannot be undone.');
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
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const si = document.getElementById('expenseSearchInput');
            if (si && si.value.length > 0 && document.activeElement === si) clearExpenseSearch();
        }
    });
});
</script>
</body>
</html>