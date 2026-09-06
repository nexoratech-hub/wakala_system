<?php
// ================================================================
// FILE: modules/evening_stock/add.php
// WAKALA FINANCIAL SYSTEM - ADD EVENING STOCK
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
// GET PROVIDERS
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order, provider_name");
$stmt->execute();
$providers = $stmt->fetchAll();

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$success_message = '';
$error_message = '';
$show_success = false;
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_evening_stock') {
    try {
        $stock_date = $_POST['stock_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $cash_balance = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $status = $_POST['status'] ?? 'pending';
        $notes = $_POST['notes'] ?? '';
        
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        $branch_name = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        $provider_data = [];
        $total_float = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'provider_') === 0 && !empty($value)) {
                $provider_id = str_replace('provider_', '', $key);
                $amount = floatval(str_replace(',', '', $value));
                if ($amount > 0) {
                    $provider_data[$provider_id] = $amount;
                    $total_float += $amount;
                }
            }
        }
        
        if (empty($provider_data)) {
            throw new Exception('Please enter at least one provider amount.');
        }
        
        $stock_number = 'ES-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM evening_stocks WHERE stock_date = ? AND branch_id = ?");
        $check_stmt->execute([$stock_date, $branch_id]);
        $exists = $check_stmt->fetchColumn();
        
        if ($exists > 0) {
            throw new Exception('An evening stock already exists for this date and branch.');
        }
        
        $provider_json = json_encode($provider_data);
        
        $insert_stmt = $db->prepare("INSERT INTO evening_stocks 
            (stock_number, employee_id, branch, branch_id, stock_date, provider_data, cash_balance, cumm_total, status, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->execute([
            $stock_number,
            $user_id,
            $branch_name,
            $branch_id,
            $stock_date,
            $provider_json,
            $cash_balance,
            $total_float,
            $status,
            $notes
        ]);
        
        $stock_id = $db->lastInsertId();
        
        logActivity($user_id, 'Add Evening Stock', 'Evening Stock', $stock_id, '', 'New evening stock added for branch: ' . $branch_name);
        
        $success_message = 'Evening stock added successfully! Stock Number: ' . $stock_number;
        $show_success = true;
        
        $_POST = [];
        
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
                <h2><i class="fas fa-moon"></i> Add Evening Stock</h2>
                <span class="page-subtitle">Create a new evening stock record</span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($show_success && !empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST" action="" class="main-form" id="eveningStockForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_evening_stock">
                
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="stock_date">Stock Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="stock_date" name="stock_date" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" required onchange="window.location.href='?branch='+this.value">
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
                            <label for="cash_balance">Cash Balance</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="cash_balance" name="cash_balance" 
                                       value="0" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                            <small>Physical cash balance in the till</small>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                                <select id="status" name="status" class="form-control">
                                    <option value="pending">Pending</option>
                                    <option value="waiting">Waiting</option>
                                    <option value="approved">Approved</option>
                                    <option value="adjusted">Adjusted</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Balances</h3>
                        <span class="section-sub">Enter the float amount for each provider</span>
                    </div>
                    
                    <div class="providers-grid">
                        <?php foreach ($providers as $provider): ?>
                            <div class="provider-item">
                                <div class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                    <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                </div>
                                <div class="provider-info">
                                    <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                    <span class="provider-code"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                                </div>
                                <div class="provider-input">
                                    <input type="text" 
                                           id="provider_<?php echo $provider['id']; ?>" 
                                           name="provider_<?php echo $provider['id']; ?>" 
                                           class="form-control provider-amount money-input" 
                                           placeholder="0.00" 
                                           oninput="formatMoneyInput(this); calculateTotals();">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Additional Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="notes">Notes</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Any additional notes..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Summary</h3>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">Total Float:</span>
                            <span class="summary-value" id="totalFloatDisplay">TSh 0.00</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Cash Balance:</span>
                            <span class="summary-value" id="cashBalanceDisplay">TSh 0.00</span>
                        </div>
                        <div class="summary-item total">
                            <span class="summary-label">Total Stock:</span>
                            <span class="summary-value" id="totalStockDisplay">TSh 0.00</span>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Evening Stock
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
    --form-shadow-lg: rgba(0,0,0,0.12);
    --form-dropdown-bg: #FFFFFF;
    --form-dropdown-border: #E5E7EB;
    --form-success-bg: #D1FAE5;
    --form-success-text: #065F46;
    --form-success-border: #A7F3D0;
    --form-danger-bg: #FEE2E2;
    --form-danger-text: #991B1B;
    --form-danger-border: #FECACA;
    --provider-bg: #EFF6FF;
    --provider-bg-hover: #DBEAFE;
    --provider-border: #93C5FD;
    --provider-text: #1E40AF;
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
    --form-shadow-lg: rgba(0,0,0,0.4);
    --form-dropdown-bg: #1F2937;
    --form-dropdown-border: #374151;
    --form-success-bg: #065F46;
    --form-success-text: #D1FAE5;
    --form-success-border: #047857;
    --form-danger-bg: #7F1D1D;
    --form-danger-text: #FEE2E2;
    --form-danger-border: #991B1B;
    --provider-bg: #1A2A3A;
    --provider-bg-hover: #1F3A4F;
    --provider-border: #2D5A7D;
    --provider-text: #93C5FD;
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
    color: #3B82F6;
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

.alert-success {
    background: var(--form-success-bg);
    color: var(--form-success-text);
    border: 1px solid var(--form-success-border);
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
    transition: all 0.3s ease;
}

.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--form-border);
    transition: all 0.3s ease;
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
    color: #3B82F6;
    margin-right: 8px;
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
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.input-group .form-control:focus + .input-icon,
.input-group .form-control:focus ~ .input-icon {
    color: #3B82F6;
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
    min-height: 80px;
}

.form-group small {
    font-size: 12px;
    color: var(--form-text-secondary);
    margin-top: 2px;
}

.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.provider-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: var(--provider-bg);
    border-radius: 8px;
    border: 2px solid var(--provider-border);
    transition: all 0.3s ease;
}

.provider-item:hover {
    background: var(--provider-bg-hover);
    border-color: #60A5FA;
}

.provider-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    flex-shrink: 0;
}

.provider-info {
    flex: 1;
    min-width: 0;
}

.provider-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--provider-text);
    display: block;
}

.provider-code {
    font-size: 10px;
    color: var(--form-text-light);
    text-transform: uppercase;
}

.provider-input {
    width: 100px;
    flex-shrink: 0;
}

.provider-input .form-control {
    padding: 6px 10px;
    border-radius: 6px;
    border: 2px solid var(--provider-border);
    font-size: 13px;
    outline: none;
    transition: all 0.3s ease;
    background: #FFFFFF;
    color: var(--form-text);
    width: 100%;
    text-align: right;
    font-weight: 600;
}

html.dark-mode .provider-input .form-control {
    background: #1A2A3A;
    color: #DBEAFE;
}

.provider-input .form-control:focus {
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
}

.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

.summary-section {
    background: var(--form-hover);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
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
    background: var(--form-success-bg);
    border-color: var(--form-success-border);
}

html.dark-mode .summary-item.total {
    background: #065F46;
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--form-text-secondary);
    font-weight: 600;
}

.summary-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--form-text);
}

.summary-item.total .summary-value {
    color: #10B981;
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
    background: #3B82F6;
    color: white;
}

.btn-submit:hover {
    background: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59,130,246,0.3);
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
    .providers-grid {
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
    
    .providers-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .provider-item {
        flex-wrap: wrap;
        padding: 8px 10px;
    }
    
    .provider-input {
        width: 100%;
    }
    
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        justify-content: center;
        width: 100%;
    }
}

@media (max-width: 480px) {
    .providers-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
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
    var providerInputs = document.querySelectorAll('.provider-amount');
    var totalFloat = 0;
    
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        totalFloat += value;
    });
    
    var cashBalanceInput = document.getElementById('cash_balance');
    var rawCash = cashBalanceInput ? cashBalanceInput.value.replace(/,/g, '') : '0';
    var cashBalance = parseFloat(rawCash) || 0;
    var totalStock = totalFloat + cashBalance;
    
    document.getElementById('totalFloatDisplay').textContent = 'TSh ' + formatNumberDisplay(totalFloat);
    document.getElementById('cashBalanceDisplay').textContent = 'TSh ' + formatNumberDisplay(cashBalance);
    document.getElementById('totalStockDisplay').textContent = 'TSh ' + formatNumberDisplay(totalStock);
}

function formatNumberDisplay(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function validateForm() {
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '') {
        alert('Please select a branch.');
        if (branch) branch.focus();
        return false;
    }
    
    var hasProvider = false;
    var providerInputs = document.querySelectorAll('.provider-amount');
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        if (value > 0) {
            hasProvider = true;
        }
    });
    
    if (!hasProvider) {
        alert('Please enter at least one provider amount.');
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form? All entered data will be lost.');
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    
    var cashBalance = document.getElementById('cash_balance');
    if (cashBalance) {
        cashBalance.addEventListener('input', function() {
            formatMoneyInput(this);
            calculateTotals();
        });
    }
    
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
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() { successAlert.style.display = 'none'; }, 5000);
    }
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { errorAlert.style.display = 'none'; }, 8000);
    }
});
</script>
</body>
</html>