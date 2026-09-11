<?php
// ================================================================
// FILE: modules/branches/add.php
// WAKALA FINANCIAL SYSTEM - ADD BRANCH
// ✅ FIXED: Does not override session selected_branch
// ✅ URL is source of truth
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
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET EMPLOYEES FOR MANAGER DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name");
$stmt->execute();
$employees = $stmt->fetchAll();

// ============================================================
// ✅ FIXED: NO session forcing - URL is source of truth
// ============================================================

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_branch') {
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
        
        // Check if branch code already exists
        $check_stmt = $db->prepare("SELECT id FROM branches WHERE branch_code = ?");
        $check_stmt->execute([$branch_code]);
        if ($check_stmt->fetch()) {
            throw new Exception('Branch code "' . $branch_code . '" already exists. Please use a different code.');
        }
        
        // Check if branch name already exists
        $check_stmt = $db->prepare("SELECT id FROM branches WHERE branch_name = ?");
        $check_stmt->execute([$branch_name]);
        if ($check_stmt->fetch()) {
            throw new Exception('Branch name "' . $branch_name . '" already exists. Please use a different name.');
        }
        
        // Insert branch
        $insert_stmt = $db->prepare("INSERT INTO branches 
            (branch_code, branch_name, location, phone, email, manager_id, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->execute([
            $branch_code,
            $branch_name,
            $location,
            $phone,
            $email,
            $manager_id > 0 ? $manager_id : null,
            $is_active
        ]);
        
        $branch_id = $db->lastInsertId();
        
        // Log activity
        logActivity($user_id, 'Add Branch', 'Branches', $branch_id, '', 'Added branch: ' . $branch_name);
        
        $_SESSION['success_message'] = 'Branch "' . $branch_name . '" added successfully!';
        header('Location: index.php');
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
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Branch</h2>
                <span class="page-subtitle">Create a new branch</span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- ===== ERROR MESSAGE ===== -->
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ===== INFO NOTE ===== -->
        <div class="info-note">
            <i class="fas fa-info-circle"></i>
            <span>
                <strong>Note:</strong> Fields marked with <span class="required-star">*</span> are required.
                Branch code must be unique (e.g., DSM, KRK, MBZ).
            </span>
        </div>

        <!-- ===== FORM ===== -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="branchForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_branch">
                
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
                                       value="<?php echo isset($_POST['branch_code']) ? htmlspecialchars($_POST['branch_code']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., DSM, KRK, MBZ" 
                                       maxlength="20" required>
                            </div>
                            <small>Unique code for the branch (e.g., DSM, KND, MBZ)</small>
                        </div>
                        <div class="form-group">
                            <label for="branch_name">Branch Name <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <input type="text" id="branch_name" name="branch_name" 
                                       value="<?php echo isset($_POST['branch_name']) ? htmlspecialchars($_POST['branch_name']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., Dar es Salaam Branch" 
                                       maxlength="100" required>
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
                                       value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., Dar es Salaam, Tanzania"
                                       maxlength="200">
                            </div>
                            <small>Physical address of the branch</small>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" id="phone" name="phone" 
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., +255 700 000 000"
                                       maxlength="20">
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
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                       class="form-control" placeholder="e.g., branch@wakala.com"
                                       maxlength="100">
                            </div>
                            <small>Official email address for the branch</small>
                        </div>
                        <div class="form-group">
                            <label for="manager_id">Branch Manager</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                                <select id="manager_id" name="manager_id" class="form-control">
                                    <option value="0">Select Manager (Optional)</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" 
                                            <?php echo (isset($_POST['manager_id']) && $_POST['manager_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small>Assign a manager to this branch (optional)</small>
                        </div>
                    </div>
                </div>

                <!-- ===== STATUS ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-cog"></i> Status</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="is_active">Branch Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-power-off"></i></span>
                                <select id="is_active" name="is_active" class="form-control">
                                    <option value="1" <?php echo (!isset($_POST['is_active']) || $_POST['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo (isset($_POST['is_active']) && $_POST['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <small>Active branches are visible and operational</small>
                        </div>
                        <div class="form-group">
                            <label>Preview</label>
                            <div class="preview-box" id="previewBox">
                                <div class="preview-icon">
                                    <i class="fas fa-store-alt"></i>
                                </div>
                                <div class="preview-info">
                                    <span class="preview-name" id="previewName">Branch Name</span>
                                    <span class="preview-code" id="previewCode">CODE</span>
                                </div>
                                <span class="preview-status active" id="previewStatus">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Branch
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
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

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --form-bg: #F3F4F6;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
    --form-card-bg: #FFFFFF;
    --form-card-header: #FAFBFC;
    --form-input-bg: #F9FAFB;
    --form-hover: #F3F4F6;
    --form-shadow: rgba(0,0,0,0.06);
    --form-danger-bg: #FEE2E2;
    --form-danger-text: #991B1B;
    --form-danger-border: #FECACA;
}

html.dark-mode {
    --form-bg: #0F172A;
    --form-text: #F9FAFB;
    --form-text-secondary: #9CA3AF;
    --form-text-light: #6B7280;
    --form-border: #334155;
    --form-card-bg: #1E293B;
    --form-card-header: #1E293B;
    --form-input-bg: #334155;
    --form-hover: #334155;
    --form-shadow: rgba(0,0,0,0.3);
    --form-danger-bg: #7F1D1D;
    --form-danger-text: #FEE2E2;
    --form-danger-border: #991B1B;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--form-bg) !important; }
.main-content { background: var(--form-bg) !important; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0 4px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.page-header-left h2 {
    font-size: 22px;
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
    font-weight: 500;
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
    transform: translateY(-2px);
}

/* ============================================================
   INFO NOTE
   ============================================================ */
.info-note {
    background: #DBEAFE;
    border: 1px solid #93C5FD;
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: #1E40AF;
    font-size: 13px;
}

.info-note i {
    font-size: 18px;
    flex-shrink: 0;
}

.info-note .required-star {
    color: #DC2626;
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
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    position: relative;
    animation: slideDown 0.4s ease forwards;
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

.form-section:last-of-type {
    border-bottom: none;
}

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
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-header h3 i {
    color: #3B82F6;
}

.section-badge {
    font-size: 11px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 2px 12px;
    border-radius: 12px;
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

/* ============================================================
   INPUT GROUP
   ============================================================ */
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
    border: 1.5px solid var(--form-border);
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
    font-size: 13px;
}

.input-group .form-control:focus {
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    background: var(--form-card-bg);
}

.input-group .form-control:focus + .input-icon,
.input-group:focus-within .input-icon {
    color: #3B82F6;
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
    background: var(--form-card-bg);
    color: var(--form-text);
    padding: 8px;
}

.form-group small {
    font-size: 11px;
    color: var(--form-text-secondary);
    margin-top: 2px;
    line-height: 1.4;
}

/* ============================================================
   PREVIEW BOX
   ============================================================ */
.preview-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--form-input-bg);
    border: 1.5px dashed var(--form-border);
    border-radius: 8px;
    transition: all 0.3s ease;
    min-height: 42px;
}

.preview-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 14px;
    flex-shrink: 0;
}

.preview-info {
    flex: 1;
    min-width: 0;
}

.preview-name {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--form-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.preview-code {
    display: block;
    font-size: 10px;
    font-weight: 600;
    color: var(--form-text-light);
    font-family: 'Courier New', monospace;
}

.preview-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 10px;
    flex-shrink: 0;
}

.preview-status.active {
    background: #D1FAE5;
    color: #065F46;
}

.preview-status.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .preview-status.active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .preview-status.inactive {
    background: #7F1D1D;
    color: #FEE2E2;
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
    background: #3B82F6;
    color: white;
    flex: 1;
    justify-content: center;
    min-width: 180px;
}

.btn-submit:hover {
    background: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.35);
    color: white;
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

html.dark-mode .btn-cancel:hover {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .page-header-left {
        flex-wrap: wrap;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .form-section {
        padding: 16px 16px;
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
    .page-header-left h2 {
        font-size: 18px;
    }
    
    .page-subtitle {
        font-size: 11px;
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
        padding: 9px 18px;
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

.info-note {
    animation: fadeInUp 0.3s ease forwards;
}

/* ============================================================
   LIVE PREVIEW UPDATES
   ============================================================ */
.form-control {
    transition: all 0.3s ease;
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
    
    // Auto-uppercase branch code
    branchCode.value = branchCode.value.trim().toUpperCase();
    
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
// LIVE PREVIEW
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    
    var branchCodeInput = document.getElementById('branch_code');
    var branchNameInput = document.getElementById('branch_name');
    var isActiveSelect = document.getElementById('is_active');
    
    var previewName = document.getElementById('previewName');
    var previewCode = document.getElementById('previewCode');
    var previewStatus = document.getElementById('previewStatus');
    
    function updatePreview() {
        // Update name
        if (branchNameInput && previewName) {
            var name = branchNameInput.value.trim();
            previewName.textContent = name || 'Branch Name';
        }
        
        // Update code
        if (branchCodeInput && previewCode) {
            var code = branchCodeInput.value.trim().toUpperCase();
            previewCode.textContent = code || 'CODE';
        }
        
        // Update status
        if (isActiveSelect && previewStatus) {
            var isActive = isActiveSelect.value == '1';
            if (isActive) {
                previewStatus.className = 'preview-status active';
                previewStatus.innerHTML = '<i class="fas fa-check-circle"></i> Active';
            } else {
                previewStatus.className = 'preview-status inactive';
                previewStatus.innerHTML = '<i class="fas fa-times-circle"></i> Inactive';
            }
        }
    }
    
    // Attach listeners
    if (branchCodeInput) {
        branchCodeInput.addEventListener('input', updatePreview);
    }
    if (branchNameInput) {
        branchNameInput.addEventListener('input', updatePreview);
    }
    if (isActiveSelect) {
        isActiveSelect.addEventListener('change', updatePreview);
    }
    
    // Initial update
    updatePreview();
    
    // ============================================================
    // AUTO-UPPERCASE BRANCH CODE
    // ============================================================
    if (branchCodeInput) {
        branchCodeInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
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
    
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
    
    // ============================================================
    // AUTO-HIDE ERROR ALERT
    // ============================================================
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { 
            errorAlert.style.transition = 'opacity 0.3s ease';
            errorAlert.style.opacity = '0';
            setTimeout(function() {
                errorAlert.style.display = 'none';
            }, 300);
        }, 8000);
    }
    
    console.log('=== ADD BRANCH PAGE ===');
    console.log('Ready to add new branch');
});
</script>
</body>
</html>