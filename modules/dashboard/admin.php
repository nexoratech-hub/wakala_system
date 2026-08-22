<?php
// ================================================================
// FILE: modules/dashboard/admin.php
// WAKALA FINANCIAL SYSTEM - COMPLETE ADMIN DASHBOARD
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: employee.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$profile_image = '../../assets/images/logo.PNG';

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
// GET DASHBOARD DATA WITH BRANCH FILTER
// ============================================================
$today = date('Y-m-d');
$month_start = date('Y-m-01');
$current_month = date('m');
$current_year = date('Y');

// --- 1. TOTAL FLOAT (Morning Report - cumm_total) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(cumm_total) as total FROM morning_reports WHERE report_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(cumm_total) as total FROM morning_reports WHERE report_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_float = $result['total'] ?? 0;

// --- 2. TOTAL CASH (Morning Report - cash_balance) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(cash_balance) as total FROM morning_reports WHERE report_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(cash_balance) as total FROM morning_reports WHERE report_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_cash = $result['total'] ?? 0;

// --- 3. TOTAL STOCK (Morning Report - FLOAT + CASH = 63,000,000) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(cumm_total + cash_balance) as total FROM morning_reports WHERE report_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT SUM(cumm_total + cash_balance) as total FROM morning_reports WHERE report_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_stock = $result['total'] ?? 0;

// --- 4. TOTAL DEPOSIT (This Month from daily_report_transactions) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(drt.amount) as total FROM daily_report_transactions drt 
            JOIN daily_reports dr ON drt.daily_report_id = dr.id 
            WHERE drt.transaction_type = 'deposit' 
            AND MONTH(drt.transaction_date) = ? AND YEAR(drt.transaction_date) = ? 
            AND dr.branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM daily_report_transactions 
            WHERE transaction_type = 'deposit' 
            AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_deposits = $result['total'] ?? 0;

// --- 5. TOTAL WITHDRAWAL (This Month from daily_report_transactions) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(drt.amount) as total FROM daily_report_transactions drt 
            JOIN daily_reports dr ON drt.daily_report_id = dr.id 
            WHERE drt.transaction_type = 'withdrawal' 
            AND MONTH(drt.transaction_date) = ? AND YEAR(drt.transaction_date) = ? 
            AND dr.branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM daily_report_transactions 
            WHERE transaction_type = 'withdrawal' 
            AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_withdrawals = $result['total'] ?? 0;

// --- 6. TOTAL CASH OUT (Store Cash Out - This Month) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out 
            WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ? 
            AND status IN ('approved', 'pending') AND branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM store_cash_out 
            WHERE MONTH(cashout_date) = ? AND YEAR(cashout_date) = ? 
            AND status IN ('approved', 'pending')";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_cashout = $result['total'] ?? 0;

// --- 7. TOTAL EXPENSES (This Month) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(amount) as total FROM expenses 
            WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ? 
            AND is_business_expense = 1 AND branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(amount) as total FROM expenses 
            WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ? 
            AND is_business_expense = 1";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_expenses = $result['total'] ?? 0;

// --- 8. TOTAL SALARIES (This Month) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries 
            WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ? 
            AND status = 'paid' AND branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(net_pay) as total FROM employee_salaries 
            WHERE MONTH(salary_month) = ? AND YEAR(salary_month) = ? 
            AND status = 'paid'";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_salaries = $result['total'] ?? 0;

// --- COMMISSION (This Month) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(total_commission) as total FROM commissions 
            WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ? 
            AND branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(total_commission) as total FROM commissions 
            WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_commission = $result['total'] ?? 0;

// --- OTHER INCOME (This Month from commissions) ---
if ($selected_branch > 0) {
    $sql = "SELECT SUM(other_income) as total FROM commissions 
            WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ? 
            AND branch_id = ?";
    $params = [$current_month, $current_year, $selected_branch];
} else {
    $sql = "SELECT SUM(other_income) as total FROM commissions 
            WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?";
    $params = [$current_month, $current_year];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_other_income = $result['total'] ?? 0;

// --- TOTAL INCOME ---
$total_income = $total_commission + $total_other_income;

// --- PROFIT = Commission + Other Income - Expenses - Salaries - Cashout ---
$profit = $total_income - $total_expenses - $total_salaries - $total_cashout;

// --- CURRENT CAPITAL (Float + Cash from Morning Report) ---
$capital_float = 0;
$capital_cash = 0;
$capital_source = 'No Data';

// Try to get today's morning report first
if ($selected_branch > 0) {
    $sql = "SELECT cumm_total, cash_balance FROM morning_reports 
            WHERE report_date = ? AND branch_id = ?";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT cumm_total, cash_balance FROM morning_reports 
            WHERE report_date = ?";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$today_morning = $stmt->fetch();

if ($today_morning) {
    $capital_float = floatval($today_morning['cumm_total'] ?? 0);
    $capital_cash = floatval($today_morning['cash_balance'] ?? 0);
    $capital_source = 'Morning Report (Today)';
} else {
    // If no today's report, get first morning report of the month
    if ($selected_branch > 0) {
        $sql = "SELECT cumm_total, cash_balance FROM morning_reports 
                WHERE report_date >= ? AND branch_id = ? 
                ORDER BY report_date ASC LIMIT 1";
        $params = [$month_start, $selected_branch];
    } else {
        $sql = "SELECT cumm_total, cash_balance FROM morning_reports 
                WHERE report_date >= ? 
                ORDER BY report_date ASC LIMIT 1";
        $params = [$month_start];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $first_morning = $stmt->fetch();
    
    if ($first_morning) {
        $capital_float = floatval($first_morning['cumm_total'] ?? 0);
        $capital_cash = floatval($first_morning['cash_balance'] ?? 0);
        $capital_source = 'Morning Report (First of Month)';
    }
}

$total_capital = $capital_float + $capital_cash;

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
        
        <!-- ===== DARK MODE TOGGLE ===== -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
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

        <!-- ===== CAPITAL CARD (Horizontal Row) - BLUE CARD ===== -->
        <div class="capital-row">
            <div class="capital-card">
                <div class="capital-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="capital-content">
                    <div class="capital-label">TOTAL CAPITAL</div>
                    <div class="capital-value"><?php echo formatCurrency($total_capital); ?></div>
                    <div class="capital-sub">
                        <span class="capital-float">Float: <?php echo formatCurrency($capital_float); ?></span>
                        <span class="capital-cash">Cash: <?php echo formatCurrency($capital_cash); ?></span>
                        <span class="capital-source"><?php echo $capital_source; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== QUICK ACTION BUTTONS ===== -->
        <div class="quick-actions">
            <a href="../morning_report/add.php" class="btn btn-morning"><i class="fas fa-sun"></i> Morning Report</a>
            <a href="../evening_stock/add.php" class="btn btn-evening"><i class="fas fa-moon"></i> Evening Stock</a>
            <a href="../commissions/add.php" class="btn btn-commission"><i class="fas fa-hand-holding-usd"></i> Commissions</a>
            <a href="../expenses/add.php" class="btn btn-expense"><i class="fas fa-receipt"></i> Expenses</a>
            <a href="../store_cash_out/add.php" class="btn btn-cashout"><i class="fas fa-money-bill-wave"></i> Cash Out</a>
            <a href="../daily_report/generate.php" class="btn btn-daily"><i class="fas fa-file-alt"></i> Daily Reports</a>
        </div>

        <!-- ============================================================
        SUMMARIES CARDS (8 Cards) with increased height
        ============================================================ -->
        <div class="summaries-grid">
            <!-- 1. TOTAL FLOAT - Light Blue -->
            <div class="summary-card card-float">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL FLOAT</div>
                    <div class="summary-value"><?php echo formatCurrency($total_float); ?></div>
                    <div class="summary-sub">Today's Morning Report</div>
                </div>
            </div>

            <!-- 2. TOTAL CASH - Light Green -->
            <div class="summary-card card-cash">
                <div class="summary-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL CASH</div>
                    <div class="summary-value"><?php echo formatCurrency($total_cash); ?></div>
                    <div class="summary-sub">Today's Morning Report</div>
                </div>
            </div>

            <!-- 3. TOTAL STOCK - Blue (Morning Report Float + Cash) -->
            <div class="summary-card card-stock">
                <div class="summary-icon"><i class="fas fa-boxes"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL STOCK</div>
                    <div class="summary-value"><?php echo formatCurrency($total_stock); ?></div>
                    <div class="summary-sub">Morning Report (Float + Cash)</div>
                </div>
            </div>

            <!-- 4. TOTAL DEPOSIT - Green -->
            <div class="summary-card card-deposit">
                <div class="summary-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL DEPOSIT</div>
                    <div class="summary-value"><?php echo formatCurrency($total_deposits); ?></div>
                    <div class="summary-sub">This Month</div>
                </div>
            </div>

            <!-- 5. TOTAL WITHDRAWAL - Red -->
            <div class="summary-card card-withdrawal">
                <div class="summary-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL WITHDRAWAL</div>
                    <div class="summary-value"><?php echo formatCurrency($total_withdrawals); ?></div>
                    <div class="summary-sub">This Month</div>
                </div>
            </div>

            <!-- 6. TOTAL CASH OUT - Maroon -->
            <div class="summary-card card-cashout-maroon">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL CASH OUT</div>
                    <div class="summary-value"><?php echo formatCurrency($total_cashout); ?></div>
                    <div class="summary-sub">This Month</div>
                </div>
            </div>

            <!-- 7. TOTAL EXPENSES - Red -->
            <div class="summary-card card-expenses-red">
                <div class="summary-icon"><i class="fas fa-receipt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL EXPENSES</div>
                    <div class="summary-value"><?php echo formatCurrency($total_expenses); ?></div>
                    <div class="summary-sub">This Month</div>
                </div>
            </div>

            <!-- 8. TOTAL SALARIES - Maroon -->
            <div class="summary-card card-salaries">
                <div class="summary-icon"><i class="fas fa-wallet"></i></div>
                <div class="summary-content">
                    <div class="summary-label">TOTAL SALARIES</div>
                    <div class="summary-value"><?php echo formatCurrency($total_salaries); ?></div>
                    <div class="summary-sub">This Month</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        COMMISSION & PROFIT CARDS
        ============================================================ -->
        <div class="profit-commission-row">
            <!-- Commission Card -->
            <div class="commission-card">
                <div class="commission-header">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>COMMISSION</span>
                </div>
                <div class="commission-body">
                    <div class="commission-main">
                        <span class="commission-label">Total Commission</span>
                        <span class="commission-value"><?php echo formatCurrency($total_commission); ?></span>
                    </div>
                    <div class="commission-other">
                        <span class="commission-label">Other Income</span>
                        <span class="commission-value"><?php echo formatCurrency($total_other_income); ?></span>
                    </div>
                    <div class="commission-total">
                        <span class="commission-label">Total Business Income</span>
                        <span class="commission-value"><?php echo formatCurrency($total_income); ?></span>
                    </div>
                </div>
            </div>

            <!-- Profit Card -->
            <div class="profit-card">
                <div class="profit-header">
                    <i class="fas fa-chart-line"></i>
                    <span>PROFIT</span>
                </div>
                <div class="profit-body">
                    <div class="profit-main">
                        <span class="profit-label">Income</span>
                        <span class="profit-value"><?php echo formatCurrency($total_income); ?></span>
                    </div>
                    <div class="profit-expenses">
                        <span class="profit-label">Expenses</span>
                        <span class="profit-value negative">- <?php echo formatCurrency($total_expenses); ?></span>
                    </div>
                    <div class="profit-salaries">
                        <span class="profit-label">Salaries</span>
                        <span class="profit-value negative">- <?php echo formatCurrency($total_salaries); ?></span>
                    </div>
                    <div class="profit-cashout">
                        <span class="profit-label">Cash Out</span>
                        <span class="profit-value negative">- <?php echo formatCurrency($total_cashout); ?></span>
                    </div>
                    <div class="profit-total">
                        <span class="profit-label">NET PROFIT</span>
                        <span class="profit-value <?php echo $profit >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo formatCurrency($profit); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES WITH DARK MODE SUPPORT
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
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
}

/* Dark Mode - Full Page */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-card-hover: #334155;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
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
   BRANCH FILTER BAR
   ============================================================ */
.branch-filter-bar {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 10px 18px;
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.branch-filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-primary);
}

.branch-filter-left i {
    color: #DC2626;
    font-size: 16px;
}

.branch-filter-left select {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: var(--bg-input);
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    cursor: pointer;
}

.branch-filter-left select:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
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
    color: var(--text-muted);
}

.branch-filter-right .date-display i {
    color: #DC2626;
}

/* ============================================================
   CAPITAL CARD - BLUE CARD
   ============================================================ */
.capital-row {
    margin-bottom: 14px;
}

.capital-card {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    border-radius: 12px;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    min-height: 90px;
}

.capital-icon {
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
}

.capital-content {
    flex: 1;
}

.capital-label {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.capital-value {
    font-size: 28px;
    font-weight: 700;
    color: #FFFFFF;
    margin: 0px 0;
}

.capital-sub {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: rgba(255,255,255,0.7);
    flex-wrap: wrap;
}

.capital-sub span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.capital-float::before {
    content: "•";
    color: #FCD34D;
    margin-right: 4px;
}

.capital-cash::before {
    content: "•";
    color: #6EE7B7;
    margin-right: 4px;
}

/* ============================================================
   QUICK ACTIONS
   ============================================================ */
.quick-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.btn {
    padding: 7px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Inter', sans-serif;
}

.btn-morning { background: #F59E0B; color: white; }
.btn-morning:hover { background: #D97706; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(245,158,11,0.3); }

.btn-evening { background: #3B82F6; color: white; }
.btn-evening:hover { background: #2563EB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }

.btn-commission { background: #10B981; color: white; }
.btn-commission:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

.btn-expense { background: #DC2626; color: white; }
.btn-expense:hover { background: #B91C1C; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,38,38,0.3); }

.btn-cashout { background: #7F1D1D; color: white; }
.btn-cashout:hover { background: #5C1313; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(127,29,29,0.3); }

.btn-daily { background: #6B7280; color: white; }
.btn-daily:hover { background: #4B5563; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(107,114,128,0.3); }

/* ============================================================
   SUMMARIES GRID - 8 CARDS with increased height
   ============================================================ */
.summaries-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.summary-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    min-height: 90px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
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
    font-weight: 600;
    color: var(--text-muted);
}

.summary-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 2px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-sub {
    font-size: 10px;
    color: var(--text-light);
}

/* Card Colors - Keep original colors for dark mode */
.card-float .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-float { border-left: 4px solid #3B82F6; }

.card-cash .summary-icon { background: #D1FAE5; color: #065F46; }
.card-cash { border-left: 4px solid #10B981; }

.card-stock .summary-icon { background: #DBEAFE; color: #1E40AF; }
.card-stock { border-left: 4px solid #1E40AF; }

.card-deposit .summary-icon { background: #D1FAE5; color: #047857; }
.card-deposit { border-left: 4px solid #059669; }

.card-withdrawal .summary-icon { background: #FEE2E2; color: #991B1B; }
.card-withdrawal { border-left: 4px solid #DC2626; }

.card-cashout-maroon .summary-icon { background: #FECACA; color: #7F1D1D; }
.card-cashout-maroon { border-left: 4px solid #7F1D1D; }

.card-expenses-red .summary-icon { background: #FEE2E2; color: #991B1B; }
.card-expenses-red { border-left: 4px solid #DC2626; }

.card-salaries .summary-icon { background: #FECACA; color: #7F1D1D; }
.card-salaries { border-left: 4px solid #7F1D1D; }

/* ============================================================
   PROFIT & COMMISSION ROW
   ============================================================ */
.profit-commission-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}

/* Commission Card */
.commission-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.commission-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 10px;
}

.commission-header i { color: #10B981; }

.commission-body {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.commission-main,
.commission-other,
.commission-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 3px 0;
}

.commission-label {
    font-size: 12px;
    color: var(--text-muted);
}

.commission-value {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}

.commission-total {
    border-top: 2px solid var(--border-color);
    padding-top: 6px;
    margin-top: 2px;
}

.commission-total .commission-label {
    font-weight: 700;
    color: var(--text-primary);
}

.commission-total .commission-value {
    font-size: 15px;
    color: #10B981;
}

/* Profit Card */
.profit-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.profit-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 10px;
}

.profit-header i { color: #DC2626; }

.profit-body {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.profit-main,
.profit-expenses,
.profit-salaries,
.profit-cashout {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
}

.profit-label {
    font-size: 12px;
    color: var(--text-muted);
}

.profit-value {
    font-size: 13px;
    font-weight: 600;
}

.profit-value.positive { color: #10B981; }
.profit-value.negative { color: #DC2626; }

.profit-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 6px;
    margin-top: 2px;
    border-top: 2px solid var(--border-color);
}

.profit-total .profit-label {
    font-weight: 700;
    font-size: 13px;
    color: var(--text-primary);
}

.profit-total .profit-value {
    font-size: 16px;
    font-weight: 700;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summaries-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 768px) {
    .summaries-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .profit-commission-row {
        grid-template-columns: 1fr;
    }
    
    .capital-card {
        flex-direction: column;
        text-align: center;
        padding: 14px;
        min-height: 80px;
    }
    
    .capital-sub {
        flex-direction: column;
        gap: 3px;
        align-items: center;
    }
    
    .branch-filter-bar {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .quick-actions {
        flex-direction: column;
    }
    
    .quick-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .summaries-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .summary-card {
        padding: 12px 14px;
        min-height: 75px;
    }
    
    .summary-icon {
        width: 36px;
        height: 36px;
        font-size: 15px;
    }
    
    .summary-value {
        font-size: 15px;
    }
    
    .capital-value {
        font-size: 20px;
    }
    
    .commission-total .commission-value,
    .profit-total .profit-value {
        font-size: 14px;
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
    animation: fadeInUp 0.3s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.03s; }
.summary-card:nth-child(2) { animation-delay: 0.06s; }
.summary-card:nth-child(3) { animation-delay: 0.09s; }
.summary-card:nth-child(4) { animation-delay: 0.12s; }
.summary-card:nth-child(5) { animation-delay: 0.15s; }
.summary-card:nth-child(6) { animation-delay: 0.18s; }
.summary-card:nth-child(7) { animation-delay: 0.21s; }
.summary-card:nth-child(8) { animation-delay: 0.24s; }

.capital-card {
    animation: fadeInUp 0.3s ease forwards;
}

.commission-card,
.profit-card {
    animation: fadeInUp 0.3s ease forwards;
    animation-delay: 0.10s;
}
</style>

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
</script>

</body>
</html>