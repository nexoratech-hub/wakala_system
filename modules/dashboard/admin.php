<?php
// ================================================================
// FILE: modules/dashboard/admin.php
// WAKALA FINANCIAL SYSTEM - ADMIN DASHBOARD
// ✅ Capital Section (Float | Cash | Total Capital)
// ✅ 9 Cards: 3 Capital + 3 Income + 3 Activity
// ✅ All cards show BRANCH-WIDE data (admin view)
// ✅ REMOVED: Admin Filter Bar (branch filter iko kwenye topbar)
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: employee.php');
    exit();
}

// ============================================================
// GET ADMIN DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: ../../login.php');
    exit();
}

// Branch filter - inatoka kwenye session (topbar) au GET
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
} elseif (isset($_SESSION['selected_branch_id']) && $_SESSION['selected_branch_id'] > 0) {
    $selected_branch = intval($_SESSION['selected_branch_id']);
} elseif (isset($_SESSION['user_branch_id']) && $_SESSION['user_branch_id'] > 0) {
    $selected_branch = intval($_SESSION['user_branch_id']);
}

if ($selected_branch == 0) {
    $selected_branch = intval($admin['branch_id'] ?? 0);
}

// Branch info
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

// ============================================================
// CAPITAL DATA (Float + Cash + Total Capital)
// ============================================================
$total_float = 0;
$total_cash = 0;
$total_capital = 0;

$sql_float = "SELECT COALESCE(SUM(drp.current_float), 0) as total_float
              FROM daily_report_providers drp
              INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
              WHERE 1=1";
$params_float = [];
if ($selected_branch > 0) {
    $sql_float .= " AND dr.branch_id = ?";
    $params_float[] = $selected_branch;
}
$stmt = $db->prepare($sql_float);
$stmt->execute($params_float);
$total_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

$sql_cash = "SELECT COALESCE(SUM(current_cash), 0) as total_cash
             FROM daily_reports
             WHERE 1=1";
$params_cash = [];
if ($selected_branch > 0) {
    $sql_cash .= " AND branch_id = ?";
    $params_cash[] = $selected_branch;
}
$stmt = $db->prepare($sql_cash);
$stmt->execute($params_cash);
$total_cash = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_cash'] ?? 0);

$total_capital = $total_float + $total_cash;

// ============================================================
// SUMMARY DATA - BRANCH WIDE
// ============================================================
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// ✅ Total Commission - Today
$sql = "SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions WHERE DATE(commission_date) = ?";
$params = [$today];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql); $stmt->execute($params);
$commission_today = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ✅ Total Commission - This Month
$sql = "SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions 
        WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?";
$params = [$month, $year];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql); $stmt->execute($params);
$commission_month = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ✅ Total Commission - All Time
$sql = "SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql); $stmt->execute($params);
$commission_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ✅ Total Other Income - All Time
$sql = "SELECT COALESCE(SUM(other_income), 0) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql); $stmt->execute($params);
$other_income_total = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ✅ Total Expenses - This Month
$expenses_month = 0;
try {
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses 
            WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
    $params = [$month, $year];
    if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $expenses_month = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $expenses_month = 0; }

// ✅ Total Transactions - This Month
$transactions_month = 0;
try {
    $sql = "SELECT COUNT(*) as total FROM transactions 
            WHERE MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?";
    $params = [$month, $year];
    if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $transactions_month = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $transactions_month = 0; }

// ✅ Total Cash Out - This Month
$cash_out_month = 0;
try {
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM cash_out 
            WHERE MONTH(cash_out_date) = ? AND YEAR(cash_out_date) = ?";
    $params = [$month, $year];
    if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $cash_out_month = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $cash_out_month = 0; }

// ✅ Total Transfers - This Month
$transfer_month = 0;
try {
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM transfers 
            WHERE MONTH(transfer_date) = ? AND YEAR(transfer_date) = ?";
    $params = [$month, $year];
    if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $transfer_month = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $transfer_month = 0; }

// ============================================================
// RECENT COMMISSIONS (BRANCH-WIDE)
// ============================================================
$sql = "
    SELECT 
        c.id,
        c.commission_number,
        c.commission_date,
        c.total_commission,
        c.other_income,
        c.total_business_income,
        c.notes,
        c.created_at,
        e.full_name as employee_name
    FROM commissions c
    LEFT JOIN employees e ON c.employee_id = e.id
    WHERE 1=1
";
$params = [];
if ($selected_branch > 0) { $sql .= " AND c.branch_id = ?"; $params[] = $selected_branch; }
$sql .= " ORDER BY c.commission_date DESC, c.id DESC LIMIT 5";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$recent_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
             WELCOME CARD (Red for Admin)
             ============================================================ -->
        <div class="welcome-card-red">
            <div class="welcome-content">
                <div class="welcome-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="welcome-info">
                    <span class="welcome-label">Welcome back, Administrator</span>
                    <h1 class="welcome-name"><?php echo htmlspecialchars($admin['full_name']); ?></h1>
                    <div class="welcome-meta">
                        <span class="welcome-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($branch_name); ?>
                        </span>
                        <?php if ($branch_code): ?>
                            <span class="welcome-meta-item">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($branch_code); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($branch_location): ?>
                            <span class="welcome-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($branch_location); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="welcome-date">
                <i class="far fa-calendar-alt"></i>
                <?php echo date('l, d M Y'); ?>
            </div>
        </div>

        <!-- ============================================================
             CAPITAL SECTION (3 Parts) - ROW 1
             ============================================================ -->
        <div class="capital-section-wrapper">
            <div class="capital-section-header">
                <div class="csh-left">
                    <div class="csh-icon">
                        <i class="fas fa-vault"></i>
                    </div>
                    <div class="csh-info">
                        <span class="csh-title">Branch Capital Overview</span>
                        <span class="csh-subtitle"><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                </div>
                <div class="csh-badge">
                    <i class="fas fa-coins"></i> Total Capital
                </div>
            </div>
            
            <div class="capital-grid-3">
                <div class="capital-part part-float">
                    <div class="cp-header">
                        <div class="cp-icon cp-icon-blue">
                            <i class="fas fa-university"></i>
                        </div>
                        <span class="cp-label">TOTAL FLOAT</span>
                    </div>
                    <div class="cp-value cp-value-blue">
                        <?php echo formatCurrency($total_float); ?>
                    </div>
                    <div class="cp-sublabel">
                        <i class="fas fa-info-circle"></i> All provider floats
                    </div>
                </div>
                
                <div class="capital-part part-cash">
                    <div class="cp-header">
                        <div class="cp-icon cp-icon-green">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span class="cp-label">CASH BALANCE</span>
                    </div>
                    <div class="cp-value cp-value-green">
                        <?php echo formatCurrency($total_cash); ?>
                    </div>
                    <div class="cp-sublabel">
                        <i class="fas fa-info-circle"></i> Branch cash balance
                    </div>
                </div>
                
                <div class="capital-part part-total">
                    <div class="cp-header">
                        <div class="cp-icon cp-icon-purple">
                            <i class="fas fa-building"></i>
                        </div>
                        <span class="cp-label">TOTAL CAPITAL</span>
                    </div>
                    <div class="cp-value cp-value-purple">
                        <?php echo formatCurrency($total_capital); ?>
                    </div>
                    <div class="cp-sublabel">
                        <i class="fas fa-check-circle"></i> Float + Cash
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             INCOME CARDS (3 Cards) - ROW 2
             ============================================================ -->
        <div class="section-title-bar">
            <h3><i class="fas fa-chart-line"></i> Branch Income</h3>
        </div>
        
        <div class="cards-grid-3">
            
            <!-- Card 1: Commission -->
            <a href="../commissions/index.php?branch_id=<?php echo $selected_branch; ?>" class="nav-card nav-card-commission">
                <div class="nav-card-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">TOTAL COMMISSION</span>
                    <span class="nav-card-value"><?php echo formatCurrency($commission_month); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-calendar-alt"></i> This month
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <!-- Card 2: Other Income -->
            <a href="../commissions/index.php?branch_id=<?php echo $selected_branch; ?>&type=other" class="nav-card nav-card-other">
                <div class="nav-card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">OTHER INCOME</span>
                    <span class="nav-card-value"><?php echo formatCurrency($other_income_total); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-plus"></i> All time
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <!-- Card 3: Expenses -->
            <a href="../expenses/index.php?branch_id=<?php echo $selected_branch; ?>" class="nav-card nav-card-expenses">
                <div class="nav-card-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">EXPENSES</span>
                    <span class="nav-card-value"><?php echo formatCurrency($expenses_month); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-calendar-alt"></i> This month
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
        </div>

        <!-- ============================================================
             ACTIVITY CARDS (3 Cards) - ROW 3
             ============================================================ -->
        <div class="section-title-bar">
            <h3><i class="fas fa-exchange-alt"></i> Branch Activity</h3>
        </div>
        
        <div class="cards-grid-3">
            
            <!-- Card 1: Transactions -->
            <a href="../daily_report/index.php?branch_id=<?php echo $selected_branch; ?>" class="nav-card nav-card-transactions">
                <div class="nav-card-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">TRANSACTIONS</span>
                    <span class="nav-card-value"><?php echo number_format($transactions_month); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-calendar-alt"></i> This month
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <!-- Card 2: Cash Out -->
            <a href="../cash_out/index.php?branch_id=<?php echo $selected_branch; ?>" class="nav-card nav-card-cashout">
                <div class="nav-card-icon">
                    <i class="fas fa-money-bill-transfer"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">CASH OUT</span>
                    <span class="nav-card-value"><?php echo formatCurrency($cash_out_month); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-calendar-alt"></i> This month
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <!-- Card 3: Transfer -->
            <a href="../transfers/index.php?branch_id=<?php echo $selected_branch; ?>" class="nav-card nav-card-transfer">
                <div class="nav-card-icon">
                    <i class="fas fa-arrow-right-arrow-left"></i>
                </div>
                <div class="nav-card-content">
                    <span class="nav-card-label">TRANSFER</span>
                    <span class="nav-card-value"><?php echo formatCurrency($transfer_month); ?></span>
                    <span class="nav-card-sub">
                        <i class="fas fa-calendar-alt"></i> This month
                    </span>
                </div>
                <div class="nav-card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
        </div>

        <!-- ============================================================
             RECENT COMMISSIONS
             ============================================================ -->
        <div class="section-container">
            <div class="section-header-view">
                <h3>
                    <i class="fas fa-history"></i>
                    Recent Commissions
                    <span class="section-count-view"><?php echo count($recent_commissions); ?></span>
                </h3>
                <a href="../commissions/index.php?branch_id=<?php echo $selected_branch; ?>" class="btn-view-all">
                    <i class="fas fa-eye"></i> View All
                </a>
            </div>

            <?php if (count($recent_commissions) > 0): ?>
                <div class="recent-list">
                    <?php foreach ($recent_commissions as $txn): 
                        $is_commission = $txn['total_commission'] > 0;
                        $is_other = $txn['other_income'] > 0;
                        $amount = $is_commission ? floatval($txn['total_commission']) : floatval($txn['other_income']);
                        $type_label = $is_commission ? 'COMMISSION' : 'OTHER INCOME';
                        $type_class = $is_commission ? 'commission' : 'other';
                        $type_icon = $is_commission ? 'fa-hand-holding-usd' : 'fa-coins';
                    ?>
                        <div class="recent-item">
                            <div class="ri-icon ri-icon-<?php echo $type_class; ?>">
                                <i class="fas <?php echo $type_icon; ?>"></i>
                            </div>
                            <div class="ri-content">
                                <div class="ri-top">
                                    <span class="ri-badge ri-badge-<?php echo $type_class; ?>">
                                        <?php echo $type_label; ?>
                                    </span>
                                    <span class="ri-ref"><?php echo htmlspecialchars($txn['commission_number']); ?></span>
                                    <span class="ri-employee">
                                        <i class="fas fa-user"></i>
                                        <?php echo htmlspecialchars($txn['employee_name'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                                <div class="ri-meta">
                                    <span class="ri-date">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($txn['commission_date'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="ri-amount ri-amount-<?php echo $type_class; ?>">
                                + <?php echo formatCurrency($amount); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-recent">
                    <i class="fas fa-inbox"></i>
                    <p>No recent commissions yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             QUICK ACTIONS (Admin)
             ============================================================ -->
        <div class="quick-actions-wrapper">
            <div class="qa-header">
                <h3><i class="fas fa-bolt"></i> Admin Quick Actions</h3>
            </div>
            <div class="qa-grid">
                <a href="../commissions/index.php?branch_id=<?php echo $selected_branch; ?>" class="qa-card qa-commission">
                    <div class="qa-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="qa-content">
                        <span class="qa-title">All Commissions</span>
                        <span class="qa-desc">View all commissions</span>
                    </div>
                </a>
                
                <a href="../employees/index.php?branch_id=<?php echo $selected_branch; ?>" class="qa-card qa-add">
                    <div class="qa-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="qa-content">
                        <span class="qa-title">Manage Employees</span>
                        <span class="qa-desc">Employees list</span>
                    </div>
                </a>
                
                <a href="../daily_report/index.php?branch_id=<?php echo $selected_branch; ?>" class="qa-card qa-other">
                    <div class="qa-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="qa-content">
                        <span class="qa-title">Daily Reports</span>
                        <span class="qa-desc">View all reports</span>
                    </div>
                </a>
                
                <a href="../reports/index.php?branch_id=<?php echo $selected_branch; ?>" class="qa-card qa-report">
                    <div class="qa-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="qa-content">
                        <span class="qa-title">Reports</span>
                        <span class="qa-desc">Analytics & reports</span>
                    </div>
                </a>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--bg-body);
    transition: margin-left 0.3s ease, width 0.3s ease;
    position: relative;
}
.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 20px 24px !important;
}
@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px !important; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px !important; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 44px; width: 100%; }
    .main-content { padding: 12px 10px !important; width: 100%; }
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --bg-hover: #f3f4f6;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-hover: #2d3a4f;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   WELCOME CARD (RED - ADMIN)
   ============================================================ */
.welcome-card-red {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 24px 28px;
    background: linear-gradient(135deg, #991B1B 0%, #B91C1C 50%, #DC2626 100%);
    border-radius: 16px;
    margin-bottom: 16px;
    box-shadow: 0 6px 24px rgba(153, 27, 27, 0.35);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
    flex-wrap: wrap;
}
.welcome-card-red::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.welcome-content {
    display: flex;
    align-items: center;
    gap: 18px;
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}
.welcome-icon {
    width: 68px; height: 68px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
.welcome-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.welcome-label {
    font-size: 11px; font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.welcome-name {
    font-size: 24px; font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.3px;
    line-height: 1.1;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}
.welcome-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.welcome-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
}
.welcome-date {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    font-size: 13px; font-weight: 700;
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1;
    white-space: nowrap;
}
.welcome-date i { color: #FCD34D; }

/* ============================================================
   CAPITAL SECTION (3 Parts)
   ============================================================ */
.capital-section-wrapper {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 16px;
    padding: 22px 26px;
    margin-bottom: 16px;
    box-shadow: 0 6px 24px rgba(30, 64, 175, 0.35);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}
.capital-section-wrapper::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.capital-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.csh-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.csh-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
}
.csh-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.csh-title { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.csh-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.csh-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.capital-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    position: relative;
    z-index: 1;
}
.capital-part {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex; flex-direction: column; gap: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0; position: relative; overflow: hidden;
}
.capital-part::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.capital-part:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-3px);
}
.part-float::before { background: #60A5FA; }
.part-cash::before { background: #86EFAC; }
.part-total::before { background: #FCD34D; }

.cp-header { display: flex; align-items: center; gap: 10px; }
.cp-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.cp-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.cp-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.cp-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }
.cp-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.cp-value {
    font-size: clamp(18px, 1.6vw, 26px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    line-height: 1.15; word-break: break-word;
}
.cp-value-blue { color: #93C5FD !important; }
.cp-value-green { color: #86EFAC !important; }
.cp-value-purple { color: #FCD34D !important; }
.cp-sublabel {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    display: inline-flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.4px;
    margin-top: auto; padding-top: 8px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

/* ============================================================
   SECTION TITLE BAR
   ============================================================ */
.section-title-bar {
    margin: 20px 0 12px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border-color);
}
.section-title-bar h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.section-title-bar h3 i {
    color: #DC2626;
    font-size: 16px;
}

/* ============================================================
   NAVIGATION CARDS
   ============================================================ */
.cards-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
    width: 100%;
}

.nav-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--shadow-color);
    border: 1.5px solid var(--border-color);
    transition: all 0.3s ease;
    min-height: 110px;
    position: relative;
    overflow: hidden;
    min-width: 0;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}

.nav-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}

.nav-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--shadow-hover);
    text-decoration: none;
    color: inherit;
}

.nav-card-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    transition: all 0.3s ease;
}
.nav-card:hover .nav-card-icon { transform: scale(1.08) rotate(-4deg); }

.nav-card-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.nav-card-label {
    font-size: 10px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.nav-card-value {
    font-size: clamp(15px, 1.2vw, 20px);
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
}
.nav-card-sub {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-light);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
}
.nav-card-arrow {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-input);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: var(--text-muted);
    transition: all 0.3s ease;
    flex-shrink: 0;
}
.nav-card:hover .nav-card-arrow {
    transform: translateX(4px);
    color: #FFFFFF;
}

/* COMMISSION */
.nav-card-commission::before { background: #10B981; }
.nav-card-commission .nav-card-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border: 1.5px solid #6EE7B7;
}
.nav-card-commission .nav-card-value { color: #059669; }
.nav-card-commission:hover { border-color: #10B981; }
.nav-card-commission:hover .nav-card-arrow { background: #10B981; color: #FFFFFF; }
html.dark-mode .nav-card-commission .nav-card-icon { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }
html.dark-mode .nav-card-commission .nav-card-value { color: #34D399; }

/* OTHER INCOME */
.nav-card-other::before { background: #7C3AED; }
.nav-card-other .nav-card-icon {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border: 1.5px solid #C4B5FD;
}
.nav-card-other .nav-card-value { color: #7C3AED; }
.nav-card-other:hover { border-color: #7C3AED; }
.nav-card-other:hover .nav-card-arrow { background: #7C3AED; color: #FFFFFF; }
html.dark-mode .nav-card-other .nav-card-icon { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .nav-card-other .nav-card-value { color: #C4B5FD; }

/* EXPENSES */
.nav-card-expenses::before { background: #DC2626; }
.nav-card-expenses .nav-card-icon {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
}
.nav-card-expenses .nav-card-value { color: #DC2626; }
.nav-card-expenses:hover { border-color: #DC2626; }
.nav-card-expenses:hover .nav-card-arrow { background: #DC2626; color: #FFFFFF; }
html.dark-mode .nav-card-expenses .nav-card-icon { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .nav-card-expenses .nav-card-value { color: #FCA5A5; }

/* TRANSACTIONS */
.nav-card-transactions::before { background: #2563EB; }
.nav-card-transactions .nav-card-icon {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.nav-card-transactions .nav-card-value { color: #1D4ED8; }
.nav-card-transactions:hover { border-color: #2563EB; }
.nav-card-transactions:hover .nav-card-arrow { background: #2563EB; color: #FFFFFF; }
html.dark-mode .nav-card-transactions .nav-card-icon { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .nav-card-transactions .nav-card-value { color: #60A5FA; }

/* CASH OUT */
.nav-card-cashout::before { background: #F59E0B; }
.nav-card-cashout .nav-card-icon {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border: 1.5px solid #FCD34D;
}
.nav-card-cashout .nav-card-value { color: #D97706; }
.nav-card-cashout:hover { border-color: #F59E0B; }
.nav-card-cashout:hover .nav-card-arrow { background: #F59E0B; color: #FFFFFF; }
html.dark-mode .nav-card-cashout .nav-card-icon { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .nav-card-cashout .nav-card-value { color: #FBBF24; }

/* TRANSFER */
.nav-card-transfer::before { background: #0D9488; }
.nav-card-transfer .nav-card-icon {
    background: linear-gradient(135deg, #CCFBF1, #99F6E4);
    color: #0D9488;
    border: 1.5px solid #5EEAD4;
}
.nav-card-transfer .nav-card-value { color: #0D9488; }
.nav-card-transfer:hover { border-color: #0D9488; }
.nav-card-transfer:hover .nav-card-arrow { background: #0D9488; color: #FFFFFF; }
html.dark-mode .nav-card-transfer .nav-card-icon { background: linear-gradient(135deg, #134E4A, #115E59); color: #5EEAD4; border-color: #14B8A6; }
html.dark-mode .nav-card-transfer .nav-card-value { color: #5EEAD4; }

/* ============================================================
   SECTION CONTAINER
   ============================================================ */
.section-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.section-header-view {
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #991B1B 100%);
    color: #FFFFFF;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.section-header-view::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.section-header-view h3 {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.section-header-view h3 i { color: #FCD34D; font-size: 16px; }
.section-count-view {
    font-size: 11px; font-weight: 800;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
}
.btn-view-all {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.18);
    color: #FFFFFF;
    border-radius: 10px;
    text-decoration: none;
    font-size: 12px; font-weight: 700;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    transition: all 0.25s ease;
    position: relative;
    z-index: 1;
    white-space: nowrap;
}
.btn-view-all:hover {
    background: #FFFFFF;
    color: #991B1B;
    transform: translateY(-2px);
}

/* RECENT LIST */
.recent-list {
    padding: 16px 22px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.recent-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: all 0.25s ease;
}
.recent-item::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    width: 4px; height: 100%;
}
.ri-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    border: 1.5px solid;
}
.ri-icon-commission {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border-color: #10B981;
}
.ri-icon-other {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border-color: #A78BFA;
}
html.dark-mode .ri-icon-commission { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; }
html.dark-mode .ri-icon-other { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; }
.recent-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    box-shadow: 0 4px 16px var(--shadow-color);
}
.ri-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ri-top {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.ri-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 9px; font-weight: 800;
    letter-spacing: 0.8px;
}
.ri-badge-commission { background: #DCFCE7; color: #15803D; border: 1.5px solid #10B981; }
.ri-badge-other { background: #EDE9FE; color: #5B21B6; border: 1.5px solid #A78BFA; }
html.dark-mode .ri-badge-commission { background: #14532D; color: #4ADE80; }
html.dark-mode .ri-badge-other { background: #4C1D95; color: #C4B5FD; }

.ri-ref {
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 800;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 3px 10px;
    border-radius: 6px;
    white-space: nowrap;
}
html.dark-mode .ri-ref { background: #1E3A5F; color: #60A5FA; }

.ri-employee {
    font-size: 11px;
    font-weight: 700;
    color: #DC2626;
    background: #FEE2E2;
    padding: 3px 10px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
html.dark-mode .ri-employee { background: #7F1D1D; color: #FCA5A5; }

.ri-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.ri-date {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.ri-date i { font-size: 10px; color: #DC2626; }

.ri-amount {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 16px;
    font-weight: 900;
    white-space: nowrap;
    letter-spacing: -0.3px;
}
.ri-amount-commission { color: #059669; }
.ri-amount-other { color: #7C3AED; }
html.dark-mode .ri-amount-commission { color: #34D399; }
html.dark-mode .ri-amount-other { color: #C4B5FD; }

/* EMPTY RECENT */
.empty-recent {
    text-align: center;
    padding: 50px 20px;
}
.empty-recent i {
    font-size: 50px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.empty-recent p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 16px 0;
}

/* ============================================================
   QUICK ACTIONS
   ============================================================ */
.quick-actions-wrapper {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    padding: 20px 22px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.qa-header {
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1.5px solid var(--border-color);
}
.qa-header h3 {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.qa-header h3 i { color: #DC2626; font-size: 16px; }

.qa-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.qa-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 18px;
    background: var(--bg-input);
    border-radius: 12px;
    text-decoration: none;
    border: 1.5px solid var(--border-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.qa-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px var(--shadow-hover);
}
.qa-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.qa-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
}
.qa-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.qa-desc {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.qa-commission .qa-icon { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; border: 1.5px solid #10B981; }
.qa-commission:hover { border-color: #10B981; }
html.dark-mode .qa-commission .qa-icon { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; }

.qa-add .qa-icon { background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1D4ED8; border: 1.5px solid #3B82F6; }
.qa-add:hover { border-color: #3B82F6; }
html.dark-mode .qa-add .qa-icon { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; }

.qa-other .qa-icon { background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED; border: 1.5px solid #A78BFA; }
.qa-other:hover { border-color: #A78BFA; }
html.dark-mode .qa-other .qa-icon { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; }

.qa-report .qa-icon { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706; border: 1.5px solid #FCD34D; }
.qa-report:hover { border-color: #FCD34D; }
html.dark-mode .qa-report .qa-icon { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .cards-grid-3 { grid-template-columns: repeat(2, 1fr); }
    .qa-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .capital-grid-3 { grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .cp-value { font-size: clamp(16px, 1.6vw, 20px); }
    .welcome-name { font-size: 20px; }
    .welcome-icon { width: 58px; height: 58px; font-size: 24px; }
}
@media (max-width: 768px) {
    .welcome-card-red { flex-direction: column; align-items: flex-start; padding: 18px 20px; }
    .welcome-date { width: 100%; justify-content: center; }
    .welcome-name { font-size: 18px; }
    .welcome-icon { width: 52px; height: 52px; font-size: 22px; }
    
    .capital-section-wrapper { padding: 16px; }
    .capital-section-header { flex-direction: column; align-items: flex-start; }
    .capital-grid-3 { grid-template-columns: 1fr; gap: 10px; }
    
    .cards-grid-3 { grid-template-columns: 1fr; gap: 10px; }
    .qa-grid { grid-template-columns: 1fr; gap: 10px; }
    
    .recent-item { flex-direction: column; align-items: flex-start; gap: 10px; }
    .ri-amount { font-size: 15px; align-self: flex-end; }
}
@media (max-width: 480px) {
    .welcome-name { font-size: 16px; }
    .welcome-icon { width: 46px; height: 46px; font-size: 18px; }
    .welcome-meta-item { font-size: 10px; padding: 3px 9px; }
    .csh-title { font-size: 14px; }
    .csh-icon { width: 40px; height: 40px; font-size: 18px; }
    .cp-value { font-size: 16px; }
    .cp-icon { width: 36px; height: 36px; font-size: 15px; }
    .nav-card { padding: 14px 16px; gap: 12px; min-height: 90px; }
    .nav-card-icon { width: 44px; height: 44px; font-size: 18px; }
    .nav-card-value { font-size: 16px; }
    .nav-card-arrow { width: 28px; height: 28px; font-size: 10px; }
    .qa-card { padding: 14px 16px; }
    .qa-icon { width: 40px; height: 40px; font-size: 16px; }
    .qa-title { font-size: 12px; }
    .section-header-view { padding: 14px 18px; }
    .recent-list { padding: 14px 18px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    console.log('%c🛡️ Admin Dashboard', 'font-size:16px; font-weight:bold; color:#DC2626;');
    console.log('%cBranch-wide data view', 'font-size:12px; color:#DC2626;');
    console.log('%cBranch filter iko kwenye TOPBAR tu ✅', 'font-size:12px; color:#F59E0B;');
});
</script>
</body>
</html>