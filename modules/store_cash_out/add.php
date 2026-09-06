<?php
// ================================================================
// FILE: modules/store_cash_out/add.php
// WAKALA FINANCIAL SYSTEM - ADD CASH OUT
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
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_cashout') {
    try {
        $cashout_date = $_POST['cashout_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $reason = trim($_POST['reason'] ?? '');
        $taken_by = trim($_POST['taken_by'] ?? '');
        $approved_by = trim($_POST['approved_by'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount.');
        }
        if (empty($reason)) {
            throw new Exception('Please enter a reason for cash out.');
        }
        
        $branch_name = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        $cashout_number = 'CO-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $approved_date = ($status == 'approved') ? date('Y-m-d') : null;
        
        $insert_stmt = $db->prepare("INSERT INTO store_cash_out 
            (cashout_number, employee_id, branch, branch_id, cashout_date, amount, reason, 
             taken_by, approved_by, approved_date, description, status, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->execute([
            $cashout_number,
            $user_id,
            $branch_name,
            $branch_id,
            $cashout_date,
            $amount,
            $reason,
            $taken_by,
            $approved_by,
            $approved_date,
            $description,
            $status,
            $notes
        ]);
        
        $cashout_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Cash Out', 'Store Cash Out', $cashout_id, '', 'New cash out added for branch: ' . $branch_name);
        
        $_SESSION['success_message'] = 'Cash out added successfully! Number: ' . $cashout_number;
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
                <h2><i class="fas fa-plus-circle"></i> Add Cash Out</h2>
                <span class="page-subtitle">Create a new cash out record</span>
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
            <form method="POST" action="" class="main-form" id="cashoutForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_cashout">
                
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cashout_date">Cash Out Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="cashout_date" name="cashout_date" 
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
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>">
                                            <?php echo htmlspecialchars($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="amount" name="amount" 
                                       value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : '0'; ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this)">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                                <select id="status" name="status" class="form-control">
                                    <option value="pending" <?php echo (isset($_POST['status']) && $_POST['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo (isset($_POST['status']) && $_POST['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo (isset($_POST['status']) && $_POST['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="cancelled" <?php echo (isset($_POST['status']) && $_POST['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-file-alt"></i> Reason &amp; Details</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reason">Reason <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-question-circle"></i></span>
                                <input type="text" id="reason" name="reason" 
                                       value="<?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., Store supplies purchase" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="taken_by">Taken By</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" id="taken_by" name="taken_by" 
                                       value="<?php echo isset($_POST['taken_by']) ? htmlspecialchars($_POST['taken_by']) : ''; ?>" 
                                       class="form-control" placeholder="Name of person taking cash">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="approved_by">Approved By</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-check"></i></span>
                                <input type="text" id="approved_by" name="approved_by" 
                                       value="<?php echo isset($_POST['approved_by']) ? htmlspecialchars($_POST['approved_by']) : ''; ?>" 
                                       class="form-control" placeholder="Name of approver">
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

                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Add Cash Out
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

function validateForm() {
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '') {
        alert('Please select a branch.');
        branch.focus();
        return false;
    }
    
    var amount = document.getElementById('amount');
    var rawValue = amount.value.replace(/,/g, '');
    if (parseFloat(rawValue) <= 0) {
        alert('Please enter a valid amount.');
        amount.focus();
        return false;
    }
    
    var reason = document.getElementById('reason');
    if (!reason || reason.value.trim() === '') {
        alert('Please enter a reason for cash out.');
        reason.focus();
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