<?php
// ================================================================
// FILE: modules/expenses/add_employee.php
// WAKALA FINANCIAL SYSTEM - ADD EXPENSE (EMPLOYEE)
// ✅ RED theme only
// ✅ Employee adds for THEIR branch only
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
    header('Location: add.php');
    exit();
}

// Employee data
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee || intval($employee['branch_id']) <= 0) {
    $_SESSION['error_message'] = 'Your account is not assigned to any branch.';
    header('Location: index_employee.php');
    exit();
}

$employee_branch_id = intval($employee['branch_id']);

// Branch info
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

// Categories
$stmt = $db->prepare("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Handle submission
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    try {
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $expense_name = trim($_POST['expense_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $is_business_expense = intval($_POST['is_business_expense'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($expense_name)) throw new Exception('Please enter expense name.');
        if (empty($category)) throw new Exception('Please select a category.');
        if ($amount <= 0) throw new Exception('Please enter a valid amount.');
        
        // Receipt upload
        $receipt_path = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/receipts/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];
            
            if (!in_array($ext, $allowed)) throw new Exception('Invalid file type.');
            if ($_FILES['receipt']['size'] > 5 * 1024 * 1024) throw new Exception('File too large.');
            
            $filename = 'receipt_' . date('Ymd') . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_dir . $filename)) {
                $receipt_path = 'uploads/receipts/' . $filename;
            }
        }
        
        $expense_number = 'EXP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("INSERT INTO expenses 
            (expense_number, employee_id, branch, branch_id, expense_date, expense_name, category, 
             amount, description, receipt_path, is_business_expense, is_salary_related, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
        
        $stmt->execute([
            $expense_number, $user_id, $branch_name, $employee_branch_id,
            $expense_date, $expense_name, $category, $amount, $description,
            $receipt_path, $is_business_expense, $notes
        ]);
        
        $expense_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Expense', 'Expenses', $expense_id, '', 
            'Employee added expense: ' . $expense_number . ' - ' . formatCurrency($amount));
        
        $_SESSION['success_message'] = 'Expense added successfully! Ref: ' . $expense_number;
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
        
        <!-- BLUE... wait, RED BRANCH CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Adding Expense For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($branch_name); ?></span>
                <?php if ($branch_code): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($branch_code); ?></span>
                <?php endif; ?>
                <?php if ($branch_location): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="index_employee.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Expense</h2>
                <span class="page-subtitle">Record a new expense</span>
            </div>
        </div>

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

        <!-- INFO NOTE -->
        <div class="info-note">
            <div class="ine-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="ine-content">
                <span class="ine-text">
                    Expense will be saved for <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                    at <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                </span>
                <span class="ine-badge">
                    <i class="fas fa-receipt"></i> Recorded under your name
                </span>
            </div>
        </div>

        <!-- FORM -->
        <div class="form-container">
            <form method="POST" action="" id="expenseForm" enctype="multipart/form-data" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_expense">
                
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
                            <label>Branch</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <input type="text" value="<?php echo htmlspecialchars($branch_name); ?>" 
                                       class="form-control" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
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
                                       class="form-control" placeholder="e.g. Office Rent" required maxlength="200">
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
                                       placeholder="0" inputmode="numeric"
                                       oninput="formatMoneyInput(this)" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="is_business_expense">Expense Type</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-briefcase"></i></span>
                                <select id="is_business_expense" name="is_business_expense" class="form-control">
                                    <option value="1">Business Expense</option>
                                    <option value="0">Personal / Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="description">Description</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <textarea id="description" name="description" class="form-control textarea-control" rows="2" placeholder="Brief description..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="receipt">Receipt (Optional)</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-paperclip"></i></span>
                                <input type="file" id="receipt" name="receipt" 
                                       class="form-control file-input" 
                                       accept=".jpg,.jpeg,.png,.pdf,.gif">
                            </div>
                            <small>Max 5MB • JPG, PNG, PDF, GIF</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <textarea id="notes" name="notes" class="form-control textarea-control" rows="2" placeholder="Additional notes (optional)..."></textarea>
                        </div>
                    </div>
                </div>
                
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
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Expense
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
<?php include __DIR__ . '/_styles.php'; ?>
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
    var a = document.getElementById('amount');
    var amount = (a && a.value) ? parseFloat(a.value.replace(/,/g, '')) || 0 : 0;
    var d = document.getElementById('amountDisplay');
    if (d) d.textContent = 'TSh ' + amount.toLocaleString('en-US');
}
function validateForm() {
    var n = document.getElementById('expense_name');
    if (!n || n.value.trim() === '') { alert('Enter expense name.'); n.focus(); return false; }
    var c = document.getElementById('category');
    if (!c || c.value === '') { alert('Select category.'); c.focus(); return false; }
    var a = document.getElementById('amount');
    var amount = parseFloat(a.value.replace(/,/g, '')) || 0;
    if (amount <= 0) { alert('Enter valid amount.'); a.focus(); return false; }
    var b = document.getElementById('submitBtn');
    b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    b.disabled = true;
    return true;
}
function confirmReset() { return confirm('Reset form?'); }
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