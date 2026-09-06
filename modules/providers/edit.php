<?php
// ================================================================
// FILE: modules/providers/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT PROVIDER
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
// CHECK PERMISSION - Only admin and super_admin can access
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET PROVIDER ID
// ============================================================
$provider_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($provider_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE id = ?");
$stmt->execute([$provider_id]);
$provider = $stmt->fetch();

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// PROCESS FORM SUBMISSION
// ============================================================
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $provider_code = trim($_POST['provider_code'] ?? '');
    $provider_name = trim($_POST['provider_name'] ?? '');
    $provider_type = $_POST['provider_type'] ?? 'bank';
    $category = trim($_POST['category'] ?? 'Financial');
    $icon_class = trim($_POST['icon_class'] ?? 'fas fa-university');
    $color_code = trim($_POST['color_code'] ?? '#0B5ED7');
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $requires_cash_balance = isset($_POST['requires_cash_balance']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');

    // Validation
    if (empty($provider_code)) {
        $error = 'Provider code is required.';
    } elseif (empty($provider_name)) {
        $error = 'Provider name is required.';
    } elseif (empty($provider_type)) {
        $error = 'Provider type is required.';
    } else {
        // Check if provider code already exists (excluding current provider)
        $check_stmt = $db->prepare("SELECT id FROM providers WHERE provider_code = ? AND id != ?");
        $check_stmt->execute([$provider_code, $provider_id]);
        if ($check_stmt->rowCount() > 0) {
            $error = 'Provider code "' . htmlspecialchars($provider_code) . '" already exists.';
        } else {
            try {
                // Store old values for logging
                $old_values = [
                    'code' => $provider['provider_code'],
                    'name' => $provider['provider_name'],
                    'type' => $provider['provider_type']
                ];

                // Update provider
                $update_stmt = $db->prepare("UPDATE providers SET 
                    provider_code = ?,
                    provider_name = ?,
                    provider_type = ?,
                    category = ?,
                    icon_class = ?,
                    color_code = ?,
                    display_order = ?,
                    is_active = ?,
                    is_default = ?,
                    requires_cash_balance = ?,
                    notes = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                
                $update_stmt->execute([
                    $provider_code,
                    $provider_name,
                    $provider_type,
                    $category,
                    $icon_class,
                    $color_code,
                    $display_order,
                    $is_active,
                    $is_default,
                    $requires_cash_balance,
                    $notes,
                    $provider_id
                ]);

                // Log activity
                logActivity($user_id, 'Edit Provider', 'Providers', $provider_id, 
                    json_encode($old_values), 
                    'Provider: ' . $provider_name . ' (' . $provider_code . ')', 
                    null);

                $_SESSION['success_message'] = 'Provider "' . htmlspecialchars($provider_name) . '" updated successfully!';
                header('Location: index.php');
                exit();

            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// GET PROVIDER TYPES FOR DROPDOWN
// ============================================================
$provider_types = [
    'bank' => 'Bank',
    'mobile_money' => 'Mobile Money',
    'other' => 'Other'
];

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-edit"></i> Edit Provider</h2>
                <span class="record-count"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Providers
                </a>
                <a href="view.php?id=<?php echo $provider_id; ?>" class="btn btn-view-link">
                    <i class="fas fa-eye"></i> View
                </a>
            </div>
        </div>

        <!-- ============================================================
        ERROR/SUCCESS MESSAGES
        ============================================================ -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        EDIT PROVIDER FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="provider-form" id="providerForm">
                
                <!-- ===== BASIC INFORMATION ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-info-circle"></i> Basic Information
                    </div>
                    
                    <div class="form-grid">
                        <!-- Provider Code -->
                        <div class="form-group">
                            <label for="provider_code" class="form-label required">Provider Code</label>
                            <input type="text" id="provider_code" name="provider_code" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['provider_code'] ?? $provider['provider_code']); ?>"
                                   placeholder="e.g., NMB, MPESA" 
                                   required>
                            <small class="form-help">Unique code for the provider (e.g., NMB, MPESA)</small>
                        </div>

                        <!-- Provider Name -->
                        <div class="form-group">
                            <label for="provider_name" class="form-label required">Provider Name</label>
                            <input type="text" id="provider_name" name="provider_name" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['provider_name'] ?? $provider['provider_name']); ?>"
                                   placeholder="e.g., NMB Bank, M-PESA" 
                                   required>
                            <small class="form-help">Full name of the provider</small>
                        </div>

                        <!-- Provider Type -->
                        <div class="form-group">
                            <label for="provider_type" class="form-label required">Provider Type</label>
                            <select id="provider_type" name="provider_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <?php foreach ($provider_types as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                        <?php echo (($_POST['provider_type'] ?? $provider['provider_type']) == $value) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">Select the type of provider</small>
                        </div>

                        <!-- Category -->
                        <div class="form-group">
                            <label for="category" class="form-label">Category</label>
                            <input type="text" id="category" name="category" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['category'] ?? $provider['category']); ?>"
                                   placeholder="e.g., Financial, Telecom">
                            <small class="form-help">Category or industry of the provider</small>
                        </div>
                    </div>
                </div>

                <!-- ===== APPEARANCE ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-palette"></i> Appearance
                    </div>
                    
                    <div class="form-grid">
                        <!-- Icon Class -->
                        <div class="form-group">
                            <label for="icon_class" class="form-label">Icon Class</label>
                            <div class="icon-picker-wrapper">
                                <input type="text" id="icon_class" name="icon_class" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['icon_class'] ?? $provider['icon_class']); ?>"
                                       placeholder="e.g., fas fa-university">
                                <button type="button" class="btn-icon-picker" onclick="openIconPicker()">
                                    <i class="fas fa-icons"></i>
                                </button>
                            </div>
                            <small class="form-help">Font Awesome icon class (e.g., fas fa-university)</small>
                        </div>

                        <!-- Color Code -->
                        <div class="form-group">
                            <label for="color_code" class="form-label">Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="color_code" name="color_code" 
                                       class="color-input" 
                                       value="<?php echo htmlspecialchars($_POST['color_code'] ?? $provider['color_code']); ?>">
                                <input type="text" id="color_code_text" name="color_code_text" 
                                       class="form-control color-text" 
                                       value="<?php echo htmlspecialchars($_POST['color_code'] ?? $provider['color_code']); ?>">
                            </div>
                            <small class="form-help">Color code for the provider badge</small>
                        </div>

                        <!-- Display Order -->
                        <div class="form-group">
                            <label for="display_order" class="form-label">Display Order</label>
                            <input type="number" id="display_order" name="display_order" 
                                   class="form-control" 
                                   value="<?php echo $_POST['display_order'] ?? $provider['display_order']; ?>"
                                   min="0">
                            <small class="form-help">Order in which provider appears (lower = first)</small>
                        </div>
                    </div>
                </div>

                <!-- ===== SETTINGS ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-cog"></i> Settings
                    </div>
                    
                    <div class="form-grid">
                        <!-- Is Active -->
                        <div class="form-group form-checkbox">
                            <label class="checkbox-label">
                                <input type="checkbox" id="is_active" name="is_active" 
                                       <?php echo ($_POST['is_active'] ?? $provider['is_active']) ? 'checked' : ''; ?>>
                                <span class="checkbox-text">Active</span>
                            </label>
                            <small class="form-help">Enable or disable this provider</small>
                        </div>

                        <!-- Is Default -->
                        <div class="form-group form-checkbox">
                            <label class="checkbox-label">
                                <input type="checkbox" id="is_default" name="is_default" 
                                       <?php echo ($_POST['is_default'] ?? $provider['is_default']) ? 'checked' : ''; ?>>
                                <span class="checkbox-text">Default Provider</span>
                            </label>
                            <small class="form-help">Mark as default provider</small>
                        </div>

                        <!-- Requires Cash Balance -->
                        <div class="form-group form-checkbox">
                            <label class="checkbox-label">
                                <input type="checkbox" id="requires_cash_balance" name="requires_cash_balance" 
                                       <?php echo ($_POST['requires_cash_balance'] ?? $provider['requires_cash_balance']) ? 'checked' : ''; ?>>
                                <span class="checkbox-text">Requires Cash Balance</span>
                            </label>
                            <small class="form-help">Provider has cash balance tracking</small>
                        </div>
                    </div>
                </div>

                <!-- ===== NOTES ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-sticky-note"></i> Notes
                    </div>
                    
                    <div class="form-group">
                        <label for="notes" class="form-label">Notes (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="4" 
                                  placeholder="Add any additional information about this provider..."><?php 
                            echo htmlspecialchars($_POST['notes'] ?? $provider['notes'] ?? ''); 
                        ?></textarea>
                    </div>
                </div>

                <!-- ===== CREATED INFO ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-history"></i> Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Created</label>
                            <div class="info-text">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo formatDateTime($provider['created_at']); ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Updated</label>
                            <div class="info-text">
                                <i class="fas fa-clock"></i>
                                <?php echo formatDateTime($provider['updated_at']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Provider
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <a href="delete.php?id=<?php echo $provider_id; ?>" class="btn btn-danger" 
                       onclick="return confirm('Are you sure you want to delete this provider?')">
                        <i class="fas fa-trash"></i> Delete
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
STYLES
============================================================ -->
<style>
/* Same as add.php styles with additional styles */

:root {
    --form-bg: #FFFFFF;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
    --form-input-bg: #F9FAFB;
    --form-hover: #F3F4F6;
    --form-shadow: rgba(0,0,0,0.06);
    --form-shadow-lg: rgba(0,0,0,0.12);
    --form-section-border: #E5E7EB;
}

html.dark-mode {
    --form-bg: #1F2937;
    --form-text: #F9FAFB;
    --form-text-secondary: #9CA3AF;
    --form-text-light: #6B7280;
    --form-border: #374151;
    --form-input-bg: #374151;
    --form-hover: #374151;
    --form-shadow: rgba(0,0,0,0.3);
    --form-shadow-lg: rgba(0,0,0,0.4);
    --form-section-border: #374151;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--form-bg) !important; }
.main-content { background: var(--form-bg) !important; }

/* Page Header */
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

.record-count {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-back {
    background: var(--form-hover);
    color: var(--form-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: 1px solid var(--form-border);
}

.btn-back:hover {
    background: var(--form-border);
    color: var(--form-text);
}

.btn-view-link {
    background: #3B82F6;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-view-link:hover {
    background: #2563EB;
    color: white;
}

/* Alerts */
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
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6; }
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Form Container */
.form-container {
    background: var(--form-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--form-shadow);
    border: 1px solid var(--form-border);
    padding: 24px;
    transition: all 0.3s ease;
}

/* Form Sections */
.form-section {
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--form-section-border);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
}

.form-section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--form-text);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section-title i { color: #3B82F6; }

/* Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px 24px;
}

/* Form Groups */
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full-width { grid-column: 1 / -1; }

.form-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--form-text);
}

.form-label.required::after {
    content: ' *';
    color: #DC2626;
}

.form-control {
    padding: 10px 14px;
    border-radius: 8px;
    border: 1.5px solid var(--form-border);
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    background: var(--form-input-bg);
    color: var(--form-text);
    width: 100%;
}

.form-control:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.form-control::placeholder { color: var(--form-text-light); }
textarea.form-control { resize: vertical; min-height: 80px; }
.form-help { font-size: 11px; color: var(--form-text-light); margin-top: 2px; }

/* Checkbox */
.form-checkbox { display: flex; flex-direction: column; gap: 2px; padding-top: 6px; }

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--form-text);
}

.checkbox-label input[type="checkbox"] { display: none; }

.checkbox-label .checkbox-text {
    position: relative;
    padding-left: 32px;
    user-select: none;
}

.checkbox-label .checkbox-text::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    border: 2px solid var(--form-border);
    border-radius: 6px;
    background: var(--form-input-bg);
    transition: all 0.3s ease;
}

.checkbox-label input[type="checkbox"]:checked + .checkbox-text::before {
    background: #DC2626;
    border-color: #DC2626;
}

.checkbox-label input[type="checkbox"]:checked + .checkbox-text::after {
    content: '✓';
    position: absolute;
    left: 4px;
    top: 50%;
    transform: translateY(-50%);
    color: white;
    font-size: 14px;
    font-weight: 700;
}

/* Icon Picker */
.icon-picker-wrapper {
    display: flex;
    gap: 8px;
    align-items: center;
}

.icon-picker-wrapper .form-control { flex: 1; }

.btn-icon-picker {
    padding: 10px 14px;
    background: var(--form-hover);
    border: 1.5px solid var(--form-border);
    border-radius: 8px;
    color: var(--form-text);
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 16px;
}

.btn-icon-picker:hover {
    background: var(--form-border);
    border-color: #DC2626;
}

/* Color Picker */
.color-picker-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
}

.color-input {
    width: 44px;
    height: 44px;
    padding: 2px;
    border: 1.5px solid var(--form-border);
    border-radius: 8px;
    cursor: pointer;
    background: var(--form-input-bg);
}

.color-input::-webkit-color-swatch-wrapper { padding: 2px; }
.color-input::-webkit-color-swatch { border-radius: 6px; border: none; }
.color-text { flex: 1; }

/* Info Text */
.info-text {
    padding: 10px 14px;
    background: var(--form-hover);
    border-radius: 8px;
    border: 1px solid var(--form-border);
    color: var(--form-text-secondary);
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-text i { color: #3B82F6; }

/* Form Actions */
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid var(--form-section-border);
    flex-wrap: wrap;
}

.btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #DC2626;
    color: white;
}

.btn-primary:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

.btn-secondary {
    background: var(--form-hover);
    color: var(--form-text);
    border: 1px solid var(--form-border);
}

.btn-secondary:hover {
    background: var(--form-border);
}

.btn-danger {
    background: #DC2626;
    color: white;
    margin-left: auto;
}

.btn-danger:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

/* Responsive */
@media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    .form-container { padding: 16px; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { justify-content: center; width: 100%; }
    .btn-danger { margin-left: 0; }
    .color-picker-wrapper { flex-wrap: wrap; }
}

@media (max-width: 480px) {
    .form-container { padding: 12px; }
    .form-section { padding-bottom: 16px; }
    .form-section-title { font-size: 14px; }
}

/* Animations */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-container {
    animation: fadeInUp 0.4s ease forwards;
    animation-delay: 0.10s;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sync color picker with text input
    const colorInput = document.getElementById('color_code');
    const colorText = document.getElementById('color_code_text');
    
    if (colorInput && colorText) {
        colorInput.addEventListener('input', function() {
            colorText.value = this.value;
        });
        
        colorText.addEventListener('input', function() {
            colorInput.value = this.value;
        });
    }
    
    // Dark Mode Sync
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

function openIconPicker() {
    alert('Enter Font Awesome icon class in the input field.\n\nExamples:\n- fas fa-university\n- fas fa-mobile-alt\n- fas fa-landmark\n- fas fa-building\n- fas fa-money-bill-wave');
}
</script>

</body>
</html>