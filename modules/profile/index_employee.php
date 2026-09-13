<?php
// ================================================================
// FILE: modules/profile/index_employee.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEE PROFILE
// RED THEME + EDIT INFO (NO CHANGE PASSWORD)
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

// ============================================================
// FETCH CURRENT USER
// ============================================================
$stmt = $db->prepare("
    SELECT e.*,
           b.branch_name AS branch_display,
           b.branch_code AS branch_display_code
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../../login.php');
    exit();
}

// ============================================================
// HANDLE: UPDATE PROFILE INFO
// ============================================================
$error_message   = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    try {
        $db->beginTransaction();

        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $gender    = $_POST['gender'] ?? '';
        $dob       = $_POST['date_of_birth'] ?? null;
        $address   = trim($_POST['address'] ?? '');
        $emergency_name  = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');

        if ($full_name === '') throw new Exception('Full name is required.');
        if ($email === '')     throw new Exception('Email is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format.');
        if ($phone === '')     throw new Exception('Phone number is required.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Email already used by another account.');

        if ($phone !== '') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $user_id]);
            if ($stmt->fetchColumn() > 0) throw new Exception('Phone number already used.');
        }

        $profile_pic_path = $user['profile_pic'];

        // Remove existing picture
        if (($_POST['remove_profile_pic'] ?? '0') === '1') {
            if (!empty($profile_pic_path) && file_exists('../../' . $profile_pic_path)) {
                @unlink('../../' . $profile_pic_path);
            }
            $profile_pic_path = null;
        }

        // Upload new picture — 15MB max
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $mime = mime_content_type($_FILES['profile_pic']['tmp_name']);

            if (!in_array($mime, $allowed)) {
                throw new Exception('Picture must be JPG, PNG, GIF or WEBP.');
            }

            // ⭐ 15MB LIMIT
            if ($_FILES['profile_pic']['size'] > 15 * 1024 * 1024) {
                throw new Exception('Picture must not exceed 15MB.');
            }

            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $upload_dir = '../../uploads/employees/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $target = $upload_dir . $filename;
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
                throw new Exception('Failed to upload profile picture.');
            }

            if (!empty($user['profile_pic']) && file_exists('../../' . $user['profile_pic'])) {
                @unlink('../../' . $user['profile_pic']);
            }

            $profile_pic_path = 'uploads/employees/' . $filename;
        }

        $stmt = $db->prepare("
            UPDATE employees SET
                full_name = ?, email = ?, phone = ?,
                gender = ?, date_of_birth = ?, address = ?,
                emergency_contact = ?, emergency_phone = ?,
                profile_pic = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $full_name, $email, $phone,
            $gender ?: null, $dob ?: null, $address ?: null,
            $emergency_name ?: null, $emergency_phone ?: null,
            $profile_pic_path, $user_id
        ]);

        if (function_exists('logActivity')) {
            logActivity($user_id, 'Update Profile', 'Profile', $user_id, '', 'Updated own profile');
        }

        $db->commit();

        $_SESSION['success_message'] = 'Profile updated successfully!';
        header('Location: index_employee.php');
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();

        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ============================================================
// FETCH ACTIVITY LOG
// ============================================================
$activities = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM activity_logs
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 8
    ");
    $stmt->execute([$user_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activities = [];
}

// ============================================================
// STATS
// ============================================================
$total_activities = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_activities = intval($stmt->fetchColumn());
} catch (Exception $e) {}

// ============================================================
// FLASH MESSAGES
// ============================================================
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// ============================================================
// DERIVED VALUES
// ============================================================
$initial    = strtoupper(substr($user['full_name'] ?? 'N', 0, 1));
$avatar     = $user['profile_pic'] ?? '';
$has_avatar = !empty($avatar) && file_exists('../../' . $avatar);

$role_label = ucfirst(str_replace('_', ' ', $user['role'] ?? 'employee'));
$role_class = 'role-' . ($user['role'] ?? 'employee');

$emp_status = $user['employment_status'] ?? 'active';
$status_icon = [
    'active'     => 'fa-check-circle',
    'on_leave'   => 'fa-clock',
    'suspended'  => 'fa-pause-circle',
    'terminated' => 'fa-ban',
][$emp_status] ?? 'fa-circle';

include_once '../../includes/admin_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-circle"></i> My Profile</h2>
                <p class="text-muted">View and update your personal information</p>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- PROFILE HERO CARD -->
        <div class="profile-hero">
            <div class="profile-hero-bg"></div>
            <div class="profile-hero-content">
                <div class="profile-avatar-wrapper">
                    <?php if ($has_avatar): ?>
                        <img src="../../<?php echo htmlspecialchars($avatar); ?>"
                             class="profile-avatar-img"
                             alt="<?php echo htmlspecialchars($user['full_name']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="profile-avatar" style="display:none;"><?php echo $initial; ?></div>
                    <?php else: ?>
                        <div class="profile-avatar"><?php echo $initial; ?></div>
                    <?php endif; ?>

                    <span class="profile-status-dot status-<?php echo $emp_status; ?>"
                          title="<?php echo ucfirst(str_replace('_', ' ', $emp_status)); ?>"></span>
                </div>

                <div class="profile-hero-info">
                    <h1 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h1>
                    <div class="profile-meta">
                        <span class="meta-chip meta-id">
                            <i class="fas fa-id-badge"></i>
                            <?php echo htmlspecialchars($user['employee_id']); ?>
                        </span>
                        <span class="meta-chip <?php echo $role_class; ?>">
                            <i class="fas fa-user-tag"></i>
                            <?php echo htmlspecialchars($role_label); ?>
                        </span>
                        <span class="meta-chip status-badge-<?php echo $emp_status; ?>">
                            <i class="fas <?php echo $status_icon; ?>"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $emp_status)); ?>
                        </span>
                    </div>
                    <div class="profile-contact">
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                        <?php if (!empty($user['phone'])): ?>
                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($user['branch_display'])): ?>
                            <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($user['branch_display']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- QUICK STATS -->
        <div class="quick-stats">
            <div class="quick-stat">
                <div class="quick-stat-icon quick-red">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Role</span>
                    <span class="quick-stat-value"><?php echo htmlspecialchars($role_label); ?></span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-blue">
                    <i class="fas fa-store"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Branch</span>
                    <span class="quick-stat-value">
                        <?php echo htmlspecialchars($user['branch_display'] ?? $user['branch'] ?? '-'); ?>
                    </span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-green">
                    <i class="far fa-calendar-check"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Member Since</span>
                    <span class="quick-stat-value">
                        <?php echo !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '-'; ?>
                    </span>
                </div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon quick-orange">
                    <i class="fas fa-history"></i>
                </div>
                <div class="quick-stat-info">
                    <span class="quick-stat-label">Activities</span>
                    <span class="quick-stat-value"><?php echo number_format($total_activities); ?></span>
                </div>
            </div>
        </div>

        <!-- PROFILE GRID -->
        <div class="profile-grid">

            <div class="profile-main">

                <!-- EDIT PROFILE INFO (ONLY FORM FOR EMPLOYEE) -->
                <div class="form-card">
                    <div class="form-card-header">
                        <div class="form-card-header-left">
                            <div class="form-card-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div>
                                <h3>Profile Information</h3>
                                <p>Update your personal details</p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="" enctype="multipart/form-data" onsubmit="return validateProfile()">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="remove_profile_pic" id="removeProfilePicInput" value="0">

                        <div class="form-card-body">

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
                                            <i class="fas fa-camera"></i> Change Photo
                                        </label>
                                        <input type="file" name="profile_pic" id="profile_pic"
                                               accept="image/*" onchange="previewProfilePic(this)" style="display:none;">
                                        <?php if ($has_avatar): ?>
                                            <button type="button" class="btn-remove-pic" onclick="removeProfilePic()">
                                                <i class="fas fa-trash"></i> Remove
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="upload-hint">JPG, PNG, GIF or WEBP · Max 15MB</p>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name <span class="required">*</span></label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email <span class="required">*</span></label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Phone <span class="required">*</span></label>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Gender</label>
                                    <select name="gender" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="male"   <?php echo ($user['gender'] ?? '') === 'male'   ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other"  <?php echo ($user['gender'] ?? '') === 'other'  ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Date of Birth</label>
                                    <input type="date" name="date_of_birth" class="form-control"
                                           value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Address</label>
                                    <input type="text" name="address" class="form-control"
                                           value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>"
                                           placeholder="e.g. Kinondoni, Dar es Salaam">
                                </div>
                            </div>

                            <div class="section-divider">
                                <span><i class="fas fa-phone-volume"></i> Emergency Contact</span>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Emergency Contact Name</label>
                                    <input type="text" name="emergency_contact" class="form-control"
                                           value="<?php echo htmlspecialchars($user['emergency_contact'] ?? ''); ?>"
                                           placeholder="e.g. Mary Doe">
                                </div>
                                <div class="form-group">
                                    <label>Emergency Phone</label>
                                    <input type="text" name="emergency_phone" class="form-control"
                                           value="<?php echo htmlspecialchars($user['emergency_phone'] ?? ''); ?>"
                                           placeholder="+255 7XX XXX XXX">
                                </div>
                            </div>

                        </div>

                        <div class="form-card-footer">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Profile
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            <!-- SIDEBAR -->
            <div class="profile-sidebar">

                <div class="side-card">
                    <div class="side-card-header">
                        <div class="side-card-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h3>Account Information</h3>
                    </div>
                    <div class="side-card-body">
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-user"></i> Username</span>
                            <span class="side-info-value mono"><?php echo htmlspecialchars($user['username'] ?? '-'); ?></span>
                        </div>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-hashtag"></i> User ID</span>
                            <span class="side-info-value mono">#<?php echo $user['id']; ?></span>
                        </div>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-id-badge"></i> Employee ID</span>
                            <span class="side-info-value">
                                <span class="chip-red"><?php echo htmlspecialchars($user['employee_id']); ?></span>
                            </span>
                        </div>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-user-tag"></i> Role</span>
                            <span class="side-info-value">
                                <span class="meta-chip <?php echo $role_class; ?>" style="padding:3px 10px;font-size:10px;">
                                    <?php echo htmlspecialchars($role_label); ?>
                                </span>
                            </span>
                        </div>
                        <?php if (!empty($user['branch_display'])): ?>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-store"></i> Branch</span>
                            <span class="side-info-value"><?php echo htmlspecialchars($user['branch_display']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-toggle-on"></i> Account Status</span>
                            <span class="side-info-value">
                                <?php if (intval($user['is_active'] ?? 1) === 1): ?>
                                    <span class="chip-green"><i class="fas fa-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="chip-gray"><i class="fas fa-times-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($user['last_login'])): ?>
                        <div class="side-info">
                            <span class="side-info-label"><i class="fas fa-sign-in-alt"></i> Last Login</span>
                            <span class="side-info-value" style="font-size:12px;">
                                <?php echo date('d M Y H:i', strtotime($user['last_login'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="side-info">
                            <span class="side-info-label"><i class="far fa-calendar-plus"></i> Joined</span>
                            <span class="side-info-value" style="font-size:12px;">
                                <?php echo !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '-'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="side-card">
                    <div class="side-card-header">
                        <div class="side-card-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3>Recent Activity</h3>
                        <span class="side-count"><?php echo count($activities); ?></span>
                    </div>
                    <div class="side-card-body">
                        <?php if (count($activities) > 0): ?>
                            <div class="activity-list">
                                <?php foreach ($activities as $a):
                                    $action_label = htmlspecialchars($a['action'] ?? 'Activity');
                                    $module = htmlspecialchars($a['module'] ?? '');
                                    $when   = !empty($a['created_at']) ? date('d M, H:i', strtotime($a['created_at'])) : '';
                                ?>
                                <div class="activity-item">
                                    <div class="activity-dot"></div>
                                    <div class="activity-content">
                                        <span class="activity-action"><?php echo $action_label; ?></span>
                                        <?php if ($module): ?>
                                            <span class="activity-module"><?php echo $module; ?></span>
                                        <?php endif; ?>
                                        <span class="activity-time">
                                            <i class="far fa-clock"></i> <?php echo $when; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="activity-empty">
                                <i class="fas fa-inbox"></i>
                                <p>No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>

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

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }

.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* PROFILE HERO */
.profile-hero {
    background: var(--bg-card);
    border-radius: 16px; border: 1.5px solid var(--border-color);
    margin-bottom: 18px; box-shadow: 0 4px 16px var(--shadow-color);
    overflow: hidden; position: relative;
}
.profile-hero-bg {
    height: 90px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    position: relative; overflow: hidden;
}
.profile-hero-bg::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.profile-hero-bg::after {
    content: ''; position: absolute;
    bottom: -60%; left: 30%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.profile-hero-content {
    padding: 0 26px 22px 26px;
    display: flex; align-items: flex-end; gap: 22px;
    flex-wrap: wrap; margin-top: -50px;
    position: relative; z-index: 1;
}
.profile-avatar-wrapper { position: relative; flex-shrink: 0; }
.profile-avatar-img, .profile-avatar {
    width: 100px; height: 100px;
    border-radius: 50%;
    border: 4px solid #FFFFFF;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 38px; font-weight: 900;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
}
.profile-avatar-img { object-fit: cover; display: block; }
html.dark-mode .profile-avatar-img,
html.dark-mode .profile-avatar { border-color: #334155; }
.profile-status-dot {
    position: absolute; bottom: 6px; right: 6px;
    width: 22px; height: 22px; border-radius: 50%;
    border: 3.5px solid #FFFFFF;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
html.dark-mode .profile-status-dot { border-color: #1e293b; }
.profile-status-dot.status-active     { background: #10B981; }
.profile-status-dot.status-on_leave   { background: #F59E0B; }
.profile-status-dot.status-suspended  { background: #DC2626; }
.profile-status-dot.status-terminated { background: #6B7280; }
.profile-hero-info { flex: 1; min-width: 0; padding-bottom: 4px; }
.profile-name {
    font-size: 24px; font-weight: 900;
    margin: 0 0 10px 0;
    color: var(--text-primary);
    word-break: break-word; line-height: 1.2;
}
.profile-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.meta-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 8px;
    font-size: 11.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
}
.meta-chip i { font-size: 10px; }
.meta-id {
    background: #FEF2F2; color: #DC2626;
    border: 1.5px solid #FCA5A5;
    font-family: 'Courier New', monospace;
}
html.dark-mode .meta-id { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.role-employee    { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.role-admin       { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.role-super_admin { background: #EDE9FE; color: #7C3AED; border: 1.5px solid #C4B5FD; }
html.dark-mode .role-employee    { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .role-admin       { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .role-super_admin { background: #4C1D95; color: #DDD6FE; border-color: #8B5CF6; }
.status-badge-active     { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.status-badge-on_leave   { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
.status-badge-suspended  { background: #FEE2E2; color: #DC2626; border: 1.5px solid #FECACA; }
.status-badge-terminated { background: #F3F4F6; color: #6B7280; border: 1.5px solid #E5E7EB; }
html.dark-mode .status-badge-active     { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .status-badge-on_leave   { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .status-badge-suspended  { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .status-badge-terminated { background: #334155; color: #94A3B8; border-color: #475569; }
.profile-contact {
    display: flex; flex-wrap: wrap; gap: 14px;
    font-size: 12.5px; color: var(--text-muted); margin-top: 4px;
}
.profile-contact span { display: inline-flex; align-items: center; gap: 5px; font-weight: 500; }
.profile-contact i { color: #DC2626; font-size: 11px; }

/* QUICK STATS */
.quick-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.quick-stat {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px; background: var(--bg-card);
    border-radius: 12px; border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; min-width: 0;
}
.quick-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: var(--red-primary);
}
.quick-stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0; color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.quick-red    { background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%); }
.quick-blue   { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.quick-green  { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.quick-orange { background: linear-gradient(135deg, #D97706 0%, #F59E0B 100%); }
.quick-stat-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.quick-stat-label {
    font-size: 10.5px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 800;
    color: var(--text-muted);
}
.quick-stat-value {
    font-size: 15px; font-weight: 900;
    color: var(--text-primary);
    word-break: break-word; line-height: 1.2;
}

/* GRID */
.profile-grid {
    display: grid; grid-template-columns: 1fr 380px;
    gap: 18px; align-items: start;
}
.profile-main { min-width: 0; display: flex; flex-direction: column; gap: 18px; }
.profile-sidebar { min-width: 0; display: flex; flex-direction: column; gap: 18px; }

/* FORM CARDS */
.form-card {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color);
}
.form-card-header {
    padding: 18px 26px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    display: flex; align-items: center; gap: 14px;
    color: #FFFFFF; position: relative; overflow: hidden;
}
.form-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.form-card-header-left {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1;
}
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
.form-card-body { padding: 24px 26px; }
.form-card-footer {
    padding: 16px 26px; background: var(--bg-input);
    border-top: 1.5px solid var(--border-color);
    display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;
}
html.dark-mode .form-card-footer { background: #0f172a; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
.form-row:last-child { margin-bottom: 0; }
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.form-group.full-width { grid-column: 1 / -1; }
.form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-group label .required { color: #DC2626; }

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
.form-control::placeholder { color: var(--text-light); font-size: 12px; }

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

.profile-upload-section {
    display: flex; align-items: center; gap: 22px;
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
    width: 100px; height: 100px;
    border-radius: 50%; background: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 42px; color: #FCA5A5;
    flex-shrink: 0;
    border: 3.5px solid #DC2626;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.2);
    overflow: hidden; position: relative;
}
.profile-upload-preview img { width: 100%; height: 100%; object-fit: cover; }
html.dark-mode .profile-upload-preview { background: #1e293b; }
.profile-upload-info { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0; }
.profile-upload-buttons { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.btn-upload {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    cursor: pointer; transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
    font-family: 'Inter', sans-serif;
}
.btn-upload:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(220, 38, 38, 0.5); }
.btn-remove-pic {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px;
    background: #FFFFFF; color: #DC2626;
    border: 1.5px solid #FCA5A5;
    border-radius: 10px;
    font-size: 12px; font-weight: 700;
    cursor: pointer; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-remove-pic:hover {
    background: #FEE2E2; border-color: #DC2626;
    transform: translateY(-2px);
}
html.dark-mode .btn-remove-pic { background: #1e293b; }
.upload-hint { font-size: 11px; color: var(--text-muted); margin: 0; font-weight: 500; }

.btn {
    padding: 12px 24px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-primary {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}

/* SIDEBAR */
.side-card {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color);
}
.side-card-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    display: flex; align-items: center; gap: 12px;
    color: #FFFFFF; position: relative; overflow: hidden;
}
.side-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 150px; height: 150px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%; pointer-events: none;
}
.side-card-icon {
    width: 38px; height: 38px;
    background: rgba(255,255,255,0.2);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.side-card-header h3 {
    font-size: 14px; font-weight: 800;
    margin: 0; color: #FFFFFF;
    flex: 1; position: relative; z-index: 1;
}
.side-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 26px; height: 26px;
    padding: 0 8px;
    background: rgba(255,255,255,0.25);
    color: #FFFFFF; border-radius: 8px;
    font-size: 12px; font-weight: 900;
    border: 1.5px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.side-card-body { padding: 14px 20px; }

.side-info {
    display: flex; justify-content: space-between;
    align-items: center; padding: 10px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 10px; flex-wrap: wrap;
}
.side-info:last-child { border-bottom: none; }
.side-info-label {
    font-size: 11.5px; font-weight: 700;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.side-info-label i { color: #DC2626; font-size: 11px; width: 12px; text-align: center; }
.side-info-value {
    font-size: 12.5px; font-weight: 700;
    color: var(--text-primary);
    text-align: right; word-break: break-word;
}
.side-info-value.mono {
    font-family: 'Courier New', monospace;
    font-size: 12px; color: var(--red-primary);
}
html.dark-mode .side-info-value.mono { color: #FCA5A5; }

.chip-red {
    display: inline-block; padding: 3px 10px;
    background: #FEF2F2; color: #DC2626;
    border: 1.5px solid #FCA5A5;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 800;
    letter-spacing: 0.3px;
}
html.dark-mode .chip-red { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.chip-green {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px;
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
    border-radius: 6px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.chip-gray {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px;
    background: #F3F4F6; color: #6B7280;
    border: 1.5px solid #E5E7EB;
    border-radius: 6px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.3px;
}
html.dark-mode .chip-green { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .chip-gray { background: #334155; color: #94A3B8; border-color: #475569; }

.activity-list { display: flex; flex-direction: column; gap: 12px; }
.activity-item {
    display: flex; gap: 12px; padding: 10px 0;
    border-bottom: 1px dashed var(--border-color);
}
.activity-item:last-child { border-bottom: none; }
.activity-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    flex-shrink: 0; margin-top: 5px;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}
.activity-content { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.activity-action {
    font-size: 12.5px; font-weight: 800;
    color: var(--text-primary); word-break: break-word;
}
.activity-module {
    display: inline-block; font-size: 10px; font-weight: 700;
    color: #DC2626; background: #FEF2F2;
    padding: 2px 8px; border-radius: 5px;
    align-self: flex-start; text-transform: uppercase;
    letter-spacing: 0.4px;
}
html.dark-mode .activity-module { background: #7F1D1D; color: #FCA5A5; }
.activity-time {
    font-size: 10.5px; color: var(--text-muted);
    display: flex; align-items: center; gap: 4px;
    font-weight: 600;
}
.activity-time i { font-size: 9px; }
.activity-empty { padding: 30px 20px; text-align: center; color: var(--text-muted); }
.activity-empty i {
    font-size: 40px; color: #FCA5A5;
    opacity: 0.5; display: block; margin-bottom: 10px;
}
.activity-empty p { margin: 0; font-size: 13px; font-weight: 500; }

/* RESPONSIVE */
@media (max-width: 1024px) {
    .profile-grid { grid-template-columns: 1fr; }
    .quick-stats { grid-template-columns: repeat(2, 1fr); }
    .form-row { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px 20px; }
    .form-card-header { padding: 16px 20px; }
    .form-card-footer { padding: 14px 20px; flex-direction: column; }
    .form-card-footer .btn { width: 100%; justify-content: center; }
    .profile-hero-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 0 20px 22px 20px;
    }
    .profile-hero-info { text-align: center; }
    .profile-meta { justify-content: center; }
    .profile-contact { justify-content: center; }
    .profile-name { font-size: 20px; }
    .quick-stats { grid-template-columns: 1fr; }
    .profile-upload-section { flex-direction: column; text-align: center; }
    .profile-upload-buttons { justify-content: center; }
}
@media (max-width: 480px) {
    .profile-avatar-img, .profile-avatar { width: 80px; height: 80px; font-size: 30px; }
    .profile-status-dot { width: 18px; height: 18px; border-width: 3px; }
    .profile-name { font-size: 18px; }
    .quick-stat { padding: 14px 16px; gap: 10px; }
    .quick-stat-icon { width: 40px; height: 40px; font-size: 17px; }
    .quick-stat-value { font-size: 13px; }
    .profile-upload-preview { width: 80px; height: 80px; font-size: 34px; }
}
</style>

<script>
function previewProfilePic(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('profilePreviewImg');
            var placeholder = document.querySelector('#profilePreview i');
            img.src = e.target.result;
            img.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
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

function validateProfile() {
    var full_name = document.querySelector('input[name="full_name"]').value.trim();
    var email     = document.querySelector('input[name="email"]').value.trim();
    var phone     = document.querySelector('input[name="phone"]').value.trim();
    if (full_name === '') { alert('Please enter your full name.'); return false; }
    if (email === '')     { alert('Please enter your email.'); return false; }
    if (phone === '')     { alert('Please enter your phone number.'); return false; }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 6000);
    }
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function() { syncDarkMode(); });
});
</script>

</body>
</html>