<?php
// ================================================================
// FILE: modules/daily_report/add.php
// ADD DAILY REPORT
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

$error = '';
$success = '';

// Get user's branch
$selected_branch = isset($_SESSION['user_branch_id']) ? intval($_SESSION['user_branch_id']) : 0;
if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
        $_SESSION['user_branch_id'] = $selected_branch;
    }
}

// Get branch name
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

try {
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get providers
    $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get morning reports for dropdown
    $stmt = $db->prepare("SELECT id, report_number, report_date FROM morning_reports ORDER BY report_date DESC LIMIT 30");
    $stmt->execute();
    $morning_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get evening stocks for dropdown
    $stmt = $db->prepare("SELECT id, stock_number, stock_date FROM evening_stocks ORDER BY stock_date DESC LIMIT 30");
    $stmt->execute();
    $evening_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get commissions for dropdown
    $stmt = $db->prepare("SELECT id, commission_number, commission_date FROM commissions ORDER BY commission_date DESC LIMIT 30");
    $stmt->execute();
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $branches = [];
    $providers = [];
    $morning_reports = [];
    $evening_stocks = [];
    $commissions = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : $selected_branch;
        $provider_id = !empty($_POST['provider_id']) ? intval($_POST['provider_id']) : null;
        $morning_report_id = !empty($_POST['morning_report_id']) ? intval($_POST['morning_report_id']) : null;
        $evening_stock_id = !empty($_POST['evening_stock_id']) ? intval($_POST['evening_stock_id']) : null;
        $commission_id = !empty($_POST['commission_id']) ? intval($_POST['commission_id']) : null;
        
        // Financial data
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
        
        // Capital data
        $opening_capital = floatval($_POST['opening_capital'] ?? 0);
        $additional_capital = floatval($_POST['additional_capital'] ?? 0);
        $profit_allocated = floatval($_POST['profit_allocated'] ?? 0);
        
        $notes = trim($_POST['notes'] ?? '');
        
        // Validate
        if (empty($report_date)) {
            $error = 'Please select report date';
        } else {
            // Generate report number
            $report_number = generateNumber('DR');
            
            // Get branch name
            $branch_name_db = 'Main';
            if ($branch_id > 0) {
                $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
                $stmt->execute([$branch_id]);
                $branch = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($branch) {
                    $branch_name_db = $branch['branch_name'];
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
            
            // Calculate totals
            $total_business_income = $total_commission + $other_income;
            $net_profit = $total_business_income - $total_expenses - $total_cash_out;
            $net_profit_after_salaries = $net_profit - $total_salaries;
            
            // Current capital calculation
            $current_capital = $opening_capital + $additional_capital + $profit_allocated;
            
            $sql = "INSERT INTO daily_reports (
                report_number, employee_id, branch, branch_id, provider_id, provider_code,
                report_date, morning_report_id, evening_stock_id, commission_id,
                morning_total, evening_total, float_difference,
                total_commission, total_deposits, total_withdrawals,
                other_income, total_business_income, total_expenses, total_cash_out, total_salaries,
                net_profit, net_profit_after_salaries,
                opening_capital, additional_capital, profit_allocated, current_capital,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $report_number,
                $user_id,
                $branch_name_db,
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
                $notes
            ]);
            
            if ($result) {
                $new_id = $db->lastInsertId();
                
                logActivity($user_id, 'Add Daily Report', 'Daily Report', $new_id, null, json_encode([
                    'report_number' => $report_number,
                    'date' => $report_date,
                    'branch' => $branch_name_db
                ]));
                
                $success = 'Daily report added successfully!';
                header('Refresh: 2; URL=view.php?id=' . $new_id);
            } else {
                $error = 'Failed to add report. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error adding daily report: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <i class="fas fa-store-alt"></i>
                <span class="branch-indicator-label">Current Branch:</span>
                <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php if ($branch_code): ?>
                    <span class="branch-indicator-code">(<?php echo htmlspecialchars($branch_code); ?>)</span>
                <?php endif; ?>
                <?php if ($branch_location): ?>
                    <span class="branch-indicator-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($branch_location); ?></span>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('d M Y'); ?></span>
            </div>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add Daily Report</h2>
                <p class="text-muted">Create a new daily business report</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
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
                            <label>Report Date <span class="required">*</span></label>
                            <input type="date" name="report_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Branch</label>
                            <select name="branch_id" class="form-control">
                                <option value="0">Main Branch</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Provider</label>
                            <select name="provider_id" class="form-control">
                                <option value="">Select Provider</option>
                                <?php foreach ($providers as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['provider_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Morning Report</label>
                            <select name="morning_report_id" class="form-control">
                                <option value="">Select Morning Report</option>
                                <?php foreach ($morning_reports as $mr): ?>
                                    <option value="<?php echo $mr['id']; ?>">
                                        <?php echo htmlspecialchars($mr['report_number'] . ' - ' . date('d M Y', strtotime($mr['report_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Evening Stock</label>
                            <select name="evening_stock_id" class="form-control">
                                <option value="">Select Evening Stock</option>
                                <?php foreach ($evening_stocks as $es): ?>
                                    <option value="<?php echo $es['id']; ?>">
                                        <?php echo htmlspecialchars($es['stock_number'] . ' - ' . date('d M Y', strtotime($es['stock_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Commission</label>
                            <select name="commission_id" class="form-control">
                                <option value="">Select Commission</option>
                                <?php foreach ($commissions as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['commission_number'] . ' - ' . date('d M Y', strtotime($c['commission_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Stock Information -->
                <div class="form-section">
                    <h4><i class="fas fa-cubes"></i> Stock Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Morning Total</label>
                            <input type="number" name="morning_total" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Evening Total</label>
                            <input type="number" name="evening_total" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Float Difference</label>
                            <input type="text" class="form-control" id="float_difference" value="0.00" disabled>
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
                            <input type="number" name="total_deposits" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Total Withdrawals</label>
                            <input type="number" name="total_withdrawals" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Commission</label>
                            <input type="number" name="total_commission" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Other Income</label>
                            <input type="number" name="other_income" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Expenses</label>
                            <input type="number" name="total_expenses" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Total Salaries</label>
                            <input type="number" name="total_salaries" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Cash Out</label>
                            <input type="number" name="total_cash_out" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                </div>

                <!-- Capital Information -->
                <div class="form-section">
                    <h4><i class="fas fa-building"></i> Capital Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Capital</label>
                            <input type="number" name="opening_capital" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Additional Capital</label>
                            <input type="number" name="additional_capital" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Profit Allocated</label>
                            <input type="number" name="profit_allocated" class="form-control" placeholder="0.00" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Current Capital</label>
                            <input type="text" class="form-control" id="current_capital" value="0.00" disabled>
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
                            <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this report"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Report</button>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
    border: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #FFFFFF;
}

.branch-indicator-left i {
    font-size: 18px;
    color: rgba(255,255,255,0.9);
}

.branch-indicator-label {
    font-weight: 500;
    opacity: 0.8;
    letter-spacing: 0.5px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 15px;
    color: #FFFFFF;
}

.branch-indicator-code {
    font-size: 12px;
    opacity: 0.7;
    color: #FFFFFF;
}

.branch-indicator-location {
    font-size: 12px;
    opacity: 0.8;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 4px;
}

.branch-indicator-location i {
    font-size: 12px;
}

.branch-indicator-right .date-display {
    font-size: 13px;
    color: rgba(255,255,255,0.8);
}

.branch-indicator-right .date-display i {
    margin-right: 4px;
}

/* ============================================================
   FORM STYLES
   ============================================================ */
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

.form-text {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
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

/* ============================================================
   BUTTONS
   ============================================================ */
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

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

/* ============================================================
   ALERTS
   ============================================================ */
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
    --shadow-hover: rgba(0,0,0,0.08);
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
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
    .header-right {
        width: 100%;
    }
    .header-right .btn {
        width: 100%;
        justify-content: center;
    }
    .branch-indicator {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
        padding: 12px 16px;
    }
    .branch-indicator-left {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .branch-indicator-name {
        font-size: 13px;
    }
    .branch-indicator-location {
        font-size: 11px;
    }
}
</style>

<script>
// Auto-calculate float difference
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
    
    // Auto-calculate current capital
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
</script>

</body>
</html>