<?php
// ================================================================
// FILE: modules/branches/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT BRANCH
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
// GET BRANCH ID
// ============================================================
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEES FOR MANAGER DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name");
$stmt->execute();
$employees = $stmt->fetchAll();

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_branch') {
    try {
        $branch_code = strtoupper(trim($_POST['branch_code'] ?? ''));
        $branch_name = trim($_POST['branch_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_id = intval($_POST['manager_id'] ?? 0);
        $is_active = intval($_POST['is_active'] ?? 1);
        
        // Validate
        if (empty($branch_code)) {
            throw new Exception('Please enter a branch code.');
        }
        if (empty($branch_name)) {
            throw new Exception('Please enter a branch name.');
        }
        
        // Check if branch code already exists (excluding current)
        $check_stmt = $db->prepare("SELECT id FROM branches WHERE branch_code = ? AND id != ?");
        $check_stmt->execute([$branch_code, $branch_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Branch code "' . $branch_code . '" already exists. Please use a different code.');
        }
        
        // Check if branch name already exists (excluding current)
        $check_stmt = $db->prepare("SELECT id FROM branches WHERE branch_name = ? AND id != ?");
        $check_stmt->execute([$branch_name, $branch_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Branch name "' . $branch_name . '" already exists. Please use a different name.');
        }
        
        // Update branch
        $update_stmt = $db->prepare("UPDATE branches 
            SET branch_code = ?, branch_name = ?, location = ?, phone = ?, email = ?, manager_id = ?, is_active = ? 
            WHERE id = ?");
        
        $update_stmt->execute([
            $branch_code,
            $branch_name,
            $location,
            $phone,
            $email,
            $manager_id > 0 ? $manager_id : null,
            $is_active,
            $branch_id
        ]);
        
        // Log activity
        logActivity($user_id, 'Edit Branch', 'Branches', $branch_id, '', 'Updated branch: ' . $branch_name);
        
        $success_message = 'Branch "' . $branch_name . '" updated successfully!';
        $show_success = true;
        
        // Refresh branch data
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        $branch = $stmt->fetch();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// ============================================================
// GET BRANCH PROVIDERS COUNT
// ============================================================
$stmt = $db->prepare("SELECT COUNT(*) FROM branch_providers WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$provider_count = $stmt->fetchColumn();

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
                <h2><i class="fas fa-edit"></i> Edit Branch</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                <span class="branch-id-badge">ID: #<?php echo $branch_id; ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="view.php?id=<?php echo $branch_id; ?>" class="btn btn-view">
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
            <form method="POST" action="" class="main-form" id="branchForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_branch">
                
                <!-- ===== BASIC INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="branch_code">Branch Code <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tag"></i></span>
                                <input type="text" id="branch_code" name="branch_code" 
                                       value="<?php echo htmlspecialchars($branch['branch_code']); ?>" 
                                       class="form-control" placeholder="e.g., DSM" required>
                            </div>
                            <small>Unique code for the branch (e.g., DSM, KND, MBZ)</small>
                        </div>
                        <div class="form-group">
                            <label for="branch_name">Branch Name <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <input type="text" id="branch_name" name="branch_name" 
                                       value="<?php echo htmlspecialchars($branch['branch_name']); ?>" 
                                       class="form-control" placeholder="e.g., Dar es Salaam Branch" required>
                            </div>
                            <small>Full name of the branch</small>
                        </div>
                    </div>
                </div>

                <!-- ===== CONTACT INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="location">Location</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                                <input type="text" id="location" name="location" 
                                       value="<?php echo htmlspecialchars($branch['location'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., Dar es Salaam, Tanzania">
                            </div>
                            <small>Physical address of the branch</small>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($branch['phone'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., +255 700 000 000">
                            </div>
                            <small>Contact phone number</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($branch['email'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., branch@wakala.com">
                            </div>
                            <small>Official email address for the branch</small>
                        </div>
                        <div class="form-group">
                            <label for="manager_id">Branch Manager</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                                <select id="manager_id" name="manager_id" class="form-control">
                                    <option value="0">Select Manager</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo ($branch['manager_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small>Assign a manager to this branch</small>
                        </div>
                    </div>
                </div>

                <!-- ===== STATUS & ADDITIONAL ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-cog"></i> Status &amp; Additional</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="is_active">Branch Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-power-off"></i></span>
                                <select id="is_active" name="is_active" class="form-control">
                                    <option value="1" <?php echo ($branch['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo ($branch['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <small>Active branches are visible and operational</small>
                        </div>
                        <div class="form-group">
                            <label>Branch Info</label>
                            <div class="info-display">
                                <div class="info-item">
                                    <span class="info-label">Created:</span>
                                    <span class="info-value"><?php echo date('d M Y H:i', strtotime($branch['created_at'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Updated:</span>
                                    <span class="info-value"><?php echo date('d M Y H:i', strtotime($branch['updated_at'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Providers:</span>
                                    <span class="info-value"><?php echo $provider_count; ?> assigned</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Branch
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset Changes
                    </button>
                    <a href="index.php" class="btn btn-cancel">
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
DASHBOARD STYLES - WITH FULL DARK MODE SUPPORT
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
    --info-bg: #F9FAFB;
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
    --info-bg: #374151;
}

/* Apply Dark Mode to Full Page */
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
   PAGE HEADER - DARK MODE
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
    color: #3B82F6;
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

.branch-id-badge {
    font-size: 12px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.1);
    padding: 2px 12px;
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
   ALERTS - DARK MODE
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
   FORM CONTAINER - DARK MODE
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
   FORM SECTIONS - DARK MODE
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
    color: #3B82F6;
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
   FORM ROWS - DARK MODE
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
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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

.form-group small {
    font-size: 12px;
    color: var(--form-text-secondary);
    margin-top: 2px;
    transition: color 0.3s ease;
}

/* ============================================================
   INFO DISPLAY
   ============================================================ */
.info-display {
    background: var(--info-bg);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    transition: all 0.3s ease;
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
   FORM ACTIONS - DARK MODE
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
    .form-row {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .page-header-left {
        flex-wrap: wrap;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 12px;
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
        gap: 6px;
    }
    
    .info-display {
        padding: 10px 12px;
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
    
    .branch-id-badge {
        font-size: 10px;
        padding: 1px 10px;
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

.form-container {
    animation: fadeInUp 0.4s ease forwards;
}

.alert {
    animation: slideDown 0.4s ease forwards;
}
</style>

<script>
// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var branchCode = document.getElementById('branch_code');
    var branchName = document.getElementById('branch_name');
    
    if (!branchCode || branchCode.value.trim() === '') {
        alert('Please enter a branch code.');
        if (branchCode) branchCode.focus();
        return false;
    }
    
    if (!branchName || branchName.value.trim() === '') {
        alert('Please enter a branch name.');
        if (branchName) branchName.focus();
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
});
</script>

</body>
</html>