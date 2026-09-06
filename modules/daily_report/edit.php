<?php
// ================================================================
// FILE: modules/daily_report/edit.php
// EDIT DAILY REPORT
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get report
    $stmt = $db->prepare("SELECT * FROM daily_reports WHERE id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching report: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$report) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

try {
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get providers
    $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get morning reports
    $stmt = $db->prepare("SELECT id, report_number, report_date FROM morning_reports ORDER BY report_date DESC LIMIT 30");
    $stmt->execute();
    $morning_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get evening stocks
    $stmt = $db->prepare("SELECT id, stock_number, stock_date FROM evening_stocks ORDER BY stock_date DESC LIMIT 30");
    $stmt->execute();
    $evening_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get commissions
    $stmt = $db->prepare("SELECT id, commission_number, commission_date FROM commissions ORDER BY commission_date DESC LIMIT 30");
    $stmt->execute();
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $provider_id = !empty($_POST['provider_id']) ? intval($_POST['provider_id']) : null;
        $morning_report_id = !empty($_POST['morning_report_id']) ? intval($_POST['morning_report_id']) : null;
        $evening_stock_id = !empty($_POST['evening_stock_id']) ? intval($_POST['evening_stock_id']) : null;
        $commission_id = !empty($_POST['commission_id']) ? intval($_POST['commission_id']) : null;
        
        $total_deposits = floatval($_POST['total_deposits'] ?? 0);
        $total_withdrawals = floatval($_POST['total_withdrawals'] ?? 0);
        $total_commission = floatval($_POST['total_commission'] ?? 0);
        $other_income = floatval($_POST['other_income'] ?? 0);
        $total_expenses = floatval($_POST['total_expenses'] ?? 0);
        $total_salaries = floatval($_POST['total_salaries'] ?? 0);
        $total_cash_out = floatval($_POST['total_cash_out'] ?? 0);
        
        $morning_total = floatval($_POST['morning_total'] ?? 0);
        $evening_total = floatval($_POST['evening_total'] ?? 0);
        $float_difference = $evening_total - $morning_total;
        
        $opening_capital = floatval($_POST['opening_capital'] ?? 0);
        $additional_capital = floatval($_POST['additional_capital'] ?? 0);
        $profit_allocated = floatval($_POST['profit_allocated'] ?? 0);
        
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($report_date)) {
            $error = 'Please select report date';
        } else {
            // Get branch name
            $branch_name = 'Main';
            if ($branch_id > 0) {
                $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
                $stmt->execute([$branch_id]);
                $branch = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($branch) {
                    $branch_name = $branch['branch_name'];
                }
            }
            
            // Get provider info
            $provider_code = null;
            if ($provider_id) {
                $stmt = $db->prepare("SELECT provider_code FROM providers WHERE id = ?");
                $stmt->execute([$provider_id]);
                $provider = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($provider) {
                    $provider_code = $provider['provider_code'];
                }
            }
            
            $total_business_income = $total_commission + $other_income;
            $net_profit = $total_business_income - $total_expenses - $total_cash_out;
            $net_profit_after_salaries = $net_profit - $total_salaries;
            $current_capital = $opening_capital + $additional_capital + $profit_allocated;
            
            $sql = "UPDATE daily_reports SET 
                branch = ?,
                branch_id = ?,
                provider_id = ?,
                provider_code = ?,
                report_date = ?,
                morning_report_id = ?,
                evening_stock_id = ?,
                commission_id = ?,
                morning_total = ?,
                evening_total = ?,
                float_difference = ?,
                total_commission = ?,
                total_deposits = ?,
                total_withdrawals = ?,
                other_income = ?,
                total_business_income = ?,
                total_expenses = ?,
                total_cash_out = ?,
                total_salaries = ?,
                net_profit = ?,
                net_profit_after_salaries = ?,
                opening_capital = ?,
                additional_capital = ?,
                profit_allocated = ?,
                current_capital = ?,
                notes = ?
                WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $branch_name,
                $branch_id > 0 ? $branch_id : null,
                $provider_id,
                $provider_code,
                $report_date,
                $morning_report_id,
                $evening_stock_id,
                $commission_id,
                $morning_total,
                $evening_total,
                $float_difference,
                $total_commission,
                $total_deposits,
                $total_withdrawals,
                $other_income,
                $total_business_income,
                $total_expenses,
                $total_cash_out,
                $total_salaries,
                $net_profit,
                $net_profit_after_salaries,
                $opening_capital,
                $additional_capital,
                $profit_allocated,
                $current_capital,
                $notes,
                $id
            ]);
            
            if ($result) {
                logActivity($user_id, 'Edit Daily Report', 'Daily Report', $id);
                $success = 'Daily report updated successfully!';
                header('Refresh: 2; URL=view.php?id=' . $id);
            } else {
                $error = 'Failed to update report. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error updating daily report: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Daily Report</h2>
                <p class="text-muted">Update daily report information</p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Details
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" class="report-form">
                <!-- Basic Information -->
                <div class="form-section">
                    <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($report['report_number']); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label>Report Date <span class="required">*</span></label>
                            <input type="date" name="report_date" class="form-control" value="<?php echo htmlspecialchars($report['report_date']); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch</label>
                            <select name="branch_id" class="form-control">
                                <option value="0">Main Branch</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo $report['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Provider</label>
                            <select name="provider_id" class="form-control">
                                <option value="">Select Provider</option>
                                <?php foreach ($providers as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $report['provider_id'] == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['provider_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Morning Report</label>
                            <select name="morning_report_id" class="form-control">
                                <option value="">Select Morning Report</option>
                                <?php foreach ($morning_reports as $mr): ?>
                                    <option value="<?php echo $mr['id']; ?>" <?php echo $report['morning_report_id'] == $mr['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mr['report_number'] . ' - ' . date('d M Y', strtotime($mr['report_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Evening Stock</label>
                            <select name="evening_stock_id" class="form-control">
                                <option value="">Select Evening Stock</option>
                                <?php foreach ($evening_stocks as $es): ?>
                                    <option value="<?php echo $es['id']; ?>" <?php echo $report['evening_stock_id'] == $es['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($es['stock_number'] . ' - ' . date('d M Y', strtotime($es['stock_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Commission</label>
                            <select name="commission_id" class="form-control">
                                <option value="">Select Commission</option>
                                <?php foreach ($commissions as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $report['commission_id'] == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['commission_number'] . ' - ' . date('d M Y', strtotime($c['commission_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Created By</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars(getEmployeeName($report['employee_id'])); ?>" disabled>
                        </div>
                    </div>
                </div>

                <!-- Stock Information -->
                <div class="form-section">
                    <h4><i class="fas fa-cubes"></i> Stock Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Morning Total</label>
                            <input type="number" name="morning_total" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['morning_total']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Evening Total</label>
                            <input type="number" name="evening_total" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['evening_total']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Float Difference</label>
                            <input type="text" class="form-control" id="float_difference" value="<?php echo number_format($report['float_difference'], 2); ?>" disabled>
                            <small class="form-text text-muted">Automatically calculated (Evening - Morning)</small>
                        </div>
                    </div>
                </div>

                <!-- Financial Information -->
                <div class="form-section">
                    <h4><i class="fas fa-coins"></i> Financial Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Deposits</label>
                            <input type="number" name="total_deposits" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_deposits']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Total Withdrawals</label>
                            <input type="number" name="total_withdrawals" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_withdrawals']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Commission</label>
                            <input type="number" name="total_commission" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_commission']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Other Income</label>
                            <input type="number" name="other_income" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['other_income']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Expenses</label>
                            <input type="number" name="total_expenses" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_expenses']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Total Salaries</label>
                            <input type="number" name="total_salaries" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_salaries']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Cash Out</label>
                            <input type="number" name="total_cash_out" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['total_cash_out']); ?>">
                        </div>
                    </div>
                </div>

                <!-- Capital Information -->
                <div class="form-section">
                    <h4><i class="fas fa-building"></i> Capital Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Capital</label>
                            <input type="number" name="opening_capital" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['opening_capital']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Additional Capital</label>
                            <input type="number" name="additional_capital" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['additional_capital']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Profit Allocated</label>
                            <input type="number" name="profit_allocated" class="form-control" placeholder="0.00" step="0.01" value="<?php echo htmlspecialchars($report['profit_allocated']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Current Capital</label>
                            <input type="text" class="form-control" id="current_capital" value="<?php echo number_format($report['current_capital'], 2); ?>" disabled>
                            <small class="form-text text-muted">Automatically calculated (Opening + Additional + Profit Allocated)</small>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-section">
                    <h4><i class="fas fa-sticky-note"></i> Additional Notes</h4>
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this report"><?php echo htmlspecialchars($report['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Report</button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Same styles as add.php */
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.form-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.form-section h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-row:last-child {
    margin-bottom: 0;
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

.form-group label .required { color: #DC2626; }

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

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.form-control:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.btn {
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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

.form-text {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* Dark Mode */
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

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const morningTotal = document.querySelector('input[name="morning_total"]');
    const eveningTotal = document.querySelector('input[name="evening_total"]');
    const floatDiff = document.getElementById('float_difference');
    
    function calculateFloatDiff() {
        const morning = parseFloat(morningTotal.value) || 0;
        const evening = parseFloat(eveningTotal.value) || 0;
        const diff = evening - morning;
        floatDiff.value = diff.toFixed(2);
    }
    
    morningTotal.addEventListener('input', calculateFloatDiff);
    eveningTotal.addEventListener('input', calculateFloatDiff);
    
    const openingCapital = document.querySelector('input[name="opening_capital"]');
    const additionalCapital = document.querySelector('input[name="additional_capital"]');
    const profitAllocated = document.querySelector('input[name="profit_allocated"]');
    const currentCapital = document.getElementById('current_capital');
    
    function calculateCurrentCapital() {
        const opening = parseFloat(openingCapital.value) || 0;
        const additional = parseFloat(additionalCapital.value) || 0;
        const profit = parseFloat(profitAllocated.value) || 0;
        const total = opening + additional + profit;
        currentCapital.value = total.toFixed(2);
    }
    
    openingCapital.addEventListener('input', calculateCurrentCapital);
    additionalCapital.addEventListener('input', calculateCurrentCapital);
    profitAllocated.addEventListener('input', calculateCurrentCapital);
});

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