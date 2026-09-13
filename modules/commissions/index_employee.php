<?php
// ================================================================
// FILE: modules/commissions/index_employee.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEE COMMISSIONS VIEW
// ✅ Employee sees ONLY THEIR OWN commissions & other income
// ✅ Buttons: Add Commission + Add Other Expense
// ✅ NEW: View button per provider
// ✅ Capital Section (Float | Cash | Total Capital)
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

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = intval($employee['branch_id'] ?? 0);

// Branch info
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
// CAPITAL DATA (Float + Cash + Total Capital for MY BRANCH)
// ============================================================
$total_float = 0;
$total_cash = 0;
$total_capital = 0;

$stmt = $db->prepare("SELECT COALESCE(SUM(drp.current_float), 0) as total_float
                      FROM daily_report_providers drp
                      INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
                      WHERE dr.branch_id = ?");
$stmt->execute([$employee_branch_id]);
$total_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

$stmt = $db->prepare("SELECT current_cash FROM daily_reports
                      WHERE branch_id = ?
                      ORDER BY report_date DESC, id DESC LIMIT 1");
$stmt->execute([$employee_branch_id]);
$total_cash = floatval($stmt->fetch(PDO::FETCH_ASSOC)['current_cash'] ?? 0);

$total_capital = $total_float + $total_cash;

// ============================================================
// SUMMARY CARDS - MY OWN
// ============================================================
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total 
                      FROM commissions 
                      WHERE branch_id = ? AND employee_id = ?");
$stmt->execute([$employee_branch_id, $user_id]);
$card_commissions = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$stmt = $db->prepare("SELECT COALESCE(SUM(other_income), 0) as total 
                      FROM commissions 
                      WHERE branch_id = ? AND employee_id = ?");
$stmt->execute([$employee_branch_id, $user_id]);
$card_other_income = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$card_total_income = $card_commissions + $card_other_income;

// Quick Stats - MY OWN
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total 
                      FROM commissions 
                      WHERE DATE(commission_date) = ? AND branch_id = ? AND employee_id = ?");
$stmt->execute([$today, $employee_branch_id, $user_id]);
$today_commission = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total 
                      FROM commissions 
                      WHERE MONTH(commission_date) = ? AND YEAR(commission_date) = ? 
                      AND branch_id = ? AND employee_id = ?");
$stmt->execute([$month, $year, $employee_branch_id, $user_id]);
$this_month_commission = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// ============================================================
// GET ALL PROVIDERS FOR MY BRANCH (with MY commission amounts)
// ============================================================
$all_providers_flat = [];

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
             AND c.employee_id = ?
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL) as my_provider_commission,
            (SELECT c.commission_date 
             FROM commissions c 
             WHERE c.branch_id = b.id 
             AND c.employee_id = ?
             AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', p.id, '\"')) IS NOT NULL
             ORDER BY c.id DESC LIMIT 1) as my_last_commission_date
        FROM branches b
        INNER JOIN branch_providers bp ON b.id = bp.branch_id AND bp.is_active = 1
        INNER JOIN providers p ON bp.provider_id = p.id AND p.is_active = 1
        WHERE b.is_active = 1 AND b.id = ?
        ORDER BY p.display_order ASC, p.provider_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute([$user_id, $user_id, $employee_branch_id]);
$provider_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($provider_rows as $row) {
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
        'my_commission' => floatval($row['my_provider_commission'] ?? 0),
        'my_last_commission_date' => $row['my_last_commission_date'] ?? null
    ];
}

$total_providers_count = count($all_providers_flat);

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
        
        <!-- BLUE BRANCH CARD -->
        <div class="branch-status-card-blue">
            <div class="branch-status-icon-blue">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info-blue">
                <span class="branch-status-label-blue">My Branch</span>
                <span class="branch-status-name-blue"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php if ($branch_code): ?>
                    <span class="branch-status-code-blue"><?php echo htmlspecialchars($branch_code); ?></span>
                <?php endif; ?>
                <?php if ($branch_location): ?>
                    <span class="branch-status-location-blue">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-status-date-blue">
                <i class="far fa-calendar-alt"></i>
                <?php echo date('d M Y'); ?>
            </div>
        </div>

        <!-- PAGE HEADER with BUTTONS -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-hand-holding-usd"></i> My Commissions</h2>
                <span class="record-count"><?php echo $total_providers_count; ?> providers</span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="add_employee.php?branch_id=<?php echo $employee_branch_id; ?>" class="btn btn-add-commission">
                        <i class="fas fa-plus-circle"></i> Add Commission
                    </a>
                    <a href="add_other_income_employee.php?branch_id=<?php echo $employee_branch_id; ?>" class="btn btn-add-other">
                        <i class="fas fa-coins"></i> Add Other Expense
                    </a>
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
                        <span class="csh-title">Branch Capital</span>
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

        <!-- 3 SUMMARY CARDS - MY OWN -->
        <div class="summaries-grid-3">
            <div class="summary-card card-commissions">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">MY COMMISSIONS</div>
                    <div class="summary-value"><?php echo formatCurrency($card_commissions); ?></div>
                    <div class="summary-sub">Total from me</div>
                </div>
            </div>

            <div class="summary-card card-other-income">
                <div class="summary-icon"><i class="fas fa-coins"></i></div>
                <div class="summary-content">
                    <div class="summary-label">MY OTHER INCOME</div>
                    <div class="summary-value"><?php echo formatCurrency($card_other_income); ?></div>
                    <div class="summary-sub">Total from me</div>
                </div>
            </div>

            <div class="summary-card card-total-income">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-content">
                    <div class="summary-label">MY TOTAL</div>
                    <div class="summary-value"><?php echo formatCurrency($card_total_income); ?></div>
                    <div class="summary-sub">Commission + Other</div>
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
                    <span class="quick-stat-label">MY COMMISSION TODAY</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($today_commission); ?></span>
                </div>
            </div>
            <div class="quick-stat-item">
                <span class="quick-stat-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-calendar-alt"></i>
                </span>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">MY COMMISSION THIS MONTH</span>
                    <span class="quick-stat-value"><?php echo formatCurrency($this_month_commission); ?></span>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header-red-with-controls">
                <div class="thrc-left">
                    <div class="provider-search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="providerSearchInput" 
                               placeholder="Search provider..."
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
                        <?php echo $total_providers_count; ?> providers
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
                                <th>Code</th>
                                <th>Type</th>
                                <th class="text-right">My Commission</th>
                                <th>Last Date</th>
                                <th style="width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $global_row = 1;
                            foreach ($all_providers_flat as $p): 
                                $provider_search = strtolower(
                                    $p['provider_name'] . ' ' . 
                                    $p['provider_code']
                                );
                            ?>
                                <tr class="provider-row-item" 
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
                                        <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo htmlspecialchars($p['provider_type']); ?>">
                                            <i class="fas fa-tag"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $p['provider_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount commission-amount <?php echo $p['my_commission'] > 0 ? '' : 'amount-zero'; ?>">
                                            <?php echo formatCurrency($p['my_commission']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($p['my_last_commission_date']): ?>
                                            <span class="date-cell">
                                                <i class="far fa-calendar"></i>
                                                <?php echo date('d M Y', strtotime($p['my_last_commission_date'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="date-cell date-empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- ✅ VIEW BUTTON -->
                                        <div class="provider-actions">
                                            <a href="view_provider_employee.php?provider_id=<?php echo $p['provider_id']; ?>&branch_id=<?php echo $employee_branch_id; ?>" 
                                               class="btn-provider btn-provider-view" 
                                               title="View My Commissions">
                                                <i class="fas fa-eye"></i>
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
                    <p>Ask admin to add providers to your branch.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
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

/* BLUE BRANCH CARD */
.branch-status-card-blue {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 12px; margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(30, 64, 175, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; width: 100%;
    color: #FFFFFF;
}
.branch-status-card-blue::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon-blue {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FCD34D;
    flex-shrink: 0; position: relative; z-index: 1;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.branch-status-info-blue {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; position: relative; z-index: 1; flex: 1;
}
.branch-status-label-blue {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.branch-status-name-blue {
    font-size: 16px; font-weight: 800;
    color: #FFFFFF; letter-spacing: 0.3px;
}
.branch-status-code-blue {
    font-size: 11px; font-weight: 700;
    color: #FCD34D;
    padding: 3px 10px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 10px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    font-family: 'Courier New', monospace;
}
.branch-status-location-blue {
    display: flex; align-items: center; gap: 4px;
    font-size: 11px; color: rgba(255, 255, 255, 0.85);
    padding: 3px 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}
.branch-status-date-blue {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: rgba(255, 255, 255, 0.9);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    flex-shrink: 0;
}

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
    width: 100%;
}
.page-header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 18px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.page-header-left h2 i { color: #10B981; margin-right: 6px; }
.record-count {
    font-size: 12px; color: var(--text-secondary);
    background: var(--bg-hover);
    padding: 2px 10px; border-radius: 12px;
}
.page-header-right { display: flex; align-items: center; gap: 10px; }
.header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* BUTTONS */
.btn-add-commission {
    background: #10B981; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}
.btn-add-commission:hover {
    background: #059669; transform: translateY(-2px); color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}
.btn-add-other {
    background: #7C3AED; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease; border: none;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
}
.btn-add-other:hover {
    background: #6D28D9; transform: translateY(-2px); color: white;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
}

/* ALERTS */
.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 14px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 20px;
    color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6;
}

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
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D; flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
}
.csh-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.csh-title { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.csh-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.csh-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
    text-transform: uppercase; letter-spacing: 0.8px;
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
}
.part-float::before { background: #60A5FA; }
.part-cash::before { background: #86EFAC; }
.part-total::before { background: #FCD34D; }

.cp-header { display: flex; align-items: center; gap: 10px; }
.cp-icon {
    width: 42px; height: 42px; border-radius: 10px;
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
    text-transform: uppercase; letter-spacing: 1.2px;
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

/* SUMMARY CARDS */
.summaries-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
    width: 100%; max-width: 100%;
}
.summary-card {
    background: var(--bg-card);
    border-radius: 12px; padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
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
    box-shadow: 0 4px 15px var(--shadow-hover);
}
.summary-icon {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.summary-content {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; justify-content: center;
}
.summary-label {
    font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--text-secondary);
    margin-bottom: 4px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.summary-value {
    font-size: clamp(14px, 1.15vw, 20px);
    font-weight: 800; color: var(--text-primary);
    margin: 4px 0; line-height: 1.2;
    word-break: break-all;
}
.summary-sub {
    font-size: 10px; color: var(--text-light);
    font-weight: 500; margin-top: 2px;
}
.card-commissions::before { background: #10B981; }
.card-commissions .summary-icon { background: #D1FAE5; color: #059669; }
.card-commissions .summary-value { color: #059669; }
.card-other-income::before { background: #7C3AED; }
.card-other-income .summary-icon { background: #EDE9FE; color: #7C3AED; }
.card-other-income .summary-value { color: #7C3AED; }
.card-total-income::before { background: #1D4ED8; }
.card-total-income .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total-income .summary-value { color: #1D4ED8; }

/* QUICK STATS */
.quick-stats-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
    width: 100%;
}
.quick-stat-item {
    background: var(--bg-card);
    border-radius: 12px; padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    min-width: 0;
}
.quick-stat-icon {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.quick-stat-info {
    display: flex; flex-direction: column;
    gap: 3px; min-width: 0; flex: 1;
}
.quick-stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.5px; font-weight: 700;
    color: var(--text-secondary);
}
.quick-stat-value {
    font-size: clamp(13px, 1vw, 16px);
    font-weight: 700; color: var(--text-primary);
    word-break: break-all;
}

/* TABLE */
.table-container {
    background: var(--bg-card);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    overflow: hidden; width: 100%; max-width: 100%;
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

.provider-search-wrapper {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 12px;
    width: 300px; max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.provider-search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.provider-search-wrapper i { color: #DC2626; font-size: 12px; flex-shrink: 0; }
.provider-search-wrapper input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 12px;
    color: #1F2937; outline: none;
    min-width: 0; font-family: 'Inter', sans-serif;
}
.provider-search-wrapper input::placeholder { color: #9CA3AF; font-size: 11px; }
.provider-search-wrapper button {
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
}
.provider-search-wrapper button:hover { background: #DC2626; color: white; }
.provider-search-count {
    font-size: 10px; font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D; color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
}
.scroll-btn {
    width: 40px; height: 40px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF; color: #DC2626;
    cursor: pointer;
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
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.record-count-red i { font-size: 11px; color: #FCD34D; }

.table-responsive {
    overflow-x: auto;
    width: 100%; max-width: 100%;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track { background: var(--bg-hover); border-radius: 4px; }
.table-responsive::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.data-table thead { background: #DC2626; }
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
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-hover); }
.data-table tbody td {
    padding: 11px 14px;
    color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

.provider-row-item.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg-hover);
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.provider-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
.provider-icon-circle {
    width: 38px; height: 38px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 16px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
}
.provider-name-text {
    font-weight: 800; color: var(--text-primary);
    font-size: 13px; white-space: nowrap;
}
.code-badge {
    display: inline-flex; align-items: center;
    padding: 4px 10px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
html.dark-mode .code-badge {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD; border-color: #3B82F6;
}
.type-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 8px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
}
.type-badge i { font-size: 9px; }
.type-bank { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
.type-mobile_money,
.type-mobile { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
.type-wallet { background: #EDE9FE; color: #5B21B6; border: 1px solid #C4B5FD; }
.type-sacco { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
html.dark-mode .type-bank { background: #1E3A5F; color: #93C5FD; border-color: #3B82F6; }
html.dark-mode .type-mobile_money,
html.dark-mode .type-mobile { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .type-wallet { background: #4C1D95; color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .type-sacco { background: #14532D; color: #4ADE80; border-color: #16A34A; }

.commission-amount {
    display: inline-flex; align-items: center;
    padding: 6px 12px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #065F46; border-radius: 8px;
    font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #6EE7B7;
    white-space: nowrap;
}
.commission-amount.amount-zero {
    background: var(--bg-hover);
    color: var(--text-light);
    border-color: var(--border-color);
}
html.dark-mode .commission-amount {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #D1FAE5; border-color: #10B981;
}
.date-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}
.date-cell i { color: #7C3AED; font-size: 10px; }
.date-cell.date-empty {
    color: var(--text-light);
    font-style: italic;
}

/* ✅ PROVIDER ACTIONS */
.provider-actions {
    display: flex;
    gap: 5px;
    justify-content: center;
    align-items: center;
}
.btn-provider {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 13px;
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
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.4);
}
html.dark-mode .btn-provider-view {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #60A5FA;
    border-color: #3B82F6;
}

.no-provider-results {
    text-align: center;
    padding: 40px 20px;
    background: var(--bg-hover);
    display: none;
}
.no-provider-results i {
    font-size: 44px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-provider-results p {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0 0 16px 0;
}
.btn-reset {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 8px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    cursor: pointer; transition: all 0.3s ease;
    display: inline-flex; align-items: center; gap: 6px;
}
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
    color: var(--text-primary);
    margin: 0 0 6px 0;
}
.empty-state p {
    color: var(--text-secondary);
    font-size: 13px;
    margin: 0;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summaries-grid-3 { grid-template-columns: repeat(3, 1fr); }
    .capital-grid-3 { grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .cp-value { font-size: clamp(16px, 1.6vw, 20px); }
}
@media (max-width: 768px) {
    .branch-status-card-blue { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .branch-status-info-blue { width: 100%; }
    .branch-status-date-blue { align-self: flex-start; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header-right { width: 100%; }
    .header-actions { width: 100%; flex-direction: column; }
    .header-actions .btn-add-commission,
    .header-actions .btn-add-other { width: 100%; justify-content: center; }
    .capital-section-wrapper { padding: 16px; }
    .capital-section-header { flex-direction: column; align-items: flex-start; }
    .capital-grid-3 { grid-template-columns: 1fr; gap: 10px; }
    .summaries-grid-3 { grid-template-columns: 1fr; gap: 10px; }
    .quick-stats-row { grid-template-columns: 1fr; gap: 10px; }
    .table-header-red-with-controls {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .thrc-left, .thrc-center, .thrc-right {
        justify-content: center;
        width: 100%;
    }
    .provider-search-wrapper { width: 100%; }
}
@media (max-width: 480px) {
    .branch-status-card-blue { flex-direction: column; text-align: center; gap: 6px; padding: 10px 12px; }
    .branch-status-info-blue { justify-content: center; }
    .branch-status-icon-blue { width: 40px; height: 40px; font-size: 16px; }
    .branch-status-name-blue { font-size: 14px; }
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
    .scroll-btn { width: 36px; height: 36px; font-size: 14px; }
    .scroll-label { font-size: 10px; }
    .btn-provider { width: 30px; height: 30px; font-size: 12px; }
}
</style>

<script>
// ============================================================
// PROVIDER SEARCH FILTER
// ============================================================
function filterProviders(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.provider-row-item');
    const clearBtn = document.getElementById('providerSearchClear');
    const countBadge = document.getElementById('providerSearchCount');
    const noResults = document.getElementById('noProviderResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        
        let idx = 1;
        rows.forEach(row => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx++;
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
    
    let visibleIdx = 1;
    rows.forEach(row => {
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

function scrollProviderTable(direction) {
    const wrapper = document.getElementById('providerTableWrapper');
    if (!wrapper) return;
    const scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
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
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    
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