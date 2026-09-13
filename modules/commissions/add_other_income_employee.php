<?php
// ================================================================
// FILE: modules/commissions/add_other_income_employee.php
// WAKALA FINANCIAL SYSTEM - ADD OTHER INCOME (EMPLOYEE)
// ✅ English, shorter instructions, better CSS
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    header('Location: add_other_income.php');
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

if ($employee_branch_id <= 0) {
    $_SESSION['error_message'] = 'Your account is not assigned to any branch.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$employee_branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index_employee.php');
    exit();
}

$branch_name = $branch['branch_name'];
$branch_code = $branch['branch_code'] ?? '';
$branch_location = $branch['location'] ?? '';

// ============================================================
// COMMON INCOME SOURCES
// ============================================================
$common_sources = [
    'Service Fee',
    'Bank Interest',
    'Cashback',
    'Refund',
    'Bonus',
    'Late Fee',
    'Transaction Fee',
    'Other'
];

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_other_income') {
    try {
        $income_date = $_POST['income_date'] ?? date('Y-m-d');
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $income_source = trim($_POST['income_source'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount greater than zero.');
        }
        
        if (empty($income_source)) {
            $income_source = 'Other Income';
        }
        
        $commission_number = 'OI-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $combined_notes = $income_source;
        if (!empty($description)) {
            $combined_notes .= ' - ' . $description;
        }
        if (!empty($notes)) {
            $combined_notes .= "\n\n" . $notes;
        }
        
        $provider_json = json_encode([]);
        
        $stmt = $db->prepare("INSERT INTO commissions 
            (commission_number, employee_id, branch, branch_id, commission_date, provider_data, 
             total_commission, other_income, total_business_income, allocate_to_capital, allocated_amount, notes) 
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'no', 0, ?)");
        
        $stmt->execute([
            $commission_number,
            $user_id,
            $branch_name,
            $employee_branch_id,
            $income_date,
            $provider_json,
            $amount,
            $amount,
            $combined_notes
        ]);
        
        $income_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Other Income', 'Commissions', $income_id, '', 
            'Employee added other income: ' . $commission_number . ' - ' . formatCurrency($amount));
        
        $_SESSION['success_message'] = 'Other income added successfully! Ref: ' . $commission_number;
        header('Location: index_employee.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

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
                <span class="branch-status-label-blue">Adding Other Income For</span>
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
            <a href="index_employee.php" class="btn-back-card-blue">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-coins"></i> Add Other Income</h2>
                <span class="page-subtitle">Record additional income (not commission)</span>
            </div>
        </div>

        <!-- MESSAGES -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo htmlspecialchars($success_message_session); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- SHORT INFO NOTE -->
        <div class="info-note-employee">
            <div class="ine-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="ine-content">
                <span class="ine-text">
                    Income will be saved for <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                    at <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                </span>
                <span class="ine-badge">
                    <i class="fas fa-chart-line"></i> Adds to profit only
                </span>
            </div>
        </div>

        <!-- FORM -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="incomeForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_other_income">
                
                <!-- INCOME INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Income Information</h3>
                        <span class="section-badge">* Required fields</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="income_date">Income Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="income_date" name="income_date" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Branch</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <input type="text" value="<?php echo htmlspecialchars($branch_name); ?>" class="form-control" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- INCOME DETAILS -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Income Details</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount (TSh) <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="amount" name="amount" 
                                       class="form-control money-input" 
                                       placeholder="0"
                                       inputmode="numeric"
                                       oninput="formatMoneyInput(this); updateSummary();"
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="income_source">
                                Income Source
                                <span class="optional-badge">Optional</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tag"></i></span>
                                <input type="text" 
                                       id="income_source" 
                                       name="income_source" 
                                       class="form-control" 
                                       list="incomeSourceList"
                                       placeholder="Select or type..."
                                       autocomplete="off">
                            </div>
                            <datalist id="incomeSourceList">
                                <?php foreach ($common_sources as $src): ?>
                                    <option value="<?php echo htmlspecialchars($src); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="description">
                                Description
                                <span class="optional-badge">Optional</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <input type="text" id="description" name="description" 
                                       class="form-control" 
                                       placeholder="Brief description...">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- NOTES -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <textarea id="notes" name="notes" 
                                          class="form-control textarea-control" 
                                          rows="2" 
                                          placeholder="Additional notes (optional)..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SUMMARY -->
                <div class="form-section summary-section">
                    <div class="summary-box">
                        <div class="summary-box-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="summary-box-content">
                            <span class="summary-box-label">Total Amount</span>
                            <span class="summary-box-value" id="totalIncomeDisplay">TSh 0</span>
                        </div>
                    </div>
                </div>
                
                <!-- ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="index_employee.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
/* ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }

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
.branch-status-name-blue { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.branch-status-code-blue {
    font-size: 11px; font-weight: 700;
    color: #FCD34D; padding: 3px 10px;
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
.btn-back-card-blue {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card-blue:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 18px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.page-header-left h2 i { color: #7C3AED; margin-right: 6px; }
.page-subtitle {
    font-size: 12px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
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

/* SHORT INFO NOTE */
.info-note-employee {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%);
    border: 1.5px solid #C4B5FD;
    border-radius: 12px;
    margin-bottom: 16px;
    color: #5B21B6;
    flex-wrap: wrap;
}
.ine-icon {
    width: 42px;
    height: 42px;
    background: rgba(124, 58, 237, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #7C3AED;
    flex-shrink: 0;
}
.ine-content {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.ine-text {
    font-size: 13px;
    font-weight: 600;
    color: #5B21B6;
    line-height: 1.5;
}
.ine-text strong {
    background: rgba(91, 33, 182, 0.12);
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #5B21B6;
}
.ine-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    background: #7C3AED;
    color: #FFFFFF;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.ine-badge i { font-size: 10px; }

html.dark-mode .info-note-employee {
    background: linear-gradient(135deg, #4C1D95 0%, #5B21B6 100%);
    border-color: #A78BFA;
    color: #C4B5FD;
}
html.dark-mode .ine-icon {
    background: rgba(196, 181, 253, 0.15);
    color: #C4B5FD;
}
html.dark-mode .ine-text { color: #DDD6FE; }
html.dark-mode .ine-text strong {
    background: rgba(196, 181, 253, 0.15);
    color: #DDD6FE;
}
html.dark-mode .ine-badge { background: #A78BFA; color: #4C1D95; }

/* FORM CONTAINER */
.form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 4px 20px var(--shadow-color);
    margin-bottom: 20px;
}
.form-section {
    padding: 22px 26px;
    border-bottom: 1px solid var(--border-color);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 18px;
    flex-wrap: wrap; gap: 8px;
}
.section-header h3 {
    font-size: 15px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.section-header h3 i { color: #7C3AED; margin-right: 8px; }
.section-badge {
    font-size: 11px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
    font-weight: 600;
}

/* OPTIONAL BADGE */
.optional-badge {
    font-size: 9px;
    font-weight: 800;
    color: #7C3AED;
    background: rgba(124, 58, 237, 0.12);
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 6px;
    display: inline-block;
    vertical-align: middle;
}
html.dark-mode .optional-badge {
    background: rgba(167, 139, 250, 0.2);
    color: #A78BFA;
}

/* FORM ROWS */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 13px; font-weight: 700; color: var(--text-primary); }
.form-group label .required { color: #DC2626; font-weight: 800; }

.input-group { position: relative; display: flex; align-items: center; }
.input-icon {
    position: absolute; left: 14px;
    color: #7C3AED; font-size: 14px;
    z-index: 1; pointer-events: none;
    transition: all 0.3s ease;
}
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--bg-input);
    color: var(--text-primary);
}
.form-control::placeholder { color: var(--text-light); }
.form-control.textarea-control { 
    padding: 12px 14px; 
    min-height: 70px; 
    resize: vertical;
    line-height: 1.6;
}
.form-control:focus {
    border-color: #7C3AED;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.12);
    background: var(--bg-card);
}
.form-control:disabled { 
    opacity: 0.7; 
    cursor: not-allowed; 
    background: var(--bg-hover);
}

/* Money input */
.money-input {
    font-weight: 900;
    font-size: 18px;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 1px;
    text-align: right;
    padding-right: 18px;
    color: #7C3AED;
}
html.dark-mode .money-input { color: #C4B5FD; }

/* Datalist */
input[list] { cursor: text; }
input[list]::-webkit-calendar-picker-indicator { display: none; }

/* SUMMARY BOX */
.summary-section {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    padding: 20px 26px;
}
html.dark-mode .summary-section {
    background: linear-gradient(135deg, #2D1B5F 0%, #1E1B4B 100%);
}
.summary-box {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #7C3AED 0%, #5B21B6 100%);
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.3);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.summary-box::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.summary-box-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative;
    z-index: 1;
}
.summary-box-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}
.summary-box-label {
    font-size: 11px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
.summary-box-value {
    font-size: clamp(22px, 2vw, 30px);
    font-weight: 900;
    color: #FCD34D;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}

/* FORM ACTIONS */
.form-actions {
    display: flex; gap: 12px;
    padding: 18px 26px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px; border-radius: 10px;
    font-weight: 700; font-size: 14px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #7C3AED 0%, #5B21B6 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
}
.btn-submit:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}
.btn-reset {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset:hover { background: var(--border-color); }
.btn-cancel {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-cancel:hover { 
    background: #FEE2E2; 
    color: #991B1B; 
    border-color: #FECACA; 
}
html.dark-mode .btn-cancel:hover { 
    background: #7F1D1D; 
    color: #FEE2E2; 
    border-color: #991B1B; 
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .branch-status-card-blue { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .branch-status-info-blue { width: 100%; }
    .btn-back-card-blue { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .form-row { grid-template-columns: 1fr; gap: 16px; }
    .form-row .full-width { grid-column: span 1; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .form-section { padding: 18px 20px; }
    .summary-box { flex-direction: column; text-align: center; padding: 18px 20px; }
    .summary-box-content { align-items: center; }
    .ine-content { justify-content: flex-start; }
    .ine-text { font-size: 12px; }
}
@media (max-width: 480px) {
    .branch-status-name-blue { font-size: 14px; }
    .branch-status-icon-blue { width: 40px; height: 40px; font-size: 16px; }
    .page-header-left h2 { font-size: 16px; }
    .money-input { font-size: 16px; }
    .summary-box-value { font-size: 20px; }
    .summary-box-icon { width: 48px; height: 48px; font-size: 22px; }
    .ine-icon { width: 36px; height: 36px; font-size: 16px; }
    .info-note-employee { padding: 12px 14px; gap: 10px; }
    .ine-content { flex-direction: column; align-items: flex-start; gap: 6px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; updateSummary(); return; }
    
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    
    var formatted = '';
    var count = 0;
    for (var i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    updateSummary();
}

// ============================================================
// UPDATE SUMMARY
// ============================================================
function updateSummary() {
    var amountInput = document.getElementById('amount');
    var amount = 0;
    
    if (amountInput && amountInput.value) {
        amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    }
    
    var display = document.getElementById('totalIncomeDisplay');
    if (display) {
        display.textContent = 'TSh ' + amount.toLocaleString('en-US');
    }
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var amountInput = document.getElementById('amount');
    var rawAmount = amountInput.value.replace(/,/g, '');
    var amount = parseFloat(rawAmount) || 0;
    
    if (amount <= 0) {
        alert('Please enter a valid amount greater than zero.');
        amountInput.focus();
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAll entered data will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateSummary();
    
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
});
</script>
</body>
</html>