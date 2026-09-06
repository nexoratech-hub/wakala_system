<?php
// ================================================================
// FILE: modules/salaries/add.php
// WAKALA FINANCIAL SYSTEM - ADD SALARY
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
// CHECK PERMISSION
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET EMPLOYEES FOR DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT id, full_name, base_salary FROM employees WHERE is_active = 1 ORDER BY full_name");
$stmt->execute();
$employees = $stmt->fetchAll();

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// ============================================================
// GET CURRENT SALARY MONTH FROM SETTINGS
// ============================================================
$stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'salary_month'");
$stmt->execute();
$result = $stmt->fetch();
$current_month = $result ? $result['setting_value'] : date('Y-m-01');

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_salary') {
    try {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $salary_month = $_POST['salary_month'] ?? date('Y-m-01');
        $base_salary = floatval(str_replace(',', '', $_POST['base_salary'] ?? 0));
        $bonus = floatval(str_replace(',', '', $_POST['bonus'] ?? 0));
        $overtime_pay = floatval(str_replace(',', '', $_POST['overtime_pay'] ?? 0));
        $allowances = floatval(str_replace(',', '', $_POST['allowances'] ?? 0));
        $tax = floatval(str_replace(',', '', $_POST['tax'] ?? 0));
        $deductions = floatval(str_replace(',', '', $_POST['deductions'] ?? 0));
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $transaction_reference = trim($_POST['transaction_reference'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($employee_id <= 0) {
            throw new Exception('Please select an employee.');
        }
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        if ($base_salary <= 0) {
            throw new Exception('Please enter base salary.');
        }
        
        // Get employee details
        $emp_stmt = $db->prepare("SELECT full_name, branch FROM employees WHERE id = ?");
        $emp_stmt->execute([$employee_id]);
        $employee_data = $emp_stmt->fetch();
        
        if (!$employee_data) {
            throw new Exception('Employee not found.');
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // Check if salary already exists for this employee and month
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM employee_salaries WHERE employee_id = ? AND salary_month = ?");
        $check_stmt->execute([$employee_id, $salary_month]);
        if ($check_stmt->fetchColumn() > 0) {
            throw new Exception('A salary record already exists for this employee for the selected month.');
        }
        
        // Calculate totals
        $total_gross = $base_salary + $bonus + $overtime_pay + $allowances;
        $net_pay = $total_gross - $tax - $deductions;
        
        // Generate salary number
        $salary_number = 'SAL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $insert_stmt = $db->prepare("INSERT INTO employee_salaries 
            (salary_number, employee_id, branch, branch_id, salary_month, base_salary, bonus, overtime_pay, 
             allowances, total_gross, tax, deductions, net_pay, payment_date, payment_method, 
             transaction_reference, description, paid_by, status, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->execute([
            $salary_number,
            $employee_id,
            $branch_name,
            $branch_id,
            $salary_month,
            $base_salary,
            $bonus,
            $overtime_pay,
            $allowances,
            $total_gross,
            $tax,
            $deductions,
            $net_pay,
            $payment_date,
            $payment_method,
            $transaction_reference,
            $description,
            $user_id,
            $status,
            $notes
        ]);
        
        $salary_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Salary', 'Salaries', $salary_id, '', 'Added salary for: ' . $employee_data['full_name']);
        
        $_SESSION['success_message'] = 'Salary added successfully! Salary Number: ' . $salary_number;
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Salary</h2>
                <span class="page-subtitle">Create a new salary record</span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST" action="" class="main-form" id="salaryForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_salary">
                
                <!-- Basic Information -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employee_id">Employee <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <select id="employee_id" name="employee_id" class="form-control" required onchange="loadEmployeeSalary(this.value)">
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo (isset($_POST['employee_id']) && $_POST['employee_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php echo (isset($_POST['branch_id']) && $_POST['branch_id'] == $b['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="salary_month">Salary Month <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="month" id="salary_month" name="salary_month" 
                                       value="<?php echo isset($_POST['salary_month']) ? htmlspecialchars($_POST['salary_month']) : $current_month; ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="payment_date">Payment Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-day"></i></span>
                                <input type="date" id="payment_date" name="payment_date" 
                                       value="<?php echo isset($_POST['payment_date']) ? htmlspecialchars($_POST['payment_date']) : date('Y-m-d'); ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Salary Breakdown -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Salary Breakdown</h3>
                        <span class="section-sub">Enter salary components</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="base_salary">Base Salary <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill"></i></span>
                                <input type="text" id="base_salary" name="base_salary" 
                                       value="<?php echo isset($_POST['base_salary']) ? htmlspecialchars($_POST['base_salary']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="bonus">Bonus</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-star"></i></span>
                                <input type="text" id="bonus" name="bonus" 
                                       value="<?php echo isset($_POST['bonus']) ? htmlspecialchars($_POST['bonus']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="overtime_pay">Overtime Pay</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-clock"></i></span>
                                <input type="text" id="overtime_pay" name="overtime_pay" 
                                       value="<?php echo isset($_POST['overtime_pay']) ? htmlspecialchars($_POST['overtime_pay']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="allowances">Allowances</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-coins"></i></span>
                                <input type="text" id="allowances" name="allowances" 
                                       value="<?php echo isset($_POST['allowances']) ? htmlspecialchars($_POST['allowances']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Deductions -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-minus-circle"></i> Deductions</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tax">Tax</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-percentage"></i></span>
                                <input type="text" id="tax" name="tax" 
                                       value="<?php echo isset($_POST['tax']) ? htmlspecialchars($_POST['tax']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                            <small>Tax amount to deduct</small>
                        </div>
                        <div class="form-group">
                            <label for="deductions">Other Deductions</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-hand-holding-usd"></i></span>
                                <input type="text" id="deductions" name="deductions" 
                                       value="<?php echo isset($_POST['deductions']) ? htmlspecialchars($_POST['deductions']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                            <small>Other deductions (e.g., loans, advances)</small>
                        </div>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-credit-card"></i> Payment Details</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_method">Payment Method</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <select id="payment_method" name="payment_method" class="form-control">
                                    <option value="cash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                                    <option value="bank_transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="mobile_money" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'mobile_money') ? 'selected' : ''; ?>>Mobile Money</option>
                                    <option value="cheque" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="transaction_reference">Transaction Reference</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-hashtag"></i></span>
                                <input type="text" id="transaction_reference" name="transaction_reference" 
                                       value="<?php echo isset($_POST['transaction_reference']) ? htmlspecialchars($_POST['transaction_reference']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., TRX-123456">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status & Notes -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-cog"></i> Status &amp; Notes</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                                <select id="status" name="status" class="form-control">
                                    <option value="pending" <?php echo (isset($_POST['status']) && $_POST['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="paid" <?php echo (isset($_POST['status']) && $_POST['status'] == 'paid') ? 'selected' : ''; ?>>Paid</option>
                                    <option value="cancelled" <?php echo (isset($_POST['status']) && $_POST['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="reversed" <?php echo (isset($_POST['status']) && $_POST['status'] == 'reversed') ? 'selected' : ''; ?>>Reversed</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-file-alt"></i></span>
                                <input type="text" id="description" name="description" 
                                       value="<?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?>" 
                                       class="form-control" placeholder="Brief description">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="notes">Notes</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="Any additional notes..."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Salary Summary</h3>
                        <span class="section-badge">Auto-calculated</span>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">Gross Pay:</span>
                            <span class="summary-value" id="grossDisplay">TSh 0.00</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Tax:</span>
                            <span class="summary-value" id="taxDisplay">TSh 0.00</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Deductions:</span>
                            <span class="summary-value" id="deductionsDisplay">TSh 0.00</span>
                        </div>
                        <div class="summary-item total">
                            <span class="summary-label">Net Pay:</span>
                            <span class="summary-value" id="netPayDisplay">TSh 0.00</span>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Add Salary
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset Form
                    </button>
                    <a href="index.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Copy all styles from add.php here - similar to evening_stock add.php with salary-specific colors */
:root {
    --form-bg: #FFFFFF;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
    --form-card-bg: #FFFFFF;
    --form-input-bg: #F9FAFB;
    --form-hover: #F3F4F6;
    --form-shadow: rgba(0,0,0,0.06);
    --form-dropdown-bg: #FFFFFF;
    --form-dropdown-border: #E5E7EB;
    --form-danger-bg: #FEE2E2;
    --form-danger-text: #991B1B;
    --form-danger-border: #FECACA;
}

html.dark-mode {
    --form-bg: #1F2937;
    --form-text: #F9FAFB;
    --form-text-secondary: #9CA3AF;
    --form-text-light: #6B7280;
    --form-border: #374151;
    --form-card-bg: #1F2937;
    --form-input-bg: #374151;
    --form-hover: #374151;
    --form-shadow: rgba(0,0,0,0.3);
    --form-dropdown-bg: #1F2937;
    --form-dropdown-border: #374151;
    --form-danger-bg: #7F1D1D;
    --form-danger-text: #FEE2E2;
    --form-danger-border: #991B1B;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--form-bg) !important; }
.main-content { background: var(--form-bg) !important; }

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
    color: var(--form-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

.btn-back {
    background: var(--form-hover);
    color: var(--form-text-secondary);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--form-border);
    color: var(--form-text);
}

.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    position: relative;
    animation: slideDown 0.4s ease forwards;
}

.alert-danger {
    background: var(--form-danger-bg);
    color: var(--form-danger-text);
    border: 1px solid var(--form-danger-border);
}

.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
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

.form-container {
    background: var(--form-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--form-shadow);
    border: 1px solid var(--form-border);
    overflow: hidden;
}

.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--form-border);
}

.form-section:last-child { border-bottom: none; }

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.section-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--form-text);
    margin: 0;
}

.section-header h3 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.section-badge {
    font-size: 11px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.section-sub {
    font-size: 13px;
    color: var(--form-text-secondary);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-row .full-width {
    grid-column: span 2;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--form-text);
}

.form-group label .required {
    color: #DC2626;
    font-weight: 700;
}

.input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 12px;
    color: var(--form-text-light);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}

.input-group .form-control {
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--form-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--form-input-bg);
    color: var(--form-text);
    width: 100%;
}

.input-group .form-control::placeholder {
    color: var(--form-text-light);
}

.input-group .form-control:focus {
    border-color: #7F1D1D;
    box-shadow: 0 0 0 3px rgba(127,29,29,0.1);
}

.input-group .form-control:focus + .input-icon,
.input-group .form-control:focus ~ .input-icon {
    color: #7F1D1D;
}

.input-group select.form-control {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}

html.dark-mode .input-group select.form-control {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%239CA3AF' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
}

.input-group select.form-control option {
    background: var(--form-dropdown-bg);
    color: var(--form-text);
}

.input-group textarea.form-control {
    padding: 10px 14px 10px 40px;
    resize: vertical;
    min-height: 60px;
}

.form-group small {
    font-size: 12px;
    color: var(--form-text-secondary);
    margin-top: 2px;
}

.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

.money-input:focus {
    border-color: #7F1D1D !important;
    box-shadow: 0 0 0 3px rgba(127,29,29,0.2) !important;
}

.summary-section {
    background: var(--form-hover);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

.summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 12px 16px;
    border-radius: 8px;
    background: var(--form-card-bg);
    border: 1px solid var(--form-border);
}

.summary-item.total {
    background: #D1FAE5;
    border-color: #A7F3D0;
}

html.dark-mode .summary-item.total {
    background: #065F46;
    border-color: #047857;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--form-text-secondary);
    font-weight: 600;
}

.summary-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--form-text);
}

.summary-item.total .summary-value {
    color: #059669;
}

.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--form-border);
    background: var(--form-hover);
}

.btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-submit {
    background: #7F1D1D;
    color: white;
}

.btn-submit:hover {
    background: #5C1313;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(127,29,29,0.3);
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-reset {
    background: var(--form-hover);
    color: var(--form-text-secondary);
}

.btn-reset:hover {
    background: var(--form-border);
    color: var(--form-text);
}

.btn-cancel {
    background: var(--form-hover);
    color: var(--form-text-secondary);
}

.btn-cancel:hover {
    background: #FEE2E2;
    color: #991B1B;
}

@media (max-width: 1024px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .form-row .full-width {
        grid-column: span 1;
    }
    
    .form-section {
        padding: 16px 14px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        justify-content: center;
        width: 100%;
    }
    
    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-container {
    animation: fadeInUp 0.4s ease forwards;
}
</style>

<script>
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9.]/g, '');
    var parts = value.split('.');
    var integerPart = parts[0] || '';
    var decimalPart = parts[1] || '';
    
    if (integerPart.length > 0) {
        integerPart = parseInt(integerPart).toLocaleString('en-US');
    }
    
    if (decimalPart.length > 2) {
        decimalPart = decimalPart.substring(0, 2);
    }
    
    var formatted = integerPart;
    if (decimalPart.length > 0) {
        formatted += '.' + decimalPart;
    }
    
    input.value = formatted;
}

function calculateTotals() {
    var baseSalary = parseFloat(document.getElementById('base_salary').value.replace(/,/g, '')) || 0;
    var bonus = parseFloat(document.getElementById('bonus').value.replace(/,/g, '')) || 0;
    var overtime = parseFloat(document.getElementById('overtime_pay').value.replace(/,/g, '')) || 0;
    var allowances = parseFloat(document.getElementById('allowances').value.replace(/,/g, '')) || 0;
    var tax = parseFloat(document.getElementById('tax').value.replace(/,/g, '')) || 0;
    var deductions = parseFloat(document.getElementById('deductions').value.replace(/,/g, '')) || 0;
    
    var grossPay = baseSalary + bonus + overtime + allowances;
    var netPay = grossPay - tax - deductions;
    
    document.getElementById('grossDisplay').textContent = 'TSh ' + formatNumber(grossPay);
    document.getElementById('taxDisplay').textContent = 'TSh ' + formatNumber(tax);
    document.getElementById('deductionsDisplay').textContent = 'TSh ' + formatNumber(deductions);
    document.getElementById('netPayDisplay').textContent = 'TSh ' + formatNumber(netPay);
}

function formatNumber(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function loadEmployeeSalary(employeeId) {
    // This can be extended to load employee's base salary
    // For now, just a placeholder
}

function validateForm() {
    var employee = document.getElementById('employee_id');
    if (!employee || employee.value === '') {
        alert('Please select an employee.');
        employee.focus();
        return false;
    }
    
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '') {
        alert('Please select a branch.');
        branch.focus();
        return false;
    }
    
    var baseSalary = document.getElementById('base_salary');
    var rawValue = baseSalary.value.replace(/,/g, '');
    if (parseFloat(rawValue) <= 0) {
        alert('Please enter a valid base salary.');
        baseSalary.focus();
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    submitBtn.disabled = true;
    
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form? All entered data will be lost.');
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    
    var moneyInputs = document.querySelectorAll('.money-input');
    moneyInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            formatMoneyInput(this);
            calculateTotals();
        });
    });
    
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
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    }
});
</script>
</body>
</html>