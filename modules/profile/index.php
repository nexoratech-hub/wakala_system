<?php
// ================================================================
// FILE: modules/profile/index.php
// WAKALA SYSTEM - USER PROFILE
// WITH USERNAME, PROFILE PICTURE UPLOAD (25MB MAX) & DARK MODE SUPPORT
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

$user_id = $_SESSION['user_id'];

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ../../login.php');
    exit();
}

// ============================================================
// GET BRANCH NAME
// ============================================================
$branch_name = 'N/A';
if ($user['branch_id'] > 0) {
    $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->execute([$user['branch_id']]);
    $branch = $stmt->fetch();
    if ($branch) {
        $branch_name = $branch['branch_name'];
    }
}

// ============================================================
// SET PROFILE PICTURE PATH
// ============================================================
$profile_pic_path = '../../assets/images/logo.PNG'; // Default
if (!empty($user['profile_pic']) && file_exists('../../' . $user['profile_pic'])) {
    $profile_pic_path = '../../' . $user['profile_pic'];
} elseif (!empty($user['profile_pic']) && file_exists($user['profile_pic'])) {
    $profile_pic_path = $user['profile_pic'];
}

// ============================================================
// HANDLE PROFILE PICTURE UPLOAD - 25MB MAX
// ============================================================
$upload_message = '';
$upload_message_type = '';

if (isset($_POST['upload_picture'])) {
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $file = $_FILES['profile_pic'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        
        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_ext, $allowed)) {
            if ($file_size <= 25000000) { // 25MB max
                // Create upload directory if not exists
                $upload_dir = '../../uploads/profiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique file name
                $new_file_name = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                $db_path = 'uploads/profiles/' . $new_file_name;
                
                // Delete old profile picture if exists
                if (!empty($user['profile_pic']) && file_exists('../../' . $user['profile_pic'])) {
                    unlink('../../' . $user['profile_pic']);
                }
                
                // Move uploaded file
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    // Update database
                    $stmt = $db->prepare("UPDATE employees SET profile_pic = ? WHERE id = ?");
                    $stmt->execute([$db_path, $user_id]);
                    
                    // Update session
                    $_SESSION['profile_pic'] = $db_path;
                    
                    // Set a flag in localStorage to notify other tabs/windows
                    echo '<script>localStorage.setItem("profile_pic_updated", "true");</script>';
                    
                    $upload_message = 'Profile picture updated successfully!';
                    $upload_message_type = 'success';
                    
                    // Refresh user data
                    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                    $profile_pic_path = '../../' . $db_path;
                } else {
                    $upload_message = 'Failed to upload image. Please try again.';
                    $upload_message_type = 'danger';
                }
            } else {
                $upload_message = 'File size must be less than 25MB.';
                $upload_message_type = 'danger';
            }
        } else {
            $upload_message = 'Only JPG, JPEG, PNG, GIF, and WEBP files are allowed.';
            $upload_message_type = 'danger';
        }
    } else {
        $upload_message = 'Please select a file to upload.';
        $upload_message_type = 'danger';
    }
}

// ============================================================
// HANDLE PROFILE UPDATE
// ============================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['upload_picture'])) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $emergency_phone = trim($_POST['emergency_phone'] ?? '');
    
    // Validate
    $errors = [];
    if (empty($username)) {
        $errors[] = 'Username is required';
    }
    if (empty($full_name)) {
        $errors[] = 'Full name is required';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Check if username already exists (except current user)
    if (!empty($username)) {
        $stmt = $db->prepare("SELECT id FROM employees WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Username already exists';
        }
    }
    
    // Check if email already exists (except current user)
    if (!empty($email)) {
        $stmt = $db->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Email already exists';
        }
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("UPDATE employees SET 
            username = ?,
            full_name = ?, 
            email = ?, 
            phone = ?, 
            address = ?,
            emergency_contact = ?,
            emergency_phone = ?
            WHERE id = ?");
        
        $stmt->execute([$username, $full_name, $email, $phone, $address, $emergency_contact, $emergency_phone, $user_id]);
        
        // Update session
        $_SESSION['full_name'] = $full_name;
        
        $message = 'Profile updated successfully!';
        $message_type = 'success';
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'danger';
    }
}

// ============================================================
// GET STATISTICS
// ============================================================
// Total morning reports
$stmt = $db->prepare("SELECT COUNT(*) as count FROM morning_reports WHERE employee_id = ?");
$stmt->execute([$user_id]);
$morning_count = $stmt->fetch()['count'] ?? 0;

// Total evening stocks
$stmt = $db->prepare("SELECT COUNT(*) as count FROM evening_stocks WHERE employee_id = ?");
$stmt->execute([$user_id]);
$evening_count = $stmt->fetch()['count'] ?? 0;

// Total commissions
$stmt = $db->prepare("SELECT COUNT(*) as count, SUM(total_commission) as total FROM commissions WHERE employee_id = ?");
$stmt->execute([$user_id]);
$commission_data = $stmt->fetch();
$commission_count = $commission_data['count'] ?? 0;
$commission_total = $commission_data['total'] ?? 0;

// Total expenses
$stmt = $db->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM expenses WHERE employee_id = ?");
$stmt->execute([$user_id]);
$expense_data = $stmt->fetch();
$expense_count = $expense_data['count'] ?? 0;
$expense_total = $expense_data['total'] ?? 0;

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
PAGE CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== DARK MODE TOGGLE ===== -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-circle" style="color:#bb0404;"></i> My Profile</h2>
                <p class="text-muted">Manage your personal information</p>
            </div>
            <div class="header-right">
                <a href="change_password.php" class="btn btn-warning">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
                <button class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($upload_message)): ?>
            <div class="alert alert-<?php echo $upload_message_type; ?>">
                <i class="fas <?php echo $upload_message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $upload_message; ?>
                <button class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ===== PROFILE CONTENT ===== -->
        <div class="profile-grid">
            
            <!-- ===== PROFILE CARD ===== -->
            <div class="profile-card">
                <div class="profile-avatar">
                    <div class="avatar-container">
                        <img src="<?php echo $profile_pic_path; ?>" alt="Profile Picture" class="avatar-image" id="profileImage">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm" style="display:inline;">
                            <label for="profile_pic_input" class="avatar-badge" title="Change Profile Picture (Max 25MB)">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" id="profile_pic_input" name="profile_pic" accept="image/*" style="display:none;" onchange="document.getElementById('uploadForm').submit();">
                            <input type="hidden" name="upload_picture" value="1">
                        </form>
                    </div>
                    <div style="margin-top:6px;font-size:11px;color:var(--text-muted);">
                        <i class="fas fa-info-circle"></i> Max file size: 25MB
                    </div>
                </div>
                <div class="profile-name">
                    <h3><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></h3>
                    <span class="role-badge"><?php echo ucfirst($user['role'] ?? 'Employee'); ?></span>
                    <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <i class="fas fa-id-badge"></i>
                        <span class="info-label">Employee ID</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['employee_id'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <span class="info-label">Username</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-store"></i>
                        <span class="info-label">Branch</span>
                        <span class="info-value"><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="info-label">Joined</span>
                        <span class="info-value"><?php echo $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-clock"></i>
                        <span class="info-label">Last Login</span>
                        <span class="info-value"><?php echo $user['last_login'] ? date('d M Y H:i', strtotime($user['last_login'])) : 'N/A'; ?></span>
                    </div>
                </div>
            </div>

            <!-- ===== STATISTICS CARD ===== -->
            <div class="stats-card">
                <h4><i class="fas fa-chart-bar" style="color:#bb0404;"></i> My Statistics</h4>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-icon"><i class="fas fa-sun"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Morning Reports</span>
                            <span class="stat-value"><?php echo number_format($morning_count); ?></span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon"><i class="fas fa-moon"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Evening Stocks</span>
                            <span class="stat-value"><?php echo number_format($evening_count); ?></span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Commissions</span>
                            <span class="stat-value"><?php echo number_format($commission_count); ?></span>
                            <span class="stat-sub">Total: <?php echo formatCurrency($commission_total); ?></span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Expenses</span>
                            <span class="stat-value"><?php echo number_format($expense_count); ?></span>
                            <span class="stat-sub">Total: <?php echo formatCurrency($expense_total); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== EDIT PROFILE FORM ===== -->
        <div class="edit-profile-card">
            <div class="edit-header">
                <h4><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Profile</h4>
                <p class="text-muted">Update your personal information</p>
            </div>
            
            <form method="POST" action="" class="profile-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username <span class="required">*</span></label>
                        <input type="text" id="username" name="username" class="form-control" 
                               value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Full Name <span class="required">*</span></label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" class="form-control" 
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" class="form-control" 
                               value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="emergency_contact">Emergency Contact Name</label>
                        <input type="text" id="emergency_contact" name="emergency_contact" class="form-control" 
                               value="<?php echo htmlspecialchars($user['emergency_contact'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="emergency_phone">Emergency Phone</label>
                        <input type="text" id="emergency_phone" name="emergency_phone" class="form-control" 
                               value="<?php echo htmlspecialchars($user['emergency_phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <!-- Empty space for alignment -->
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="reset" class="btn btn-reset-form">
                        <i class="fas fa-undo"></i> Reset
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
STYLES WITH DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --bg-primary: #f3f4f6;
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-card-hover: #f9fafb;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.08);
    --alert-success-bg: #D1FAE5;
    --alert-success-text: #065F46;
    --alert-danger-bg: #FEE2E2;
    --alert-danger-text: #991B1B;
}

/* Dark Mode - Full Page */
body.dark-mode {
    --bg-primary: #0f172a;
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-card-hover: #334155;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
    --alert-success-bg: #064E3B;
    --alert-success-text: #6EE7B7;
    --alert-danger-bg: #7F1D1D;
    --alert-danger-text: #FCA5A5;
}

/* Apply Dark Mode to Full Page */
body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--bg-body) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   DARK MODE TOGGLE BUTTON
   ============================================================ */
.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.dark-mode-btn:hover {
    background: var(--bg-card-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.dark-mode-btn i {
    font-size: 16px;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-warning {
    background: #F59E0B;
    color: #1F2937;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-warning:hover {
    background: #D97706;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
    color: #ffffff;
}

/* ============================================================
   ALERT
   ============================================================ */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    border: 1px solid transparent;
    position: relative;
}

.alert-success {
    background: var(--alert-success-bg);
    color: var(--alert-success-text);
    border-color: var(--alert-success-bg);
}

.alert-danger {
    background: var(--alert-danger-bg);
    color: var(--alert-danger-text);
    border-color: var(--alert-danger-bg);
}

.alert-close {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: inherit;
    opacity: 0.6;
    padding: 0 4px;
}

.alert-close:hover {
    opacity: 1;
}

/* ============================================================
   PROFILE AVATAR
   ============================================================ */
.profile-avatar {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.avatar-container {
    position: relative;
    display: inline-block;
}

.avatar-image {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #bb0404;
    box-shadow: 0 4px 16px rgba(187,4,4,0.25);
    transition: all 0.3s ease;
    background: var(--bg-card);
}

.avatar-image:hover {
    transform: scale(1.02);
    box-shadow: 0 6px 24px rgba(187,4,4,0.35);
}

.avatar-badge {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #bb0404;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    border: 3px solid var(--bg-card);
    transition: all 0.3s ease;
    z-index: 10;
}

.avatar-badge:hover {
    transform: scale(1.1);
    background: #8a0303;
    box-shadow: 0 0 20px rgba(187,4,4,0.4);
}

/* ============================================================
   PROFILE GRID
   ============================================================ */
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Profile Card */
.profile-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
    text-align: center;
}

.profile-name {
    margin-top: 12px;
}

.profile-name h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.role-badge {
    display: inline-block;
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 2px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin: 6px 4px 0 0;
}

.status-badge {
    display: inline-block;
    padding: 2px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin: 6px 0 0 0;
}

.status-active {
    background: #D1FAE5;
    color: #065F46;
}

.status-inactive {
    background: #FEE2E2;
    color: #991B1B;
}

.profile-info {
    margin-top: 16px;
    text-align: left;
    border-top: 1px solid var(--border-color);
    padding-top: 16px;
}

.info-item {
    display: flex;
    align-items: center;
    padding: 6px 0;
    gap: 12px;
}

.info-item i {
    width: 20px;
    color: #bb0404;
    font-size: 16px;
}

.info-label {
    font-size: 12px;
    color: var(--text-muted);
    width: 120px;
    flex-shrink: 0;
}

.info-value {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 500;
}

/* Stats Card */
.stats-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.stats-card h4 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--bg-table-even);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #DBEAFE;
    color: #1D4ED8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-info {
    flex: 1;
}

.stat-label {
    font-size: 11px;
    color: var(--text-muted);
    display: block;
}

.stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    display: block;
}

.stat-sub {
    font-size: 11px;
    color: var(--text-light);
    display: block;
}

/* ============================================================
   EDIT PROFILE CARD
   ============================================================ */
.edit-profile-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.edit-header {
    margin-bottom: 20px;
}

.edit-header h4 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.edit-header .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.profile-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}

.form-group label .required {
    color: #DC2626;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.form-control::placeholder {
    color: var(--text-light);
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.btn-primary {
    background: #bb0404;
    color: #ffffff;
    border: none;
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
    color: #ffffff;
}

.btn-reset-form {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 500;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-reset-form:hover {
    background: var(--bg-table-hover);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-right {
        width: 100%;
    }
    
    .header-right .btn {
        width: 100%;
        justify-content: center;
    }
    
    .profile-form .form-row {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .info-item {
        flex-wrap: wrap;
        gap: 4px;
    }
    
    .info-label {
        width: 100%;
        font-size: 11px;
    }
    
    .info-value {
        font-size: 13px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .avatar-image {
        width: 100px;
        height: 100px;
    }
    
    .avatar-badge {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .profile-card, .stats-card, .edit-profile-card {
        padding: 16px;
    }
    
    .profile-name h3 {
        font-size: 18px;
    }
    
    .stat-value {
        font-size: 16px;
    }
    
    .dark-mode-btn {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .avatar-image {
        width: 80px;
        height: 80px;
    }
    
    .avatar-badge {
        width: 28px;
        height: 28px;
        font-size: 12px;
        bottom: 2px;
        right: 2px;
    }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.profile-card {
    animation: fadeInUp 0.3s ease forwards;
}

.stats-card {
    animation: fadeInUp 0.3s ease forwards;
    animation-delay: 0.10s;
}

.edit-profile-card {
    animation: fadeInUp 0.3s ease forwards;
    animation-delay: 0.20s;
}
</style>

<!-- ============================================================
JAVASCRIPT
============================================================ -->
<script>
// ============================================================
// DARK MODE TOGGLE
// ============================================================
function toggleDarkMode() {
    const body = document.body;
    const btn = document.getElementById('darkModeToggle');
    const icon = btn.querySelector('i');
    const text = btn.querySelector('span');
    
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
        icon.className = 'fas fa-sun';
        text.textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        icon.className = 'fas fa-moon';
        text.textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

// Check for saved dark mode preference
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = localStorage.getItem('darkMode');
    const btn = document.getElementById('darkModeToggle');
    const icon = btn?.querySelector('i');
    const text = btn?.querySelector('span');
    
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        if (icon) icon.className = 'fas fa-sun';
        if (text) text.textContent = 'Light Mode';
    }
    
    // Auto-dismiss alert after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.display = 'none';
        }, 5000);
    });
});

// ============================================================
// PROFILE PICTURE PREVIEW
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('profile_pic_input');
    const profileImage = document.getElementById('profileImage');
    
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = this.files[0];
            if (file) {
                // Check file size (25MB = 26214400 bytes)
                if (file.size > 26214400) {
                    alert('File size exceeds 25MB. Please choose a smaller file.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    profileImage.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Listen for profile picture updates from other tabs/windows
    window.addEventListener('storage', function(e) {
        if (e.key === 'profile_pic_updated' && e.newValue === 'true') {
            // Reload the page to refresh profile picture
            localStorage.removeItem('profile_pic_updated');
            window.location.reload();
        }
    });
});
</script>

</body>
</html>