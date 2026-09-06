<?php
// ================================================================
// FILE: modules/daily_report/generate.php
// GENERATE DAILY REPORT
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Check permission
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$error = '';
$success = '';
$report_data = null;
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get providers
    $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if report already exists for this date
    $stmt = $db->prepare("SELECT id FROM daily_reports WHERE report_date = ? AND employee_id = ?");
    $stmt->execute([$selected_date, $user_id]);
    $existing_report = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get morning report for the date
    $stmt = $db->prepare("SELECT * FROM morning_reports WHERE report_date = ? AND employee_id = ?");
    $stmt->execute([$selected_date, $user_id]);
    $morning_report = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get evening stock for the date
    $stmt = $db->prepare("SELECT * FROM evening_stocks WHERE stock_date = ? AND employee_id = ?");
    $stmt->execute([$selected_date, $user_id]);
    $evening_stock = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get commission for the date
    $stmt = $db->prepare("SELECT * FROM commissions WHERE commission_date = ? AND employee_id = ?");
    $stmt->execute([$selected_date, $user_id]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get expenses for the date
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE expense_date = ? AND employee_id = ? AND is_business_expense = 1");
    $stmt->execute([$selected_date, $user_id]);
    $expenses_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $expenses_total = $expenses_result['total'] ?? 0;

    // Get cash out for the date
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM store_cash_out WHERE cashout_date = ? AND employee_id = ? AND status = 'approved'");
    $stmt->execute([$selected_date, $user_id]);
    $cashout_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $cashout_total = $cashout_result['total'] ?? 0;

    // Get salary for the month
    $month_start = date('Y-m-01', strtotime($selected_date));
    $stmt = $db->prepare("SELECT SUM(net_pay) as total FROM employee_salaries WHERE salary_month = ? AND employee_id = ? AND status = 'paid'");
    $stmt->execute([$month_start, $user_id]);
    $salary_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $salary_total = $salary_result['total'] ?? 0;

    // Get opening capital
    $stmt = $db->prepare("SELECT amount FROM capital_management WHERE transaction_type = 'opening' ORDER BY transaction_date ASC LIMIT 1");
    $stmt->execute();
    $opening_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $opening_capital = $opening_result['amount'] ?? 0;

    // Get additional capital for the month
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management 
                          WHERE transaction_type = 'additional' 
                          AND transaction_date >= ? AND transaction_date <= ?");
    $stmt->execute([$month_start, $selected_date]);
    $additional_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $additional_capital = $additional_result['total'] ?? 0;

    // Get profit allocation for the month
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management 
                          WHERE transaction_type = 'profit_allocation' 
                          AND transaction_date >= ? AND transaction_date <= ?");
    $stmt->execute([$month_start, $selected_date]);
    $profit_allocation_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $profit_allocated = $profit_allocation_result['total'] ?? 0;

} catch (PDOException $e) {
    error_log("Error loading data for generate: " . $e->getMessage());
    $branches = [];
    $providers = [];
    $existing_report = null;
    $morning_report = null;
    $evening_stock = null;
    $commission = null;
    $expenses_total = 0;
    $cashout_total = 0;
    $salary_total = 0;
    $opening_capital = 0;
    $additional_capital = 0;
    $profit_allocated = 0;
}

// Calculate totals
$morning_total = $morning_report['cumm_total'] ?? 0;
$morning_cash = $morning_report['cash_balance'] ?? 0;
$evening_total = $evening_stock['cumm_total'] ?? 0;
$evening_cash = $evening_stock['cash_balance'] ?? 0;
$float_diff = $evening_total - $morning_total;
$cash_diff = $evening_cash - $morning_cash;
$total_commission = $commission['total_commission'] ?? 0;
$other_income = $commission['other_income'] ?? 0;
$total_income = $total_commission + $other_income;
$total_expenses = floatval($expenses_total);
$total_cashout = floatval($cashout_total);
$total_salaries = floatval($salary_total);
$net_profit = $total_income - $total_expenses - $total_salaries - $total_cashout;
$current_capital = $opening_capital + $additional_capital + $profit_allocated + $net_profit;

// Prepare report data
$report_data = [
    'morning_total' => $morning_total,
    'morning_cash' => $morning_cash,
    'evening_total' => $evening_total,
    'evening_cash' => $evening_cash,
    'float_diff' => $float_diff,
    'cash_diff' => $cash_diff,
    'total_commission' => $total_commission,
    'other_income' => $other_income,
    'total_income' => $total_income,
    'total_expenses' => $total_expenses,
    'total_cashout' => $total_cashout,
    'total_salaries' => $total_salaries,
    'net_profit' => $net_profit,
    'opening_capital' => $opening_capital,
    'additional_capital' => $additional_capital,
    'profit_allocated' => $profit_allocated,
    'current_capital' => $current_capital,
    'has_morning' => !empty($morning_report),
    'has_evening' => !empty($evening_stock),
    'has_commission' => !empty($commission)
];

// Handle form submission - Generate Report
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate'])) {
    try {
        if ($existing_report) {
            $error = 'A report already exists for this date.';
        } elseif (!$morning_report) {
            $error = 'Please submit morning report first for this date.';
        } elseif (!$evening_stock) {
            $error = 'Please submit evening stock first for this date.';
        } elseif (!$commission) {
            $error = 'Please submit commission first for this date.';
        } else {
            $report_number = generateNumber('DR');
            $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
            $provider_id = isset($_POST['provider_id']) ? intval($_POST['provider_id']) : null;
            $provider_code = '';
            
            if ($provider_id > 0) {
                foreach ($providers as $p) {
                    if ($p['id'] == $provider_id) {
                        $provider_code = $p['provider_code'];
                        break;
                    }
                }
            }
            
            $branch_name = 'Main';
            if ($branch_id > 0) {
                foreach ($branches as $b) {
                    if ($b['id'] == $branch_id) {
                        $branch_name = $b['branch_name'];
                        break;
                    }
                }
            }
            
            $sql = "INSERT INTO daily_reports (
                report_number, employee_id, branch_id, branch, report_date,
                provider_id, provider_code,
                morning_total, evening_total, float_difference,
                total_commission, other_income, total_deposits, total_withdrawals,
                current_float, current_cash, total_business_income,
                total_expenses, total_cash_out, total_salaries, net_profit, net_profit_after_salaries,
                opening_capital, additional_capital, profit_allocated, current_capital,
                morning_report_id, evening_stock_id, commission_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $report_number,
                $user_id,
                $branch_id,
                $branch_name,
                $selected_date,
                $provider_id,
                $provider_code,
                $morning_total,
                $evening_total,
                $float_diff,
                $total_commission,
                $other_income,
                0, // total_deposits
                0, // total_withdrawals
                $evening_total,
                $evening_cash,
                $total_income,
                $total_expenses,
                $total_cashout,
                $total_salaries,
                $net_profit,
                $net_profit, // net_profit_after_salaries
                $opening_capital,
                $additional_capital,
                $profit_allocated,
                $current_capital,
                $morning_report['id'] ?? null,
                $evening_stock['id'] ?? null,
                $commission['id'] ?? null
            ]);
            
            if ($result) {
                $report_id = $db->lastInsertId();
                
                // Log activity
                logActivity($user_id, 'Generate Daily Report', 'Daily Report', $report_id, null, json_encode([
                    'report_number' => $report_number,
                    'date' => $selected_date,
                    'branch' => $branch_name
                ]));
                
                $success = 'Daily report generated successfully!';
                header('Refresh: 2; URL=view.php?id=' . $report_id);
            } else {
                $error = 'Failed to generate report. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error generating daily report: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
PAGE CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- Dark Mode Toggle -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Generate Daily Report</h2>
                <p class="text-muted">Generate daily report for <?php echo date('d M Y', strtotime($selected_date)); ?></p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- Date Selector -->
        <div class="date-selector">
            <form method="GET" action="" class="date-form">
                <div class="form-group">
                    <label>Select Date</label>
                    <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" class="form-control" 
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-calendar"></i> Load Data</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Data Summary -->
        <div class="data-summary">
            <h4><i class="fas fa-clipboard-list"></i> Data Summary for <?php echo date('d M Y', strtotime($selected_date)); ?></h4>
            
            <div class="summary-grid">
                <div class="summary-item <?php echo $report_data['has_morning'] ? 'has-data' : 'no-data'; ?>">
                    <span class="item-icon"><i class="fas fa-sun"></i></span>
                    <span class="item-label">Morning Report</span>
                    <span class="item-status"><?php echo $report_data['has_morning'] ? '✅ Submitted' : '❌ Pending'; ?></span>
                    <?php if ($report_data['has_morning']): ?>
                        <span class="item-value">Float: <?php echo formatCurrency($morning_total); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="summary-item <?php echo $report_data['has_evening'] ? 'has-data' : 'no-data'; ?>">
                    <span class="item-icon"><i class="fas fa-moon"></i></span>
                    <span class="item-label">Evening Stock</span>
                    <span class="item-status"><?php echo $report_data['has_evening'] ? '✅ Submitted' : '❌ Pending'; ?></span>
                    <?php if ($report_data['has_evening']): ?>
                        <span class="item-value">Float: <?php echo formatCurrency($evening_total); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="summary-item <?php echo $report_data['has_commission'] ? 'has-data' : 'no-data'; ?>">
                    <span class="item-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="item-label">Commission</span>
                    <span class="item-status"><?php echo $report_data['has_commission'] ? '✅ Submitted' : '❌ Pending'; ?></span>
                    <?php if ($report_data['has_commission']): ?>
                        <span class="item-value">Total: <?php echo formatCurrency($total_commission); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Report Preview -->
        <?php if ($report_data['has_morning'] && $report_data['has_evening'] && $report_data['has_commission'] && !$existing_report): ?>
        <div class="report-preview">
            <h4><i class="fas fa-eye" style="color:#bb0404;"></i> Report Preview</h4>
            
            <div class="preview-grid">
                <div class="preview-item">
                    <span class="label">Morning Total</span>
                    <span class="value"><?php echo formatCurrency($morning_total); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Evening Total</span>
                    <span class="value"><?php echo formatCurrency($evening_total); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Float Difference</span>
                    <span class="value <?php echo $float_diff >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo ($float_diff >= 0 ? '+' : '') . formatCurrency($float_diff); ?>
                    </span>
                </div>
                <div class="preview-item">
                    <span class="label">Total Commission</span>
                    <span class="value positive"><?php echo formatCurrency($total_commission); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Other Income</span>
                    <span class="value positive"><?php echo formatCurrency($other_income); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Total Income</span>
                    <span class="value positive"><?php echo formatCurrency($total_income); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Expenses</span>
                    <span class="value negative"><?php echo formatCurrency($total_expenses); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Salaries</span>
                    <span class="value negative"><?php echo formatCurrency($total_salaries); ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Cash Out</span>
                    <span class="value negative"><?php echo formatCurrency($total_cashout); ?></span>
                </div>
                <div class="preview-item highlight">
                    <span class="label">Net Profit</span>
                    <span class="value <?php echo $net_profit >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatCurrency($net_profit); ?>
                    </span>
                </div>
                <div class="preview-item highlight">
                    <span class="label">Opening Capital</span>
                    <span class="value"><?php echo formatCurrency($opening_capital); ?></span>
                </div>
                <div class="preview-item highlight">
                    <span class="label">Additional Capital</span>
                    <span class="value positive"><?php echo formatCurrency($additional_capital); ?></span>
                </div>
                <div class="preview-item highlight">
                    <span class="label">Profit Allocated</span>
                    <span class="value positive"><?php echo formatCurrency($profit_allocated); ?></span>
                </div>
                <div class="preview-item highlight">
                    <span class="label">Current Capital</span>
                    <span class="value positive" style="color:#bb0404;font-size:18px;">
                        <?php echo formatCurrency($current_capital); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Generate Form -->
        <div class="generate-form">
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">Main Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Provider (Optional)</label>
                        <select name="provider_id" class="form-control">
                            <option value="0">Select Provider</option>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['provider_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="generate" class="btn btn-primary btn-lg" onclick="return confirm('Are you sure you want to generate this daily report?')">
                        <i class="fas fa-file-alt"></i> Generate Daily Report
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Missing Data Warning -->
        <?php if (!$report_data['has_morning'] || !$report_data['has_evening'] || !$report_data['has_commission']): ?>
        <div class="missing-data">
            <h4><i class="fas fa-exclamation-triangle" style="color:#F59E0B;"></i> Missing Required Data</h4>
            <p>Please ensure the following are submitted before generating the report:</p>
            <ul>
                <?php if (!$report_data['has_morning']): ?>
                    <li><i class="fas fa-times-circle" style="color:#DC2626;"></i> Morning Report - <a href="../morning_report/add.php?date=<?php echo $selected_date; ?>">Submit Now</a></li>
                <?php else: ?>
                    <li><i class="fas fa-check-circle" style="color:#10B981;"></i> Morning Report - Completed</li>
                <?php endif; ?>
                
                <?php if (!$report_data['has_evening']): ?>
                    <li><i class="fas fa-times-circle" style="color:#DC2626;"></i> Evening Stock - <a href="../evening_stock/add.php?date=<?php echo $selected_date; ?>">Submit Now</a></li>
                <?php else: ?>
                    <li><i class="fas fa-check-circle" style="color:#10B981;"></i> Evening Stock - Completed</li>
                <?php endif; ?>
                
                <?php if (!$report_data['has_commission']): ?>
                    <li><i class="fas fa-times-circle" style="color:#DC2626;"></i> Commission - <a href="../commissions/add.php?date=<?php echo $selected_date; ?>">Submit Now</a></li>
                <?php else: ?>
                    <li><i class="fas fa-check-circle" style="color:#10B981;"></i> Commission - Completed</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($existing_report): ?>
        <div class="existing-report">
            <h4><i class="fas fa-info-circle" style="color:#3B82F6;"></i> Report Already Exists</h4>
            <p>A daily report for <?php echo date('d M Y', strtotime($selected_date)); ?> has already been generated.</p>
            <a href="view.php?id=<?php echo $existing_report['id']; ?>" class="btn btn-info">
                <i class="fas fa-eye"></i> View Existing Report
            </a>
        </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
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
    --shadow-hover: rgba(0,0,0,0.08);
}

body.dark-mode {
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
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

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
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

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

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

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
}

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.btn-info {
    background: #3B82F6;
    color: white;
}
.btn-info:hover { background: #2563EB; }

.btn-lg {
    padding: 12px 30px;
    font-size: 15px;
}

.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

.date-selector {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.date-form {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.date-form .form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.date-form .form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.date-form .form-control {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    min-width: 180px;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.data-summary {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.data-summary h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.summary-item {
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    text-align: center;
}

.summary-item .item-icon { font-size: 20px; display: block; margin-bottom: 4px; }
.summary-item .item-label { font-size: 12px; color: var(--text-muted); display: block; }
.summary-item .item-status { font-size: 12px; font-weight: 600; display: block; margin: 4px 0; }
.summary-item .item-value { font-size: 13px; font-weight: 500; color: var(--text-primary); display: block; }

.summary-item.has-data { border-left: 4px solid #10B981; }
.summary-item.has-data .item-status { color: #10B981; }

.summary-item.no-data { border-left: 4px solid #DC2626; }
.summary-item.no-data .item-status { color: #DC2626; }

.report-preview {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.report-preview h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 12px 0;
}

.preview-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.preview-item {
    padding: 8px 12px;
    border-radius: 6px;
    background: var(--bg-table-even);
    text-align: center;
}

.preview-item .label {
    font-size: 11px;
    color: var(--text-muted);
    display: block;
}

.preview-item .value {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
}

.preview-item .value.positive { color: #10B981; }
.preview-item .value.negative { color: #DC2626; }

.preview-item.highlight {
    background: #bb040410;
    border: 1px solid #bb040430;
}
.preview-item.highlight .value { color: #bb0404; }

.generate-form {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}

.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding-top: 8px;
}

.missing-data {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid #FEF3C7;
    background: #FFFBEB;
    margin-bottom: 20px;
}

body.dark-mode .missing-data {
    background: #1e293b;
    border-color: #F59E0B;
}

.missing-data h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.missing-data p {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 0 8px 0;
}

.missing-data ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.missing-data ul li {
    padding: 4px 0;
    font-size: 13px;
    color: var(--text-secondary);
}

.missing-data ul li i { margin-right: 8px; }
.missing-data ul li a { color: #bb0404; text-decoration: none; }
.missing-data ul li a:hover { text-decoration: underline; }

.existing-report {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid #DBEAFE;
    background: #EFF6FF;
    margin-bottom: 20px;
}

body.dark-mode .existing-report {
    background: #1e293b;
    border-color: #3B82F6;
}

.existing-report h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.existing-report p {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 0 12px 0;
}

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: 1fr; }
    .preview-grid { grid-template-columns: 1fr 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .date-form { flex-direction: column; }
    .date-form .form-control { width: 100%; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .preview-grid { grid-template-columns: 1fr; }
}
</style>

<script>
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const btn = document.getElementById('darkModeToggle');
    if (document.body.classList.contains('dark-mode')) {
        btn.querySelector('i').className = 'fas fa-sun';
        btn.querySelector('span').textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        btn.querySelector('i').className = 'fas fa-moon';
        btn.querySelector('span').textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
});
</script>

</body>
</html>