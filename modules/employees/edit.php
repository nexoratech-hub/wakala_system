<?php
// ================================================================
// FILE: modules/employees/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT EMPLOYEE
// RED THEME + AUTO ID + EDITABLE REFEREES
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

// ============================================================
// GET EMPLOYEE ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid employee ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH EMPLOYEE
// ============================================================
$stmt = $db->prepare("
    SELECT e.*,
           b.branch_name AS branch_display,
           b.branch_code AS branch_display_code
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['error_message'] = 'Employee not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// LOAD BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// LOAD REFEREES (existing)
// ============================================================
$referees = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM employee_referees
        WHERE employee_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$id]);
    $referees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $referees = [];
}

// ============================================================
// HANDLE UPDATE
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_employee') {
    try {
        $db->beginTransaction();

        // ---------- INPUT ----------
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
        $new_password    = $_POST['password'] ?? '';
        $password_conf   = $_POST['password_confirmation'] ?? '';

        // ---------- VALIDATION ----------
        if ($full_name === '')      throw new Exception('Full name is required.');
        if ($employee_code === '')  throw new Exception('Employee ID is required.');
        if ($email === '')          throw new Exception('Email is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format.');
        if ($username === '')       throw new Exception('Username is required.');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            throw new Exception('Username must be 3–50 characters (letters, numbers, dot, dash, underscore only).');
        }
        if ($phone === '')          throw new Exception('Phone number is required.');
        if ($branch_id <= 0)        throw new Exception('Please select a branch.');

        if ($new_password !== '' && strlen($new_password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }
        if ($new_password !== '' && $new_password !== $password_conf) {
            throw new Exception('Passwords do not match.');
        }

        if (!in_array($role_input, ['employee', 'admin', 'super_admin'])) {
            $role_input = 'employee';
        }
        if (!in_array($employment_stat, ['active', 'terminated', 'suspended', 'on_leave'])) {
            $employment_stat = 'active';
        }

        // ---------- BRANCH NAME ----------
        $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([$branch_id]);
        $branch_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch_row) throw new Exception('Invalid branch selected.');
        $branch_name = $branch_row['branch_name'];

        // ---------- UNIQUENESS (skip self) ----------
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Email already used by another employee.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ? AND id != ?");
        $stmt->execute([$employee_code, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Employee ID already used.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Username already used by another employee.');

        if ($phone !== '') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $id]);
            if ($stmt->fetchColumn() > 0) throw new Exception('Phone number already used.');
        }

        // ---------- PROFILE PICTURE ----------
        $profile_pic_path = $employee['profile_pic'];

        // Remove existing picture
        if (!empty($_POST['remove_profile_pic']) && $_POST['remove_profile_pic'] === '1') {
            if (!empty($profile_pic_path) && file_exists('../../' . $profile_pic_path)) {
                @unlink('../../' . $profile_pic_path);
            }
            $profile_pic_path = null;
        }

        // New picture uploaded
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $mime    = mime_content_type($_FILES['profile_pic']['tmp_name']);

            if (!in_array($mime, $allowed)) {
                throw new Exception('Profile picture must be JPG, PNG, GIF or WEBP.');
            }
            if ($_FILES['profile_pic']['size'] > 3 * 1024 * 1024) {
                throw new Exception('Profile picture must not exceed 3MB.');
            }

            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $filename = 'emp_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $upload_dir = '../../uploads/employees/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $target = $upload_dir . $filename;
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
                throw new Exception('Failed to upload profile picture.');
            }

            // Delete old
            if (!empty($employee['profile_pic']) && file_exists('../../' . $employee['profile_pic'])) {
                @unlink('../../' . $employee['profile_pic']);
            }

            $profile_pic_path = 'uploads/employees/' . $filename;
        }

        // ---------- BUILD UPDATE ----------
        $sql = "
            UPDATE employees SET
                employee_id = ?,
                full_name = ?,
                email = ?,
                phone = ?,
                gender = ?,
                date_of_birth = ?,
                username = ?,
                role = ?,
                branch = ?,
                branch_id = ?,
                profile_pic = ?,
                position = ?,
                base_salary = ?,
                salary_currency = ?,
                hire_date = ?,
                employment_status = ?,
                emergency_contact = ?,
                emergency_phone = ?,
                address = ?,
                updated_at = NOW()
        ";
        $params = [
            $employee_code,
            $full_name,
            $email,
            $phone ?: null,
            $gender ?: null,
            $dob ?: null,
            $username,
            $role_input,
            $branch_name,
            $branch_id,
            $profile_pic_path,
            $position ?: null,
            $base_salary ?: 0,
            'TSh',
            $hire_date,
            $employment_stat,
            $emergency_name ?: null,
            $emergency_phone ?: null,
            $address ?: null
        ];

        // Password change (only if provided)
        if ($new_password !== '') {
            $sql .= ", password_hash = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // ---------- UPDATE REFEREES ----------
        // Strategy: delete all existing, re-insert from form.
        // This is simpler and safer for a 2-referee UI.
        $stmt = $db->prepare("DELETE FROM employee_referees WHERE employee_id = ?");
        $stmt->execute([$id]);

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
                $id,
                $r_name,
                $r_phone ?: null,
                $r_rel ?: null,
                $r_addr ?: null
            ]);
        }

        // ---------- LOG ----------
        if (function_exists('logActivity')) {
            logActivity(
                $user_id,
                'Edit Employee',
                'Employees',
                $id,
                $employee['employee_id'],
                'Updated employee: ' . $full_name . ' (' . $employee_code . ')'
            );
        }

        $db->commit();

        $_SESSION['success_message'] = 'Employee "' . $full_name . '" updated successfully!';
        header('Location: view.php?id=' . $id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();

        // Reload employee from POST (so changes aren't lost visually)
        // For simplicity, just re-fetch from DB
        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ============================================================
// DERIVED VALUES
// ============================================================
$initial    = strtoupper(substr($employee['full_name'] ?? 'N', 0, 1));
$avatar     = $employee['profile_pic'] ?? '';
$has_avatar = !empty($avatar) && file_exists('../../' . $avatar);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-edit"></i> Edit Employee</h2>
                <p class="text-muted">
                    <i class="fas fa-id-badge"></i>
                    <?php echo htmlspecialchars($employee['employee_id'] ?? '-'); ?>
                    &nbsp;·&nbsp;
                    <?php echo htmlspecialchars($employee['full_name'] ?? '-'); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-back">
                    <i class="fas fa-eye"></i> View
                </a>
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
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
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" name="remove_profile_pic" id="removeProfilePicInput" value="0">

            <!-- ============================================================
            MAIN CARD
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <h3>Employee Information</h3>
                            <p>Update personal and work details</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-pen-to-square"></i> Editing
                    </div>
                </div>

                <div class="form-card-body">

                    <!-- Profile Picture -->
                    <div class="profile-upload-section">
                        <div class="profile-upload-preview" id="profilePreview">
                            <?php if ($has_avatar): ?>
                                <img id="profilePreviewImg" src="../../<?php echo htmlspecialchars($avatar); ?>" alt="Current">
                                <i class="fas fa-user" style="display:none;"></i>
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                                <img id="profilePreviewImg" src="" style="display:none;" alt="Preview">
                            <?php endif; ?>
                        </div>
                        <div class="profile-upload-info">
                            <div class="profile-upload-buttons">
                                <label class="btn-upload" for="profile_pic">
                                    <i class="fas fa-camera"></i> Change Picture
                                </label>
                                <input type="file" name="profile_pic" id="profile_pic"
                                       accept="image/*" onchange="previewProfilePic(this)" style="display:none;">
                                <?php if ($has_avatar): ?>
                                    <button type="button" class="btn-remove-pic" onclick="removeProfilePic()">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                <?php endif; ?>
                            </div>
                            <p class="upload-hint">JPG, PNG, GIF or WEBP · Max 3MB</p>
                        </div>
                    </div>

                    <!-- Row: Branch + Employee ID -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch <span class="required">*</span></label>
                            <select name="branch_id" id="branchSelect" class="form-control" required>
                                <option value="">Select Branch...</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"
                                        <?php echo intval($employee['branch_id']) === intval($b['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                        <?php if (!empty($b['branch_code'])): ?>
                                            — <?php echo htmlspecialchars($b['branch_code']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Changing branch does not change the Employee ID automatically.
                            </p>
                        </div>

                        <div class="form-group">
                            <label>Employee ID <span class="required">*</span></label>
                            <input type="text" name="employee_id" id="employeeIdInput"
                                   class="form-control auto-id-input"
                                   value="<?php echo htmlspecialchars($employee['employee_id']); ?>"
                                   readonly required>
                            <p class="field-hint">
                                <i class="fas fa-lock"></i>
                                Employee ID is locked. Contact admin to change.
                            </p>
                        </div>
                    </div>

                    <!-- Row: Full Name + Username -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['full_name']); ?>"
                                   placeholder="e.g. John Doe" required>
                        </div>
                        <div class="form-group">
                            <label>Username <span class="required">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['username']); ?>"
                                   pattern="[a-zA-Z0-9._-]{3,50}"
                                   title="3–50 characters (letters, numbers, dot, dash, underscore only)"
                                   placeholder="e.g. jdoe" required>
                        </div>
                    </div>

                    <!-- Row: Email + Phone -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['email']); ?>"
                                   placeholder="e.g. john@example.com" required>
                        </div>
                        <div class="form-group">
                            <label>Phone <span class="required">*</span></label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>"
                                   placeholder="+255 7XX XXX XXX" required>
                        </div>
                    </div>

                    <!-- Row: Gender + DOB -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">Select...</option>
                                <option value="male"   <?php echo ($employee['gender'] ?? '') === 'male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($employee['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo ($employee['gender'] ?? '') === 'other'  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['date_of_birth'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Row: Address -->
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label>Residential Address</label>
                            <input type="text" name="address" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>"
                                   placeholder="e.g. Kinondoni, Dar es Salaam">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-briefcase"></i> Work Information</span>
                    </div>

                    <!-- Row: Role + Employment Status -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role <span class="required">*</span></label>
                            <select name="role" class="form-control" required>
                                <option value="employee"    <?php echo ($employee['role'] ?? '') === 'employee'    ? 'selected' : ''; ?>>Employee</option>
                                <option value="admin"       <?php echo ($employee['role'] ?? '') === 'admin'       ? 'selected' : ''; ?>>Admin</option>
                                <option value="super_admin" <?php echo ($employee['role'] ?? '') === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Employment Status</label>
                            <select name="employment_status" class="form-control">
                                <option value="active"     <?php echo ($employee['employment_status'] ?? 'active') === 'active'     ? 'selected' : ''; ?>>Active</option>
                                <option value="on_leave"   <?php echo ($employee['employment_status'] ?? '') === 'on_leave'   ? 'selected' : ''; ?>>On Leave</option>
                                <option value="suspended"  <?php echo ($employee['employment_status'] ?? '') === 'suspended'  ? 'selected' : ''; ?>>Suspended</option>
                                <option value="terminated" <?php echo ($employee['employment_status'] ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row: Position + Salary -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Position</label>
                            <input type="text" name="position" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['position'] ?? ''); ?>"
                                   placeholder="e.g. Cashier">
                        </div>
                        <div class="form-group">
                            <label>Base Salary (TSh)</label>
                            <input type="text" name="base_salary" id="salaryInput" class="form-control"
                                   value="<?php echo !empty($employee['base_salary']) ? number_format($employee['base_salary']) : ''; ?>"
                                   placeholder="e.g. 500,000"
                                   inputmode="numeric"
                                   oninput="formatMoneyInput(this)">
                        </div>
                    </div>

                    <!-- Row: Hire Date -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Hire Date</label>
                            <input type="date" name="hire_date" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['hire_date'] ?? date('Y-m-d')); ?>">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-phone-volume"></i> Emergency Contact</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['emergency_contact'] ?? ''); ?>"
                                   placeholder="e.g. Mary Doe">
                        </div>
                        <div class="form-group">
                            <label>Emergency Phone</label>
                            <input type="text" name="emergency_phone" class="form-control"
                                   value="<?php echo htmlspecialchars($employee['emergency_phone'] ?? ''); ?>"
                                   placeholder="+255 7XX XXX XXX">
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-lock"></i> Change Password (Optional)</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>New Password</label>
                            <div class="password-wrapper">
                                <input type="password" name="password" id="password" class="form-control"
                                       placeholder="Leave blank to keep current" minlength="6">
                                <button type="button" class="toggle-password" onclick="togglePassword('password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Only fill this if you want to change the password.
                            </p>
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" name="password_confirmation" id="password_confirmation"
                                       class="form-control" placeholder="Repeat new password" minlength="6">
                                <button type="button" class="toggle-password" onclick="togglePassword('password_confirmation', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ============================================================
            REFEREES CARD
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <h3>Referees</h3>
                            <p>Update referees for this employee</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-user-check"></i> 2 Referees
                    </div>
                </div>

                <div class="form-card-body">
                    <div class="referees-grid">

                        <?php
                        // Ensure we always render exactly 2 referee cards
                        for ($r = 0; $r < 2; $r++):
                            $ref = $referees[$r] ?? null;
                        ?>
                        <div class="referee-card">
                            <div class="referee-card-header">
                                <div class="referee-number"><?php echo $r + 1; ?></div>
                                <h4>Referee #<?php echo $r + 1; ?></h4>
                            </div>

                            <div class="form-group">
                                <label>Full Name <?php echo $r === 0 ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="text" name="referee_name[]" class="form-control"
                                       placeholder="e.g. <?php echo $r === 0 ? 'Jane Smith' : 'Robert Johnson'; ?>"
                                       value="<?php echo htmlspecialchars($ref['referee_name'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Phone Number <?php echo $r === 0 ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="text" name="referee_phone[]" class="form-control"
                                       placeholder="+255 7XX XXX XXX"
                                       value="<?php echo htmlspecialchars($ref['referee_phone'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Relationship</label>
                                <div class="combo-wrapper">
                                    <input type="text"
                                           name="referee_relationship[]"
                                           class="form-control combo-input"
                                           list="relationshipList<?php echo $r; ?>"
                                           placeholder="Select or type..."
                                           autocomplete="off"
                                           value="<?php echo htmlspecialchars($ref['relationship'] ?? ''); ?>">
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
                                       placeholder="e.g. <?php echo $r === 0 ? 'Mikocheni, Dar es Salaam' : 'Kariakoo, Dar es Salaam'; ?>"
                                       value="<?php echo htmlspecialchars($ref['address'] ?? ''); ?>">
                            </div>
                        </div>
                        <?php endfor; ?>

                    </div>
                </div>
            </div>

            <!-- FORM ACTIONS -->
            <div class="form-actions">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary-large">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="reset" class="btn btn-reset-large" onclick="return confirmReset(event)">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" class="btn btn-submit-large" id="submitBtn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>

        </form>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important; padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0;
}
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
}
.page-header .header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn {
    padding: 10px 20px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-back {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-back:hover {
    background: #FEF2F2; color: var(--red-primary);
    border-color: var(--red-primary); transform: translateY(-2px);
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* FORM CARD */
.form-card {
    background: var(--bg-card);
    border-radius: 16px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 4px 16px var(--shadow-color);
}
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
    backdrop-filter: blur(8px);
}
.form-card-header h3 { font-size: 17px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.form-card-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); font-weight: 500; }
.form-card-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px;
    background: rgba(255,255,255,0.2);
    color: #FFFFFF; border-radius: 20px;
    font-size: 12px; font-weight: 700;
    border: 1px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.form-card-body { padding: 26px; }

/* FORM ROWS */
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

.form-control {
    padding: 12px 16px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.form-control:focus {
    outline: none; border-color: var(--red-primary);
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.form-control::placeholder { color: var(--text-light); font-size: 12px; }
.form-control[readonly] {
    background: #F3F4F6;
    color: var(--text-muted);
    cursor: not-allowed;
}
html.dark-mode .form-control[readonly] { background: #1e293b; }

.field-hint {
    font-size: 11px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 5px;
    font-weight: 500; line-height: 1.5;
}
.field-hint i { font-size: 10px; color: var(--red-primary); }

.auto-id-input {
    font-family: 'Courier New', monospace !important;
    font-weight: 800 !important;
    font-size: 14px !important;
    letter-spacing: 1px;
    color: var(--red-primary) !important;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%) !important;
    border-color: #FCA5A5 !important;
}
html.dark-mode .auto-id-input {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%) !important;
    border-color: #DC2626 !important;
    color: #FCA5A5 !important;
}

/* PASSWORD */
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

/* COMBO */
.combo-wrapper { position: relative; display: flex; align-items: center; }
.combo-input { padding-right: 42px; }
.combo-arrow {
    position: absolute; right: 16px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted); font-size: 12px;
    pointer-events: none;
}
.combo-input:focus ~ .combo-arrow { color: var(--red-primary); }

/* PROFILE UPLOAD */
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
    width: 110px; height: 110px;
    border-radius: 50%; background: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 46px; color: #FCA5A5;
    flex-shrink: 0;
    border: 4px solid #DC2626;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.2);
    overflow: hidden; position: relative;
}
.profile-upload-preview img {
    width: 100%; height: 100%; object-fit: cover;
}
html.dark-mode .profile-upload-preview { background: #1e293b; }
.profile-upload-info {
    display: flex; flex-direction: column; gap: 8px;
    flex: 1; min-width: 0;
}
.profile-upload-buttons {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
}
.btn-upload {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-radius: 10px;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
    font-family: 'Inter', sans-serif;
}
.btn-upload:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(220, 38, 38, 0.5); }
.btn-remove-pic {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px;
    background: #FFFFFF;
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
    border-radius: 10px;
    font-size: 12px; font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-remove-pic:hover {
    background: #FEE2E2;
    border-color: #DC2626;
    transform: translateY(-2px);
}
html.dark-mode .btn-remove-pic { background: #1e293b; }
.upload-hint { font-size: 11px; color: var(--text-muted); margin: 0; font-weight: 500; }

/* SECTION DIVIDER */
.section-divider {
    display: flex; align-items: center; gap: 12px;
    margin: 24px 0 18px 0;
}
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
.section-divider span i { font-size: 13px; }

/* REFEREES */
.referees-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.referee-card {
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 14px; padding: 20px;
    transition: all 0.3s ease;
}
.referee-card:hover {
    border-color: #FCA5A5;
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.1);
}
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

/* FORM ACTIONS */
.form-actions {
    display: flex; gap: 12px;
    padding: 22px 26px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap; justify-content: flex-end;
    position: sticky; bottom: 16px; z-index: 10;
}
.btn-secondary-large, .btn-reset-large, .btn-submit-large {
    padding: 13px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    min-width: 140px; justify-content: center;
}
.btn-secondary-large {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary-large:hover {
    background: #F3F4F6; color: var(--text-primary);
    transform: translateY(-2px);
}
html.dark-mode .btn-secondary-large:hover { background: #334155; }
.btn-reset-large {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset-large:hover {
    background: #FEF3C7; color: #D97706;
    border-color: #FDE68A; transform: translateY(-2px);
}
html.dark-mode .btn-reset-large:hover {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.btn-submit-large {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-submit-large:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5); }
.btn-submit-large:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* RESPONSIVE */
@media (max-width: 1024px) {
    .form-row { grid-template-columns: 1fr; }
    .referees-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px; }
    .form-card-header { padding: 16px 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }
    .profile-upload-section { flex-direction: column; text-align: center; }
    .profile-upload-buttons { justify-content: center; }
    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-reset-large, .btn-submit-large { width: 100%; }
}
@media (max-width: 480px) {
    .profile-upload-preview { width: 90px; height: 90px; font-size: 36px; }
    .form-card-header h3 { font-size: 15px; }
    .referee-card { padding: 16px; }
    .form-actions { padding: 14px; }
}
</style>

<script>
// ============================================================
// PROFILE PICTURE PREVIEW
// ============================================================
function previewProfilePic(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('profilePreviewImg');
            var placeholder = document.querySelector('#profilePreview i');
            img.src = e.target.result;
            img.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';

            // Reset "remove" flag if user picks a new pic
            document.getElementById('removeProfilePicInput').value = '0';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removeProfilePic() {
    if (!confirm('Remove profile picture?')) return;

    document.getElementById('removeProfilePicInput').value = '1';

    var img = document.getElementById('profilePreviewImg');
    var placeholder = document.querySelector('#profilePreview i');
    img.style.display = 'none';
    img.src = '';
    if (placeholder) placeholder.style.display = 'block';
}

// ============================================================
// PASSWORD TOGGLE
// ============================================================
function togglePassword(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (!field) return;

    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// ============================================================
// MONEY INPUT
// ============================================================
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

// ============================================================
// VALIDATION
// ============================================================
function validateForm() {
    var fullName = document.querySelector('input[name="full_name"]').value.trim();
    var email    = document.querySelector('input[name="email"]').value.trim();
    var username = document.querySelector('input[name="username"]').value.trim();
    var phone    = document.querySelector('input[name="phone"]').value.trim();
    var branch   = document.getElementById('branchSelect').value;
    var password = document.getElementById('password').value;
    var passwordConf = document.getElementById('password_confirmation').value;

    if (branch === '')       { alert('Please select a branch.'); return false; }
    if (fullName === '')     { alert('Please enter the full name.'); return false; }
    if (username === '')     { alert('Please enter the username.'); return false; }
    if (email === '')        { alert('Please enter the email.'); return false; }
    if (phone === '')        { alert('Please enter the phone number.'); return false; }

    // Password is OPTIONAL — only validate if provided
    if (password !== '' || passwordConf !== '') {
        if (password.length < 6) { alert('Password must be at least 6 characters.'); return false; }
        if (password !== passwordConf) { alert('Passwords do not match.'); return false; }
    }

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    return true;
}

function confirmReset(e) {
    if (!confirm('Reset all changes to the original values?')) {
        e.preventDefault();
        return false;
    }
    setTimeout(function() { window.location.reload(); }, 100);
    return true;
}
</script>

</body>
</html>