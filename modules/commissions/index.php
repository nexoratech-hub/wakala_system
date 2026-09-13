<?php
// ================================================================
// FILE: modules/commissions/index.php
// WAKALA FINANCIAL SYSTEM - COMMISSIONS LIST
// ✅ NEW: BLUE branch card
// ✅ NEW: Single continuous table (all providers 1-30)
// ✅ NEW: Red line separator between branches
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
// HANDLE DELETE COMMISSIONS FOR PROVIDER
// ============================================================
if (isset($_GET['delete_provider_commissions']) && isset($_GET['branch_id']) && isset($_GET['provider_id'])) {
    try {
        $delete_branch_id = intval($_GET['branch_id']);
        $delete_provider_id = intval($_GET['provider_id']);
        
        $stmt = $db->prepare("
            DELETE FROM commissions 
            WHERE branch_id = ? 
            AND JSON_EXTRACT(provider_data, CONCAT('$.\"', ?, '\"')) IS NOT NULL
        ");
        $stmt->execute([$delete_branch_id, $delete_provider_id]);
        
        $deleted_count = $stmt->rowCount();
        
        logActivity(
            $user_id, 
            'Delete Provider Commissions', 
            'Commissions', 
            0, 
            '', 
            'Deleted ' . $deleted_count . ' commission(s) for provider ID: ' . $delete_provider_id
        );
        
        $_SESSION['success_message'] = $deleted_count . ' commission record(s) deleted successfully. Provider remains active with 0 commission.';
        header('Location: index.php?branch_id=' . $delete_branch_id);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        header('Location: index.php');
        exit();
    }
}

// ============================================================
// BRANCH FILTER
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

// ============================================================
// CAPITAL DATA
// ============================================================
$total_float = 0;
$total_cash = 0;
$total_capital = 0;

$sql_float = "SELECT COALESCE(SUM(drp.current_float), 0) as total_float
              FROM daily_report_providers drp
              INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
              WHERE 1=1";
$params_float = [];
if ($selected_branch > 0) { $sql_float .= " AND dr.branch_id = ?"; $params_float[] = $selected_branch; }
$stmt = $db->prepare($sql_float);
$stmt->execute($params_float);
$total_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

$sql_cash = "SELECT COALESCE(SUM(current_cash), 0) as total_cash
             FROM daily_reports
             WHERE 1=1";
$params_cash = [];
if ($selected_branch > 0) { $sql_cash .= " AND branch_id = ?"; $params_cash[] = $selected_branch; }
$stmt = $db->prepare($sql_cash);
$stmt->execute($params_cash);
$total_cash = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_cash'] ?? 0);

$total_capital = $total_float + $total_cash;

// ============================================================
// SUMMARY CARDS
// ============================================================
$sql = "SELECT SUM(total_commission) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$card_commissions = $result['total'] ?? 0;

$sql = "SELECT SUM(other_income) as total FROM commissions WHERE 1=1";
$params = [];
if ($selected_branch > 0) { $sql .= " AND branch_id = ?"; $params[] = $selected_branch; }
$stmt = $db->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetch();
$card_other_income = $result['total'] ?? 0;

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

$card_profit = $card_commissions + $card_other_income - $card_expenses;

// ============================================================
// ✅ GET ALL PROVIDERS AS ONE FLAT LIST (grouped by branch order)
// ============================================================
$all_providers_flat = [];

$branch_where = "";
$branch_params_providers = [];
if ($selected_branch > 0) {
    $branch_where = " AND b.id = ? ";
    $branch_params_providers[] = $selected_branch;
}

$sql = "SELECT 
            b.id as branch_id,
            b.branch_name,
            b.branch_code,
            b.location,
            p.id as provider_id,
            p.provider_name,
            p.provider_code,
            p.icon_class,
            p.color_code,
            p.provider_type,
            bp.provider_code as branch_provider_code,
            (SELECT SUM(CAST(JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) AS DECIMAL(15,2)))
             FROM commissions c 
             WHERE c.branch_id = b.id 
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL) as total_provider_commission,
            (SELECT emp.full_name 
             FROM commissions c 
             LEFT JOIN employees emp ON c.employee_id = emp.id
             WHERE c.branch_id = b.id 
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL
             ORDER BY c.id DESC LIMIT 1) as last_added_by,
            (SELECT c.commission_date 
             FROM commissions c 
             WHERE c.branch_id = b.id 
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL
             ORDER BY c.id DESC LIMIT 1) as last_commission_date,
            (SELECT COUNT(*) 
             FROM commissions c 
             WHERE c.branch_id = b.id 
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL) as commission_count
        FROM branches b
        INNER JOIN branch_providers bp ON b.id = bp.branch_id AND bp.is_active = 1
        INNER JOIN providers p ON bp.provider_id = p.id AND p.is_active = 1
        WHERE b.is_active = 1 " . $branch_where . "
        ORDER BY b.branch_name ASC, p.display_order ASC, p.provider_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($branch_params_providers);
$provider_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Track branch transitions for red line
$previous_branch_id = null;
$total_providers_count = count($provider_rows);
$total_branches_count = 0;
$branch_ids_seen = [];

foreach ($provider_rows as $row) {
    if ($row['branch_id'] != $previous_branch_id) {
        $is_first_of_branch = ($previous_branch_id !== null);
        $previous_branch_id = $row['branch_id'];
        
        if (!in_array($row['branch_id'], $branch_ids_seen)) {
            $branch_ids_seen[] = $row['branch_id'];
        }
    } else {
        $is_first_of_branch = false;
    }
    
    $all_providers_flat[] = [
        'branch_id' => $row['branch_id'],
        'branch_name' => $row['branch_name'],
        'branch_code' => $row['branch_code'] ?? '',
        'branch_location' => $row['location'] ?? '',
        'provider_id' => $row['provider_id'],
        'provider_name' => $row['provider_name'],
        'provider_code' => $row['branch_provider_code'] ?? $row['provider_code'],
        'icon_class' => $row['icon_class'] ?? 'fas fa-university',
        'color_code' => $row['color_code'] ?? '#3B82F6',
        'provider_type' => $row['provider_type'] ?? 'bank',
        'total_commission' => floatval($row['total_provider_commission'] ?? 0),
        'last_added_by' => $row['last_added_by'] ?? 'N/A',
        'last_commission_date' => $row['last_commission_date'] ?? null,
        'commission_count' => intval($row['commission_count'] ?? 0),
        'is_first_of_branch' => $is_first_of_branch,
        'is_first_overall' => ($previous_branch_id === $row['branch_id'] && count($all_providers_flat) === 0)
    ];
}

$total_branches_count = count($branch_ids_seen);

// ============================================================
// GET COMMISSIONS LIST (for count)
// ============================================================
$sql = "SELECT COUNT(*) as total
        FROM commissions c
        WHERE 1=1 " . $branch_filter;
$stmt = $db->prepare($sql);
$stmt->execute($branch_params);
$commission_count = intval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

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
        
        <!-- ============================================================
             ✅ BLUE BRANCH CARD
             ============================================================ -->
        <div class="branch-status-card-blue">
            <div class="branch-status-icon-blue">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info-blue">
                <span class="branch-status-label-blue">
                    <?php echo $selected_branch > 0 ? 'Filtered Branch' : 'Showing All Branches'; ?>
                </span>
                <span class="branch-status-name-blue"><?php echo htmlspecialchars($commission_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($commission_branch_code)): ?>
                    <span class="branch-status-code-blue"><?php echo htmlspecialchars($commission_branch_code); ?></span>
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
                    <a href="add_capital.php<?php echo $branch_qs; ?>" class="btn btn-add-capital">
                        <i class="fas fa-plus"></i> Add Capital
                    </a>
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

        <!-- CAPITAL SECTION (3 Parts) -->
        <div class="capital-section-wrapper">
            <div class="capital-section-header">
                <div class="csh-left">
                    <div class="csh-icon">
                        <i class="fas fa-vault"></i>
                    </div>
                    <div class="csh-info">
                        <span class="csh-title">Capital Overview</span>
                        <span class="csh-subtitle"><?php echo htmlspecialchars($commission_branch_name); ?></span>
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

        <!-- 4 SUMMARY CARDS -->
        <div class="summaries-grid-2x2">
            <div class="summary-card card-commissions">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">COMMISSIONS</div>
                    <div class="summary-value"><?php echo formatCurrency($card_commissions); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>

            <div class="summary-card card-other-income">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-content">
                    <div class="summary-label">OTHER INCOME</div>
                    <div class="summary-value"><?php echo formatCurrency($card_other_income); ?></div>
                    <div class="summary-sub">All Time</div>
                </div>
            </div>

            <div class="summary-card card-expenses">
                <div class="summary-icon"><i class="fas fa-receipt"></i></div>
                <div class="summary-content">
                    <div class="summary-label">EXPENSES</div>
                    <div class="summary-value"><?php echo formatCurrency($card_expenses); ?></div>
                    <div class="summary-sub">All Time (incl. Salaries)</div>
                </div>
            </div>

            <div class="summary-card card-profit <?php echo $card_profit < 0 ? 'card-loss' : ''; ?>">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-content">
                    <div class="summary-label">PROFIT</div>
                    <div class="summary-value"><?php echo formatCurrency($card_profit); ?></div>
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

        <!-- ============================================================
             ✅ SINGLE CONTINUOUS TABLE (All Providers 1-30)
             Red line separator between branches
             ============================================================ -->
        <div class="table-container">
            
            <!-- RED HEADER with Search + Scroll + Count -->
            <div class="table-header-red-with-controls">
                <div class="thrc-left">
                    <div class="provider-search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="providerSearchInput" 
                               placeholder="Search provider or branch..."
                               oninput="filterProviders(this)">
                        <button type="button" id="providerSearchClear" onclick="clearProviderSearch()" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="provider-search-count" id="providerSearchCount" style="display:none;">0</span>
                    </div>
                </div>
                
                <div class="thrc-center">
                    <button type="button" class="scroll-btn" onclick="scrollProviderTable('left')" title="Scroll Left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="scroll-label">
                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                    </span>
                    <button type="button" class="scroll-btn" onclick="scrollProviderTable('right')" title="Scroll Right">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div class="thrc-right">
                    <span class="record-count-red">
                        <i class="fas fa-university"></i>
                        <?php echo $total_providers_count; ?> providers / <?php echo $total_branches_count; ?> branches
                    </span>
                </div>
            </div>

            <?php if (count($all_providers_flat) > 0): ?>
                
                <div class="table-responsive" id="providerTableWrapper">
                    <table class="data-table providers-table" id="providersTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Provider</th>
                                <th>Branch</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th class="text-right">Total Commission</th>
                                <th>Last Date</th>
                                <th>Added By</th>
                                <th style="width: 130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $global_row = 1;
                            $current_branch_in_list = null;
                            foreach ($all_providers_flat as $index => $p): 
                                $is_new_branch = ($current_branch_in_list !== $p['branch_id']);
                                $current_branch_in_list = $p['branch_id'];
                                
                                $provider_search = strtolower(
                                    $p['provider_name'] . ' ' . 
                                    $p['provider_code'] . ' ' . 
                                    $p['branch_name'] . ' ' . 
                                    $p['last_added_by']
                                );
                            ?>
                                <?php if ($is_new_branch && $global_row > 1): ?>
                                    <!-- ✅ RED LINE SEPARATOR between branches -->
                                    <tr class="branch-separator-row">
                                        <td colspan="9">
                                            <div class="branch-separator-line"></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <tr class="provider-row-item" 
                                    data-branch-id="<?php echo $p['branch_id']; ?>"
                                    data-search="<?php echo htmlspecialchars($provider_search); ?>">
                                    <td class="row-number"><?php echo $global_row++; ?></td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($p['color_code']); ?>;">
                                                <i class="<?php echo htmlspecialchars($p['icon_class']); ?>"></i>
                                            </div>
                                            <div class="provider-info-text">
                                                <span class="provider-name-text"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="branch-badge-cell">
                                            <i class="fas fa-store-alt"></i>
                                            <?php echo htmlspecialchars($p['branch_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo htmlspecialchars($p['provider_type']); ?>">
                                            <i class="fas fa-tag"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $p['provider_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount commission-amount <?php echo $p['total_commission'] > 0 ? '' : 'amount-zero'; ?>">
                                            <?php echo formatCurrency($p['total_commission']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($p['last_commission_date']): ?>
                                            <span class="date-cell">
                                                <i class="far fa-calendar"></i>
                                                <?php echo date('d M Y', strtotime($p['last_commission_date'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="date-cell date-empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="added-by-cell">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars($p['last_added_by']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="provider-actions">
                                            <a href="view_provider_commissions.php?provider_id=<?php echo $p['provider_id']; ?>&branch_id=<?php echo $p['branch_id']; ?>" 
                                               class="btn-provider btn-provider-view" 
                                               title="View Commissions">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <a href="add.php?branch_id=<?php echo $p['branch_id']; ?>&provider_id=<?php echo $p['provider_id']; ?>" 
                                               class="btn-provider btn-provider-edit" 
                                               title="Add/Edit Commission">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <a href="index.php?delete_provider_commissions=1&branch_id=<?php echo $p['branch_id']; ?>&provider_id=<?php echo $p['provider_id']; ?>" 
                                               class="btn-provider btn-provider-delete" 
                                               onclick="return confirmDeleteCommissions('<?php echo addslashes($p['provider_name']); ?>', <?php echo $p['commission_count']; ?>)"
                                               title="Delete Commissions (Provider remains)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="no-provider-results" id="noProviderResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No providers match your search</p>
                    <button type="button" class="btn btn-reset" onclick="clearProviderSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-university"></i>
                    <h3>No Providers Found</h3>
                    <p>Add providers to branches to see them here.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

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
* { box-sizing: border-box; }
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
.main-content {
    background: var(--commission-bg) !important;
    width: 100% !important;
    max-width: 100% !important;
    padding: 16px 20px !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    display: block;
}

/* ============================================================
   ✅ BLUE BRANCH CARD
   ============================================================ */
.branch-status-card-blue {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 12px;
    margin-bottom: 14px;
    box-shadow: 0 4px 20px rgba(30, 64, 175, 0.35);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
    width: 100%;
    color: #FFFFFF;
}
.branch-status-card-blue::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-card-blue::after {
    content: '';
    position: absolute;
    bottom: -60%; left: 20%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.04);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-icon-blue {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FCD34D;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 1px solid rgba(255, 255, 255, 0.15);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
}
.branch-status-info-blue {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}
.branch-status-label-blue {
    font-size: 10px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name-blue {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.branch-status-code-blue {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    letter-spacing: 0.8px;
    font-family: 'Courier New', monospace;
}

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding: 0 4px;
    flex-wrap: wrap; gap: 10px; width: 100%;
}
.page-header-left { display: flex; align-items: center; gap: 10px; }
.page-header-left h2 {
    font-size: 18px; font-weight: 700;
    color: var(--commission-text); margin: 0;
}
.page-header-left h2 i { color: #10B981; margin-right: 6px; }
.record-count {
    font-size: 12px; color: var(--commission-text-secondary);
    background: var(--commission-hover);
    padding: 2px 10px; border-radius: 12px;
}
.header-actions {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}

/* BUTTONS */
.btn-add-capital {
    background: #F59E0B; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}
.btn-add-capital:hover {
    background: #D97706; transform: translateY(-1px); color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
}
.btn-add-commission {
    background: #10B981; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
}
.btn-add-commission:hover {
    background: #059669; transform: translateY(-1px); color: white;
}
.btn-add-other {
    background: #7C3AED; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
}
.btn-add-other:hover {
    background: #6D28D9; transform: translateY(-1px); color: white;
}
.btn-export {
    background: #1E40AF; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-export:hover { background: #1D4ED8; transform: translateY(-1px); }
.btn-reset {
    background: var(--commission-hover);
    color: var(--commission-text-secondary);
    border: 1px solid var(--commission-border);
    padding: 8px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    cursor: pointer; transition: all 0.3s ease;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-reset:hover {
    background: var(--commission-border);
    color: var(--commission-text);
}

/* DROPDOWN */
.dropdown { position: relative; display: inline-block; }
.dropdown-toggle i.fa-chevron-down { font-size: 10px; margin-left: 2px; }
.dropdown-menu {
    display: none; position: absolute; right: 0; top: 100%;
    margin-top: 4px;
    background: var(--commission-dropdown-bg);
    min-width: 180px; border-radius: 8px;
    box-shadow: 0 4px 20px var(--commission-shadow-lg);
    border: 1px solid var(--commission-dropdown-border);
    z-index: 1000; overflow: hidden; padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 14px; text-decoration: none;
    color: var(--commission-text); font-size: 12px;
    font-weight: 500; transition: background 0.2s ease;
    white-space: nowrap;
}
.dropdown-menu a:hover { background: var(--commission-hover); }
.dropdown-menu a i { width: 16px; font-size: 13px; }
.dropdown-menu a i.fa-file-csv { color: #0B5ED7; }
.dropdown-menu a i.fa-file-excel { color: #1D7D1D; }
.dropdown-menu a i.fa-file-pdf { color: #DC2626; }
.dropdown-menu a i.fa-print { color: #6B7280; }

/* ALERTS */
.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 14px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
    width: 100%;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 20px;
    color: inherit; cursor: pointer; padding: 0 4px;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

/* CAPITAL SECTION */
.capital-section-wrapper {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 16px; padding: 22px 26px; margin-bottom: 16px;
    box-shadow: 0 6px 24px rgba(30, 64, 175, 0.35);
    position: relative; overflow: hidden; color: #FFFFFF;
}
.capital-section-wrapper::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.capital-section-header {
    display: flex; justify-content: space-between; align-items: center;
    gap: 16px; margin-bottom: 18px; padding-bottom: 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    position: relative; z-index: 1; flex-wrap: wrap;
}
.csh-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.csh-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 12px; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D; flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.csh-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.csh-title { font-size: 16px; font-weight: 800; color: #FFFFFF; letter-spacing: 0.3px; }
.csh-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.csh-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap; text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 2px 10px rgba(252, 211, 77, 0.2);
}

.capital-grid-3 {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; position: relative; z-index: 1;
}
.capital-part {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 14px; padding: 18px 20px;
    display: flex; flex-direction: column; gap: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0; position: relative; overflow: hidden;
}
.capital-part::before {
    content: ''; position: absolute; top: 0; left: 0;
    width: 4px; height: 100%;
}
.capital-part:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.part-float::before { background: #60A5FA; }
.part-cash::before { background: #86EFAC; }
.part-total::before { background: #FCD34D; }

.cp-header { display: flex; align-items: center; gap: 10px; }
.cp-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.cp-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.cp-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.cp-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }

.cp-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.cp-value {
    font-size: clamp(18px, 1.6vw, 26px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.15;
    word-break: break-word;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
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
.cp-sublabel i { font-size: 10px; }

/* SUMMARY CARDS */
.summaries-grid-2x2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
    width: 100%; max-width: 100%;
}
.summary-card {
    background: var(--commission-card-bg);
    border-radius: 12px; padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
    min-height: 110px; position: relative;
    overflow: hidden; min-width: 0;
}
.summary-card::before {
    content: ''; position: absolute;
    top: 0; left: 0; width: 5px; height: 100%;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px var(--commission-shadow-lg);
}
.summary-icon {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.summary-content {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column;
    justify-content: center; overflow: hidden;
}
.summary-label {
    font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--commission-text-secondary);
    margin-bottom: 4px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.summary-value {
    font-size: clamp(14px, 1.15vw, 20px);
    font-weight: 800; color: var(--commission-text);
    margin: 4px 0; line-height: 1.2;
    word-break: break-all; overflow-wrap: anywhere;
    letter-spacing: -0.3px;
}
.summary-sub {
    font-size: 10px; color: var(--commission-text-light);
    font-weight: 500; margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.card-commissions::before { background: #10B981; }
.card-commissions .summary-icon { background: #D1FAE5; color: #059669; }
.card-commissions .summary-value { color: #059669; }
html.dark-mode .card-commissions .summary-icon { background: #065F46; color: #34D399; }
html.dark-mode .card-commissions .summary-value { color: #34D399; }
.card-other-income::before { background: #7C3AED; }
.card-other-income .summary-icon { background: #EDE9FE; color: #7C3AED; }
.card-other-income .summary-value { color: #7C3AED; }
html.dark-mode .card-other-income .summary-icon { background: #4C1D95; color: #A78BFA; }
html.dark-mode .card-other-income .summary-value { color: #A78BFA; }
.card-expenses::before { background: #DC2626; }
.card-expenses .summary-icon { background: #FEE2E2; color: #DC2626; }
.card-expenses .summary-value { color: #DC2626; }
html.dark-mode .card-expenses .summary-icon { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .card-expenses .summary-value { color: #FCA5A5; }
.card-profit::before { background: #1D4ED8; }
.card-profit .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-profit .summary-value { color: #1D4ED8; }
html.dark-mode .card-profit .summary-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .card-profit .summary-value { color: #60A5FA; }
.card-profit.card-loss::before { background: #F59E0B; }
.card-profit.card-loss .summary-icon { background: #FEF3C7; color: #D97706; }
.card-profit.card-loss .summary-value { color: #D97706; }
html.dark-mode .card-profit.card-loss .summary-icon { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .card-profit.card-loss .summary-value { color: #FBBF24; }

/* QUICK STATS */
.quick-stats-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
    width: 100%; max-width: 100%;
}
.quick-stat-item {
    background: var(--commission-card-bg);
    border-radius: 12px; padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    transition: all 0.3s ease;
    min-width: 0; overflow: hidden;
}
.quick-stat-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--commission-shadow-lg);
}
.quick-stat-icon {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.quick-stat-info {
    display: flex; flex-direction: column;
    gap: 3px; min-width: 0; overflow: hidden; flex: 1;
}
.quick-stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.5px; font-weight: 700;
    color: var(--commission-text-secondary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.quick-stat-value {
    font-size: clamp(13px, 1vw, 16px);
    font-weight: 700; color: var(--commission-text);
    word-break: break-all; line-height: 1.2;
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--commission-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--commission-shadow);
    border: 1px solid var(--commission-border);
    overflow: hidden; width: 100%; max-width: 100%;
}

/* RED HEADER with Search + Scroll + Count */
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
.thrc-left {
    display: flex; align-items: center; justify-content: flex-start;
    position: relative; z-index: 1;
}
.thrc-center {
    display: flex; align-items: center; justify-content: center;
    gap: 12px; position: relative; z-index: 1;
}
.thrc-right {
    display: flex; align-items: center; justify-content: flex-end;
    position: relative; z-index: 1;
}

/* Provider Search */
.provider-search-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 12px;
    width: 300px;
    max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.provider-search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.provider-search-wrapper i {
    color: #DC2626;
    font-size: 12px;
    flex-shrink: 0;
}
.provider-search-wrapper input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 12px;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-family: 'Inter', sans-serif;
}
.provider-search-wrapper input::placeholder {
    color: #9CA3AF;
    font-size: 11px;
}
.provider-search-wrapper button {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.provider-search-wrapper button:hover {
    background: #DC2626;
    color: white;
    transform: scale(1.1);
}
.provider-search-count {
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    flex-shrink: 0;
    white-space: nowrap;
}

/* Scroll buttons */
.scroll-btn {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #DC2626;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn:active { transform: translateY(0); }
.scroll-label {
    font-size: 11px;
    font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    padding: 0 6px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
.scroll-label i { font-size: 12px; }

.record-count-red {
    font-size: 11px;
    font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.record-count-red i {
    font-size: 11px;
    color: #FCD34D;
}

/* TABLE RESPONSIVE */
.table-responsive {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track {
    background: var(--commission-hover);
    border-radius: 4px;
}
.table-responsive::-webkit-scrollbar-thumb {
    background: #DC2626;
    border-radius: 4px;
}
.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #B91C1C;
}

/* DATA TABLE */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.data-table thead {
    background: #DC2626;
    position: sticky;
    top: 0;
    z-index: 5;
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
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--commission-border);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--commission-hover); }
.data-table tbody td {
    padding: 11px 14px;
    color: var(--commission-text);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

/* ============================================================
   ✅ BRANCH SEPARATOR ROW (RED LINE)
   ============================================================ */
.branch-separator-row {
    background: transparent !important;
    border: none !important;
    height: 0;
}
.branch-separator-row td {
    padding: 0 !important;
    border: none !important;
    height: 0;
    background: transparent !important;
}
.branch-separator-line {
    height: 4px;
    background: linear-gradient(90deg, #DC2626 0%, #B91C1C 50%, #DC2626 100%);
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.4);
    border-radius: 2px;
    margin: 8px 0;
    position: relative;
}
.branch-separator-line::before {
    content: '';
    position: absolute;
    top: -2px;
    left: 0;
    right: 0;
    height: 1px;
    background: rgba(220, 38, 38, 0.3);
}
.branch-separator-line::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 1px;
    background: rgba(220, 38, 38, 0.3);
}

/* PROVIDER ROW */
.provider-row-item {
    background: var(--commission-card-bg);
}
.provider-row-item:hover {
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.04), rgba(124, 58, 237, 0.02)) !important;
}
.provider-row-item.hidden-by-search {
    display: none !important;
}
.branch-separator-row.hidden-by-search {
    display: none !important;
}

/* Row Number */
.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--commission-hover);
    font-size: 11px; font-weight: 700;
    color: var(--commission-text-secondary);
    border: 1px solid var(--commission-border);
}

/* Provider Cell */
.provider-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
.provider-icon-circle {
    width: 38px; height: 38px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 16px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
    border: 2px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
}
.provider-icon-circle:hover {
    transform: scale(1.08) rotate(-5deg);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.25);
}
.provider-info-text {
    display: flex; flex-direction: column;
    gap: 2px; min-width: 0;
}
.provider-name-text {
    font-weight: 800; color: var(--commission-text);
    font-size: 13px; letter-spacing: 0.2px;
    white-space: nowrap;
}

/* ✅ Branch Badge Cell */
.branch-badge-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E40AF;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
    box-shadow: 0 2px 4px rgba(29, 78, 216, 0.1);
}
.branch-badge-cell i {
    color: #2563EB;
    font-size: 10px;
}
html.dark-mode .branch-badge-cell {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD;
    border-color: #3B82F6;
}
html.dark-mode .branch-badge-cell i { color: #60A5FA; }

/* Code Badge */
.code-badge {
    display: inline-flex; align-items: center;
    padding: 4px 10px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
html.dark-mode .code-badge {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD; border-color: #3B82F6;
}

/* Type Badge */
.type-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 8px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.type-badge i { font-size: 9px; }
.type-bank { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
.type-mobile_money,
.type-mobile { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
.type-wallet { background: #EDE9FE; color: #5B21B6; border: 1px solid #C4B5FD; }
.type-sacco { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
.type-default { background: #F3F4F6; color: #374151; border: 1px solid #E5E7EB; }
html.dark-mode .type-bank { background: #1E3A5F; color: #93C5FD; border-color: #3B82F6; }
html.dark-mode .type-mobile_money,
html.dark-mode .type-mobile { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .type-wallet { background: #4C1D95; color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .type-sacco { background: #14532D; color: #4ADE80; border-color: #16A34A; }

/* Commission Amount */
.commission-amount {
    display: inline-flex; align-items: center;
    padding: 6px 12px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #065F46; border-radius: 8px;
    font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #6EE7B7;
    white-space: nowrap;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.1);
}
.commission-amount.amount-zero {
    background: var(--commission-hover);
    color: var(--commission-text-light);
    border-color: var(--commission-border);
    box-shadow: none;
}
html.dark-mode .commission-amount {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #D1FAE5; border-color: #10B981;
}

/* Date Cell */
.date-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--commission-text-secondary);
    white-space: nowrap;
}
.date-cell i { color: #7C3AED; font-size: 10px; }
.date-cell.date-empty {
    color: var(--commission-text-light);
    font-style: italic;
}

/* Added By Cell */
.added-by-cell {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #92400E; border-radius: 8px;
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
    border: 1px solid #FCD34D;
    box-shadow: 0 2px 4px rgba(217, 119, 6, 0.1);
}
.added-by-cell i { color: #D97706; font-size: 12px; }
html.dark-mode .added-by-cell {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FCD34D; border-color: #F59E0B;
}
html.dark-mode .added-by-cell i { color: #FBBF24; }

/* Provider Actions */
.provider-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
    align-items: center;
}
.btn-provider {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 14px;
    position: relative;
    overflow: hidden;
}
.btn-provider-view {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
    box-shadow: 0 2px 6px rgba(29, 78, 216, 0.15);
}
.btn-provider-view:hover {
    background: linear-gradient(135deg, #1D4ED8, #2563EB);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.4);
}
.btn-provider-edit {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border: 1.5px solid #FCD34D;
    box-shadow: 0 2px 6px rgba(217, 119, 6, 0.15);
}
.btn-provider-edit:hover {
    background: linear-gradient(135deg, #D97706, #F59E0B);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(217, 119, 6, 0.4);
}
.btn-provider-delete {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
    box-shadow: 0 2px 6px rgba(153, 27, 27, 0.15);
}
.btn-provider-delete:hover {
    background: linear-gradient(135deg, #991B1B, #DC2626);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(153, 27, 27, 0.4);
}
html.dark-mode .btn-provider-view {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .btn-provider-edit {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FBBF24; border-color: #F59E0B;
}
html.dark-mode .btn-provider-delete {
    background: linear-gradient(135deg, #7F1D1D, #991B1B);
    color: #FCA5A5; border-color: #DC2626;
}

/* No Results */
.no-provider-results {
    text-align: center;
    padding: 40px 20px;
    background: var(--commission-hover);
    display: none;
}
.no-provider-results i {
    font-size: 44px;
    color: var(--commission-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-provider-results p {
    font-size: 14px;
    color: var(--commission-text-secondary);
    margin: 0 0 16px 0;
    font-weight: 500;
}

/* Empty State */
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

/* RESPONSIVE */
@media (max-width: 1200px) {
    .summary-value { font-size: clamp(14px, 1.6vw, 18px); }
    .table-header-red-with-controls {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .thrc-left, .thrc-center, .thrc-right {
        justify-content: center;
        width: 100%;
    }
    .provider-search-wrapper {
        width: 100%;
    }
}
@media (max-width: 1024px) {
    .main-content { padding: 14px 16px !important; }
    .summary-card { padding: 14px 16px; min-height: 100px; gap: 12px; }
    .summary-icon { width: 46px; height: 46px; font-size: 18px; }
    .capital-grid-3 { grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .cp-value { font-size: clamp(16px, 1.6vw, 20px); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card-blue { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .branch-status-info-blue { width: 100%; }
    .page-header { flex-direction: column; gap: 10px; align-items: flex-start; }
    .header-actions { width: 100%; flex-direction: column; align-items: stretch; }
    .header-actions .btn-add-capital,
    .header-actions .btn-add-commission,
    .header-actions .btn-add-other,
    .header-actions .btn-export { justify-content: center; width: 100%; }
    .dropdown { width: 100%; }
    .dropdown-menu { width: 100%; right: auto; left: 0; }
    .capital-section-wrapper { padding: 16px; }
    .capital-section-header { flex-direction: column; align-items: flex-start; }
    .capital-grid-3 { grid-template-columns: 1fr; gap: 10px; }
    .summaries-grid-2x2 { grid-template-columns: 1fr; gap: 10px; }
    .quick-stats-row { grid-template-columns: 1fr; gap: 10px; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-card-blue { flex-direction: column; text-align: center; gap: 6px; padding: 10px 12px; }
    .branch-status-info-blue { justify-content: center; }
    .branch-status-icon-blue { width: 40px; height: 40px; font-size: 16px; }
    .branch-status-name-blue { font-size: 14px; }
    .summaries-grid-2x2 { grid-template-columns: 1fr; gap: 10px; }
    .summary-card { padding: 12px 14px; min-height: 85px; gap: 10px; }
    .summary-icon { width: 42px; height: 42px; font-size: 16px; }
    .summary-value { font-size: clamp(13px, 4vw, 16px); }
    .capital-section-wrapper { padding: 12px; }
    .csh-title { font-size: 14px; }
    .csh-icon { width: 40px; height: 40px; font-size: 18px; }
    .cp-value { font-size: 16px; }
    .cp-icon { width: 36px; height: 36px; font-size: 15px; }
    .data-table thead th,
    .data-table tbody td { padding: 8px 10px; font-size: 11px; }
    .provider-icon-circle { width: 32px; height: 32px; font-size: 14px; }
    .provider-name-text { font-size: 12px; }
    .provider-actions { flex-direction: column; gap: 4px; }
    .btn-provider { width: 28px; height: 28px; font-size: 12px; }
    .scroll-btn { width: 36px; height: 36px; font-size: 14px; }
    .scroll-label { font-size: 10px; }
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

document.addEventListener('click', function(event) {
    var dropdown = document.getElementById('exportDropdown');
    var button = document.querySelector('.dropdown-toggle');
    if (button && !button.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// ============================================================
// EXPORT
// ============================================================
function exportData(format) {
    var dropdown = document.getElementById('exportDropdown');
    dropdown.classList.remove('show');
    var params = new URLSearchParams();
    params.set('format', format);
    var selectedBranch = '<?php echo $selected_branch; ?>';
    if (selectedBranch && selectedBranch !== '0' && selectedBranch !== '') {
        params.set('branch_id', selectedBranch);
    }
    var searchInput = document.getElementById('providerSearchInput');
    if (searchInput && searchInput.value.trim()) {
        params.set('search', searchInput.value.trim());
    }
    window.location.href = 'export.php?' + params.toString();
}

// ============================================================
// PROVIDER SEARCH FILTER
// ============================================================
function filterProviders(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const providerRows = document.querySelectorAll('.provider-row-item');
    const separatorRows = document.querySelectorAll('.branch-separator-row');
    const clearBtn = document.getElementById('providerSearchClear');
    const countBadge = document.getElementById('providerSearchCount');
    const noResults = document.getElementById('noProviderResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        providerRows.forEach(row => row.classList.remove('hidden-by-search'));
        separatorRows.forEach(row => row.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        
        let idx = 1;
        providerRows.forEach(row => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx++;
        });
        return;
    }
    
    let matchCount = 0;
    let lastVisibleBranchId = null;
    let firstMatchOfBranch = false;
    
    providerRows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        const branchId = row.getAttribute('data-branch-id');
        
        if (searchData.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
            
            // Check if this is first visible of its branch
            if (branchId !== lastVisibleBranchId) {
                firstMatchOfBranch = true;
                lastVisibleBranchId = branchId;
            } else {
                firstMatchOfBranch = false;
            }
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    // Hide all separators during search (cleaner look)
    separatorRows.forEach(row => row.classList.add('hidden-by-search'));
    
    // Renumber visible rows
    let visibleIdx = 1;
    providerRows.forEach(row => {
        if (!row.classList.contains('hidden-by-search')) {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = visibleIdx++;
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    
    if (noResults) {
        noResults.style.display = matchCount === 0 ? 'block' : 'none';
    }
}

function clearProviderSearch() {
    const input = document.getElementById('providerSearchInput');
    if (input) {
        input.value = '';
        filterProviders(input);
        input.focus();
    }
}

// ============================================================
// SCROLL PROVIDER TABLE
// ============================================================
function scrollProviderTable(direction) {
    const wrapper = document.getElementById('providerTableWrapper');
    if (!wrapper) return;
    const scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// CONFIRM DELETE COMMISSIONS (not provider)
// ============================================================
function confirmDeleteCommissions(providerName, count) {
    var msg = 'Are you sure you want to DELETE COMMISSIONS for:\n\n' +
              'Provider: ' + providerName + '\n' +
              'Commission Records: ' + count + '\n\n' +
              'NOTE: The provider WILL REMAIN ACTIVE, only their commissions will be deleted.\n' +
              'The provider commission will become 0.\n\n' +
              'This action cannot be undone.';
    return confirm(msg);
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    
    var closeBtns = document.querySelectorAll('.alert-close');
    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const providerInput = document.getElementById('providerSearchInput');
            if (providerInput && providerInput.value.length > 0 && document.activeElement === providerInput) {
                clearProviderSearch();
            }
        }
    });
});
</script>
</body>
</html>