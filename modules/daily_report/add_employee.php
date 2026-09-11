<?php
// ================================================================
// FILE: modules/daily_report/add_employee.php
// EMPLOYEE - GENERATE DAILY REPORT
// ✅ Employee only sees OWN morning reports
// ✅ Employee name saved when generating
// ✅ Uses shared employee_sidebar & header
// ✅ Page takes FULL DEVICE WIDTH
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Employee tu
if ($role !== 'employee') {
    header('Location: generate.php');
    exit();
}

$error = '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

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

$employee_branch_id = $employee['branch_id'] ?? 0;
$selected_branch = $employee_branch_id;

try {
    // ============================================================
    // GET BRANCH INFO
    // ============================================================
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $branch_name = $branch['branch_name'] ?? 'My Branch';
    $branch_code = $branch['branch_code'] ?? '';
    $branch_location = $branch['location'] ?? '';
    
    // ============================================================
    // CHECK EXISTING REPORT - ONLY MY OWN
    // ============================================================
    $stmt = $db->prepare("
        SELECT id FROM daily_reports 
        WHERE report_date = ? 
        AND branch_id = ? 
        AND employee_id = ?
    ");
    $stmt->execute([$selected_date, $selected_branch, $user_id]);
    $existing_report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================================
    // GET MORNING REPORT - ONLY MY OWN
    // ============================================================
    $stmt = $db->prepare("
        SELECT * FROM morning_reports 
        WHERE report_date = ? 
        AND branch_id = ? 
        AND employee_id = ?
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_date, $selected_branch, $user_id]);
    $morning_report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================================
    // GET ALL PROVIDERS FROM MORNING REPORT
    // ============================================================
    $providers_data = [];
    if ($morning_report) {
        $stmt = $db->prepare("
            SELECT 
                mrp.provider_id,
                mrp.provider_code,
                mrp.provider_name,
                mrp.float_balance,
                mrp.cash_balance
            FROM morning_report_providers mrp
            WHERE mrp.report_id = ?
            ORDER BY mrp.provider_name
        ");
        $stmt->execute([$morning_report['id']]);
        $providers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $existing_report = null;
    $morning_report = null;
    $providers_data = [];
}

// Calculate totals
$morning_float = $morning_report['cumm_total'] ?? 0;
$morning_cash = $morning_report['cash_balance'] ?? 0;
$total_capital = $morning_float + $morning_cash;
$providers_count = count($providers_data);

// ============================================================
// HANDLE FORM SUBMISSION - Generate Report
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate'])) {
    try {
        if ($existing_report) {
            $error = 'Tayari unayo daily report kwa tarehe hii.';
        } elseif (!$morning_report) {
            $error = 'Hakuna morning report kwa tarehe hii. Tafadhali tengeneza morning report kwanza.';
        } elseif (empty($providers_data)) {
            $error = 'Hakuna providers kwenye morning report yako.';
        } else {
            $report_number = generateNumber('DR');
            
            $db->beginTransaction();
            
            // ============================================================
            // STEP 1: INSERT INTO daily_reports
            // ============================================================
            $sql = "INSERT INTO daily_reports (
                report_number, employee_id, branch_id, branch, report_date,
                morning_report_id,
                morning_total, evening_total, float_difference,
                total_commission, other_income, total_deposits, total_withdrawals,
                current_float, current_cash, total_business_income,
                total_expenses, total_cash_out, total_salaries, net_profit, net_profit_after_salaries,
                opening_capital, additional_capital, profit_allocated, current_capital
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $report_number,
                $user_id,                    // ✅ Employee anayegenerate
                $selected_branch,
                $branch_name,
                $selected_date,
                $morning_report['id'],       // ✅ Reference kwa morning report
                $morning_float,              // morning_total
                0,                           // evening_total
                0,                           // float_difference
                0,                           // total_commission
                0,                           // other_income
                0,                           // total_deposits
                0,                           // total_withdrawals
                $morning_float,              // current_float
                $morning_cash,               // current_cash
                $morning_float,              // total_business_income
                0,                           // total_expenses
                0,                           // total_cash_out
                0,                           // total_salaries
                0,                           // net_profit
                0,                           // net_profit_after_salaries
                0,                           // opening_capital
                0,                           // additional_capital
                0,                           // profit_allocated
                $total_capital               // current_capital = float + cash
            ]);
            
            $report_id = $db->lastInsertId();
            
            // ============================================================
            // STEP 2: INSERT INTO daily_report_providers
            // ============================================================
            $stmt_provider = $db->prepare("
                INSERT INTO daily_report_providers (
                    daily_report_id, provider_id, provider_code, provider_name,
                    morning_float, morning_cash, current_float, current_cash,
                    total_deposits, total_withdrawals
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($providers_data as $pd) {
                $stmt_provider->execute([
                    $report_id,
                    $pd['provider_id'],
                    $pd['provider_code'],
                    $pd['provider_name'],
                    $pd['float_balance'],
                    $pd['cash_balance'],
                    $pd['float_balance'],
                    $pd['cash_balance'],
                    0,
                    0
                ]);
            }
            
            $db->commit();
            
            // Log
            logActivity($user_id, 'Generate Daily Report', 'Daily Report', $report_id, null, json_encode([
                'report_number' => $report_number,
                'date' => $selected_date,
                'branch' => $branch_name,
                'providers' => $providers_count,
                'float' => $morning_float,
                'cash' => $morning_cash,
                'generated_by' => $user_id
            ]));
            
            $_SESSION['success_message'] = 'Daily report imeundwa successfully! ' . $providers_count . ' providers included.';
            header('Location: view_employee.php?id=' . $report_id);
            exit();
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error: " . $e->getMessage());
    }
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- Branch Card -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">My Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($branch_location): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch_location); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-magic" style="color:#7C3AED;"></i> Generate Daily Report</h2>
                <p class="text-muted">
                    <i class="fas fa-user"></i> Employee mode - unaona morning reports zako tu
                </p>
            </div>
            <div class="header-right">
                <a href="index_employee.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Date Selector -->
        <div class="selector-card">
            <h4><i class="fas fa-calendar-check"></i> Select Date</h4>
            <form method="GET" action="" class="date-form">
                <div class="form-group">
                    <label>Report Date</label>
                    <input type="date" 
                           name="date" 
                           value="<?php echo htmlspecialchars($selected_date); ?>" 
                           class="form-control" 
                           max="<?php echo date('Y-m-d'); ?>"
                           onchange="this.form.submit()">
                </div>
            </form>
        </div>

        <?php if (!$morning_report): ?>
            <!-- NO MORNING REPORT -->
            <div class="no-data-card">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>No Morning Report Found</h3>
                <p>
                    Hakuna morning report kwa <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong>
                    kwa branch <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                    <br><small>Employee mode: unaona morning reports zako tu</small>
                </p>
                <a href="../morning_report/add_employee.php?date=<?php echo $selected_date; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Submit Morning Report
                </a>
            </div>
            
        <?php elseif ($existing_report): ?>
            <!-- REPORT ALREADY EXISTS -->
            <div class="existing-card">
                <i class="fas fa-info-circle"></i>
                <h3>Daily Report Already Exists</h3>
                <p>
                    Daily report kwa tarehe <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong> 
                    imekwisha kuwa generated kwa branch <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                </p>
                <a href="view_employee.php?id=<?php echo $existing_report['id']; ?>" class="btn btn-info">
                    <i class="fas fa-eye"></i> View Existing Report
                </a>
            </div>
            
        <?php else: ?>
            <!-- MORNING REPORT FOUND -->
            
            <!-- Info Bar -->
            <div class="info-bar">
                <i class="fas fa-info-circle"></i>
                <span>
                    Morning Report: <strong><?php echo htmlspecialchars($morning_report['report_number']); ?></strong>
                    <br><small>Submitted by you</small>
                </span>
            </div>
            
            <div class="preview-card">
                <div class="preview-header">
                    <div class="preview-header-left">
                        <div class="preview-icon">
                            <i class="fas fa-sun"></i>
                        </div>
                        <div>
                            <h3>Morning Report Data</h3>
                            <p>Report: <strong><?php echo htmlspecialchars($morning_report['report_number']); ?></strong> 
                               • Date: <?php echo date('d M Y', strtotime($selected_date)); ?></p>
                        </div>
                    </div>
                    <div class="preview-header-right">
                        <span class="providers-badge">
                            <i class="fas fa-university"></i> <?php echo $providers_count; ?> Providers
                        </span>
                    </div>
                </div>
                
                <!-- Summary -->
                <div class="preview-summary">
                    <div class="summary-item">
                        <span class="summary-label">Total Float</span>
                        <span class="summary-value float-color"><?php echo formatCurrency($morning_float); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Cash Balance</span>
                        <span class="summary-value cash-color"><?php echo formatCurrency($morning_cash); ?></span>
                    </div>
                    <div class="summary-item highlight">
                        <span class="summary-label">TOTAL CAPITAL</span>
                        <span class="summary-value capital-color"><?php echo formatCurrency($total_capital); ?></span>
                    </div>
                </div>
                
                <!-- Providers Table -->
                <div class="providers-section">
                    <h4><i class="fas fa-list"></i> Providers from Morning Report</h4>
                    <div class="providers-table-wrapper">
                        <table class="providers-preview-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>Provider</th>
                                    <th>Code</th>
                                    <th class="text-right">Float</th>
                                    <th class="text-right">Cash</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $i = 1;
                                foreach ($providers_data as $pd): 
                                ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><strong><?php echo htmlspecialchars($pd['provider_name']); ?></strong></td>
                                        <td><span class="code-badge"><?php echo htmlspecialchars($pd['provider_code']); ?></span></td>
                                        <td class="text-right text-float"><?php echo formatCurrency($pd['float_balance']); ?></td>
                                        <td class="text-right text-cash"><?php echo formatCurrency($pd['cash_balance']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="totals-row">
                                    <td colspan="3"><strong>TOTAL</strong></td>
                                    <td class="text-right"><strong class="text-float"><?php echo formatCurrency($morning_float); ?></strong></td>
                                    <td class="text-right"><strong class="text-cash"><?php echo formatCurrency($morning_cash); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Generate Form -->
            <div class="generate-action-card">
                <div class="generate-info">
                    <i class="fas fa-info-circle"></i>
                    <p>
                        Bonyeza <strong>Generate</strong> kwa ku-save providers wote kwenye daily report.
                        <br><small>Jina lako litahifadhiwa kama <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Employee'); ?></strong></small>
                    </p>
                </div>
                <form method="POST" action="" onsubmit="return confirm('Generate daily report?\n\nThis will save: Summary + <?php echo $providers_count; ?> providers.\n\nContinue?');">
                    <button type="submit" name="generate" class="btn btn-generate btn-lg">
                        <i class="fas fa-magic"></i> Generate Daily Report
                        <span class="btn-count">(<?php echo $providers_count; ?> providers)</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
/* ============================================================
   ✅ PAGE TAKES FULL DEVICE WIDTH
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }

html {
    width: 100%;
    overflow-x: hidden;
}

body {
    width: 100%;
    overflow-x: hidden;
    margin: 0;
    padding: 0;
}

/* ✅ MAIN WRAPPER - SHIFTED RIGHT FOR SIDEBAR */
.main-wrapper {
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--bg-body);
    transition: margin-left 0.3s ease, width 0.3s ease;
    overflow-x: hidden;
    position: relative;
}

.main-content {
    padding: 20px 24px;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    margin: 0;
}

/* ✅ RESPONSIVE */
@media (max-width: 1024px) {
    .main-wrapper {
        margin-left: 240px;
        width: calc(100% - 240px);
        padding-top: 56px;
    }
    .main-content {
        padding: 16px 18px;
    }
}

@media (max-width: 768px) {
    .main-wrapper {
        margin-left: 0;
        width: 100%;
        padding-top: 50px;
    }
    .main-content {
        padding: 16px 14px;
        width: 100%;
    }
}

@media (max-width: 480px) {
    .main-wrapper {
        padding-top: 44px;
        width: 100%;
    }
    .main-content {
        padding: 12px 10px;
        width: 100%;
    }
}

/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
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
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
}
.main-wrapper { background: var(--bg-body) !important; }
.main-content { background: var(--bg-body) !important; }

/* ============================================================
   BRANCH CARD
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap;
    gap: 10px;
    width: 100%;
}
.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    min-width: 0;
    flex: 1;
}
.branch-icon-wrapper {
    width: 38px;
    height: 38px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #FFFFFF;
    flex-shrink: 0;
}
.branch-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.branch-indicator-label {
    font-size: 10px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 700;
    font-size: 14px;
    color: #FFFFFF;
}
.branch-indicator-code {
    font-size: 10px;
    font-weight: 600;
    color: #FFFFFF;
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
}
.branch-location {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    color: rgba(255,255,255,0.85);
    padding: 3px 10px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    white-space: nowrap;
}
.branch-indicator-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.date-display {
    font-size: 12px;
    color: rgba(255,255,255,0.85);
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 5px;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
}
.page-header .header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}
.page-header .header-left .text-muted {
    font-size: 12px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 8px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); color: white; }
.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); color: var(--text-primary); }
.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; color: white; }

.btn-generate {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}
.btn-generate:hover {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
    color: white;
}
.btn-lg { padding: 14px 32px; font-size: 15px; }
.btn-count {
    background: rgba(255,255,255,0.2);
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    margin-left: 6px;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* ============================================================
   INFO BAR
   ============================================================ */
.info-bar {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 1px solid #93C5FD;
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1E40AF;
    font-size: 13px;
}
html.dark-mode .info-bar {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
    color: #93C5FD;
}
.info-bar i { font-size: 18px; }
.info-bar strong {
    background: rgba(30, 64, 175, 0.15);
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
}

/* ============================================================
   SELECTOR CARD
   ============================================================ */
.selector-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 16px;
    width: 100%;
}
.selector-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}
.selector-card h4 i { color: #7C3AED; margin-right: 6px; }

.date-form {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    min-width: 200px;
}
.form-control:focus {
    outline: none;
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
}

/* ============================================================
   NO DATA / EXISTING CARDS
   ============================================================ */
.no-data-card,
.existing-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 48px 24px;
    border: 1px solid var(--border-color);
    text-align: center;
    width: 100%;
}
.no-data-card i { font-size: 56px; color: #F59E0B; display: block; margin-bottom: 16px; }
.existing-card i { font-size: 56px; color: #3B82F6; display: block; margin-bottom: 16px; }

.no-data-card h3,
.existing-card h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.no-data-card p,
.existing-card p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 20px 0;
}
.no-data-card p small { font-size: 12px; color: var(--text-light); }

/* ============================================================
   PREVIEW CARD
   ============================================================ */
.preview-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 1px 3px var(--shadow-color);
    width: 100%;
}

.preview-header {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #FFFFFF;
    flex-wrap: wrap;
    gap: 12px;
}
.preview-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.preview-icon {
    width: 46px;
    height: 46px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    border: 1px solid rgba(255,255,255,0.15);
}
.preview-header-left h3 {
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 2px 0;
    color: #FFFFFF;
}
.preview-header-left p {
    font-size: 12px;
    color: rgba(255,255,255,0.85);
    margin: 0;
}
.preview-header-left p strong {
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
}
.providers-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(255,255,255,0.2);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,0.15);
}

.preview-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    background: var(--bg-table-even);
    border-bottom: 1px solid var(--border-color);
}

.summary-item {
    padding: 16px 20px;
    border-right: 1px solid var(--border-color);
    text-align: center;
}
.summary-item:last-child { border-right: none; }
.summary-item.highlight {
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.06), rgba(124, 58, 237, 0.02));
    border-left: 3px solid #7C3AED;
}
.summary-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 4px;
}
.summary-value {
    display: block;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 0.3px;
}
.float-color { color: #3B82F6; }
.cash-color { color: #10B981; }
.capital-color { color: #7C3AED; font-size: 22px; }

.providers-section {
    padding: 16px 24px;
}
.providers-section h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}
.providers-section h4 i { color: #7C3AED; margin-right: 6px; }

.providers-table-wrapper {
    max-height: 400px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    -webkit-overflow-scrolling: touch;
}
.providers-table-wrapper::-webkit-scrollbar { width: 6px; height: 6px; }
.providers-table-wrapper::-webkit-scrollbar-track { background: var(--bg-table-even); }
.providers-table-wrapper::-webkit-scrollbar-thumb { background: #7C3AED; border-radius: 3px; }

.providers-preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 600px;
}
.providers-preview-table thead {
    background: #7C3AED;
    position: sticky;
    top: 0;
}
.providers-preview-table thead th {
    padding: 10px 14px;
    text-align: left;
    color: #FFFFFF;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.providers-preview-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-secondary);
}
.providers-preview-table tbody tr:hover { background: var(--bg-table-hover); }
.providers-preview-table tbody tr:nth-child(even) { background: var(--bg-table-even); }

.code-badge {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 2px 8px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; }

.text-right { text-align: right; }
.text-float { color: #3B82F6; font-weight: 700; }
.text-cash { color: #10B981; font-weight: 700; }

.totals-row {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%) !important;
    border-top: 2px solid #7C3AED;
}
html.dark-mode .totals-row {
    background: linear-gradient(135deg, #2d3a4f 0%, #334155 100%) !important;
}
.totals-row td { padding: 12px 14px; font-weight: 700; color: var(--text-primary); }

/* ============================================================
   GENERATE ACTION CARD
   ============================================================ */
.generate-action-card {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border: 2px solid #7C3AED;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    width: 100%;
}
html.dark-mode .generate-action-card {
    background: linear-gradient(135deg, #2D1B5F 0%, #1E1B4B 100%);
    border-color: #7C3AED;
}
.generate-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 250px;
}
.generate-info i {
    font-size: 22px;
    color: #7C3AED;
    flex-shrink: 0;
}
.generate-info p {
    font-size: 13px;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.5;
}
.generate-info p small { font-size: 12px; color: var(--text-muted); display: block; margin-top: 4px; }
.generate-info strong { color: #7C3AED; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .preview-summary { grid-template-columns: 1fr; }
    .summary-item {
        border-right: none;
        border-bottom: 1px solid var(--border-color);
    }
    .summary-item:last-child { border-bottom: none; }
}

@media (max-width: 768px) {
    .preview-summary { grid-template-columns: 1fr; }
    .summary-item {
        border-right: none;
        border-bottom: 1px solid var(--border-color);
    }
    .summary-item:last-child { border-bottom: none; }
    
    .generate-action-card {
        flex-direction: column;
        text-align: center;
    }
    .generate-action-card .btn-generate {
        width: 100%;
        justify-content: center;
    }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { width: 100%; justify-content: center; }
    
    .form-control { min-width: 100%; width: 100%; }
    .date-form { flex-direction: column; }
    .form-group { width: 100%; }
    
    .branch-indicator { flex-direction: column; gap: 8px; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    
    .preview-header { flex-direction: column; align-items: flex-start; }
    .providers-badge { align-self: flex-start; }
}

@media (max-width: 480px) {
    .preview-header { padding: 14px 16px; }
    .preview-header-left h3 { font-size: 14px; }
    .preview-header-left p { font-size: 11px; }
    .summary-value { font-size: 16px; }
    .capital-color { font-size: 18px; }
    .providers-section { padding: 12px 14px; }
    .providers-preview-table thead th,
    .providers-preview-table tbody td { padding: 8px 10px; font-size: 12px; }
    .btn-lg { padding: 12px 20px; font-size: 13px; }
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
});
</script>

</body>
</html>