<?php
// ================================================================
// FILE: modules/commissions/add_employee.php
// WAKALA FINANCIAL SYSTEM - ADD COMMISSION (EMPLOYEE)
// ✅ English, shorter instructions, better CSS
// ✅ Employee adds commission for THEIR branch only
// ✅ Auto-set employee_id = current user
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
// GET PROVIDERS FOR THIS BRANCH
// ============================================================
$stmt = $db->prepare("
    SELECT 
        p.*,
        bp.provider_code as branch_provider_code
    FROM providers p
    INNER JOIN branch_providers bp ON p.id = bp.provider_id
    WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
    ORDER BY p.display_order, p.provider_name
");
$stmt->execute([$employee_branch_id]);
$all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_commission') {
    try {
        $commission_date = $_POST['commission_date'] ?? date('Y-m-d');
        $notes = $_POST['notes'] ?? '';
        
        // Build provider data
        $provider_data = [];
        $total_commission = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'provider_') === 0 && !empty($value)) {
                $provider_id = str_replace('provider_', '', $key);
                $amount = floatval(str_replace(',', '', $value));
                if ($amount > 0) {
                    $provider_data[$provider_id] = $amount;
                    $total_commission += $amount;
                }
            }
        }
        
        if (empty($provider_data)) {
            throw new Exception('Please enter at least one provider amount.');
        }
        
        if ($total_commission <= 0) {
            throw new Exception('Total commission must be greater than 0.');
        }
        
        $commission_number = 'COM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $provider_json = json_encode($provider_data);
        
        $stmt = $db->prepare("INSERT INTO commissions 
            (commission_number, employee_id, branch, branch_id, commission_date, provider_data, 
             total_commission, other_income, total_business_income, allocate_to_capital, allocated_amount, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'no', 0, ?)");
        
        $stmt->execute([
            $commission_number,
            $user_id,
            $branch_name,
            $employee_branch_id,
            $commission_date,
            $provider_json,
            $total_commission,
            $total_commission,
            $notes
        ]);
        
        $commission_id = $db->lastInsertId();
        
        logActivity(
            $user_id, 
            'Add Commission', 
            'Commissions', 
            $commission_id, 
            '', 
            'Employee added commission: ' . $commission_number . ' - ' . formatCurrency($total_commission)
        );
        
        $_SESSION['success_message'] = 'Commission added successfully! Ref: ' . $commission_number;
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
                <span class="branch-status-label-blue">Adding Commission For</span>
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
                <h2><i class="fas fa-plus-circle"></i> Add Commission</h2>
                <span class="page-subtitle">Enter commission amount for each provider</span>
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
                    Commission will be saved for <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                    at <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                </span>
                <span class="ine-badge">
                    <i class="fas fa-hand-holding-usd"></i> Adds to profit only
                </span>
            </div>
        </div>

        <!-- FORM -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="commissionForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_commission">
                
                <!-- BASIC INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">* Required fields</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="commission_date">Commission Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="commission_date" name="commission_date" 
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
                
                <!-- PROVIDER COMMISSIONS -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Commissions</h3>
                        <span class="section-badge">Enter amount for each provider</span>
                    </div>
                    
                    <?php if (count($all_providers) > 0): ?>
                        <div class="providers-grid">
                            <?php foreach ($all_providers as $provider): ?>
                                <div class="provider-item">
                                    <div class="provider-icon" style="background: <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?>;">
                                        <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                    </div>
                                    <div class="provider-info">
                                        <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                        <span class="provider-code"><?php echo htmlspecialchars($provider['branch_provider_code'] ?? $provider['provider_code']); ?></span>
                                    </div>
                                    <div class="provider-input">
                                        <input type="text" 
                                               id="provider_<?php echo $provider['id']; ?>" 
                                               name="provider_<?php echo $provider['id']; ?>" 
                                               class="form-control provider-amount money-input" 
                                               placeholder="0" 
                                               inputmode="numeric"
                                               oninput="formatMoneyInput(this); calculateTotals();">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-providers">
                            <i class="fas fa-info-circle"></i>
                            <p>No providers found for your branch. Please contact admin.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- NOTES -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <textarea id="notes" name="notes" class="form-control textarea-control" rows="2" placeholder="Additional notes (optional)..."></textarea>
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
                            <span class="summary-box-label">
                                <i class="fas fa-university"></i> Providers Selected: <span id="providersCount">0</span>
                            </span>
                            <span class="summary-box-value" id="totalCommissionDisplay">TSh 0</span>
                        </div>
                    </div>
                </div>
                
                <!-- ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Commission
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
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
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
.branch-status-name-blue {
    font-size: 16px; font-weight: 800;
    color: #FFFFFF; letter-spacing: 0.3px;
}
.branch-status-code-blue {
    font-size: 11px; font-weight: 700;
    color: #FCD34D;
    padding: 3px 10px;
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
.page-header-left h2 i { color: #10B981; margin-right: 6px; }
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
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 1.5px solid #6EE7B7;
    border-radius: 12px;
    margin-bottom: 16px;
    color: #065F46;
    flex-wrap: wrap;
}
.ine-icon {
    width: 42px;
    height: 42px;
    background: rgba(5, 150, 105, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #059669;
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
    color: #065F46;
    line-height: 1.5;
}
.ine-text strong {
    background: rgba(5, 150, 105, 0.12);
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #065F46;
}
.ine-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    background: #10B981;
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
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-color: #10B981;
    color: #D1FAE5;
}
html.dark-mode .ine-icon {
    background: rgba(52, 211, 153, 0.15);
    color: #34D399;
}
html.dark-mode .ine-text { color: #D1FAE5; }
html.dark-mode .ine-text strong {
    background: rgba(52, 211, 153, 0.15);
    color: #D1FAE5;
}
html.dark-mode .ine-badge { background: #34D399; color: #065F46; }

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
.section-header h3 i { color: #10B981; margin-right: 8px; }
.section-badge {
    font-size: 11px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
    font-weight: 600;
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
    color: #10B981; font-size: 14px;
    z-index: 1; pointer-events: none;
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
    border-color: #10B981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12);
    background: var(--bg-card);
}
.form-control:disabled { 
    opacity: 0.7; 
    cursor: not-allowed; 
    background: var(--bg-hover);
}

/* PROVIDERS GRID */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.provider-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.provider-item:hover {
    border-color: #10B981;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
    background: var(--bg-card);
}
.provider-item:focus-within {
    border-color: #10B981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12);
    background: var(--bg-card);
}

.provider-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}

.provider-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.provider-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.provider-code {
    font-size: 10px;
    font-weight: 700;
    color: #1D4ED8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .provider-code { color: #60A5FA; }

.provider-input {
    width: 110px;
    flex-shrink: 0;
}
.provider-input .form-control {
    padding: 8px 12px;
    border-radius: 8px;
    border: 2px solid var(--border-color);
    font-size: 14px;
    font-weight: 800;
    text-align: right;
    font-family: 'Inter', 'Courier New', monospace;
    background: var(--bg-card);
    color: #059669;
    padding-left: 12px;
}
html.dark-mode .provider-input .form-control { color: #34D399; }
.provider-input .form-control:focus {
    border-color: #10B981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
}

.empty-providers {
    text-align: center; padding: 30px 20px;
    color: var(--text-muted);
    background: var(--bg-input);
    border-radius: 10px;
    border: 1px dashed var(--border-color);
}
.empty-providers i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
.empty-providers p { font-size: 13px; margin: 0; }

/* SUMMARY BOX */
.summary-section {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    padding: 20px 26px;
}
html.dark-mode .summary-section {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%);
}
.summary-box {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
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
    color: rgba(255, 255, 255, 0.9);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.summary-box-label i { font-size: 11px; color: #FCD34D; }
.summary-box-label span#providersCount {
    background: #FCD34D;
    color: #78350F;
    padding: 1px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 900;
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
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
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
@media (max-width: 1200px) {
    .providers-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .branch-status-card-blue { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .branch-status-info-blue { width: 100%; }
    .btn-back-card-blue { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .form-row { grid-template-columns: 1fr; gap: 16px; }
    .form-row .full-width { grid-column: span 1; }
    .providers-grid { grid-template-columns: 1fr; gap: 8px; }
    .provider-item { padding: 12px 14px; }
    .provider-input { width: 100px; }
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
    .provider-icon { width: 34px; height: 34px; font-size: 14px; }
    .provider-name { font-size: 12px; }
    .provider-input { width: 90px; }
    .provider-input .form-control { font-size: 13px; padding: 6px 10px; }
    .summary-box-value { font-size: 22px; }
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
    if (value === '') { input.value = ''; calculateTotals(); return; }
    
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
    calculateTotals();
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
function calculateTotals() {
    var providerInputs = document.querySelectorAll('.provider-amount');
    var total = 0;
    var count = 0;
    
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        if (value > 0) {
            total += value;
            count++;
        }
    });
    
    var totalDisplay = document.getElementById('totalCommissionDisplay');
    if (totalDisplay) totalDisplay.textContent = 'TSh ' + total.toLocaleString('en-US');
    
    var countDisplay = document.getElementById('providersCount');
    if (countDisplay) countDisplay.textContent = count;
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var hasProvider = false;
    var providerInputs = document.querySelectorAll('.provider-amount');
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        if (value > 0) hasProvider = true;
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

// ============================================================
// CONFIRM RESET
// ============================================================
function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAll entered data will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    
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