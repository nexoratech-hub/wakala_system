<?php
// ================================================================
// FILE: modules/reports/expense.php
// WAKALA FINANCIAL SYSTEM - EXPENSE REPORT
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
// GET FILTER PARAMETERS
// ============================================================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';

// ============================================================
// GET EXPENSES
// ============================================================
$sql = "SELECT 
            e.*,
            emp.full_name as employee_name,
            ec.category_name
        FROM expenses e
        LEFT JOIN employees emp ON e.employee_id = emp.id
        LEFT JOIN expense_categories ec ON e.category = ec.category_name
        WHERE e.expense_date BETWEEN ? AND ?
        AND e.is_business_expense = 1";

$params = [$start_date, $end_date];

if (!empty($category)) {
    $sql .= " AND e.category = ?";
    $params[] = $category;
}

if ($role === 'employee') {
    $sql .= " AND e.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY e.expense_date DESC, e.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_expenses = 0;
$category_totals = [];

foreach ($expenses as $exp) {
    $amount = floatval($exp['amount'] ?? 0);
    $total_expenses += $amount;
    
    $cat = $exp['category'] ?? 'Other';
    if (!isset($category_totals[$cat])) {
        $category_totals[$cat] = 0;
    }
    $category_totals[$cat] += $amount;
}

// Sort category totals
arsort($category_totals);

// ============================================================
// GET CATEGORIES FOR FILTER
// ============================================================
$cat_stmt = $db->query("SELECT category_name FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $cat_stmt->fetchAll();

// ============================================================
// INCLUDE HEADER
// ============================================================
if ($role === 'employee') {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
} else {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
}
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-receipt"></i> Expense Report</h2>
                <span class="record-count"><?php echo count($expenses); ?> records</span>
            </div>
            <div class="page-header-right">
                <a href="export.php?type=expense&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Export
                </a>
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- ============================================================
        FILTER FORM
        ============================================================ -->
        <div class="filter-container">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <div class="filter-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['category_name']); ?>" 
                                <?php echo $category == $cat['category_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <a href="expense.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - 3 CARDS
        ============================================================ -->
        <div class="summaries-grid-three">
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Expenses</div>
                    <div class="summary-value"><?php echo count($expenses); ?></div>
                    <div class="summary-sub">Records</div>
                </div>
            </div>

            <div class="summary-card card-amount">
                <div class="summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Amount</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_expenses); ?></div>
                    <div class="summary-sub">All Expenses</div>
                </div>
            </div>

            <div class="summary-card card-categories">
                <div class="summary-icon"><i class="fas fa-tags"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Categories</div>
                    <div class="summary-value"><?php echo count($category_totals); ?></div>
                    <div class="summary-sub">Expense Categories</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        CATEGORY BREAKDOWN
        ============================================================ -->
        <?php if (!empty($category_totals)): ?>
        <div class="category-breakdown">
            <h4><i class="fas fa-chart-pie"></i> Category Breakdown</h4>
            <div class="category-list">
                <?php foreach ($category_totals as $cat_name => $cat_total): 
                    $percentage = $total_expenses > 0 ? round(($cat_total / $total_expenses) * 100) : 0;
                ?>
                    <div class="category-item">
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($cat_name); ?></span>
                            <span class="category-amount"><?php echo formatCurrency($cat_total); ?></span>
                        </div>
                        <div class="category-bar">
                            <div class="category-bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                        </div>
                        <span class="category-percent"><?php echo $percentage; ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        EXPENSES TABLE
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Expense Records</h3>
                <div class="table-actions">
                    <input type="text" id="searchInput" placeholder="Search..." class="search-input">
                </div>
            </div>

            <?php if (empty($expenses)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No Expenses Found</h3>
                    <p>No expense records found for the selected filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="expensesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Expense No.</th>
                                <th>Date</th>
                                <th>Expense Name</th>
                                <th>Category</th>
                                <th>Employee</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($expenses as $exp): 
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="exp-number">
                                            <?php echo htmlspecialchars($exp['expense_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo formatDate($exp['expense_date']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($exp['expense_name']); ?>
                                    </td>
                                    <td>
                                        <span class="category-badge">
                                            <?php echo htmlspecialchars($exp['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($exp['employee_name'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <span class="amount-exp">
                                            <?php echo formatCurrency($exp['amount']); ?>
                                        </span>
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
    <?php if ($role === 'employee') {
        include_once '../../includes/employee_footer.php';
    } else {
        include_once '../../includes/admin_footer.php';
    } ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
:root {
    --exp-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-card-bg: #FFFFFF;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
    --exp-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --exp-bg: #1F2937;
    --exp-text: #F9FAFB;
    --exp-text-secondary: #9CA3AF;
    --exp-text-light: #6B7280;
    --exp-border: #374151;
    --exp-card-bg: #1F2937;
    --exp-hover: #374151;
    --exp-shadow: rgba(0,0,0,0.3);
    --exp-shadow-lg: rgba(0,0,0,0.4);
}

body {
    background: var(--exp-bg) !important;
    color: var(--exp-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--exp-bg) !important; }
.main-content { background: var(--exp-bg) !important; }

/* Page Header */
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
    color: var(--exp-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #E11D48;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    background: #1D4ED8;
    color: white;
}

.btn-back {
    background: var(--exp-hover);
    color: var(--exp-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--exp-border);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--exp-border);
}

/* Filter */
.filter-container {
    background: var(--exp-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--exp-border);
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--exp-shadow);
}

.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 20px;
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
    color: var(--exp-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--exp-border);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    background: var(--exp-hover);
    color: var(--exp-text);
    transition: all 0.3s ease;
    min-width: 140px;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.filter-actions {
    flex-direction: row;
    gap: 8px;
}

.btn-filter {
    background: #DC2626;
    color: white;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #B91C1C;
}

.btn-reset {
    background: var(--exp-hover);
    color: var(--exp-text);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--exp-border);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--exp-border);
}

/* Summaries */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}

.summary-card {
    background: var(--exp-card-bg);
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    transition: all 0.3s ease;
    min-height: 95px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--exp-shadow-lg);
}

.summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
}

.summary-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--exp-text-secondary);
}

.summary-value {
    font-size: 18px;
    font-weight: 800;
    color: var(--exp-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-sub {
    font-size: 10px;
    color: var(--exp-text-light);
    font-weight: 500;
}

.card-total .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total { border-left: 4px solid #3B82F6; }

.card-amount .summary-icon { background: #FEE2E2; color: #DC2626; }
.card-amount { border-left: 4px solid #DC2626; }

.card-categories .summary-icon { background: #FEF3C7; color: #D97706; }
.card-categories { border-left: 4px solid #F59E0B; }

html.dark-mode .card-amount .summary-icon { background: #7F1D1D; color: #DC2626; }
html.dark-mode .card-categories .summary-icon { background: #5F3A1E; color: #FBBF24; }

/* Category Breakdown */
.category-breakdown {
    background: var(--exp-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--exp-border);
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--exp-shadow);
}

.category-breakdown h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--exp-text);
    margin: 0 0 12px 0;
}

.category-breakdown h4 i {
    color: #3B82F6;
    margin-right: 8px;
}

.category-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.category-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.category-info {
    display: flex;
    justify-content: space-between;
    min-width: 180px;
    flex-shrink: 0;
}

.category-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--exp-text);
}

.category-amount {
    font-size: 13px;
    font-weight: 600;
    color: var(--exp-text-secondary);
}

.category-bar {
    flex: 1;
    height: 8px;
    background: var(--exp-hover);
    border-radius: 4px;
    overflow: hidden;
}

.category-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #DC2626, #E11D48);
    transition: width 0.6s ease;
}

.category-percent {
    font-size: 12px;
    font-weight: 600;
    color: var(--exp-text-secondary);
    min-width: 40px;
    text-align: right;
}

/* Table */
.table-container {
    background: var(--exp-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--exp-border);
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--exp-text);
    margin: 0;
}

.table-header h3 i {
    color: #E11D48;
    margin-right: 8px;
}

.table-actions {
    display: flex;
    gap: 10px;
}

.search-input {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--exp-border);
    font-size: 12px;
    outline: none;
    width: 180px;
    transition: all 0.3s ease;
    background: var(--exp-hover);
    color: var(--exp-text);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--exp-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--exp-hover);
}

.data-table tbody td {
    padding: 8px 12px;
    color: var(--exp-text);
    font-size: 12px;
}

.exp-number {
    font-weight: 600;
    color: #3B82F6;
}

.category-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 500;
    background: var(--exp-hover);
    color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
}

.amount-exp {
    color: #DC2626;
    font-weight: 600;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 48px;
    color: var(--exp-text-light);
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 18px;
    color: var(--exp-text);
    margin: 0 0 6px 0;
}

.empty-state p {
    color: var(--exp-text-secondary);
    font-size: 13px;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-group input,
    .filter-group select {
        width: 100%;
        min-width: auto;
    }
    
    .filter-actions {
        flex-direction: row;
    }
    
    .filter-actions .btn-filter,
    .filter-actions .btn-reset {
        flex: 1;
        justify-content: center;
    }
    
    .category-info {
        min-width: 120px;
    }
    
    .category-item {
        flex-wrap: wrap;
    }
    
    .category-bar {
        flex-basis: 100%;
        order: 3;
    }
    
    .category-percent {
        min-width: 30px;
    }
}

@media (max-width: 480px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .summary-card {
        min-height: 70px;
        padding: 8px 10px;
    }
    
    .summary-icon {
        width: 30px;
        height: 30px;
        font-size: 12px;
    }
    
    .summary-value {
        font-size: 13px;
    }
    
    .summary-label {
        font-size: 8px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#expensesTable tbody tr');
            
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
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
});
</script>

</body>
</html>