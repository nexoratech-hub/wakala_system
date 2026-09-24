<?php
// ================================================================
// FILE: modules/expenses/add.php
// WAKALA FINANCIAL SYSTEM - ADD EXPENSE (ADMIN)
// ✅ RED theme only
// ✅ Branch filter, category dropdown
// ✅ REMOVED: Receipt upload
// ✅ Description is OPTIONAL
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: add_employee.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll();

// ============================================================
// GET CATEGORIES
// ============================================================
$stmt = $db->prepare("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
$stmt->execute();
$categories = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

$add_branch_name = 'All Branches';
$add_branch_code = '';
$add_branch_location = '';

if ($selected_branch > 0) {
    foreach ($all_branches as $b) {
        if ($b['id'] == $selected_branch) {
            $add_branch_name = $b['branch_name'];
            $add_branch_code = $b['branch_code'];
            $add_branch_location = $b['location'] ?? '';
            break;
        }
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    try {
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $expense_name = trim($_POST['expense_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $is_business_expense = intval($_POST['is_business_expense'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation (Description is OPTIONAL - not validated)
        if ($branch_id <= 0) throw new Exception('Please select a branch.');
        if (empty($expense_name)) throw new Exception('Please enter expense name.');
        if (empty($category)) throw new Exception('Please select a category.');
        if ($amount <= 0) throw new Exception('Please enter a valid amount.');
        
        // Get branch name
        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // ✅ REMOVED: Receipt upload handling
        $receipt_path = null;
        
        // Generate expense number
        $expense_number = 'EXP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert
        $stmt = $db->prepare("INSERT INTO expenses 
            (expense_number, employee_id, branch, branch_id, expense_date, expense_name, category, 
             amount, description, receipt_path, is_business_expense, is_salary_related, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
        
        $stmt->execute([
            $expense_number,
            $user_id,
            $branch_name,
            $branch_id,
            $expense_date,
            $expense_name,
            $category,
            $amount,
            $description,
            $receipt_path,
            $is_business_expense,
            $notes
        ]);
        
        $expense_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Expense', 'Expenses', $expense_id, '', 'Added expense: ' . $expense_number . ' - ' . formatCurrency($amount));
        
        $_SESSION['success_message'] = 'Expense of ' . formatCurrency($amount) . ' added successfully! Ref: ' . $expense_number;
        header('Location: index.php' . ($branch_id > 0 ? '?branch_id=' . $branch_id : ''));
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Adding Expense For' : 'Adding Expense (Select Branch)'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($add_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($add_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($add_branch_code); ?></span>
                <?php endif; ?>
                <?php if ($selected_branch > 0 && !empty($add_branch_location)): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($add_branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="index.php<?php echo $selected_branch > 0 ? '?branch_id=' . $selected_branch : ''; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Expense</h2>
                <span class="page-subtitle">Record a new expense</span>
            </div>
        </div>

        <!-- MESSAGES -->
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- FORM -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="expenseForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_expense">
                
                <!-- BASIC INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">* Required fields</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="expense_date">Expense Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="expense_date" name="expense_date" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($all_branches as $br): ?>
                                        <option value="<?php echo $br['id']; ?>" <?php echo ($selected_branch == $br['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($br['branch_name']); ?>
                                            <?php if ($br['branch_code']): ?>
                                                (<?php echo htmlspecialchars($br['branch_code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EXPENSE DETAILS -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-receipt"></i> Expense Details</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="expense_name">Expense Name <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tag"></i></span>
                                <input type="text" id="expense_name" name="expense_name" 
                                       class="form-control" 
                                       placeholder="e.g. Office Rent - September"
                                       maxlength="200" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="category">Category <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tags"></i></span>
                                <select id="category" name="category" class="form-control" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
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
                                       oninput="formatMoneyInput(this)"
                                       required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="is_business_expense">Expense Type</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-briefcase"></i></span>
                                <select id="is_business_expense" name="is_business_expense" class="form-control">
                                    <option value="1">Business Expense (counts in profit)</option>
                                    <option value="0">Personal / Other (not in profit)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="description">Description <span class="optional-badge">Optional</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <textarea id="description" name="description" 
                                          class="form-control textarea-control" 
                                          rows="3" 
                                          placeholder="Detailed description of the expense (optional)..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NOTES -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Additional Notes</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <textarea id="notes" name="notes" 
                                      class="form-control textarea-control" 
                                      rows="2" 
                                      placeholder="Any additional notes (optional)..."></textarea>
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
                            <span class="summary-box-label">Expense Amount</span>
                            <span class="summary-box-value" id="amountDisplay">TSh 0</span>
                        </div>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Expense
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="index.php<?php echo $selected_branch > 0 ? '?branch_id=' . $selected_branch : ''; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   RED THEME - ADD EXPENSE
   ============================================================ */
:root {
    --exp-bg: #f3f4f6;
    --exp-card-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-input-bg: #F9FAFB;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
}
html.dark-mode {
    --exp-bg: #0f172a;
    --exp-card-bg: #1E293B;
    --exp-text: #F1F5F9;
    --exp-text-secondary: #94A3B8;
    --exp-text-light: #64748B;
    --exp-border: #334155;
    --exp-input-bg: #374151;
    --exp-hover: #2D3A4F;
    --exp-shadow: rgba(0,0,0,0.3);
}
* { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100% !important;
}
body { background: var(--exp-bg) !important; color: var(--exp-text); }
.main-wrapper, .main-content { background: var(--exp-bg) !important; }
.main-content { padding: 16px 20px !important; }

.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; color: #FFFFFF;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1;
    position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
}
.branch-status-location {
    display: flex; align-items: center; gap: 4px;
    font-size: 12px; color: rgba(255, 255, 255, 0.7);
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 16px; padding: 0 4px;
    flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--exp-text); margin: 0;
}
.page-header-left h2 i { color: #DC2626; margin-right: 6px; }
.page-subtitle {
    font-size: 13px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 3px 12px; border-radius: 12px;
}

.alert {
    padding: 14px 18px; border-radius: 8px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 12px;
    font-weight: 500; font-size: 13px;
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6;
}

.form-container {
    background: var(--exp-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}
.form-section {
    padding: 22px 26px;
    border-bottom: 1px solid var(--exp-border);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 18px;
    flex-wrap: wrap; gap: 8px;
}
.section-header h3 {
    font-size: 15px; font-weight: 700;
    color: var(--exp-text); margin: 0;
}
.section-header h3 i { color: #DC2626; margin-right: 8px; }
.section-badge {
    font-size: 11px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 3px 12px; border-radius: 12px;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 13px; font-weight: 700; color: var(--exp-text); }
.form-group label .required { color: #DC2626; font-weight: 800; }

/* ✅ OPTIONAL BADGE */
.optional-badge {
    font-size: 9px;
    font-weight: 800;
    color: #DC2626;
    background: rgba(220, 38, 38, 0.1);
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 6px;
    display: inline-block;
    vertical-align: middle;
}
html.dark-mode .optional-badge {
    background: rgba(252, 165, 165, 0.2);
    color: #FCA5A5;
}

.input-group { position: relative; display: flex; align-items: center; }
.input-icon {
    position: absolute; left: 14px;
    color: #DC2626; font-size: 14px;
    z-index: 1; pointer-events: none;
}
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border-radius: 10px;
    border: 1.5px solid var(--exp-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--exp-input-bg);
    color: var(--exp-text);
}
.form-control::placeholder { color: var(--exp-text-light); }
.form-control.textarea-control {
    padding: 12px 14px; min-height: 70px;
    resize: vertical; line-height: 1.6;
}
.form-control:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
    background: var(--exp-card-bg);
}
.input-group select.form-control {
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23DC2626' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 40px;
    cursor: pointer;
}
.input-group select.form-control option {
    background: var(--exp-card-bg);
    color: var(--exp-text);
}
.form-group small {
    font-size: 12px; color: var(--exp-text-secondary);
    margin-top: 2px;
}
.money-input {
    font-weight: 900; font-size: 18px;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 1px; text-align: right;
    padding-right: 18px;
    color: #DC2626;
}
html.dark-mode .money-input { color: #FCA5A5; }

.summary-section {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    padding: 20px 26px;
}
html.dark-mode .summary-section {
    background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);
}
.summary-box {
    display: flex; align-items: center; gap: 18px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
    color: #FFFFFF; position: relative; overflow: hidden;
}
.summary-box::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.summary-box-icon {
    width: 60px; height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative; z-index: 1;
}
.summary-box-content {
    display: flex; flex-direction: column; gap: 4px;
    flex: 1; min-width: 0;
    position: relative; z-index: 1;
}
.summary-box-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.summary-box-value {
    font-size: clamp(22px, 2vw, 30px);
    font-weight: 900; color: #FCD34D;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
}

.form-actions {
    display: flex; gap: 12px;
    padding: 18px 26px;
    border-top: 1px solid var(--exp-border);
    background: var(--exp-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px; border-radius: 10px;
    font-weight: 700; font-size: 14px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.45);
}
.btn-submit:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}
.btn-reset, .btn-cancel {
    background: var(--exp-card-bg);
    color: var(--exp-text-secondary);
    border: 1.5px solid var(--exp-border);
}
.btn-reset:hover { background: var(--exp-border); }
.btn-cancel:hover {
    background: #FEE2E2; color: #991B1B;
    border-color: #FECACA;
}
html.dark-mode .btn-cancel:hover {
    background: #7F1D1D; color: #FEE2E2;
    border-color: #991B1B;
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 10px; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; gap: 16px; }
    .form-row .full-width { grid-column: span 1; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .summary-box { flex-direction: column; text-align: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .form-section { padding: 16px 14px; }
}
</style>

<script>
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

function updateSummary() {
    var amountInput = document.getElementById('amount');
    var amount = 0;
    if (amountInput && amountInput.value) {
        amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    }
    var display = document.getElementById('amountDisplay');
    if (display) {
        display.textContent = 'TSh ' + amount.toLocaleString('en-US');
    }
}

function validateForm() {
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '') { alert('Please select a branch.'); branch.focus(); return false; }
    var name = document.getElementById('expense_name');
    if (!name || name.value.trim() === '') { alert('Please enter expense name.'); name.focus(); return false; }
    var cat = document.getElementById('category');
    if (!cat || cat.value === '') { alert('Please select category.'); cat.focus(); return false; }
    var amountInput = document.getElementById('amount');
    var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    if (amount <= 0) { alert('Please enter a valid amount.'); amountInput.focus(); return false; }
    
    var btn = document.getElementById('submitBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form? All entered data will be lost.');
}

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
});
</script>
</body>
</html>