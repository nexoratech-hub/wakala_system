<?php
// ================================================================
// FILE: modules/employees/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT EMPLOYEE
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
// GET EMPLOYEE ID
// ============================================================
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
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
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_employee') {
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $base_salary = floatval(str_replace(',', '', $_POST['base_salary'] ?? 0));
        $hire_date = $_POST['hire_date'] ?? date('Y-m-d');
        $employment_status = $_POST['employment_status'] ?? 'active';
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $is_active = intval($_POST['is_active'] ?? 1);
        
        // Validate
        if (empty($full_name)) {
            throw new Exception('Please enter full name.');
        }
        if (empty($email)) {
            throw new Exception('Please enter email.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        if (empty($username)) {
            throw new Exception('Please enter username.');
        }
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        // Check if email already exists (excluding current)
        $check_stmt = $db->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
        $check_stmt->execute([$email, $employee_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Email "' . $email . '" already exists. Please use a different email.');
        }
        
        // Check if username already exists (excluding current)
        $check_stmt = $db->prepare("SELECT id FROM employees WHERE username = ? AND id != ?");
        $check_stmt->execute([$username, $employee_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Username "' . $username . '" already exists. Please use a different username.');
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // Update query
        $sql = "UPDATE employees SET 
            full_name = ?, email = ?, phone = ?, username = ?, role = ?, 
            branch = ?, branch_id = ?, base_salary = ?, hire_date = ?, 
            employment_status = ?, address = ?, emergency_contact = ?, 
            emergency_phone = ?, is_active = ?";
        
        $params = [
            $full_name,
            $email,
            $phone,
            $username,
            $role,
            $branch_name,
            $branch_id,
            $base_salary,
            $hire_date,
            $employment_status,
            $address,
            $emergency_contact,
            $emergency_phone,
            $is_active
        ];
        
        // If password is provided, update it
        if (!empty($password)) {
            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters.');
            }
            $sql .= ", password_hash = ?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $employee_id;
        
        $update_stmt = $db->prepare($sql);
        $update_stmt->execute($params);
        
        logActivity($user_id, 'Edit Employee', 'Employees', $employee_id, '', 'Updated employee: ' . $full_name);
        
        $success_message = 'Employee "' . $full_name . '" updated successfully!';
        $show_success = true;
        
        // Refresh employee data
        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
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
                <h2><i class="fas fa-user-edit"></i> Edit Employee</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($employee['full_name']); ?></span>
                <span class="employee-id-badge"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="view.php?id=<?php echo $employee_id; ?>" class="btn btn-view">
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
            <form method="POST" action="" class="main-form" id="employeeForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_employee">
                
                <!-- ===== PERSONAL INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full Name <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($employee['full_name']); ?>" 
                                       class="form-control" placeholder="e.g., John Doe" required>
                            </div>
                            <small>Enter the employee's full name</small>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($employee['email']); ?>" 
                                       class="form-control" placeholder="e.g., john@wakala.com" required>
                            </div>
                            <small>Official email address for the employee</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., +255 700 000 000">
                            </div>
                            <small>Primary contact number</small>
                        </div>
                        <div class="form-group">
                            <label for="address">Physical Address</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                                <input type="text" id="address" name="address" 
                                       value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., Dar es Salaam, Tanzania">
                            </div>
                            <small>Residential address</small>
                        </div>
                    </div>
                </div>

                <!-- ===== ACCOUNT INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-key"></i> Account Information</h3>
                        <span class="section-badge">Change password to update</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                                <input type="text" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($employee['username']); ?>" 
                                       class="form-control" placeholder="e.g., johndoe" required>
                            </div>
                            <small>Unique username for login</small>
                        </div>
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-lock"></i></span>
                                <input type="password" id="password" name="password" 
                                       class="form-control" placeholder="Leave blank to keep current password">
                            </div>
                            <small>Leave blank to keep current password. Minimum 6 characters if changing.</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role">User Role <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-shield"></i></span>
                                <select id="role" name="role" class="form-control" required>
                                    <option value="employee" <?php echo ($employee['role'] == 'employee') ? 'selected' : ''; ?>>Employee</option>
                                    <option value="admin" <?php echo ($employee['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    <?php if ($role == 'super_admin'): ?>
                                        <option value="super_admin" <?php echo ($employee['role'] == 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <small>Determines access level in the system</small>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Assigned Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php echo ($employee['branch_id'] == $b['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small>Branch where the employee works</small>
                        </div>
                    </div>
                </div>

                <!-- ===== EMPLOYMENT INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="base_salary">Base Salary</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill"></i></span>
                                <input type="text" id="base_salary" name="base_salary" 
                                       value="<?php echo number_format($employee['base_salary'] ?? 0, 0, '.', ','); ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this)">
                            </div>
                            <small>Monthly base salary amount</small>
                        </div>
                        <div class="form-group">
                            <label for="hire_date">Hire Date</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="hire_date" name="hire_date" 
                                       value="<?php echo $employee['hire_date'] ?? date('Y-m-d'); ?>" 
                                       class="form-control">
                            </div>
                            <small>Date when employee was hired</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employment_status">Employment Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user-check"></i></span>
                                <select id="employment_status" name="employment_status" class="form-control">
                                    <option value="active" <?php echo ($employee['employment_status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="on_leave" <?php echo ($employee['employment_status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                                    <option value="suspended" <?php echo ($employee['employment_status'] == 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="terminated" <?php echo ($employee['employment_status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                                </select>
                            </div>
                            <small>Current employment status</small>
                        </div>
                        <div class="form-group">
                            <label for="is_active">Account Status</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-power-off"></i></span>
                                <select id="is_active" name="is_active" class="form-control">
                                    <option value="1" <?php echo ($employee['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo ($employee['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <small>Active accounts can login to the system</small>
                        </div>
                    </div>
                </div>

                <!-- ===== EMERGENCY CONTACT ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                        <span class="section-badge">Optional</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_contact">Emergency Contact Name</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" id="emergency_contact" name="emergency_contact" 
                                       value="<?php echo htmlspecialchars($employee['emergency_contact'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., Jane Doe">
                            </div>
                            <small>Name of the emergency contact person</small>
                        </div>
                        <div class="form-group">
                            <label for="emergency_phone">Emergency Phone</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" id="emergency_phone" name="emergency_phone" 
                                       value="<?php echo htmlspecialchars($employee['emergency_phone'] ?? ''); ?>" 
                                       class="form-control" placeholder="e.g., +255 700 000 111">
                            </div>
                            <small>Phone number of the emergency contact</small>
                        </div>
                    </div>
                </div>

                <!-- ===== SUMMARY / INFO ===== -->
                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Additional Information</h3>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-id-card"></i> Employee ID</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Created</span>
                            <span class="info-value"><?php echo date('d M Y H:i', strtotime($employee['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                            <span class="info-value"><?php echo date('d M Y H:i', strtotime($employee['updated_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-sign-in-alt"></i> Last Login</span>
                            <span class="info-value"><?php echo $employee['last_login'] ? date('d M Y H:i', strtotime($employee['last_login'])) : 'Never'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Employee
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

.employee-id-badge {
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
   MONEY INPUT STYLES
   ============================================================ */
.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

.money-input:focus {
    border-color: #3B82F6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
}

/* ============================================================
   SUMMARY / INFO GRID
   ============================================================ */
.summary-section {
    background: var(--form-hover);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px 14px;
    background: var(--form-card-bg);
    border-radius: 8px;
    border: 1px solid var(--form-border);
    transition: all 0.3s ease;
}

.info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--form-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
}

.info-label i {
    margin-right: 4px;
}

.info-value {
    font-size: 14px;
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
    
    .info-grid {
        grid-template-columns: 1fr;
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
    
    .employee-id-badge {
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
    
    .info-item {
        padding: 6px 12px;
    }
    
    .info-value {
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
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    // Remove all non-digit characters except decimal point
    var value = input.value.replace(/[^0-9.]/g, '');
    
    // Split by decimal point
    var parts = value.split('.');
    var integerPart = parts[0] || '';
    var decimalPart = parts[1] || '';
    
    // Format integer part with commas
    if (integerPart.length > 0) {
        integerPart = parseInt(integerPart).toLocaleString('en-US');
    }
    
    // Limit decimal to 2 places
    if (decimalPart.length > 2) {
        decimalPart = decimalPart.substring(0, 2);
    }
    
    // Reconstruct the value
    var formatted = integerPart;
    if (decimalPart.length > 0) {
        formatted += '.' + decimalPart;
    }
    
    input.value = formatted;
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var fullName = document.getElementById('full_name');
    if (!fullName || fullName.value.trim() === '') {
        alert('Please enter full name.');
        if (fullName) fullName.focus();
        return false;
    }
    
    var email = document.getElementById('email');
    if (!email || email.value.trim() === '') {
        alert('Please enter email.');
        if (email) email.focus();
        return false;
    }
    
    // Validate email format
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email.value.trim())) {
        alert('Please enter a valid email address.');
        email.focus();
        return false;
    }
    
    var username = document.getElementById('username');
    if (!username || username.value.trim() === '') {
        alert('Please enter username.');
        if (username) username.focus();
        return false;
    }
    
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '') {
        alert('Please select a branch.');
        if (branch) branch.focus();
        return false;
    }
    
    var password = document.getElementById('password');
    if (password && password.value.trim() !== '' && password.value.length < 6) {
        alert('Password must be at least 6 characters.');
        password.focus();
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