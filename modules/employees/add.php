<?php
// ================================================================
// FILE: modules/employees/add.php
// WAKALA FINANCIAL SYSTEM - ADD EMPLOYEE
// RED THEME + AUTO ID + REFEREES
// PROFILE PICTURE: MAX 15MB
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error_message = '';
$form_data     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_employee') {
    try {
        $db->beginTransaction();

        $full_name       = trim($_POST['full_name'] ?? '');
        $employee_code   = trim($_POST['employee_id'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $gender          = $_POST['gender'] ?? '';
        $dob             = $_POST['date_of_birth'] ?? null;
        $username        = trim($_POST['username'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $branch_id       = intval($_POST['branch_id'] ?? 0);
        $role_input      = $_POST['role'] ?? 'employee';
        $position        = trim($_POST['position'] ?? '');
        $base_salary     = floatval(str_replace(',', '', $_POST['base_salary'] ?? 0));
        $hire_date       = $_POST['hire_date'] ?? date('Y-m-d');
        $employment_stat = $_POST['employment_status'] ?? 'active';
        $emergency_name  = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $password        = $_POST['password'] ?? '';
        $password_conf   = $_POST['password_confirmation'] ?? '';

        if ($full_name === '')      throw new Exception('Full name is required.');
        if ($employee_code === '')  throw new Exception('Employee ID is required (auto-generated from branch).');
        if ($email === '')          throw new Exception('Email is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format.');
        if ($username === '')       throw new Exception('Username is required.');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            throw new Exception('Username must be 3–50 characters (letters, numbers, dot, dash, underscore only).');
        }
        if ($phone === '')          throw new Exception('Phone number is required.');
        if ($branch_id <= 0)        throw new Exception('Please select a branch.');
        if ($password === '')       throw new Exception('Password is required.');
        if (strlen($password) < 6)  throw new Exception('Password must be at least 6 characters.');
        if ($password !== $password_conf) throw new Exception('Passwords do not match.');

        if (!in_array($role_input, ['employee', 'admin', 'super_admin'])) $role_input = 'employee';
        if (!in_array($employment_stat, ['active', 'terminated', 'suspended', 'on_leave'])) $employment_stat = 'active';

        $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([$branch_id]);
        $branch_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch_row) throw new Exception('Invalid branch selected.');
        $branch_name = $branch_row['branch_name'];

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Email already exists.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_code]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Employee ID already exists. Refresh to get the latest ID.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Username already exists. Please choose another.');

        if ($phone !== '') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetchColumn() > 0) throw new Exception('Phone number already exists.');
        }

        $profile_pic_path = null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $mime    = mime_content_type($_FILES['profile_pic']['tmp_name']);

            if (!in_array($mime, $allowed)) {
                throw new Exception('Profile picture must be JPG, PNG, GIF or WEBP.');
            }

            // ⭐ 15MB LIMIT
            if ($_FILES['profile_pic']['size'] > 15 * 1024 * 1024) {
                throw new Exception('Profile picture must not exceed 15MB.');
            }

            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $filename = 'emp_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $upload_dir = '../../uploads/employees/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $target = $upload_dir . $filename;
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
                throw new Exception('Failed to upload profile picture.');
            }
            $profile_pic_path = 'uploads/employees/' . $filename;
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO employees
            (employee_id, full_name, email, phone, gender, date_of_birth,
             username, password_hash, role, branch, branch_id, profile_pic,
             position, base_salary, salary_currency, hire_date,
             employment_status, emergency_contact, emergency_phone,
             address, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $employee_code, $full_name, $email, $phone ?: null,
            $gender ?: null, $dob ?: null, $username, $password_hash,
            $role_input, $branch_name, $branch_id, $profile_pic_path,
            $position ?: null, $base_salary ?: 0, 'TSh', $hire_date,
            $employment_stat, $emergency_name ?: null, $emergency_phone ?: null,
            $address ?: null
        ]);

        $new_employee_id = $db->lastInsertId();

        $referee_names     = $_POST['referee_name']         ?? [];
        $referee_phones    = $_POST['referee_phone']        ?? [];
        $referee_relations = $_POST['referee_relationship'] ?? [];
        $referee_addresses = $_POST['referee_address']      ?? [];

        for ($i = 0; $i < count($referee_names); $i++) {
            $r_name  = trim($referee_names[$i]     ?? '');
            $r_phone = trim($referee_phones[$i]    ?? '');
            $r_rel   = trim($referee_relations[$i] ?? '');
            $r_addr  = trim($referee_addresses[$i] ?? '');

            if ($r_name === '') continue;

            $stmt = $db->prepare("
                INSERT INTO employee_referees
                (employee_id, referee_name, referee_phone, relationship, address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $new_employee_id, $r_name,
                $r_phone ?: null, $r_rel ?: null, $r_addr ?: null
            ]);
        }

        if (function_exists('logActivity')) {
            logActivity($user_id, 'Add Employee', 'Employees', $new_employee_id, '',
                'Added new employee: ' . $full_name . ' (' . $employee_code . ')');
        }

        $db->commit();
        $_SESSION['success_message'] = 'Employee "' . $full_name . '" added successfully!';
        header('Location: index.php');
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
        $form_data = $_POST;
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-plus"></i> Add New Employee</h2>
                <p class="text-muted">Fill in the employee details and referees below</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Employees
                </a>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="employeeForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="add_employee">

            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon"><i class="fas fa-user-tie"></i></div>
                        <div>
                            <h3>Employee Information</h3>
                            <p>Basic personal and work details</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-id-badge"></i> New Employee
                    </div>
                </div>

                <div class="form-card-body">

                    <div class="profile-upload-section">
                        <div class="profile-upload-preview" id="profilePreview">
                            <i class="fas fa-user"></i>
                            <img id="profilePreviewImg" style="display:none;" alt="Preview">
                        </div>
                        <div class="profile-upload-info">
                            <label class="btn-upload" for="profile_pic">
                                <i class="fas fa-camera"></i> Choose Profile Picture
                            </label>
                            <input type="file" name="profile_pic" id="profile_pic"
                                   accept="image/*" onchange="previewProfilePic(this)" style="display:none;">
                            <p class="upload-hint">JPG, PNG, GIF or WEBP · Max 15MB</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch <span class="required">*</span></label>
                            <select name="branch_id" id="branchSelect" class="form-control" required
                                    onchange="onBranchChange(this.value)">
                                <option value="">Select Branch...</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"
                                        data-code="<?php echo htmlspecialchars($b['branch_code'] ?? ''); ?>"
                                        <?php echo (intval($form_data['branch_id'] ?? 0) === intval($b['id'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                        <?php if (!empty($b['branch_code'])): ?>
                                            — <?php echo htmlspecialchars($b['branch_code']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                Employee ID <span class="required">*</span>
                                <span class="auto-badge"><i class="fas fa-magic"></i> Auto</span>
                            </label>
                            <div class="auto-id-wrapper">
                                <input type="text" name="employee_id" id="employeeIdInput"
                                       class="form-control auto-id-input"
                                       placeholder="Select branch to generate ID..."
                                       value="<?php echo htmlspecialchars($form_data['employee_id'] ?? ''); ?>"
                                       readonly required>
                                <button type="button" class="refresh-id-btn"
                                        onclick="refreshEmployeeId()" title="Regenerate ID">
                                    <i class="fas fa-sync-alt" id="refreshIdIcon"></i>
                                </button>
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Auto-generated from branch code. Format: <code>BRANCH-EMP-0001</code>
                            </p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   placeholder="e.g. John Doe"
                                   value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Username <span class="required">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   placeholder="e.g. jdoe"
                                   pattern="[a-zA-Z0-9._-]{3,50}"
                                   value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" required>
                            <p class="field-hint"><i class="fas fa-info-circle"></i> Used for login. 3–50 chars.</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   placeholder="e.g. john@example.com"
                                   value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone <span class="required">*</span></label>
                            <input type="text" name="phone" class="form-control"
                                   placeholder="+255 7XX XXX XXX"
                                   value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">Select...</option>
                                <option value="male"   <?php echo (($form_data['gender'] ?? '') === 'male')   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo (($form_data['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo (($form_data['gender'] ?? '') === 'other')  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label>Residential Address</label>
                            <input type="text" name="address" class="form-control"
                                   placeholder="e.g. Kinondoni, Dar es Salaam"
                                   value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-briefcase"></i> Work Information</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Role <span class="required">*</span></label>
                            <select name="role" class="form-control" required>
                                <option value="employee"    <?php echo (($form_data['role'] ?? 'employee') === 'employee')    ? 'selected' : ''; ?>>Employee</option>
                                <option value="admin"       <?php echo (($form_data['role'] ?? '') === 'admin')       ? 'selected' : ''; ?>>Admin</option>
                                <option value="super_admin" <?php echo (($form_data['role'] ?? '') === 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Employment Status</label>
                            <select name="employment_status" class="form-control">
                                <option value="active"     <?php echo (($form_data['employment_status'] ?? 'active') === 'active')     ? 'selected' : ''; ?>>Active</option>
                                <option value="on_leave"   <?php echo (($form_data['employment_status'] ?? '') === 'on_leave')   ? 'selected' : ''; ?>>On Leave</option>
                                <option value="suspended"  <?php echo (($form_data['employment_status'] ?? '') === 'suspended')  ? 'selected' : ''; ?>>Suspended</option>
                                <option value="terminated" <?php echo (($form_data['employment_status'] ?? '') === 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Position</label>
                            <input type="text" name="position" class="form-control"
                                   placeholder="e.g. Cashier"
                                   value="<?php echo htmlspecialchars($form_data['position'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Base Salary (TSh)</label>
                            <input type="text" name="base_salary" id="salaryInput" class="form-control"
                                   placeholder="e.g. 500,000" inputmode="numeric"
                                   value="<?php echo htmlspecialchars($form_data['base_salary'] ?? ''); ?>"
                                   oninput="formatMoneyInput(this)">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Hire Date</label>
                            <input type="date" name="hire_date" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['hire_date'] ?? date('Y-m-d')); ?>">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-phone-volume"></i> Emergency Contact</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact" class="form-control"
                                   placeholder="e.g. Mary Doe"
                                   value="<?php echo htmlspecialchars($form_data['emergency_contact'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Emergency Phone</label>
                            <input type="text" name="emergency_phone" class="form-control"
                                   placeholder="+255 7XX XXX XXX"
                                   value="<?php echo htmlspecialchars($form_data['emergency_phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-lock"></i> Account Security</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Password <span class="required">*</span></label>
                            <div class="password-wrapper">
                                <input type="password" name="password" id="password" class="form-control"
                                       placeholder="At least 6 characters" minlength="6" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Confirm Password <span class="required">*</span></label>
                            <div class="password-wrapper">
                                <input type="password" name="password_confirmation" id="password_confirmation"
                                       class="form-control" placeholder="Repeat password" minlength="6" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('password_confirmation', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <h3>Referees</h3>
                            <p>Provide at least 2 referees</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-user-check"></i> 2 Referees
                    </div>
                </div>

                <div class="form-card-body">
                    <div class="referees-grid">
                        <?php for ($r = 0; $r < 2; $r++): ?>
                        <div class="referee-card">
                            <div class="referee-card-header">
                                <div class="referee-number"><?php echo $r + 1; ?></div>
                                <h4>Referee #<?php echo $r + 1; ?></h4>
                            </div>

                            <div class="form-group">
                                <label>Full Name <?php echo $r === 0 ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="text" name="referee_name[]" class="form-control"
                                       placeholder="e.g. <?php echo $r === 0 ? 'Jane Smith' : 'Robert Johnson'; ?>"
                                       value="<?php echo htmlspecialchars($form_data['referee_name'][$r] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Phone Number <?php echo $r === 0 ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="text" name="referee_phone[]" class="form-control"
                                       placeholder="+255 7XX XXX XXX"
                                       value="<?php echo htmlspecialchars($form_data['referee_phone'][$r] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Relationship</label>
                                <div class="combo-wrapper">
                                    <input type="text" name="referee_relationship[]"
                                           class="form-control combo-input"
                                           list="relationshipList<?php echo $r; ?>"
                                           placeholder="Select or type..."
                                           autocomplete="off"
                                           value="<?php echo htmlspecialchars($form_data['referee_relationship'][$r] ?? ''); ?>">
                                    <datalist id="relationshipList<?php echo $r; ?>">
                                        <option value="Friend">
                                        <option value="Former Employer">
                                        <option value="Colleague">
                                        <option value="Teacher">
                                        <option value="Relative">
                                        <option value="Neighbor">
                                        <option value="Business Partner">
                                        <option value="Supervisor">
                                        <option value="Other">
                                    </datalist>
                                    <i class="fas fa-chevron-down combo-arrow"></i>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" name="referee_address[]" class="form-control"
                                       placeholder="e.g. <?php echo $r === 0 ? 'Mikocheni' : 'Kariakoo'; ?>"
                                       value="<?php echo htmlspecialchars($form_data['referee_address'][$r] ?? ''); ?>">
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn btn-secondary-large">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" class="btn btn-reset-large" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" class="btn btn-submit-large" id="submitBtn">
                    <i class="fas fa-save"></i> Save Employee
                </button>
            </div>

        </form>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   Same styles as before, red theme
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }

:root {
    --bg-body: #f3f4f6; --bg-card: #ffffff; --bg-input: #f9fafb;
    --text-primary: #1f2937; --text-secondary: #374151;
    --text-muted: #6b7280; --text-light: #9ca3af;
    --border-color: #e5e7eb; --shadow-color: rgba(0,0,0,0.06);
    --red-primary: #DC2626; --red-dark: #B91C1C;
}
html.dark-mode {
    --bg-body: #0f172a; --bg-card: #1e293b; --bg-input: #334155;
    --text-primary: #f1f5f9; --text-secondary: #cbd5e1;
    --text-muted: #94a3b8; --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }
.btn-back {
    padding: 10px 20px; background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color); border-radius: 10px;
    font-weight: 600; font-size: 13px;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-back:hover { background: #FEE2E2; color: var(--red-primary); border-color: var(--red-primary); transform: translateY(-2px); }

.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

.form-card { background: var(--bg-card); border-radius: 16px; border: 1.5px solid var(--border-color); margin-bottom: 20px; overflow: hidden; box-shadow: 0 4px 16px var(--shadow-color); }
.form-card-header {
    padding: 18px 26px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.form-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.form-card-header-left { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
.form-card-icon {
    width: 48px; height: 48px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.3);
}
.form-card-header h3 { font-size: 17px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.form-card-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); font-weight: 500; }
.form-card-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px;
    background: rgba(255,255,255,0.2); color: #FFFFFF;
    border-radius: 20px; font-size: 12px; font-weight: 700;
    border: 1px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.form-card-body { padding: 26px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.form-group.full-width { grid-column: 1 / -1; }
.form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.form-group label .required { color: #DC2626; }
.auto-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    color: #92400E;
    border-radius: 6px; font-size: 9px; font-weight: 800;
    letter-spacing: 0.5px; text-transform: uppercase;
    border: 1px solid #FCD34D;
}
.form-control {
    padding: 12px 16px; border: 1.5px solid var(--border-color);
    border-radius: 10px; font-size: 13px;
    color: var(--text-primary); background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease; width: 100%;
}
.form-control:focus {
    outline: none; border-color: var(--red-primary);
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.field-hint {
    font-size: 11px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 5px;
    font-weight: 500; line-height: 1.5;
}
.field-hint i { font-size: 10px; color: var(--red-primary); }
.field-hint code {
    background: var(--bg-input); padding: 1px 6px; border-radius: 4px;
    font-family: 'Courier New', monospace; font-size: 10px;
    color: var(--red-primary); border: 1px solid var(--border-color);
}
.auto-id-wrapper { display: flex; gap: 6px; align-items: stretch; }
.auto-id-input {
    flex: 1;
    font-family: 'Courier New', monospace !important;
    font-weight: 800 !important;
    font-size: 14px !important;
    letter-spacing: 1px;
    color: var(--red-primary) !important;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%) !important;
    border-color: #FCA5A5 !important;
    cursor: not-allowed;
}
.refresh-id-btn {
    padding: 0 16px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border: none; border-radius: 10px;
    cursor: pointer; font-size: 14px;
    transition: all 0.3s ease; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.refresh-id-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5); }
.refresh-id-btn.spinning i { animation: spin 0.8s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.password-wrapper { position: relative; display: flex; align-items: center; }
.password-wrapper .form-control { padding-right: 46px; }
.toggle-password {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: transparent; border: none;
    color: var(--text-muted); cursor: pointer;
    font-size: 14px; padding: 6px; border-radius: 6px;
}
.toggle-password:hover { color: var(--red-primary); background: rgba(220, 38, 38, 0.1); }

.combo-wrapper { position: relative; display: flex; align-items: center; }
.combo-input { padding-right: 42px; }
.combo-arrow {
    position: absolute; right: 16px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted); font-size: 12px;
    pointer-events: none;
}
.combo-input:focus ~ .combo-arrow { color: var(--red-primary); }

.profile-upload-section {
    display: flex; align-items: center; gap: 24px;
    padding: 20px;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border: 2px dashed #FCA5A5;
    border-radius: 14px; margin-bottom: 24px;
}
html.dark-mode .profile-upload-section {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626;
}
.profile-upload-preview {
    width: 96px; height: 96px;
    border-radius: 50%; background: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 42px; color: #FCA5A5;
    flex-shrink: 0;
    border: 3px solid #DC2626;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.2);
    overflow: hidden; position: relative;
}
.profile-upload-preview img { width: 100%; height: 100%; object-fit: cover; }
.profile-upload-info { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0; }
.btn-upload {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    cursor: pointer; transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
    align-self: flex-start;
    font-family: 'Inter', sans-serif;
}
.btn-upload:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(220, 38, 38, 0.5); }
.upload-hint { font-size: 11px; color: var(--text-muted); margin: 0; font-weight: 500; }

.section-divider { display: flex; align-items: center; gap: 12px; margin: 24px 0 18px 0; }
.section-divider::before,
.section-divider::after {
    content: ''; flex: 1; height: 1.5px;
    background: linear-gradient(90deg, transparent, var(--border-color), transparent);
}
.section-divider span {
    font-size: 12px; font-weight: 800;
    color: var(--red-primary);
    text-transform: uppercase; letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 6px;
    padding: 0 8px; white-space: nowrap;
}

.referees-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.referee-card {
    background: var(--bg-input); border: 2px solid var(--border-color);
    border-radius: 14px; padding: 20px; transition: all 0.3s ease;
}
.referee-card:hover { border-color: #FCA5A5; box-shadow: 0 6px 20px rgba(220, 38, 38, 0.1); }
.referee-card-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px; padding-bottom: 14px;
    border-bottom: 1.5px dashed var(--border-color);
}
.referee-number {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 15px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.referee-card-header h4 { margin: 0; font-size: 15px; font-weight: 800; }
.referee-card .form-group { margin-bottom: 12px; }
.referee-card .form-group:last-child { margin-bottom: 0; }
.referee-card .form-group label { font-size: 11px; }

.form-actions {
    display: flex; gap: 12px;
    padding: 22px 26px;
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap; justify-content: flex-end;
    position: sticky; bottom: 16px; z-index: 10;
}
.btn-secondary-large, .btn-reset-large, .btn-submit-large {
    padding: 13px 26px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    min-width: 140px; justify-content: center;
}
.btn-secondary-large {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary-large:hover { background: #F3F4F6; color: var(--text-primary); transform: translateY(-2px); }
.btn-reset-large {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset-large:hover { background: #FEF3C7; color: #D97706; border-color: #FDE68A; transform: translateY(-2px); }
.btn-submit-large {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-submit-large:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5); }
.btn-submit-large:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

@media (max-width: 1024px) {
    .form-row { grid-template-columns: 1fr; }
    .referees-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px; }
    .form-card-header { padding: 16px 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right .btn-back { width: 100%; justify-content: center; }
    .profile-upload-section { flex-direction: column; text-align: center; }
    .btn-upload { align-self: center; }
    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-reset-large, .btn-submit-large { width: 100%; }
}
</style>

<script>
function onBranchChange(branchId) {
    var input = document.getElementById('employeeIdInput');
    if (!branchId || branchId <= 0) {
        input.value = '';
        input.placeholder = 'Select branch to generate ID...';
        return;
    }
    input.value = 'Generating...';
    input.placeholder = 'Generating...';
    fetch('get_next_id.php?branch_id=' + encodeURIComponent(branchId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) input.value = data.employee_id;
            else { input.value = ''; input.placeholder = 'Error: ' + (data.error || 'Could not generate ID'); }
        })
        .catch(function() {
            input.value = '';
            input.placeholder = 'Network error. Click refresh.';
        });
}
function refreshEmployeeId() {
    var select = document.getElementById('branchSelect');
    var btn = document.querySelector('.refresh-id-btn');
    if (!select.value) { alert('Please select a branch first.'); return; }
    btn.classList.add('spinning');
    onBranchChange(select.value);
    setTimeout(function() { btn.classList.remove('spinning'); }, 1000);
}
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('branchSelect');
    var input  = document.getElementById('employeeIdInput');
    if (select && select.value && (!input.value || input.value.trim() === '')) {
        onBranchChange(select.value);
    }
});
function previewProfilePic(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('profilePreviewImg');
            var placeholder = document.querySelector('#profilePreview i');
            img.src = e.target.result;
            img.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function togglePassword(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (!field) return;
    if (field.type === 'password') { field.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
    else { field.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
}
function formatMoneyInput(input) {
    var cursorPos = input.selectionStart;
    var oldLength = input.value.length;
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    var formatted = '', count = 0;
    for (var i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    var newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}
function validateForm() {
    var fullName = document.querySelector('input[name="full_name"]').value.trim();
    var empId    = document.getElementById('employeeIdInput').value.trim();
    var email    = document.querySelector('input[name="email"]').value.trim();
    var username = document.querySelector('input[name="username"]').value.trim();
    var phone    = document.querySelector('input[name="phone"]').value.trim();
    var branch   = document.getElementById('branchSelect').value;
    var password = document.getElementById('password').value;
    var passwordConf = document.getElementById('password_confirmation').value;
    if (branch === '')       { alert('Please select a branch.'); return false; }
    if (empId === '' || empId === 'Generating...') { alert('Employee ID is still generating. Please wait or click refresh.'); return false; }
    if (fullName === '')     { alert('Please enter the full name.'); return false; }
    if (username === '')     { alert('Please enter the username.'); return false; }
    if (email === '')        { alert('Please enter the email.'); return false; }
    if (phone === '')        { alert('Please enter the phone number.'); return false; }
    if (password.length < 6) { alert('Password must be at least 6 characters.'); return false; }
    if (password !== passwordConf) { alert('Passwords do not match.'); return false; }
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    return true;
}
function resetForm() {
    if (!confirm('Clear all fields?')) return;
    document.getElementById('employeeForm').reset();
    var img = document.getElementById('profilePreviewImg');
    var placeholder = document.querySelector('#profilePreview i');
    img.style.display = 'none'; img.src = '';
    if (placeholder) placeholder.style.display = 'block';
    document.getElementById('employeeIdInput').value = '';
    document.getElementById('employeeIdInput').placeholder = 'Select branch to generate ID...';
}
</script>

</body>
</html>