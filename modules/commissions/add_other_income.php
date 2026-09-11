<?php
// ================================================================
// FILE: modules/commissions/add_other_income.php
// WAKALA FINANCIAL SYSTEM - ADD OTHER INCOME
// FULL VERSION with optional Income Source (dropdown OR manual)
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
$all_branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER - USES branch_id (MATCHES TOPBAR)
// ============================================================
$selected_branch = 0;

if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

unset($_SESSION['selected_branch']);

// Get branch name for display
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
// COMMON INCOME SOURCES (for dropdown suggestions)
// ============================================================
$common_sources = [
    'Service Fee',
    'Bank Interest',
    'Cashback',
    'Refund',
    'Commission Bonus',
    'Airtime Bonus',
    'Agent Bonus',
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
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $income_source = trim($_POST['income_source'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allocate_to_capital = $_POST['allocate_to_capital'] ?? 'yes';
        $notes = trim($_POST['notes'] ?? '');
        
        // Validate
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount greater than zero.');
        }
        
        // Income source is OPTIONAL - default to 'Other Income' if empty
        if (empty($income_source)) {
            $income_source = 'Other Income';
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // Generate reference number
        $commission_number = 'OI-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Build notes combining source + description
        $combined_notes = $income_source;
        if (!empty($description)) {
            $combined_notes .= ' - ' . $description;
        }
        if (!empty($notes)) {
            $combined_notes .= "\n\n" . $notes;
        }
        
        // Insert into commissions table with only other_income
        $provider_json = json_encode([]);
        $allocated_amount = ($allocate_to_capital == 'yes') ? $amount : 0;
        
        $insert_stmt = $db->prepare("INSERT INTO commissions 
            (commission_number, employee_id, branch, branch_id, commission_date, provider_data, 
             total_commission, other_income, total_business_income, allocate_to_capital, allocated_amount, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->execute([
            $commission_number,
            $user_id,
            $branch_name,
            $branch_id,
            $income_date,
            $provider_json,
            0,
            $amount,
            $amount,
            $allocate_to_capital,
            $allocated_amount,
            $combined_notes
        ]);
        
        $income_id = $db->lastInsertId();
        
        // Log activity
        logActivity($user_id, 'Add Other Income', 'Commissions', $income_id, '', 'Added other income: ' . $commission_number . ' - ' . formatCurrency($amount));
        
        $_SESSION['success_message'] = 'Other income of ' . formatCurrency($amount) . ' added successfully! Reference: ' . $commission_number;
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
        
        <!-- ============================================================
        PERSISTENT RED BRANCH STATUS CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Adding Other Income For' : 'Adding Other Income (All Branches)'; ?>
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

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-coins"></i> Add Other Income</h2>
                <span class="page-subtitle">Record additional income (not commissions)</span>
            </div>
        </div>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
        ============================================================ -->
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        INFO NOTE
        ============================================================ -->
        <div class="info-note">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Other Income</strong>
                <p>Use this form to record income that is NOT from provider commissions. Examples: service fees, bank interest, cashback, refunds, or any other business income.</p>
            </div>
        </div>

        <!-- ============================================================
        FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="incomeForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_other_income">
                
                <!-- ===== INCOME INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Income Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
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
                            <small>Date when the income was received</small>
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
                            <small>Auto-selected from topbar filter</small>
                        </div>
                    </div>
                </div>

                <!-- ===== INCOME DETAILS ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Income Details</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="amount" name="amount" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this)" 
                                       required>
                            </div>
                            <small>Enter amount in TSh</small>
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
                                       placeholder="Select or type your own..." 
                                       autocomplete="off">
                            </div>
                            <datalist id="incomeSourceList">
                                <?php foreach ($common_sources as $src): ?>
                                    <option value="<?php echo htmlspecialchars($src); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small>Chagua kutoka orodha au andika mwenyewe (si lazima)</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="description">Description <span class="optional-badge">Optional</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <input type="text" id="description" name="description" 
                                       class="form-control" 
                                       placeholder="Brief description of the income">
                            </div>
                            <small>Provide more details about this income (optional)</small>
                        </div>
                    </div>
                </div>

                <!-- ===== ADDITIONAL INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-cog"></i> Additional Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="allocate_to_capital">Allocate to Capital</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-building"></i></span>
                                <select id="allocate_to_capital" name="allocate_to_capital" class="form-control">
                                    <option value="yes">Yes - Add to Capital</option>
                                    <option value="no">No - Keep as Profit</option>
                                </select>
                            </div>
                            <small>Should this income be added to branch capital?</small>
                        </div>
                        <div class="form-group">
                            <label>Branch Info</label>
                            <div class="info-display">
                                <div class="info-item">
                                    <span class="info-label">Branch:</span>
                                    <span class="info-value" id="branchInfoName"><?php echo htmlspecialchars($add_branch_name); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date:</span>
                                    <span class="info-value"><?php echo date('d M Y'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="notes">Additional Notes <span class="optional-badge">Optional</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Any additional notes about this income..."></textarea>
                            </div>
                            <small>Extra notes for record keeping (optional)</small>
                        </div>
                    </div>
                </div>

                <!-- ===== SUMMARY PREVIEW ===== -->
                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Summary</h3>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-item total">
                            <span class="summary-label">Total Income:</span>
                            <span class="summary-value" id="totalIncomeDisplay">TSh 0</span>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Other Income
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset Form
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
    --form-info-bg: #EDE9FE;
    --form-info-text: #5B21B6;
    --form-info-border: #C4B5FD;
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
    --form-info-bg: #4C1D95;
    --form-info-text: #DDD6FE;
    --form-info-border: #6D28D9;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--form-bg) !important; }
.main-content { background: var(--form-bg) !important; }

/* ============================================================
   PERSISTENT RED BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 18px 24px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.3s ease forwards;
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

.branch-status-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
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
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
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
    color: #7C3AED;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

/* ============================================================
   INFO NOTE
   ============================================================ */
.info-note {
    background: var(--form-info-bg);
    border: 1px solid var(--form-info-border);
    border-left: 4px solid #7C3AED;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    color: var(--form-info-text);
    animation: slideDown 0.3s ease forwards;
}

.info-note > i {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}

.info-note strong {
    font-weight: 700;
    font-size: 14px;
    display: block;
    margin-bottom: 4px;
}

.info-note p {
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
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
    font-size: 16px;
    font-weight: 600;
    color: var(--form-text);
    margin: 0;
}

.section-header h3 i {
    color: #7C3AED;
    margin-right: 8px;
}

.section-badge {
    font-size: 11px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

/* ============================================================
   OPTIONAL BADGE
   ============================================================ */
.optional-badge {
    font-size: 9px;
    font-weight: 700;
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
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
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
   DATALIST INPUT (Income Source)
   ============================================================ */
input[list] {
    cursor: text;
}

input[list]::-webkit-calendar-picker-indicator {
    display: none;
}

/* ============================================================
   INFO DISPLAY (Right side)
   ============================================================ */
.info-display {
    background: var(--form-hover);
    border-radius: 8px;
    padding: 10px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    border: 1px solid var(--form-border);
    height: 100%;
    justify-content: center;
    min-height: 42px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
}

.info-label {
    font-size: 12px;
    color: var(--form-text-secondary);
}

.info-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--form-text);
}

/* ============================================================
   SUMMARY SECTION
   ============================================================ */
.summary-section {
    background: var(--form-hover);
}

.summary-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
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
}

.summary-item.total {
    background: var(--form-info-bg);
    border-color: var(--form-info-border);
}

.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--form-text-secondary);
    font-weight: 600;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: #7C3AED;
}

html.dark-mode .summary-item.total .summary-value {
    color: #A78BFA;
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
}

.btn-submit {
    background: #7C3AED;
    color: white;
}

.btn-submit:hover {
    background: #6D28D9;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
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
@media (max-width: 768px) {
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
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
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 480px) {
    .branch-status-name {
        font-size: 16px;
    }
    
    .branch-status-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .page-header-left h2 {
        font-size: 17px;
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
        font-size: 17px;
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
    
    // Update summary
    updateSummary();
}

// ============================================================
// UPDATE SUMMARY DISPLAY
// ============================================================
function updateSummary() {
    var amountInput = document.getElementById('amount');
    var amount = 0;
    
    if (amountInput) {
        var raw = amountInput.value.replace(/,/g, '');
        amount = parseFloat(raw) || 0;
    }
    
    var display = document.getElementById('totalIncomeDisplay');
    if (display) {
        display.textContent = 'TSh ' + amount.toLocaleString('en-US', { 
            minimumFractionDigits: 0, 
            maximumFractionDigits: 0 
        });
    }
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
    
    var amountInput = document.getElementById('amount');
    var rawAmount = amountInput.value.replace(/,/g, '');
    var amount = parseFloat(rawAmount) || 0;
    
    if (amount <= 0) {
        alert('Please enter a valid amount greater than zero.');
        amountInput.focus();
        return false;
    }
    
    // Income source is OPTIONAL - no validation required
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
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
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Update summary on load
    updateSummary();
    
    // Update branch info display when branch changes
    var branchSelect = document.getElementById('branch_id');
    if (branchSelect) {
        branchSelect.addEventListener('change', function() {
            var selectedOption = this.options[this.selectedIndex];
            var branchText = selectedOption.textContent.trim();
            var branchInfo = document.getElementById('branchInfoName');
            if (branchInfo) {
                branchInfo.textContent = branchText;
            }
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