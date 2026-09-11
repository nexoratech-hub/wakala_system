<?php
// ================================================================
// FILE: modules/branches/providers_edit.php
// WAKALA FINANCIAL SYSTEM - EDIT BRANCH PROVIDER
// FIXED: Uses 'branch_id', no variable collision with topbar
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

// ============================================================
// GET IDs - USES branch_id FIRST (MATCHES TOPBAR)
// ============================================================
$provider_link_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$branch_id = 0;

// Primary: branch_id
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $branch_id = intval($_GET['branch_id']);
}
// Fallback: branch (for backward compatibility)
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0' && intval($_GET['branch']) > 0) {
    $branch_id = intval($_GET['branch']);
}

if ($provider_link_id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider or branch selected.';
    header('Location: index.php');
    exit();
}

// ============================================================
// NO SESSION FORCING - URL IS SOURCE OF TRUTH
// ============================================================
unset($_SESSION['selected_branch']);
unset($_SESSION['providers_branch_id']);

// ============================================================
// GET BRANCH DETAILS (uses $edit_branch to avoid collision)
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$edit_branch = $stmt->fetch();

if (!$edit_branch) {
    $_SESSION['error_message'] = 'Branch not found or inactive.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH PROVIDER DETAILS
// ============================================================
$stmt = $db->prepare("SELECT bp.*, p.provider_name, p.provider_type, p.icon_class, p.color_code 
                      FROM branch_providers bp 
                      JOIN providers p ON bp.provider_id = p.id 
                      WHERE bp.id = ? AND bp.branch_id = ?");
$stmt->execute([$provider_link_id, $branch_id]);
$provider_link = $stmt->fetch();

if (!$provider_link) {
    $_SESSION['error_message'] = 'Provider not found in this branch.';
    header('Location: providers.php?branch_id=' . $branch_id);
    exit();
}

// ============================================================
// GET ALL ACTIVE PROVIDERS (for reference)
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY provider_name");
$stmt->execute();
$all_providers = $stmt->fetchAll();

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_provider') {
    try {
        $provider_code = strtoupper(trim($_POST['provider_code'] ?? ''));
        $is_active = intval($_POST['is_active'] ?? 1);
        
        if (empty($provider_code)) {
            throw new Exception('Please enter a provider code.');
        }
        
        // Check if same code exists for same provider (duplicate code check) - excluding current
        $check_stmt = $db->prepare("SELECT id FROM branch_providers WHERE branch_id = ? AND provider_code = ? AND id != ?");
        $check_stmt->execute([$branch_id, $provider_code, $provider_link_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Provider code "' . $provider_code . '" is already in use in this branch. Please use a different code.');
        }
        
        // Update branch provider
        $update_stmt = $db->prepare("UPDATE branch_providers SET provider_code = ?, is_active = ? WHERE id = ?");
        $update_stmt->execute([$provider_code, $is_active, $provider_link_id]);
        
        // Log activity
        logActivity($user_id, 'Edit Branch Provider', 'Branch Providers', $branch_id, '', 
                    "Updated provider code to $provider_code for {$edit_branch['branch_name']}");
        
        $_SESSION['success_message'] = 'Provider updated successfully!';
        header('Location: providers.php?branch_id=' . $branch_id);
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
        PERSISTENT RED BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-edit"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Editing Provider For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($edit_branch['branch_name']); ?></span>
                <span class="branch-status-code"><?php echo htmlspecialchars($edit_branch['branch_code']); ?></span>
                <?php if (!empty($edit_branch['location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($edit_branch['location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Providers</span>
            </a>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-edit"></i> Edit Provider</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($provider_link['provider_name']); ?></span>
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
        FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_provider">
                
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Provider Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Provider</label>
                            <div class="display-group">
                                <div class="display-icon" style="background: <?php echo $provider_link['color_code'] ?? '#0B5ED7'; ?>;">
                                    <i class="<?php echo $provider_link['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                </div>
                                <span class="display-value"><?php echo htmlspecialchars($provider_link['provider_name']); ?></span>
                                <span class="display-type"><?php echo ucfirst(str_replace('_', ' ', $provider_link['provider_type'] ?? 'Bank')); ?></span>
                            </div>
                            <small>Provider cannot be changed. Delete and re-add if needed.</small>
                        </div>
                        <div class="form-group">
                            <label for="provider_code">Provider Code <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tag"></i></span>
                                <input type="text" id="provider_code" name="provider_code" 
                                       value="<?php echo htmlspecialchars($provider_link['provider_code']); ?>" 
                                       class="form-control" placeholder="e.g., MPESA001" required>
                            </div>
                            <small>
                                <strong>Note:</strong> Code must be unique within this branch.
                                <br>Current code: <strong><?php echo htmlspecialchars($provider_link['provider_code']); ?></strong>
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="is_active">Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-power-off"></i></span>
                                <select id="is_active" name="is_active" class="form-control">
                                    <option value="1" <?php echo ($provider_link['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo ($provider_link['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <small>Inactive providers will not be shown in reports</small>
                        </div>
                        <div class="form-group">
                            <label>Branch Info</label>
                            <div class="info-display">
                                <div class="info-item">
                                    <span class="info-label">Branch:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($edit_branch['branch_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Provider ID:</span>
                                    <span class="info-value">#<?php echo $provider_link['id']; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Provider
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

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --bp-form-bg: #f3f4f6;
    --bp-form-card-bg: #FFFFFF;
    --bp-form-text: #1F2937;
    --bp-form-text-secondary: #6B7280;
    --bp-form-text-light: #9CA3AF;
    --bp-form-border: #E5E7EB;
    --bp-form-input-bg: #F9FAFB;
    --bp-form-hover: #F3F4F6;
    --bp-form-shadow: rgba(0,0,0,0.06);
    --bp-form-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --bp-form-bg: #0f172a;
    --bp-form-card-bg: #1E293B;
    --bp-form-text: #F1F5F9;
    --bp-form-text-secondary: #94A3B8;
    --bp-form-text-light: #64748B;
    --bp-form-border: #334155;
    --bp-form-input-bg: #374151;
    --bp-form-hover: #2D3A4F;
    --bp-form-shadow: rgba(0,0,0,0.3);
    --bp-form-shadow-lg: rgba(0,0,0,0.5);
}

body {
    background: var(--bp-form-bg) !important;
    color: var(--bp-form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--bp-form-bg) !important; }
.main-content { background: var(--bp-form-bg) !important; }

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
    color: var(--bp-form-text);
    margin: 0;
}

.page-header-left h2 i { color: #DC2626; margin-right: 8px; }

.page-subtitle {
    font-size: 13px;
    color: var(--bp-form-text-secondary);
    background: var(--bp-form-hover);
    padding: 3px 12px;
    border-radius: 12px;
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

html.dark-mode .alert-danger {
    background: #7F1D1D;
    color: #FEE2E2;
    border: 1px solid #991B1B;
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
    background: var(--bp-form-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--bp-form-shadow);
    border: 1px solid var(--bp-form-border);
    overflow: hidden;
    transition: all 0.3s ease;
    animation: fadeInUp 0.4s ease forwards;
}

.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--bp-form-border);
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
    color: var(--bp-form-text);
    margin: 0;
}

.section-header h3 i { color: #DC2626; margin-right: 8px; }

.section-badge {
    font-size: 11px;
    color: var(--bp-form-text-secondary);
    background: var(--bp-form-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

/* ============================================================
   FORM ROWS & GROUPS
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
    color: var(--bp-form-text);
}

.form-group label .required { color: #DC2626; font-weight: 700; }

.input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 12px;
    color: var(--bp-form-text-light);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}

.input-group .form-control {
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--bp-form-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--bp-form-input-bg);
    color: var(--bp-form-text);
    width: 100%;
}

.input-group .form-control::placeholder { color: var(--bp-form-text-light); }

.input-group .form-control:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
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
    background: var(--bp-form-card-bg);
    color: var(--bp-form-text);
}

.form-group small {
    font-size: 12px;
    color: var(--bp-form-text-secondary);
    margin-top: 2px;
    line-height: 1.5;
}

.form-group small strong { color: #DC2626; }

/* ============================================================
   DISPLAY GROUP (Read-only provider info)
   ============================================================ */
.display-group {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: var(--bp-form-hover);
    border-radius: 8px;
    border: 1px solid var(--bp-form-border);
}

.display-icon {
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

.display-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--bp-form-text);
}

.display-type {
    font-size: 11px;
    color: var(--bp-form-text-secondary);
    background: var(--bp-form-card-bg);
    padding: 1px 10px;
    border-radius: 12px;
}

/* ============================================================
   INFO DISPLAY
   ============================================================ */
.info-display {
    background: var(--bp-form-hover);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    height: 100%;
    justify-content: center;
    border: 1px solid var(--bp-form-border);
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
}

.info-label {
    font-size: 12px;
    color: var(--bp-form-text-secondary);
}

.info-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--bp-form-text);
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--bp-form-border);
    background: var(--bp-form-hover);
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
    background: #DC2626;
    color: white;
}

.btn-submit:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.35);
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-cancel {
    background: var(--bp-form-card-bg);
    color: var(--bp-form-text-secondary);
    border: 1px solid var(--bp-form-border);
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
    
    .form-section { padding: 16px 14px; }
    
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
        gap: 6px;
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
}
</style>

<script>
// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var providerCode = document.getElementById('provider_code');
    
    if (!providerCode || providerCode.value.trim() === '') {
        alert('Please enter a provider code.');
        if (providerCode) providerCode.focus();
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// DARK MODE SYNC
// ============================================================
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
    
    // Auto-hide error alert
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.display = 'none';
        }, 8000);
    }
});
</script>
</body>
</html>