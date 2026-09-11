<?php
// ================================================================
// FILE: modules/commissions/index.php
// WAKALA FINANCIAL SYSTEM - COMMISSIONS LIST
// ✅ FIXED: Export button redirects to export.php (server-side)
// ✅ FIXED: Full width + smaller font + no overflow
// ✅ FIXED: Support BOTH 'branch' AND 'branch_id'
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

$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll();

// ============================================================
// ✅ BRANCH FILTER - Support BOTH 'branch' AND 'branch_id'
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

$branch_filter = '';
$branch_params = [];
if ($selected_branch > 0) {
    $branch_filter = " AND c.branch_id = ? ";
    $branch_params[] = $selected_branch;
}

$commission_branch_name = 'All Branches';
$commission_branch_code = '';
if ($selected_branch > 0) {
    foreach ($all_branches as $b) {
        if ($b['id'] == $selected_branch) {
            $commission_branch_name = $b['branch_name'];
            $commission_branch_code = $b['branch_code'];
            break;
        }
    }
}

$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

// TODAY
$sql = "SELECT SUM(total_commission) as total FROM commissions WHERE DATE(commission_date) = ?";
$params = [$today];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$today_commission = $result['total'] ?? 0;

// THIS MONTH
$sql = "SELECT SUM(total_commission) as total FROM commissions WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ?";
$params = [$month, $year];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$this_month_commission = $result['total'] ?? 0;

// TOTAL COMMISSION
$sql = "SELECT SUM(total_commission) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$total_commission_all = $result['total'] ?? 0;

$card_commissions = $total_commission_all;

// OTHER INCOME
$sql = "SELECT SUM(other_income) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$card_other_income = $result['total'] ?? 0;

// EXPENSES
$card_expenses = 0;

$sql = "SELECT SUM(amount) as total FROM expenses WHERE is_business_expense = 1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $card_expenses += floatval($result['total'] ?? 0);
} catch (Exception $e) { }

$sql = "SELECT SUM(net_pay) as total FROM employee_salaries WHERE status = 'paid'";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $card_expenses += floatval($result['total'] ?? 0);
} catch (Exception $e) { }

// PROFIT
$card_profit = $card_commissions + $card_other_income - $card_expenses;

// GET COMMISSIONS LIST
$sql = "SELECT 
            c.id, c.commission_number, c.commission_date, c.provider_data,
            c.total_commission, c.other_income, c.total_business_income,
            c.allocate_to_capital, c.allocated_amount, c.created_at, c.notes,
            emp.full_name as employee_name,
            b.branch_name as branch_name, b.id as branch_id
        FROM commissions c
        LEFT JOIN employees emp ON c.employee_id = emp.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE 1=1 " . $branch_filter . "
        ORDER BY c.commission_date DESC, c.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($branch_params);
$commissions = $stmt->fetchAll();

$commission_count = count($commissions);

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }

$branch_qs = ($selected_branch > 0) ? '?branch_id=' . $selected_branch : '';

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Filtered Branch' : 'Showing All Branches'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($commission_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($commission_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($commission_branch_code); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-hand-holding-usd"></i> Commissions</h2>
                <span class="record-count"><?php echo $commission_count; ?> records</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="add.php<?php echo $branch_qs; ?>" class="btn btn-add-commission">
                        <i class="fas fa-plus-circle"></i> Add Commission
                    </a>
                    <a href="add_other_income.php<?php echo $branch_qs; ?>" class="btn btn-add-other">
                        <i class="fas fa-coins"></i> Add Other Income
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

        <!-- 4 SUMMARY CARDS - 2x2 GRID -->
        <div class="summaries-grid-2x2">
            
            <div class="summary-card card-commissions">
                <div class="summary-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="summary-content">
                    <div class="summary-label">COMMISSIONS</div>
                    <div class="summary-value" title="<?php echo formatCurrency($card_commissions); ?>"><?php echo formatCurrency($card_commissions); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>

            <div class="summary-card card-other-income">
                <div class="summary-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="summary-content">
                    <div class="summary-label">OTHER INCOME</div>
                    <div class="summary-value" title="<?php echo formatCurrency($card_other_income); ?>"><?php echo formatCurrency($card_other_income); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>

            <div class="summary-card card-expenses">
                <div class="summary-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="summary-content">
                    <div class="summary-label">EXPENSES</div>
                    <div class="summary-value" title="<?php echo formatCurrency($card_expenses); ?>"><?php echo formatCurrency($card_expenses); ?></div>
                    <div class="summary-sub">All Time (incl. Salaries)</div>
                </div>
            </div>

            <div class="summary-card card-profit <?php echo $card_profit < 0 ? 'card-loss' : ''; ?>">
                <div class="summary-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-content">
                    <div class="summary-label">PROFIT</div>
                    <div class="summary-value" title="<?php echo formatCurrency($card_profit); ?>"><?php echo formatCurrency($card_profit); ?></div>
                    <div class="summary-sub">Commissions + Other - Expenses</div>
                </div>
            </div>
            
        </div>

        <!-- QUICK STATS -->
        <div class="quick-stats-row">
            <div class="quick-stat-item">
                <span class="quick-stat-icon" style="background:#D1FAE5;color:#059669;">
                    <i class="fas fa-calendar-day"></i>
                </span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">TODAY COMMISSION</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_commission); ?></span>
                </div>
            </div>
            <div class="quick-stat-item">
                <span class="quick-stat-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-calendar-alt"></i>
                </span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">THIS MONTH</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($this_month_commission); ?></span>
                </div>
            </div>
        </div>

        <!-- TABLE -->
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
                    <div class="empty-actions">
                        <a href="add.php<?php echo $branch_qs; ?>" class="btn btn-add-commission">
                            <i class="fas fa-plus-circle"></i> Add Commission
                        </a>
                        <a href="add_other_income.php<?php echo $branch_qs; ?>" class="btn btn-add-other">
                            <i class="fas fa-coins"></i> Add Other Income
                        </a>
                    </div>
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
                                $provider_data = json_decode($commission['provider_data'] ?? '{}', true);
                                $provider_count = count($provider_data);
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><span class="commission-number"><?php echo htmlspecialchars($commission['commission_number']); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($commission['commission_date'])); ?></td>
                                    <td><span class="employee-name"><?php echo htmlspecialchars($commission['employee_name'] ?? 'N/A'); ?></span></td>
                                    <td><span class="branch-name"><?php echo htmlspecialchars($commission['branch_name'] ?? 'Main'); ?></span></td>
                                    <td>
                                        <span class="provider-count">
                                            <i class="fas fa-building"></i>
                                            <?php echo $provider_count; ?> providers
                                        </span>
                                    </td>
                                    <td><span class="amount commission"><?php echo formatCurrency($commission['total_commission']); ?></span></td>
                                    <td><span class="amount other-income"><?php echo formatCurrency($commission['other_income']); ?></span></td>
                                    <td><span class="amount total-income"><?php echo formatCurrency($commission['total_business_income']); ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-view" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-edit" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="delete.php?id=<?php echo $commission['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirmDelete(<?php echo $commission['id']; ?>)"><i class="fas fa-trash"></i></a>
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
    
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES - FULL WIDTH + SMALLER FONT + NO OVERFLOW
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --commission-bg: #f3f4f6;
    --commission-text: #1F2937;
    --commission-text-secondary: #6B7280;
    --commission-text-light: #9CA3AF;
    --commission-border: #E5E7EB;
    --commission-card-bg: #FFFFFF;
    --commission-input-bg: #F9FAFB;
    --commission-hover: #F3F4F6;
    --commission-shadow: rgba(0,0,0,0.06);
    --commission-shadow-lg: rgba(0,0,0,0.12);
    --commission-dropdown-bg: #FFFFFF;
    --commission-dropdown-border: #E5E7EB;
}

html.dark-mode {
    --commission-bg: #0f172a;
    --commission-text: #F1F5F9;
    --commission-text-secondary: #94A3B8;
    --commission-text-light: #64748B;
    --commission-border: #334155;
    --commission-card-bg: #1E293B;
    --commission-input-bg: #374151;
    --commission-hover: #2D3A4F;
    --commission-shadow: rgba(0,0,0,0.3);
    --commission-shadow-lg: rgba(0,0,0,0.5);
    --commission-dropdown-bg: #1E293B;
    --commission-dropdown-border: #334155;
}

/* ============================================================
   RESET - ZUIA OVERFLOW KABISA
   ============================================================ */
* {
    box-sizing: border-box;
}

html, body {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

body {
    background: var(--commission-bg) !important;
    color: var(--commission-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--commission-bg) !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* ============================================================
   FULL WIDTH - ZUIA HORIZONTAL SCROLL
   ============================================================ */
.main-content {
    background: var(--commission-bg) !important;
    width: 100% !important;
    max-width: 100% !important;
    padding: 16px 20px !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    box-sizing: border-box;
    display: block;
}

/* ============================================================
   RED BRANCH CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 14px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.3s ease forwards;
    flex-wrap: wrap;
    width: 100%;
}

.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}

.branch-status-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-status-name {
    font-size: 16px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-status-code {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding: 0 4px;
    flex-wrap: wrap;
    gap: 10px;
    width: 100%;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-header-left h2 {
    font-size: 18px;
    font-weight: 700;
    color: var(--commission-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #10B981;
    margin-right: 6px;
}

.record-count {
    font-size: 12px;
    color: var(--commission-text-secondary);
    background: var(--commission-hover);
    padding: 2px 10px;
    border-radius: 12px;
}

.header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-add-commission {
    background: #10B981;
    color: white;
    padding: 9px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}

.btn-add-commission:hover {
    background: #059669;
    transform: translateY(-1px);
    color: white;
}

.btn-add-other {
    background: #7C3AED;
    color: white;
    padding: 9px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}

.btn-add-other:hover {
    background: #6D28D9;
    transform: translateY(-1px);
    color: white;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 9px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}

.btn-export:hover {
    background: #1D4ED8;
    transform: translateY(-1px);
}

/* ============================================================
   DROPDOWN
   ============================================================ */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle i.fa-chevron-down {
    font-size: 10px;
    margin-left: 2px;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--commission-dropdown-bg);
    min-width: 180px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--commission-shadow-lg);
    border: 1px solid var(--commission-dropdown-border);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
}

.dropdown-menu.show { display: block; }

.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    text-decoration: none;
    color: var(--commission-text);
    font-size: 12px;
    font-weight: 500;
    transition: background 0.2s ease;
    white-space: nowrap;
}

.dropdown-menu a:hover {
    background: var(--commission-hover);
}

.dropdown-menu a i {
    width: 16px;
    font-size: 13px;
}

.dropdown-menu a i.fa-file-csv { color: #0B5ED7; }
.dropdown-menu a i.fa-file-excel { color: #1D7D1D; }
.dropdown-menu a i.fa-file-pdf { color: #DC2626; }
.dropdown-menu a i.fa-print { color: #6B7280; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    font-size: 13px;
    position: relative;
    animation: slideDown 0.4s ease forwards;
    width: 100%;
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

.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }

.alert-close {
    background: transparent;
    border: none;
    font-size: 20px;
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
   4 SUMMARY CARDS - 2x2 GRID
   ============================================================ */
.summaries-grid-2x2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 14px;
    width: 100%;
    max-width: 100%;
}

.summary-card {
    background: var(--commission-card-bg);
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
    min-height: 110px;
    height: auto;
    position: relative;
    overflow: hidden;
    min-width: 0;
    width: 100%;
    max-width: 100%;
}

.summary-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px var(--commission-shadow-lg);
}

.summary-icon {
    width: 52px;
    height: 52px;
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
    overflow: hidden;
    max-width: 100%;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    font-weight: 700;
    color: var(--commission-text-secondary);
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-value {
    font-size: clamp(14px, 1.15vw, 20px);
    font-weight: 800;
    color: var(--commission-text);
    margin: 4px 0;
    line-height: 1.2;
    word-break: break-all;
    overflow-wrap: anywhere;
    white-space: normal;
    letter-spacing: -0.3px;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

.summary-sub {
    font-size: 10px;
    color: var(--commission-text-light);
    font-weight: 500;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* CARD 1: COMMISSIONS */
.card-commissions::before { background: #10B981; }
.card-commissions .summary-icon { background: #D1FAE5; color: #059669; }
.card-commissions .summary-value { color: #059669; }

html.dark-mode .card-commissions .summary-icon { background: #065F46; color: #34D399; }
html.dark-mode .card-commissions .summary-value { color: #34D399; }

/* CARD 2: OTHER INCOME */
.card-other-income::before { background: #7C3AED; }
.card-other-income .summary-icon { background: #EDE9FE; color: #7C3AED; }
.card-other-income .summary-value { color: #7C3AED; }

html.dark-mode .card-other-income .summary-icon { background: #4C1D95; color: #A78BFA; }
html.dark-mode .card-other-income .summary-value { color: #A78BFA; }

/* CARD 3: EXPENSES */
.card-expenses::before { background: #DC2626; }
.card-expenses .summary-icon { background: #FEE2E2; color: #DC2626; }
.card-expenses .summary-value { color: #DC2626; }

html.dark-mode .card-expenses .summary-icon { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .card-expenses .summary-value { color: #FCA5A5; }

/* CARD 4: PROFIT */
.card-profit::before { background: #1D4ED8; }
.card-profit .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-profit .summary-value { color: #1D4ED8; }

html.dark-mode .card-profit .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-profit .summary-value { color: #60A5FA; }

/* CARD 4: PROFIT (LOSS) */
.card-profit.card-loss::before { background: #F59E0B; }
.card-profit.card-loss .summary-icon { background: #FEF3C7; color: #D97706; }
.card-profit.card-loss .summary-value { color: #D97706; }

html.dark-mode .card-profit.card-loss .summary-icon { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .card-profit.card-loss .summary-value { color: #FBBF24; }

/* ============================================================
   QUICK STATS ROW
   ============================================================ */
.quick-stats-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 14px;
    width: 100%;
    max-width: 100%;
}

.quick-stat-item {
    background: var(--commission-card-bg);
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
    min-width: 0;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.quick-stat-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--commission-shadow-lg);
}

.quick-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
}

.quick-stat-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    overflow: hidden;
    flex: 1;
}

.quick-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--commission-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.quick-stat-value {
    font-size: clamp(13px, 1vw, 16px);
    font-weight: 700;
    color: var(--commission-text);
    word-break: break-all;
    overflow-wrap: anywhere;
    line-height: 1.2;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--commission-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    overflow: hidden;
    width: 100%;
    max-width: 100%;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--commission-border);
    flex-wrap: wrap;
    gap: 10px;
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--commission-text);
    margin: 0;
}

.table-header h3 i {
    color: #10B981;
    margin-right: 6px;
}

.table-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input {
    padding: 7px 12px;
    border-radius: 8px;
    border: 1px solid var(--commission-border);
    font-size: 12px;
    outline: none;
    width: 220px;
    transition: all 0.3s ease;
    background: var(--commission-input-bg);
    color: var(--commission-text);
    max-width: 100%;
}

.search-input::placeholder {
    color: var(--commission-text-light);
}

.search-input:focus {
    border-color: #10B981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
}

.table-responsive {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
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
    padding: 11px 14px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--commission-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--commission-hover);
}

.data-table tbody td {
    padding: 11px 14px;
    color: var(--commission-text);
}

.commission-number {
    font-weight: 600;
    color: #10B981;
    font-size: 11px;
}

.employee-name {
    font-weight: 500;
    color: var(--commission-text);
}

.branch-name {
    background: var(--commission-hover);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    color: var(--commission-text-secondary);
}

.provider-count {
    font-size: 11px;
    color: var(--commission-text-secondary);
}

.provider-count i {
    color: #3B82F6;
    margin-right: 4px;
}

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

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-action {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 12px;
}

.btn-view { background: #DBEAFE; color: #1D4ED8; }
.btn-view:hover { background: #BFDBFE; }
.btn-edit { background: #D1FAE5; color: #059669; }
.btn-edit:hover { background: #A7F3D0; }
.btn-delete { background: #FEE2E2; color: #DC2626; }
.btn-delete:hover { background: #FECACA; }

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    width: 100%;
}

.empty-state i {
    font-size: 50px;
    color: #10B981;
    margin-bottom: 14px;
}

.empty-state h3 {
    font-size: 18px;
    color: var(--commission-text);
    margin: 0 0 6px 0;
}

.empty-state p {
    color: var(--commission-text-secondary);
    font-size: 13px;
    margin: 0 0 20px 0;
}

.empty-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-value {
        font-size: clamp(14px, 1.6vw, 18px);
    }
}

@media (max-width: 1024px) {
    .main-content {
        padding: 14px 16px !important;
    }
    
    .summary-card {
        padding: 14px 16px;
        min-height: 100px;
        gap: 12px;
    }
    
    .summary-icon {
        width: 46px;
        height: 46px;
        font-size: 18px;
    }
    
    .summary-value {
        font-size: clamp(13px, 1.8vw, 17px);
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 12px !important;
    }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
    }
    
    .branch-status-info {
        width: 100%;
    }
    
    .page-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    
    .header-actions .btn-add-commission,
    .header-actions .btn-add-other,
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
    
    .summaries-grid-2x2 {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .summary-card {
        padding: 14px 16px;
        min-height: 90px;
        gap: 12px;
    }
    
    .summary-icon {
        width: 44px;
        height: 44px;
        font-size: 17px;
    }
    
    .summary-value {
        font-size: clamp(14px, 3.5vw, 18px);
    }
    
    .quick-stats-row {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .table-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .table-actions {
        width: 100%;
    }
    
    .search-input {
        width: 100%;
    }
    
    .empty-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .empty-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 10px !important;
    }
    
    .branch-status-card {
        flex-direction: column;
        text-align: center;
        gap: 6px;
        padding: 10px 12px;
    }
    
    .branch-status-info {
        justify-content: center;
    }
    
    .branch-status-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .branch-status-name {
        font-size: 14px;
    }
    
    .summaries-grid-2x2 {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .summary-card {
        padding: 12px 14px;
        min-height: 85px;
        gap: 10px;
    }
    
    .summary-icon {
        width: 42px;
        height: 42px;
        font-size: 16px;
    }
    
    .summary-value {
        font-size: clamp(13px, 4vw, 16px);
        letter-spacing: -0.2px;
    }
    
    .summary-label {
        font-size: 10px;
    }
    
    .summary-sub {
        font-size: 9px;
    }
    
    .data-table thead th,
    .data-table tbody td {
        padding: 8px 10px;
        font-size: 11px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }
    
    .btn-action {
        width: 26px;
        height: 26px;
        font-size: 11px;
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
.summary-card:nth-child(4) { animation-delay: 0.20s; }
</style>

<script>
// ============================================================
// DROPDOWN TOGGLE
// ============================================================
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

// ============================================================
// ✅ EXPORT - Redirect to export.php (Server-side)
// ============================================================
function exportData(format) {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.remove('show');
    
    // Build URL with parameters
    var params = new URLSearchParams();
    params.set('format', format);
    
    // Add branch filter if set
    var selectedBranch = '<?php echo $selected_branch; ?>';
    if (selectedBranch && selectedBranch !== '0' && selectedBranch !== '') {
        params.set('branch_id', selectedBranch);
    }
    
    // Add search filter if set
    var searchInput = document.getElementById('searchInput');
    if (searchInput && searchInput.value.trim()) {
        params.set('search', searchInput.value.trim());
    }
    
    // ✅ Redirect to export.php
    window.location.href = 'export.php?' + params.toString();
}

// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(id) {
    return confirm('Are you sure you want to delete this commission record?\n\nThis action cannot be undone.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    
    // Search filter
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#commissionsTable tbody tr');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
            });
        });
    }
    
    // Dark mode sync
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    
    // Close alert buttons
    var closeBtns = document.querySelectorAll('.alert-close');
    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
});
</script>
</body>
</html>