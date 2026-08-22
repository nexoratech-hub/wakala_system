<?php
// ================================================================
// FILE: modules/settings/index.php
// WAKALA FINANCIAL SYSTEM - SYSTEM SETTINGS
// WITH DARK MODE SUPPORT - FULLY FUNCTIONAL
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
// CHECK PERMISSION - Only super_admin can access settings
// ============================================================
if ($role !== 'super_admin') {
    header('Location: ../dashboard/admin.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET SYSTEM SETTINGS
// ============================================================
$settings = [];
$stmt = $db->prepare("SELECT * FROM system_settings ORDER BY setting_group, setting_key");
$stmt->execute();
$results = $stmt->fetchAll();

foreach ($results as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// ============================================================
// HANDLE SETTINGS UPDATE
// ============================================================
$success_message = '';
$error_message = '';
$show_success = false;
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    try {
        $db->beginTransaction();
        
        $updated_count = 0;
        
        foreach ($_POST as $key => $value) {
            // Skip non-setting fields
            if ($key === 'action' || $key === 'submit' || $key === 'csrf_token') {
                continue;
            }
            
            // Clean the value
            $value = trim($value);
            
            // Check if this setting exists in our database
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
            $check_stmt->execute([$key]);
            $exists = $check_stmt->fetchColumn();
            
            if ($exists > 0) {
                // Update existing setting
                $update_stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                $update_stmt->execute([$value, $key]);
                $updated_count++;
            } else {
                // Insert new setting (should not happen normally)
                $insert_stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general')");
                $insert_stmt->execute([$key, $value]);
                $updated_count++;
            }
        }
        
        $db->commit();
        
        if ($updated_count > 0) {
            $success_message = 'Settings updated successfully! (' . $updated_count . ' settings updated)';
            $show_success = true;
        } else {
            $success_message = 'No changes were made.';
            $show_success = true;
        }
        
        // Refresh settings array
        $settings = [];
        $stmt = $db->prepare("SELECT * FROM system_settings");
        $stmt->execute();
        $results = $stmt->fetchAll();
        foreach ($results as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Update session with new company name
        if (isset($settings['company_name'])) {
            $_SESSION['company_name'] = $settings['company_name'];
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = 'Error updating settings: ' . $e->getMessage();
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

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-cog"></i> System Settings</h2>
                <span class="page-subtitle">Configure your system preferences</span>
            </div>
            <div class="page-header-right">
                <span class="last-updated">
                    <i class="fas fa-clock"></i> Last updated: <?php echo date('d M Y H:i'); ?>
                </span>
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
        SETTINGS TABS
        ============================================================ -->
        <div class="settings-container">
            <div class="settings-tabs">
                <button class="tab-btn active" onclick="showTab('general')">
                    <i class="fas fa-building"></i>
                    <span>General</span>
                </button>
                <button class="tab-btn" onclick="showTab('financial')">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Financial</span>
                </button>
                <button class="tab-btn" onclick="showTab('salary')">
                    <i class="fas fa-wallet"></i>
                    <span>Salary</span>
                </button>
                <button class="tab-btn" onclick="showTab('system')">
                    <i class="fas fa-server"></i>
                    <span>System</span>
                </button>
            </div>

            <form method="POST" action="" class="settings-form" id="settingsForm">
                <input type="hidden" name="action" value="update_settings">
                
                <!-- ============================================================
                GENERAL SETTINGS TAB
                ============================================================ -->
                <div class="tab-content" id="tab-general">
                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-building"></i> Company Information</h3>
                            <span class="card-badge">Required</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="company_name">
                                        Company Name <span class="required">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-building"></i></span>
                                        <input type="text" id="company_name" name="company_name" 
                                               value="<?php echo htmlspecialchars($settings['company_name'] ?? 'Wakala System'); ?>" 
                                               class="form-control" placeholder="Enter company name" required>
                                    </div>
                                    <small>Official name of your company or business</small>
                                    <div class="field-hint">
                                        <i class="fas fa-info-circle"></i>
                                        Current value: <strong><?php echo htmlspecialchars($settings['company_name'] ?? 'Wakala System'); ?></strong>
                                    </div>
                                </div>
                                <div class="settings-field">
                                    <label for="company_address">Company Address</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <input type="text" id="company_address" name="company_address" 
                                               value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>" 
                                               class="form-control" placeholder="Enter company address">
                                    </div>
                                    <small>Physical location of your business</small>
                                </div>
                            </div>
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="company_phone">Company Phone</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-phone"></i></span>
                                        <input type="text" id="company_phone" name="company_phone" 
                                               value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" 
                                               class="form-control" placeholder="Enter company phone">
                                    </div>
                                    <small>Primary contact number</small>
                                </div>
                                <div class="settings-field">
                                    <label for="company_email">Company Email</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                        <input type="email" id="company_email" name="company_email" 
                                               value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" 
                                               class="form-control" placeholder="Enter company email">
                                    </div>
                                    <small>Official email address</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-flag"></i> Currency &amp; Format</h3>
                            <span class="card-badge">Required</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="currency">Currency <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-dollar-sign"></i></span>
                                        <input type="text" id="currency" name="currency" 
                                               value="<?php echo htmlspecialchars($settings['currency'] ?? 'TSh'); ?>" 
                                               class="form-control" placeholder="e.g., TSh" required>
                                    </div>
                                    <small>e.g., TSh, USD, EUR, KES</small>
                                </div>
                                <div class="settings-field">
                                    <label for="date_format">Date Format <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                        <select id="date_format" name="date_format" class="form-control" required>
                                            <option value="d-m-Y" <?php echo ($settings['date_format'] ?? 'd-m-Y') == 'd-m-Y' ? 'selected' : ''; ?>>DD-MM-YYYY</option>
                                            <option value="m-d-Y" <?php echo ($settings['date_format'] ?? 'd-m-Y') == 'm-d-Y' ? 'selected' : ''; ?>>MM-DD-YYYY</option>
                                            <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'd-m-Y') == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="d M Y" <?php echo ($settings['date_format'] ?? 'd-m-Y') == 'd M Y' ? 'selected' : ''; ?>>DD Mon YYYY</option>
                                        </select>
                                    </div>
                                    <small>Format for displaying dates throughout the system</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                FINANCIAL SETTINGS TAB
                ============================================================ -->
                <div class="tab-content" id="tab-financial" style="display:none;">
                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-coins"></i> Financial Settings</h3>
                            <span class="card-badge">Important</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="opening_capital">Opening Capital <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-money-bill"></i></span>
                                        <input type="number" id="opening_capital" name="opening_capital" 
                                               value="<?php echo htmlspecialchars($settings['opening_capital'] ?? '0'); ?>" 
                                               class="form-control" step="0.01" placeholder="0.00" required>
                                    </div>
                                    <small>Initial capital for the business at startup</small>
                                    <div class="field-hint">
                                        <i class="fas fa-info-circle"></i>
                                        This value is used as the starting point for capital management
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                SALARY SETTINGS TAB
                ============================================================ -->
                <div class="tab-content" id="tab-salary" style="display:none;">
                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-wallet"></i> Salary Settings</h3>
                            <span class="card-badge">Required</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="salary_month">Current Salary Month <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-calendar-month"></i></span>
                                        <input type="month" id="salary_month" name="salary_month" 
                                               value="<?php echo htmlspecialchars($settings['salary_month'] ?? date('Y-m')); ?>" 
                                               class="form-control" required>
                                    </div>
                                    <small>Select the current month for salary processing</small>
                                    <div class="field-hint">
                                        <i class="fas fa-info-circle"></i>
                                        This determines which month's salaries are being processed
                                    </div>
                                </div>
                                <div class="settings-field">
                                    <label for="tax_rate">Tax Rate (%)</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-percent"></i></span>
                                        <input type="number" id="tax_rate" name="tax_rate" 
                                               value="<?php echo htmlspecialchars($settings['tax_rate'] ?? '0'); ?>" 
                                               class="form-control" step="0.01" min="0" max="100" placeholder="0">
                                    </div>
                                    <small>Default tax rate for salary calculations</small>
                                    <div class="field-hint">
                                        <i class="fas fa-info-circle"></i>
                                        Leave 0 if tax is calculated manually per employee
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                SYSTEM SETTINGS TAB
                ============================================================ -->
                <div class="tab-content" id="tab-system" style="display:none;">
                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-server"></i> System Settings</h3>
                            <span class="card-badge">Advanced</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="timezone">Timezone <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-globe"></i></span>
                                        <select id="timezone" name="timezone" class="form-control" required>
                                            <option value="Africa/Dar_es_Salaam" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Dar_es_Salaam' ? 'selected' : ''; ?>>Africa/Dar_es_Salaam</option>
                                            <option value="Africa/Nairobi" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi</option>
                                            <option value="Africa/Kampala" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Kampala' ? 'selected' : ''; ?>>Africa/Kampala</option>
                                            <option value="Africa/Maputo" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Maputo' ? 'selected' : ''; ?>>Africa/Maputo</option>
                                            <option value="Africa/Lagos" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos</option>
                                            <option value="Africa/Johannesburg" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'Africa/Johannesburg' ? 'selected' : ''; ?>>Africa/Johannesburg</option>
                                            <option value="UTC" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                        </select>
                                    </div>
                                    <small>System timezone for all date/time operations</small>
                                </div>
                                <div class="settings-field">
                                    <label for="system_currency">Currency Symbol</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-money-bill"></i></span>
                                        <input type="text" id="system_currency" name="system_currency" 
                                               value="<?php echo htmlspecialchars($settings['system_currency'] ?? 'TSh'); ?>" 
                                               class="form-control" placeholder="TSh">
                                    </div>
                                    <small>Currency symbol for display purposes</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fas fa-database"></i> Database Information</h3>
                            <span class="card-badge">Read-only</span>
                        </div>
                        <div class="settings-group">
                            <div class="settings-info">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-database"></i> Database Name:</span>
                                    <span class="info-value"><?php echo defined('DB_NAME') ? DB_NAME : 'Not available'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-server"></i> Database Host:</span>
                                    <span class="info-value"><?php echo defined('DB_HOST') ? DB_HOST : 'Not available'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-code-branch"></i> System Version:</span>
                                    <span class="info-value">Wakala System v2.0</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-calendar-check"></i> Database Updated:</span>
                                    <span class="info-value"><?php echo date('d M Y H:i:s'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-cog"></i> Settings Count:</span>
                                    <span class="info-value"><?php echo count($settings); ?> settings</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                SAVE BUTTON
                ============================================================ -->
                <div class="settings-actions">
                    <button type="submit" class="btn btn-save" name="submit" id="saveBtn">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset Changes
                    </button>
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
DASHBOARD STYLES - WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --settings-bg: #FFFFFF;
    --settings-text: #1F2937;
    --settings-text-secondary: #6B7280;
    --settings-border: #E5E7EB;
    --settings-card-bg: #FFFFFF;
    --settings-card-header: #FAFBFC;
    --settings-input-bg: #FFFFFF;
    --settings-hover: #F3F4F6;
    --settings-shadow: rgba(0,0,0,0.06);
    --settings-shadow-lg: rgba(0,0,0,0.12);
    --settings-alert-success-bg: #D1FAE5;
    --settings-alert-success-text: #065F46;
    --settings-alert-success-border: #A7F3D0;
    --settings-alert-danger-bg: #FEE2E2;
    --settings-alert-danger-text: #991B1B;
    --settings-alert-danger-border: #FECACA;
}

html.dark-mode {
    --settings-bg: #1F2937;
    --settings-text: #F9FAFB;
    --settings-text-secondary: #9CA3AF;
    --settings-border: #374151;
    --settings-card-bg: #1F2937;
    --settings-card-header: #374151;
    --settings-input-bg: #374151;
    --settings-hover: #374151;
    --settings-shadow: rgba(0,0,0,0.3);
    --settings-shadow-lg: rgba(0,0,0,0.4);
    --settings-alert-success-bg: #065F46;
    --settings-alert-success-text: #D1FAE5;
    --settings-alert-success-border: #047857;
    --settings-alert-danger-bg: #7F1D1D;
    --settings-alert-danger-text: #FEE2E2;
    --settings-alert-danger-border: #991B1B;
}

/* ============================================================
   PAGE HEADER - DARK MODE SUPPORT
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 0 4px;
    flex-wrap: wrap;
    gap: 10px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.page-header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--settings-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #DC2626;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--settings-text-secondary);
    background: var(--settings-hover);
    padding: 3px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.page-header-right {
    display: flex;
    align-items: center;
}

.last-updated {
    font-size: 12px;
    color: var(--settings-text-secondary);
    background: var(--settings-hover);
    padding: 4px 14px;
    border-radius: 12px;
    border: 1px solid var(--settings-border);
    transition: all 0.3s ease;
}

.last-updated i {
    color: #059669;
    margin-right: 4px;
}

/* ============================================================
   ALERTS - DARK MODE SUPPORT
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
    background: var(--settings-alert-success-bg);
    color: var(--settings-alert-success-text);
    border: 1px solid var(--settings-alert-success-border);
}

.alert-danger {
    background: var(--settings-alert-danger-bg);
    color: var(--settings-alert-danger-text);
    border: 1px solid var(--settings-alert-danger-border);
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
   SETTINGS CONTAINER - DARK MODE SUPPORT
   ============================================================ */
.settings-container {
    background: var(--settings-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--settings-shadow);
    border: 1px solid var(--settings-border);
    overflow: hidden;
    animation: fadeInUp 0.4s ease forwards;
    transition: all 0.3s ease;
}

/* ============================================================
   SETTINGS TABS - DARK MODE SUPPORT
   ============================================================ */
.settings-tabs {
    display: flex;
    background: var(--settings-card-header);
    border-bottom: 1px solid var(--settings-border);
    padding: 0 20px;
    overflow-x: auto;
    gap: 4px;
    transition: all 0.3s ease;
}

.tab-btn {
    padding: 14px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 600;
    font-size: 14px;
    color: var(--settings-text-secondary);
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-btn:hover {
    color: var(--settings-text);
    background: rgba(220,38,38,0.05);
    border-radius: 4px 4px 0 0;
}

.tab-btn.active {
    color: #DC2626;
    border-bottom-color: #DC2626;
    background: rgba(220,38,38,0.05);
}

.tab-btn i {
    font-size: 15px;
    color: inherit;
}

/* ============================================================
   TAB CONTENT
   ============================================================ */
.tab-content {
    padding: 24px 20px;
}

/* ============================================================
   SETTINGS CARD - DARK MODE SUPPORT
   ============================================================ */
.settings-card {
    background: var(--settings-card-bg);
    border-radius: 10px;
    border: 1px solid var(--settings-border);
    overflow: hidden;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.settings-card:hover {
    box-shadow: 0 2px 8px var(--settings-shadow);
}

.settings-card:last-child {
    margin-bottom: 0;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    background: var(--settings-card-header);
    border-bottom: 1px solid var(--settings-border);
    transition: all 0.3s ease;
}

.card-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--settings-text);
    margin: 0;
    transition: color 0.3s ease;
}

.card-header h3 i {
    color: #DC2626;
    margin-right: 8px;
}

.card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 12px;
    border-radius: 12px;
    background: var(--settings-hover);
    color: var(--settings-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
}

/* ============================================================
   SETTINGS FIELDS - DARK MODE SUPPORT
   ============================================================ */
.settings-group {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px;
}

.settings-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.settings-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.settings-field label {
    font-size: 13px;
    font-weight: 600;
    color: var(--settings-text);
    transition: color 0.3s ease;
}

.settings-field label .required {
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
    color: var(--settings-text-secondary);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
    transition: color 0.3s ease;
}

.input-group .form-control {
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--settings-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--settings-input-bg);
    color: var(--settings-text);
    width: 100%;
}

.input-group .form-control::placeholder {
    color: var(--settings-text-secondary);
}

.input-group .form-control:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.1);
}

.input-group .form-control:focus + .input-icon,
.input-group .form-control:focus ~ .input-icon {
    color: #DC2626;
}

.input-group .form-control[readonly] {
    background: var(--settings-hover);
    cursor: not-allowed;
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

.settings-field small {
    font-size: 12px;
    color: var(--settings-text-secondary);
    margin-top: 2px;
    transition: color 0.3s ease;
}

.field-hint {
    font-size: 12px;
    color: var(--settings-text-secondary);
    background: var(--settings-hover);
    padding: 6px 12px;
    border-radius: 6px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.field-hint i {
    color: #3B82F6;
    font-size: 14px;
}

.field-hint strong {
    color: var(--settings-text);
}

/* ============================================================
   SETTINGS INFO - DARK MODE SUPPORT
   ============================================================ */
.settings-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 4px 0;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 16px;
    border-radius: 6px;
    transition: background 0.2s ease;
}

.info-row:hover {
    background: var(--settings-hover);
}

.info-label {
    font-weight: 500;
    color: var(--settings-text-secondary);
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: color 0.3s ease;
}

.info-label i {
    color: var(--settings-text-secondary);
    width: 16px;
    text-align: center;
}

.info-value {
    font-weight: 600;
    color: var(--settings-text);
    font-size: 13px;
    font-family: 'Courier New', monospace;
    transition: color 0.3s ease;
}

/* ============================================================
   SETTINGS ACTIONS - DARK MODE SUPPORT
   ============================================================ */
.settings-actions {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid var(--settings-border);
    background: var(--settings-card-header);
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
}

.btn-save {
    background: #DC2626;
    color: white;
}

.btn-save:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

.btn-save:active {
    transform: translateY(0);
}

.btn-save:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-reset {
    background: var(--settings-hover);
    color: var(--settings-text-secondary);
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--settings-border);
    color: var(--settings-text);
    transform: translateY(-2px);
    box-shadow: 0 2px 8px var(--settings-shadow);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .settings-row {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-header-right {
        width: 100%;
    }
    
    .last-updated {
        width: 100%;
        text-align: center;
    }
    
    .settings-tabs {
        padding: 0 10px;
        gap: 2px;
    }
    
    .tab-btn {
        padding: 12px 14px;
        font-size: 13px;
    }
    
    .settings-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .tab-content {
        padding: 16px 14px;
    }
    
    .settings-actions {
        flex-direction: column;
    }
    
    .settings-actions .btn {
        justify-content: center;
        width: 100%;
    }
    
    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        padding: 6px 12px;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .settings-group {
        padding: 14px;
    }
}

@media (max-width: 480px) {
    .page-header-left h2 {
        font-size: 17px;
    }
    
    .page-subtitle {
        font-size: 11px;
        padding: 2px 10px;
    }
    
    .tab-btn {
        padding: 10px 10px;
        font-size: 12px;
    }
    
    .tab-btn span {
        display: none;
    }
    
    .tab-btn i {
        font-size: 18px;
    }
    
    .input-group .form-control {
        padding: 8px 12px 8px 36px;
        font-size: 13px;
    }
    
    .input-icon {
        left: 10px;
        font-size: 13px;
    }
    
    .settings-card h3 {
        font-size: 14px;
    }
    
    .card-badge {
        font-size: 10px;
        padding: 1px 10px;
    }
    
    .settings-actions .btn {
        padding: 8px 16px;
        font-size: 13px;
    }
    
    .alert {
        padding: 10px 14px;
        font-size: 13px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.settings-card {
    animation: fadeInUp 0.4s ease forwards;
}

.settings-card:nth-child(1) { animation-delay: 0.05s; }
.settings-card:nth-child(2) { animation-delay: 0.10s; }

.alert {
    animation: slideDown 0.4s ease forwards;
}
</style>

<script>
// ============================================================
// TAB NAVIGATION
// ============================================================
function showTab(tabName) {
    // Hide all tabs
    var tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(function(tab) {
        tab.style.display = 'none';
    });
    
    // Show selected tab
    var selectedTab = document.getElementById('tab-' + tabName);
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }
    
    // Update tab buttons
    var tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    // Find and activate clicked button
    var buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(function(btn) {
        var onclickAttr = btn.getAttribute('onclick');
        if (onclickAttr && onclickAttr.includes("'" + tabName + "'")) {
            btn.classList.add('active');
        }
    });
}

// ============================================================
// CONFIRM RESET
// ============================================================
function confirmReset() {
    return confirm('Are you sure you want to reset all changes? This will reload the page.');
}

// ============================================================
// HANDLE FORM SUBMIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('settingsForm');
    var saveBtn = document.getElementById('saveBtn');
    
    // ============================================================
    // SET INITIAL TAB
    // ============================================================
    showTab('general');
    
    // ============================================================
    // AUTO-HIDE MESSAGES
    // ============================================================
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
    
    // ============================================================
    // CLOSE ALERT
    // ============================================================
    var closeBtns = document.querySelectorAll('.alert-close');
    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // ============================================================
    // RESET BUTTON
    // ============================================================
    var resetBtn = document.querySelector('.btn-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to reset all changes? This will reload the page.')) {
                e.preventDefault();
            }
        });
    }
    
    // ============================================================
    // DARK MODE SYNC
    // ============================================================
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
    
    // Listen for dark mode changes from other parts of the system
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
    
    // Also check periodically for dark mode changes
    setInterval(function() {
        var currentDark = localStorage.getItem('darkMode') === 'true';
        var htmlHasDark = document.documentElement.classList.contains('dark-mode');
        if (currentDark !== htmlHasDark) {
            syncDarkMode();
        }
    }, 2000);
});

// ============================================================
// LOGGING FOR DEBUG
// ============================================================
console.log('%c ⚙️ System Settings Page Loaded', 
    'background:#DC2626; color:white; padding:4px 12px; border-radius:4px; font-weight:bold;');
console.log('%c 🌙 Dark Mode: ' + (localStorage.getItem('darkMode') === 'true' ? 'ON' : 'OFF'),
    'color:#6B7280; font-size:12px;');
console.log('%c 📋 Settings Count: <?php echo count($settings); ?>', 
    'color:#6B7280; font-size:12px;');
</script>

</body>
</html>