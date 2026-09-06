<?php
// ================================================================
// FILE: modules/morning_report/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT MORNING REPORT
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
// GET REPORT ID
// ============================================================
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM morning_reports WHERE id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only edit their own reports
if ($role == 'employee' && $report['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

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
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($report['provider_data'] ?? '{}', true);
$total_float = $report['cumm_total'] ?? 0;
$cash_balance = $report['cash_balance'] ?? 0;

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$success_message = '';
$error_message = '';
$show_success = false;
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_morning_report') {
    try {
        // Get form data
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $cash_balance = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $notes = $_POST['notes'] ?? '';
        
        // Validate
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // Build provider data from POST
        $provider_data_new = [];
        $total_float_new = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'provider_') === 0 && !empty($value)) {
                $provider_id = str_replace('provider_', '', $key);
                $amount = floatval(str_replace(',', '', $value));
                if ($amount > 0) {
                    $provider_data_new[$provider_id] = $amount;
                    $total_float_new += $amount;
                }
            }
        }
        
        if (empty($provider_data_new)) {
            throw new Exception('Please enter at least one provider amount.');
        }
        
        // Check if report already exists for this date and branch (excluding current)
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports 
                                    WHERE report_date = ? AND branch_id = ? AND employee_id = ? AND id != ?");
        $check_stmt->execute([$report_date, $branch_id, $user_id, $report_id]);
        $exists = $check_stmt->fetchColumn();
        
        if ($exists > 0) {
            throw new Exception('A morning report already exists for this branch on this date.');
        }
        
        // Update data
        $provider_json = json_encode($provider_data_new);
        
        $update_stmt = $db->prepare("UPDATE morning_reports 
            SET report_date = ?, branch_id = ?, branch = ?, provider_data = ?, cash_balance = ?, cumm_total = ?, notes = ? 
            WHERE id = ?");
        
        $update_stmt->execute([
            $report_date,
            $branch_id,
            $branch_name,
            $provider_json,
            $cash_balance,
            $total_float_new,
            $notes,
            $report_id
        ]);
        
        // Log activity
        try {
            $stmt = $db->prepare("INSERT INTO activity_logs (employee_id, action, module, record_id, new_value, branch_id) 
                                  VALUES (?, 'Edit Morning Report', 'Morning Report', ?, ?, ?)");
            $stmt->execute([$user_id, $report_id, 'Morning report updated', $branch_id]);
        } catch (Exception $e) {
            // Activity log table might not exist, ignore
        }
        
        $success_message = 'Morning report updated successfully!';
        $show_success = true;
        
        // Refresh report data
        $stmt = $db->prepare("SELECT * FROM morning_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch();
        $provider_data = json_decode($report['provider_data'] ?? '{}', true);
        $total_float = $report['cumm_total'] ?? 0;
        $cash_balance = $report['cash_balance'] ?? 0;
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// ============================================================
// GET BRANCH NAME
// ============================================================
$selected_branch = $report['branch_id'] ?? 0;
$branch_name = '';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-edit"></i> Edit Morning Report</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($report['report_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php<?php echo $selected_branch > 0 ? '?branch=' . $selected_branch : ''; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="view.php?id=<?php echo $report_id; ?>" class="btn btn-view">
                    <i class="fas fa-eye"></i> View
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
        ============================================================ -->
        <?php if ($show_success && !empty($success_message)): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger" id="errorAlert">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="morningReportForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_morning_report">
                <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                
                <!-- ===== BASIC INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="report-number-badge"><?php echo htmlspecialchars($report['report_number']); ?></span>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="report_date">Report Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="report_date" name="report_date" 
                                       value="<?php echo $report['report_date']; ?>" 
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
                                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
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
                                       value="<?php echo number_format($cash_balance, 0, '.', ','); ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                            <small>Physical cash balance in the till</small>
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <input type="text" id="notes" name="notes" 
                                       value="<?php echo htmlspecialchars($report['notes'] ?? ''); ?>" 
                                       class="form-control" placeholder="Any additional notes...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== PROVIDERS SECTION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Balances</h3>
                        <span class="section-sub">Enter the float amount for each provider</span>
                    </div>
                    
                    <div class="providers-grid" id="providersContainer">
                        <?php foreach ($providers as $provider): 
                            $provider_key = 'provider_' . $provider['id'];
                            $provider_value = isset($provider_data[$provider['id']]) ? number_format($provider_data[$provider['id']], 0, '.', ',') : '';
                        ?>
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
                                           value="<?php echo $provider_value; ?>"
                                           data-provider-id="<?php echo $provider['id']; ?>"
                                           oninput="formatMoneyInput(this); calculateTotals();">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="providers-summary">
                        <div class="summary-row">
                            <span class="summary-label">Total Float:</span>
                            <span class="summary-value" id="totalFloatDisplay">TSh <?php echo number_format($total_float, 0, '.', ','); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Cash Balance:</span>
                            <span class="summary-value" id="cashBalanceDisplay">TSh <?php echo number_format($cash_balance, 0, '.', ','); ?></span>
                        </div>
                        <div class="summary-row total">
                            <span class="summary-label">Grand Total (Float + Cash):</span>
                            <span class="summary-value" id="grandTotalDisplay">TSh <?php echo number_format($total_float + $cash_balance, 0, '.', ','); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Report
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset Form
                    </button>
                    <a href="index.php<?php echo $selected_branch > 0 ? '?branch=' . $selected_branch : ''; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --form-bg: #FFFFFF;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
    --form-card-bg: #FFFFFF;
    --form-card-header: #FAFBFC;
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
    --provider-bg: #E8F5E9;
    --provider-bg-hover: #C8E6C9;
    --provider-border: #A5D6A7;
    --provider-text: #1B5E20;
}

html.dark-mode {
    --form-bg: #1F2937;
    --form-text: #F9FAFB;
    --form-text-secondary: #9CA3AF;
    --form-text-light: #6B7280;
    --form-border: #374151;
    --form-card-bg: #1F2937;
    --form-card-header: #374151;
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
    --provider-bg: #1B3A1B;
    --provider-bg-hover: #2E4F2E;
    --provider-border: #2E7D32;
    --provider-text: #A5D6A7;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--form-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--form-bg) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
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
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #F59E0B;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 3px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.report-number-badge {
    font-size: 13px;
    font-weight: 600;
    color: #F59E0B;
    background: rgba(245,158,11,0.1);
    padding: 4px 14px;
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

.btn-view {
    background: #DBEAFE;
    color: #1D4ED8;
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

.btn-view:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

/* ============================================================
   ALERTS
   ============================================================ */
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
    transition: all 0.3s ease;
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

.alert i {
    font-size: 20px;
    flex-shrink: 0;
}

.alert span {
    flex: 1;
}

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

.alert-close:hover {
    opacity: 1;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   FORM CONTAINER
   ============================================================ */
.form-container {
    background: var(--form-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--form-shadow);
    border: 1px solid var(--form-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

/* ============================================================
   FORM SECTIONS
   ============================================================ */
.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--form-border);
    transition: all 0.3s ease;
}

.form-section:last-child {
    border-bottom: none;
}

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
    color: #F59E0B;
    margin-right: 8px;
}

.section-sub {
    font-size: 13px;
    color: var(--form-text-secondary);
}

/* ============================================================
   FORM ROWS
   ============================================================ */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
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
    transition: color 0.3s ease;
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
    transition: color 0.3s ease;
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
    border-color: #F59E0B;
    box-shadow: 0 0 0 3px rgba(245,158,11,0.1);
}

.input-group .form-control:focus + .input-icon,
.input-group .form-control:focus ~ .input-icon {
    color: #F59E0B;
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

.form-group small {
    font-size: 12px;
    color: var(--form-text-secondary);
    margin-top: 2px;
    transition: color 0.3s ease;
}

/* ============================================================
   PROVIDERS GRID - LIGHT GREEN BACKGROUND
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
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
    border-color: #66BB6A;
    box-shadow: 0 2px 8px var(--form-shadow);
    transform: translateY(-1px);
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
    transition: color 0.3s ease;
}

.provider-code {
    font-size: 10px;
    color: var(--form-text-light);
    text-transform: uppercase;
}

.provider-input {
    width: 110px;
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
    background: #2D4A2D;
    color: #E8F5E9;
}

.provider-input .form-control:focus {
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
}

.provider-input .form-control::placeholder {
    color: var(--form-text-light);
}

/* ============================================================
   MONEY INPUT STYLES
   ============================================================ */
.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

.money-input:focus {
    border-color: #4CAF50 !important;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2) !important;
}

/* ============================================================
   PROVIDERS SUMMARY
   ============================================================ */
.providers-summary {
    background: var(--form-hover);
    border-radius: 8px;
    padding: 14px 18px;
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 12px;
    transition: all 0.3s ease;
}

.summary-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.summary-row.total {
    border-left: 2px solid var(--form-border);
    padding-left: 16px;
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

.summary-row.total .summary-value {
    color: #10B981;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--form-border);
    background: var(--form-card-header);
    transition: all 0.3s ease;
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
    background: #F59E0B;
    color: white;
}

.btn-submit:hover {
    background: #D97706;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
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

/* ============================================================
   RESPONSIVE
   ============================================================ */
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
    
    .form-section {
        padding: 16px 14px;
    }
    
    .providers-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .provider-item {
        padding: 8px 10px;
        flex-wrap: wrap;
    }
    
    .provider-input {
        width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        justify-content: center;
        width: 100%;
    }
    
    .providers-summary {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .summary-row.total {
        border-left: none;
        border-top: 2px solid var(--form-border);
        padding-left: 0;
        padding-top: 8px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
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
    
    .summary-value {
        font-size: 15px;
    }
    
    .provider-item {
        padding: 6px 8px;
    }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT - 1,000,000 format
// ============================================================
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

// ============================================================
// CALCULATE TOTALS
// ============================================================
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
    var grandTotal = totalFloat + cashBalance;
    
    var totalFloatDisplay = document.getElementById('totalFloatDisplay');
    var cashBalanceDisplay = document.getElementById('cashBalanceDisplay');
    var grandTotalDisplay = document.getElementById('grandTotalDisplay');
    
    if (totalFloatDisplay) totalFloatDisplay.textContent = 'TSh ' + formatNumberDisplay(totalFloat);
    if (cashBalanceDisplay) cashBalanceDisplay.textContent = 'TSh ' + formatNumberDisplay(cashBalance);
    if (grandTotalDisplay) grandTotalDisplay.textContent = 'TSh ' + formatNumberDisplay(grandTotal);
}

function formatNumberDisplay(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// VALIDATE FORM
// ============================================================
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
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// CONFIRM RESET
// ============================================================
function confirmReset() {
    return confirm('Are you sure you want to reset the form? All entered data will be lost.');
}

// ============================================================
// DARK MODE SYNC
// ============================================================
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
    
    var successAlert = document.getElementById('successAlert');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.display = 'none';
        }, 5000);
    }
    
    var errorAlert = document.getElementById('errorAlert');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.display = 'none';
        }, 8000);
    }
    
    var closeBtns = document.querySelectorAll('.alert-close');
    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
});
</script>

</body>
</html>