<?php
// ================================================================
// FILE: modules/commissions/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT COMMISSION
// FIXED: No variable collision + branch context card
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

// ============================================================
// CHECK PERMISSION
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET COMMISSION ID
// ============================================================
$commission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($commission_id <= 0) {
    $_SESSION['error_message'] = 'Invalid commission selected.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET COMMISSION DATA (uses $edit_commission)
// ============================================================
$stmt = $db->prepare("SELECT * FROM commissions WHERE id = ?");
$stmt->execute([$commission_id]);
$edit_commission = $stmt->fetch();

if (!$edit_commission) {
    $_SESSION['error_message'] = 'Commission not found.';
    header('Location: index.php');
    exit();
}

// Check permission - employee can only edit their own commissions
if ($role == 'employee' && $edit_commission['employee_id'] != $user_id) {
    $_SESSION['error_message'] = 'You do not have permission to edit this commission.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$edit_branches = $stmt->fetchAll();

// ============================================================
// GET PROVIDERS
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order, provider_name");
$stmt->execute();
$edit_providers = $stmt->fetchAll();

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($edit_commission['provider_data'] ?? '{}', true);
$total_commission = floatval($edit_commission['total_commission'] ?? 0);
$other_income = floatval($edit_commission['other_income'] ?? 0);
$total_business_income = floatval($edit_commission['total_business_income'] ?? 0);

// Determine commission type
$is_other_income_only = (empty($provider_data) && $other_income > 0);

// ============================================================
// DETERMINE BRANCH NAME FOR RED CARD
// ============================================================
$edit_branch_name = $edit_commission['branch'] ?? 'Main';
$edit_branch_code = '';
$edit_branch_location = '';

if ($edit_commission['branch_id'] > 0) {
    foreach ($edit_branches as $br) {
        if ($br['id'] == $edit_commission['branch_id']) {
            $edit_branch_name = $br['branch_name'];
            $edit_branch_code = $br['branch_code'];
            $edit_branch_location = $br['location'] ?? '';
            break;
        }
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_commission') {
    try {
        $commission_date = $_POST['commission_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $other_income_new = floatval(str_replace(',', '', $_POST['other_income'] ?? 0));
        $allocate_to_capital = $_POST['allocate_to_capital'] ?? 'yes';
        $notes = $_POST['notes'] ?? '';
        
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($edit_branches as $br) {
            if ($br['id'] == $branch_id) {
                $branch_name = $br['branch_name'];
                break;
            }
        }
        
        // Build provider data from POST
        $provider_data_new = [];
        $total_commission_new = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'provider_') === 0 && !empty($value)) {
                $provider_id = str_replace('provider_', '', $key);
                $amount = floatval(str_replace(',', '', $value));
                if ($amount > 0) {
                    $provider_data_new[$provider_id] = $amount;
                    $total_commission_new += $amount;
                }
            }
        }
        
        // Allow submission even without provider amounts IF other income > 0
        if (empty($provider_data_new) && $other_income_new <= 0) {
            throw new Exception('Please enter at least one provider amount or other income.');
        }
        
        $total_business_income_new = $total_commission_new + $other_income_new;
        $allocated_amount = ($allocate_to_capital == 'yes') ? $total_business_income_new : 0;
        
        $provider_json = json_encode($provider_data_new);
        
        $update_stmt = $db->prepare("UPDATE commissions 
            SET branch_id = ?, branch = ?, commission_date = ?, provider_data = ?, 
                total_commission = ?, other_income = ?, total_business_income = ?, 
                allocate_to_capital = ?, allocated_amount = ?, notes = ? 
            WHERE id = ?");
        
        $update_stmt->execute([
            $branch_id,
            $branch_name,
            $commission_date,
            $provider_json,
            $total_commission_new,
            $other_income_new,
            $total_business_income_new,
            $allocate_to_capital,
            $allocated_amount,
            $notes,
            $commission_id
        ]);
        
        logActivity($user_id, 'Edit Commission', 'Commissions', $commission_id, '', 'Updated commission: ' . $edit_commission['commission_number']);
        
        $_SESSION['success_message'] = 'Commission updated successfully!';
        header('Location: view.php?id=' . $commission_id);
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
        
        // Refresh data
        $stmt = $db->prepare("SELECT * FROM commissions WHERE id = ?");
        $stmt->execute([$commission_id]);
        $edit_commission = $stmt->fetch();
        $provider_data = json_decode($edit_commission['provider_data'] ?? '{}', true);
        $total_commission = floatval($edit_commission['total_commission'] ?? 0);
        $other_income = floatval($edit_commission['other_income'] ?? 0);
        $total_business_income = floatval($edit_commission['total_business_income'] ?? 0);
        $is_other_income_only = (empty($provider_data) && $other_income > 0);
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PERSISTENT RED BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-edit"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    Editing <?php echo $is_other_income_only ? 'Other Income' : 'Commission'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($edit_branch_name); ?></span>
                <?php if (!empty($edit_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($edit_branch_code); ?></span>
                <?php endif; ?>
                <?php if (!empty($edit_branch_location)): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($edit_branch_location); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="view.php?id=<?php echo $commission_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to View</span>
            </a>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2>
                    <i class="fas <?php echo $is_other_income_only ? 'fa-coins' : 'fa-edit'; ?>"></i>
                    Edit <?php echo $is_other_income_only ? 'Other Income' : 'Commission'; ?>
                </h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($edit_commission['commission_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="view.php?id=<?php echo $commission_id; ?>" class="btn btn-view">
                    <i class="fas fa-eye"></i> View
                </a>
            </div>
        </div>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
        ============================================================ -->
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

        <!-- ============================================================
        FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="commissionForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_commission">
                
                <!-- ===== BASIC INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="commission_date">Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="commission_date" name="commission_date" 
                                       value="<?php echo $edit_commission['commission_date']; ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($edit_branches as $br): ?>
                                        <option value="<?php echo $br['id']; ?>" <?php echo ($edit_commission['branch_id'] == $br['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($br['branch_name']); ?>
                                            <?php if ($br['branch_code']): ?>
                                                (<?php echo htmlspecialchars($br['branch_code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small>Branch where this commission was earned</small>
                        </div>
                    </div>
                </div>

                <!-- ===== PROVIDER COMMISSIONS ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Commissions</h3>
                        <span class="section-sub">Enter commission amount for each provider</span>
                    </div>
                    
                    <div class="providers-grid">
                        <?php foreach ($edit_providers as $provider): 
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
                                           oninput="formatMoneyInput(this); calculateTotals();">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ===== OTHER INCOME ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-coins"></i> Other Income</h3>
                        <span class="section-sub">Additional income (optional)</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="other_income">Other Income</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-coins"></i></span>
                                <input type="text" id="other_income" name="other_income" 
                                       value="<?php echo number_format($other_income, 0, '.', ','); ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this); calculateTotals();">
                            </div>
                            <small>Any additional income besides commissions</small>
                        </div>
                        <div class="form-group">
                            <label for="allocate_to_capital">Allocate to Capital</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-building"></i></span>
                                <select id="allocate_to_capital" name="allocate_to_capital" class="form-control">
                                    <option value="yes" <?php echo ($edit_commission['allocate_to_capital'] == 'yes') ? 'selected' : ''; ?>>Yes - Add to Capital</option>
                                    <option value="no" <?php echo ($edit_commission['allocate_to_capital'] == 'no') ? 'selected' : ''; ?>>No - Keep as Profit</option>
                                </select>
                            </div>
                            <small>Should this income be added to capital?</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="notes">Notes</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Any additional notes..."><?php echo htmlspecialchars($edit_commission['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== SUMMARY ===== -->
                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Summary</h3>
                        <span class="section-badge">Auto-calculated</span>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">Total Commission:</span>
                            <span class="summary-value" id="totalCommissionDisplay">TSh <?php echo number_format($total_commission, 0, '.', ','); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Other Income:</span>
                            <span class="summary-value" id="otherIncomeDisplay">TSh <?php echo number_format($other_income, 0, '.', ','); ?></span>
                        </div>
                        <div class="summary-item total">
                            <span class="summary-label">Total Business Income:</span>
                            <span class="summary-value" id="totalIncomeDisplay">TSh <?php echo number_format($total_business_income, 0, '.', ','); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="view.php?id=<?php echo $commission_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --form-bg: #f3f4f6;
    --form-card-bg: #FFFFFF;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
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
    --provider-bg: #ECFDF5;
    --provider-bg-hover: #D1FAE5;
    --provider-border: #A7F3D0;
    --provider-text: #065F46;
}

html.dark-mode {
    --form-bg: #0f172a;
    --form-card-bg: #1E293B;
    --form-text: #F1F5F9;
    --form-text-secondary: #94A3B8;
    --form-text-light: #64748B;
    --form-border: #334155;
    --form-input-bg: #374151;
    --form-hover: #2D3A4F;
    --form-shadow: rgba(0,0,0,0.3);
    --form-shadow-lg: rgba(0,0,0,0.5);
    --form-dropdown-bg: #1E293B;
    --form-dropdown-border: #334155;
    --form-success-bg: #065F46;
    --form-success-text: #D1FAE5;
    --form-success-border: #047857;
    --form-danger-bg: #7F1D1D;
    --form-danger-text: #FEE2E2;
    --form-danger-border: #991B1B;
    --provider-bg: #1B3A2B;
    --provider-bg-hover: #1F4B2F;
    --provider-border: #2E7D32;
    --provider-text: #A5D6A7;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--form-bg) !important; }

.main-content {
    background: var(--form-bg) !important;
    padding: 16px 20px !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    box-sizing: border-box;
}

/* ============================================================
   PERSISTENT RED BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}

.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}

.branch-status-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-status-name {
    font-size: 18px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-status-code {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-status-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.btn-back-card {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
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
    flex-wrap: wrap;
    gap: 12px;
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
    color: #10B981;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

.page-header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-view {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 9px 20px;
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
    transform: translateY(-1px);
    color: #1E40AF;
}

html.dark-mode .btn-view {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .btn-view:hover {
    background: #3B82F6;
    color: #FFFFFF;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    font-size: 13px;
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

.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }

.alert-close {
    background: transparent;
    border: none;
    font-size: 20px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}

.alert-close:hover { opacity: 1; }

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
    animation: fadeInUp 0.4s ease forwards;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
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
    flex-wrap: wrap;
    gap: 8px;
}

.section-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--form-text);
    margin: 0;
}

.section-header h3 i {
    color: #10B981;
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

/* ============================================================
   FORM ROWS
   ============================================================ */
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
    border-color: #10B981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
}

.input-group select.form-control {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
    cursor: pointer;
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

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
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
    min-width: 0;
}

.provider-item:hover {
    background: var(--provider-bg-hover);
    border-color: #34D399;
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
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
    background: #1A3A2A;
    color: #D1FAE5;
}

.provider-input .form-control:focus {
    border-color: #10B981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
}

.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* ============================================================
   SUMMARY SECTION
   ============================================================ */
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
    padding: 14px;
    border-radius: 8px;
    background: var(--form-card-bg);
    border: 1px solid var(--form-border);
    text-align: center;
    min-width: 0;
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
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.summary-value {
    font-size: clamp(14px, 1.2vw, 18px);
    font-weight: 700;
    color: var(--form-text);
    word-break: break-all;
    overflow-wrap: anywhere;
    line-height: 1.2;
    max-width: 100%;
}

.summary-item.total .summary-value {
    color: #10B981;
}

html.dark-mode .summary-item.total .summary-value {
    color: #34D399;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--form-border);
    background: var(--form-hover);
    flex-wrap: wrap;
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
    white-space: nowrap;
}

.btn-submit {
    background: #10B981;
    color: white;
}

.btn-submit:hover {
    background: #059669;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-reset {
    background: var(--form-card-bg);
    color: var(--form-text-secondary);
    border: 1px solid var(--form-border);
}

.btn-reset:hover {
    background: var(--form-border);
    color: var(--form-text);
}

.btn-cancel {
    background: var(--form-card-bg);
    color: var(--form-text-secondary);
    border: 1px solid var(--form-border);
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

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-value {
        font-size: clamp(13px, 1.4vw, 16px);
    }
}

@media (max-width: 1024px) {
    .providers-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 12px !important;
    }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
    }
    
    .branch-status-info {
        width: 100%;
    }
    
    .btn-back-card {
        width: 100%;
        justify-content: center;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .page-header-right {
        width: 100%;
    }
    
    .page-header-right .btn {
        width: 100%;
        justify-content: center;
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
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 10px !important;
    }
    
    .branch-status-name {
        font-size: 15px;
    }
    
    .branch-status-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .page-header-left h2 {
        font-size: 17px;
    }
    
    .providers-grid {
        grid-template-columns: 1fr;
    }
    
    .input-group .form-control {
        padding: 8px 12px 8px 36px;
        font-size: 13px;
    }
    
    .input-icon {
        left: 10px;
        font-size: 13px;
    }
    
    .btn {
        padding: 8px 16px;
        font-size: 13px;
    }
    
    .summary-value {
        font-size: clamp(13px, 4vw, 16px);
    }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
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
    var totalCommission = 0;
    
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        totalCommission += value;
    });
    
    var otherIncomeInput = document.getElementById('other_income');
    var rawOther = otherIncomeInput ? otherIncomeInput.value.replace(/,/g, '') : '0';
    var otherIncome = parseFloat(rawOther) || 0;
    var totalIncome = totalCommission + otherIncome;
    
    var tcDisplay = document.getElementById('totalCommissionDisplay');
    var oiDisplay = document.getElementById('otherIncomeDisplay');
    var tiDisplay = document.getElementById('totalIncomeDisplay');
    
    if (tcDisplay) tcDisplay.textContent = 'TSh ' + formatNumber(totalCommission);
    if (oiDisplay) oiDisplay.textContent = 'TSh ' + formatNumber(otherIncome);
    if (tiDisplay) tiDisplay.textContent = 'TSh ' + formatNumber(totalIncome);
}

function formatNumber(num) {
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
    
    var hasAmount = false;
    var providerInputs = document.querySelectorAll('.provider-amount');
    providerInputs.forEach(function(input) {
        var rawValue = input.value.replace(/,/g, '');
        var value = parseFloat(rawValue) || 0;
        if (value > 0) hasAmount = true;
    });
    
    var otherIncomeInput = document.getElementById('other_income');
    var rawOther = otherIncomeInput ? otherIncomeInput.value.replace(/,/g, '') : '0';
    var otherIncome = parseFloat(rawOther) || 0;
    if (otherIncome > 0) hasAmount = true;
    
    if (!hasAmount) {
        alert('Please enter at least one provider amount or other income.');
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
    return confirm('Are you sure you want to reset the form? All changes will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    
    var otherIncome = document.getElementById('other_income');
    if (otherIncome) {
        otherIncome.addEventListener('input', function() {
            formatMoneyInput(this);
            calculateTotals();
        });
    }
    
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