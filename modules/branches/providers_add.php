<?php
// ================================================================
// FILE: modules/branches/providers_add.php
// WAKALA FINANCIAL SYSTEM - ADD BRANCH PROVIDER
// WITH CHECKBOX SELECTION - ALLOW MULTIPLE CODES PER PROVIDER
// ================================================================

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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get branch ID
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get branch details
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: index.php');
    exit();
}

// Get all active providers
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
$stmt->execute();
$all_providers = $stmt->fetchAll();

// Get existing codes for this branch (for validation)
$stmt = $db->prepare("SELECT provider_id, provider_code FROM branch_providers WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$existing_providers = $stmt->fetchAll();

// Build array of existing codes per provider
$existing_codes_by_provider = [];
foreach ($existing_providers as $ep) {
    $existing_codes_by_provider[$ep['provider_id']][] = $ep['provider_code'];
}

// Get all existing codes (for duplicate check)
$all_existing_codes = array_column($existing_providers, 'provider_code');

// Handle form submission
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_providers') {
    try {
        $selected_providers = isset($_POST['providers']) ? $_POST['providers'] : [];
        $provider_codes = isset($_POST['provider_codes']) ? $_POST['provider_codes'] : [];
        
        if (empty($selected_providers)) {
            throw new Exception('Please select at least one provider to assign.');
        }
        
        $added_count = 0;
        $error_count = 0;
        $errors = [];
        
        foreach ($selected_providers as $provider_id) {
            $provider_id = intval($provider_id);
            
            // Check if code is provided
            $code = isset($provider_codes[$provider_id]) ? strtoupper(trim($provider_codes[$provider_id])) : '';
            
            if (empty($code)) {
                $provider_name = '';
                foreach ($all_providers as $p) {
                    if ($p['id'] == $provider_id) {
                        $provider_name = $p['provider_name'];
                        break;
                    }
                }
                $errors[] = 'Provider code is required for ' . $provider_name;
                $error_count++;
                continue;
            }
            
            // Check if this code already exists in this branch (regardless of provider)
            if (in_array($code, $all_existing_codes)) {
                $provider_name = '';
                foreach ($all_providers as $p) {
                    if ($p['id'] == $provider_id) {
                        $provider_name = $p['provider_name'];
                        break;
                    }
                }
                $errors[] = 'Code "' . $code . '" is already in use in this branch. Please use a different code.';
                $error_count++;
                continue;
            }
            
            // Insert branch provider
            $insert_stmt = $db->prepare("INSERT INTO branch_providers (branch_id, provider_id, provider_code, is_active) VALUES (?, ?, ?, 1)");
            $insert_stmt->execute([$branch_id, $provider_id, $code]);
            
            // Add to existing codes array for subsequent checks
            $all_existing_codes[] = $code;
            $existing_codes_by_provider[$provider_id][] = $code;
            
            $added_count++;
        }
        
        if ($added_count > 0) {
            // Get provider names for log
            $provider_names = [];
            foreach ($all_providers as $p) {
                if (in_array($p['id'], $selected_providers)) {
                    $provider_names[] = $p['provider_name'];
                }
            }
            
            logActivity($user_id, 'Add Branch Providers', 'Branch Providers', $branch_id, '', "Added " . implode(', ', $provider_names) . " to {$branch['branch_name']}");
            
            $_SESSION['success_message'] = $added_count . ' provider(s) added successfully!';
            if ($error_count > 0) {
                $_SESSION['error_message'] = 'Some providers had errors: ' . implode('; ', $errors);
            }
            header('Location: providers.php?branch_id=' . $branch_id);
            exit();
        } else {
            throw new Exception('No providers were added. Errors: ' . implode('; ', $errors));
        }
        
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
        
        <!-- ===== BRANCH INDICATOR CARD ===== -->
        <div class="branch-indicator-card">
            <div class="branch-card-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-card-info">
                <span class="branch-card-label">Assign Providers To</span>
                <span class="branch-card-name"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                <span class="branch-card-code"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                <?php if (!empty($branch['location'])): ?>
                    <span class="branch-card-location">
                        <i class="fas fa-map-marker-alt"></i> 
                        <?php echo htmlspecialchars($branch['location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-card-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($existing_providers); ?></span>
                    <span class="stat-label">Assigned</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($all_providers); ?></span>
                    <span class="stat-label">Available</span>
                </div>
            </div>
            <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ===== INFO NOTE ===== -->
        <div class="info-note">
            <i class="fas fa-info-circle"></i>
            <span>
                <strong>Note:</strong> You can add the same provider multiple times with different codes. 
                Each code must be unique within this branch.
            </span>
        </div>

        <!-- ===== FORM ===== -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="providerForm">
                <input type="hidden" name="action" value="add_providers">
                
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-check-square"></i> Select Providers to Assign</h3>
                        <span class="section-badge"><?php echo count($all_providers); ?> providers available</span>
                    </div>
                    
                    <div class="form-actions-top">
                        <button type="button" class="btn btn-select-all" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="btn btn-deselect-all" onclick="deselectAll()">
                            <i class="fas fa-times"></i> Deselect All
                        </button>
                        <span class="selected-count" id="selectedCount">0 selected</span>
                    </div>

                    <div class="providers-grid">
                        <?php foreach ($all_providers as $provider): 
                            $provider_type = $provider['provider_type'] ?? 'bank';
                            $icon = $provider['icon_class'] ?? 'fas fa-university';
                            $color = $provider['color_code'] ?? '#0B5ED7';
                            
                            // Get existing codes for this provider
                            $existing_codes = isset($existing_codes_by_provider[$provider['id']]) 
                                ? $existing_codes_by_provider[$provider['id']] 
                                : [];
                            
                            $has_existing = !empty($existing_codes);
                        ?>
                            <div class="provider-item" data-provider-id="<?php echo $provider['id']; ?>">
                                
                                <div class="provider-checkbox-wrapper">
                                    <input type="checkbox" 
                                           id="provider_<?php echo $provider['id']; ?>" 
                                           name="providers[]" 
                                           value="<?php echo $provider['id']; ?>"
                                           class="provider-checkbox"
                                           onchange="updateSelectedCount(); toggleCodeInput(<?php echo $provider['id']; ?>)">
                                    <label for="provider_<?php echo $provider['id']; ?>" class="provider-checkbox-label">
                                        <span class="custom-checkbox">
                                            <i class="fas fa-check"></i>
                                        </span>
                                    </label>
                                </div>
                                
                                <div class="provider-icon" style="background: <?php echo $color; ?>;">
                                    <i class="<?php echo $icon; ?>"></i>
                                </div>
                                
                                <div class="provider-info">
                                    <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                    <span class="provider-type">
                                        <i class="fas <?php echo $provider_type == 'mobile_money' ? 'fa-mobile-alt' : 'fa-university'; ?>"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $provider_type)); ?>
                                    </span>
                                    <?php if ($has_existing): ?>
                                        <span class="existing-codes-badge">
                                            <i class="fas fa-tags"></i>
                                            Existing codes: <?php echo implode(', ', $existing_codes); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="provider-code-input" id="code_input_<?php echo $provider['id']; ?>" style="display: none;">
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-tag"></i></span>
                                        <input type="text" 
                                               id="code_<?php echo $provider['id']; ?>" 
                                               name="provider_codes[<?php echo $provider['id']; ?>]" 
                                               class="form-control" 
                                               placeholder="Enter unique code (e.g., MPESA002)"
                                               data-provider="<?php echo $provider['id']; ?>"
                                               autocomplete="off">
                                    </div>
                                    <small>Enter a unique code for this provider</small>
                                </div>
                                
                                <div class="provider-status available">
                                    <i class="fas fa-plus-circle"></i> Available
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Assign Selected Providers
                    </button>
                    <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-cancel">
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
   BRANCH INDICATOR CARD
   ============================================================ */
.branch-indicator-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 18px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}

.branch-indicator-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
}

.branch-indicator-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
}

.branch-card-icon {
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

.branch-card-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.branch-card-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-card-name {
    font-size: 18px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-card-code {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    padding: 2px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-card-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.branch-card-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    position: relative;
    z-index: 1;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.stat-number {
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
}

.stat-label {
    font-size: 9px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.5);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-divider {
    width: 1px;
    height: 30px;
    background: rgba(255, 255, 255, 0.15);
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
   INFO NOTE
   ============================================================ */
.info-note {
    background: #DBEAFE;
    border: 1px solid #93C5FD;
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1E40AF;
}

.info-note i {
    font-size: 18px;
    flex-shrink: 0;
}

.info-note span {
    font-size: 13px;
}

.info-note strong {
    font-weight: 700;
}

html.dark-mode .info-note {
    background: #1E3A5F;
    border-color: #3B82F6;
    color: #93C5FD;
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

.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
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
    background: var(--bp-form-bg, #FFFFFF);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid var(--bp-form-border, #E5E7EB);
    overflow: hidden;
}

.form-section {
    padding: 20px 24px;
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
    color: var(--bp-form-text, #1F2937);
    margin: 0;
}

.section-header h3 i { color: #3B82F6; margin-right: 8px; }

.section-badge {
    font-size: 12px;
    color: var(--bp-form-text-secondary, #6B7280);
    background: var(--bp-form-hover, #F3F4F6);
    padding: 3px 14px;
    border-radius: 12px;
}

/* ============================================================
   FORM ACTIONS TOP
   ============================================================ */
.form-actions-top {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 16px;
    padding: 12px 16px;
    background: var(--bp-form-hover, #F9FAFB);
    border-radius: 8px;
    border: 1px solid var(--bp-form-border, #E5E7EB);
}

.btn-select-all {
    background: #3B82F6;
    color: white;
    padding: 6px 16px;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-select-all:hover {
    background: #2563EB;
    transform: translateY(-1px);
}

.btn-deselect-all {
    background: #6B7280;
    color: white;
    padding: 6px 16px;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-deselect-all:hover {
    background: #4B5563;
    transform: translateY(-1px);
}

.selected-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--bp-form-text, #1F2937);
    margin-left: auto;
}

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 12px;
}

.provider-item {
    background: var(--bp-form-bg, #FFFFFF);
    border: 2px solid var(--bp-form-border, #E5E7EB);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s ease;
    position: relative;
    flex-wrap: wrap;
}

.provider-item:hover {
    border-color: #3B82F6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
}

.provider-item.has-existing {
    border-color: #F59E0B;
}

.provider-item.has-existing:hover {
    border-color: #D97706;
}

/* Checkbox */
.provider-checkbox-wrapper {
    position: relative;
    flex-shrink: 0;
}

.provider-checkbox {
    display: none;
}

.custom-checkbox {
    width: 24px;
    height: 24px;
    border: 2px solid #D1D5DB;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #FFFFFF;
}

.provider-checkbox:checked + .provider-checkbox-label .custom-checkbox {
    background: #3B82F6;
    border-color: #3B82F6;
}

.provider-checkbox:checked + .provider-checkbox-label .custom-checkbox i {
    color: #FFFFFF;
    font-size: 14px;
}

.custom-checkbox i {
    color: transparent;
    font-size: 14px;
}

/* Provider Icon */
.provider-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    flex-shrink: 0;
}

/* Provider Info */
.provider-info {
    flex: 1;
    min-width: 0;
}

.provider-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--bp-form-text, #1F2937);
    display: block;
}

.provider-type {
    font-size: 11px;
    font-weight: 500;
    color: var(--bp-form-text-secondary, #6B7280);
    display: flex;
    align-items: center;
    gap: 4px;
}

.existing-codes-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 500;
    color: #D97706;
    background: #FEF3C7;
    padding: 1px 10px;
    border-radius: 10px;
    margin-top: 2px;
}

/* Provider Code Input */
.provider-code-input {
    margin-top: 4px;
    width: 100%;
}

.provider-code-input .input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.provider-code-input .input-icon {
    position: absolute;
    left: 10px;
    color: #9CA3AF;
    font-size: 12px;
    z-index: 1;
}

.provider-code-input .form-control {
    padding: 6px 10px 6px 32px;
    border-radius: 6px;
    border: 1px solid #D1D5DB;
    font-size: 12px;
    outline: none;
    transition: all 0.2s ease;
    background: #FFFFFF;
    color: #1F2937;
    width: 100%;
}

.provider-code-input .form-control:focus {
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.provider-code-input .form-control.error {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

.provider-code-input small {
    font-size: 10px;
    color: #9CA3AF;
    display: block;
    margin-top: 2px;
}

/* Provider Status */
.provider-status {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 12px;
    border-radius: 12px;
    flex-shrink: 0;
    margin-left: auto;
}

.provider-status.available {
    background: #D1FAE5;
    color: #065F46;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--bp-form-border, #E5E7EB);
    background: var(--bp-form-hover, #F9FAFB);
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
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-cancel {
    background: var(--bp-form-hover, #F3F4F6);
    color: var(--bp-form-text-secondary, #6B7280);
}

.btn-cancel:hover {
    background: #FEE2E2;
    color: #991B1B;
}

/* ============================================================
   DARK MODE SUPPORT
   ============================================================ */
:root {
    --bp-form-bg: #FFFFFF;
    --bp-form-text: #1F2937;
    --bp-form-text-secondary: #6B7280;
    --bp-form-border: #E5E7EB;
    --bp-form-hover: #F3F4F6;
}

html.dark-mode {
    --bp-form-bg: #1F2937;
    --bp-form-text: #F9FAFB;
    --bp-form-text-secondary: #9CA3AF;
    --bp-form-border: #374151;
    --bp-form-hover: #374151;
}

html.dark-mode .provider-item {
    background: #1F2937;
    border-color: #374151;
}

html.dark-mode .custom-checkbox {
    background: #374151;
    border-color: #4B5563;
}

html.dark-mode .provider-code-input .form-control {
    background: #374151;
    border-color: #4B5563;
    color: #F9FAFB;
}

html.dark-mode .alert-danger {
    background: #7F1D1D;
    color: #FEE2E2;
    border-color: #991B1B;
}

html.dark-mode .existing-codes-badge {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .provider-status.available {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .info-note {
    background: #1E3A5F;
    border-color: #3B82F6;
    color: #93C5FD;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .branch-indicator-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
    }
    
    .branch-card-stats {
        margin-left: 0;
        width: 100%;
        justify-content: center;
    }
    
    .btn-back-card {
        width: 100%;
        justify-content: center;
    }
    
    .providers-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions-top {
        flex-wrap: wrap;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
}

@media (max-width: 480px) {
    .branch-card-name {
        font-size: 15px;
    }
    
    .branch-card-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .provider-item {
        flex-wrap: wrap;
        padding: 12px 14px;
    }
    
    .provider-status {
        width: 100%;
        text-align: center;
        margin-left: 0;
    }
    
    .provider-code-input {
        width: 100%;
    }
}
</style>

<script>
// ============================================================
// UPDATE SELECTED COUNT
// ============================================================
function updateSelectedCount() {
    var checkboxes = document.querySelectorAll('.provider-checkbox:checked');
    var count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count + ' selected';
    
    // Show/hide code inputs for selected providers
    checkboxes.forEach(function(cb) {
        var providerId = cb.value;
        toggleCodeInput(providerId);
    });
    
    // Hide code inputs for unchecked providers
    var allCheckboxes = document.querySelectorAll('.provider-checkbox:not(:checked)');
    allCheckboxes.forEach(function(cb) {
        var providerId = cb.value;
        var codeInput = document.getElementById('code_input_' + providerId);
        if (codeInput) {
            codeInput.style.display = 'none';
        }
    });
}

// ============================================================
// TOGGLE CODE INPUT
// ============================================================
function toggleCodeInput(providerId) {
    var checkbox = document.getElementById('provider_' + providerId);
    var codeInput = document.getElementById('code_input_' + providerId);
    var codeField = document.getElementById('code_' + providerId);
    
    if (checkbox && checkbox.checked) {
        codeInput.style.display = 'block';
        if (codeField) {
            codeField.required = true;
            setTimeout(function() {
                codeField.focus();
            }, 100);
        }
    } else {
        codeInput.style.display = 'none';
        if (codeField) {
            codeField.required = false;
            codeField.value = '';
            codeField.classList.remove('error');
        }
    }
}

// ============================================================
// SELECT ALL
// ============================================================
function selectAll() {
    var checkboxes = document.querySelectorAll('.provider-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = true;
        var providerId = cb.value;
        toggleCodeInput(providerId);
    });
    updateSelectedCount();
}

// ============================================================
// DESELECT ALL
// ============================================================
function deselectAll() {
    var checkboxes = document.querySelectorAll('.provider-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = false;
        var providerId = cb.value;
        toggleCodeInput(providerId);
    });
    updateSelectedCount();
}

// ============================================================
// FORM VALIDATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('providerForm');
    var submitBtn = document.getElementById('submitBtn');
    
    form.addEventListener('submit', function(e) {
        var selected = document.querySelectorAll('.provider-checkbox:checked');
        var hasErrors = false;
        var errorMessages = [];
        
        selected.forEach(function(cb) {
            var providerId = cb.value;
            var codeInput = document.getElementById('code_' + providerId);
            var providerItem = cb.closest('.provider-item');
            var providerName = providerItem.querySelector('.provider-name').textContent;
            
            if (codeInput && codeInput.value.trim() === '') {
                hasErrors = true;
                errorMessages.push('Please enter a code for ' + providerName);
                codeInput.classList.add('error');
            } else if (codeInput) {
                codeInput.classList.remove('error');
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Please fix the following errors:\n\n' + errorMessages.join('\n'));
            return false;
        }
        
        if (selected.length === 0) {
            e.preventDefault();
            alert('Please select at least one provider to assign.');
            return false;
        }
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';
        submitBtn.disabled = true;
    });
    
    // Initialize selected count
    updateSelectedCount();
    
    // Dark mode sync
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
});
</script>
</body>
</html>