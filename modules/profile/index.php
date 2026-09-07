<?php
// ================================================================
// FILE: modules/profile/index.php
// WAKALA FINANCIAL SYSTEM - USER PROFILE
// WITH AJAX PROFILE PICTURE UPLOAD
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
$branch_name = 'Main';
if ($user['branch_id'] > 0) {
    $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->execute([$user['branch_id']]);
    $branch = $stmt->fetch();
    if ($branch) {
        $branch_name = $branch['branch_name'];
    }
}

// ============================================================
// HANDLE PROFILE UPDATE
// ============================================================
$error_message = '';
$show_error = false;
$success_message = '';
$show_success = false;

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        if (empty($full_name)) {
            throw new Exception('Please enter your full name.');
        }
        if (empty($email)) {
            throw new Exception('Please enter your email.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        
        $check_stmt = $db->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
        $check_stmt->execute([$email, $user_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('Email "' . $email . '" is already used by another user.');
        }
        
        $update_stmt = $db->prepare("UPDATE employees SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
        $update_stmt->execute([$full_name, $email, $phone, $address, $user_id]);
        
        $_SESSION['full_name'] = $full_name;
        
        $success_message = 'Profile updated successfully!';
        $show_success = true;
        
        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password)) {
            throw new Exception('Please enter your current password.');
        }
        if (empty($new_password)) {
            throw new Exception('Please enter a new password.');
        }
        if (strlen($new_password) < 6) {
            throw new Exception('New password must be at least 6 characters.');
        }
        if ($new_password !== $confirm_password) {
            throw new Exception('Passwords do not match.');
        }
        
        if (!password_verify($current_password, $user['password_hash'])) {
            throw new Exception('Current password is incorrect.');
        }
        
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $db->prepare("UPDATE employees SET password_hash = ? WHERE id = ?");
        $update_stmt->execute([$new_hash, $user_id]);
        
        $success_message = 'Password changed successfully!';
        $show_success = true;
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// ============================================================
// AJAX UPLOAD PROFILE PICTURE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_upload_picture') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'image_url' => ''];
    
    try {
        if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a valid image file.');
        }
        
        $file = $_FILES['profile_pic'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 25 * 1024 * 1024;
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            throw new Exception('Only JPG, PNG, GIF, and WEBP images are allowed.');
        }
        if ($file['size'] > $max_size) {
            throw new Exception('Image size must be less than 25MB.');
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
        
        $upload_dir = '../../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $target_path = $upload_dir . $filename;
        
        // Delete old profile picture
        if (!empty($user['profile_pic'])) {
            $old_path = '../../' . $user['profile_pic'];
            if (file_exists($old_path)) {
                unlink($old_path);
            }
        }
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $db_path = 'uploads/profiles/' . $filename;
            $update_stmt = $db->prepare("UPDATE employees SET profile_pic = ? WHERE id = ?");
            $update_stmt->execute([$db_path, $user_id]);
            
            // Get the URL for the image
            $image_url = '/wakala_system/uploads/profiles/' . $filename . '?t=' . time();
            
            $response['success'] = true;
            $response['message'] = 'Profile picture updated successfully!';
            $response['image_url'] = $image_url;
            $response['db_path'] = $db_path;
        } else {
            throw new Exception('Failed to upload image.');
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// ============================================================
// GET PROFILE PICTURE PATH
// ============================================================
$profile_pic = '';

if (!empty($user['profile_pic'])) {
    $filename = basename($user['profile_pic']);
    $profile_pic = '/wakala_system/uploads/profiles/' . $filename;
}

// If no profile picture, use default
if (empty($profile_pic)) {
    $initial = strtoupper(substr($user['full_name'] ?? 'U', 0, 1));
    $profile_pic = 'data:image/svg+xml,' . urlencode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect width="200" height="200" fill="#E5E7EB"/><circle cx="100" cy="80" r="50" fill="#9CA3AF"/><circle cx="100" cy="200" r="70" fill="#9CA3AF"/><text x="100" y="130" text-anchor="middle" font-size="40" fill="#6B7280" font-family="Arial" font-weight="bold">' . $initial . '</text></svg>');
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
        
        <!-- ============================================================
        BRANCH INDICATOR CARD - RED
        ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Current Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>
                <div class="branch-user-count">
                    <i class="fas fa-user"></i>
                    <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y, H:i'); ?>
                </span>
            </div>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-user-circle"></i> My Profile</h2>
                <span class="page-subtitle">Manage your account settings</span>
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
        PROFILE CONTAINER
        ============================================================ -->
        <div class="profile-container">
            
            <!-- ===== LEFT COLUMN - PROFILE PICTURE ===== -->
            <div class="profile-left">
                <div class="profile-card">
                    <div class="profile-avatar">
                        <img src="<?php echo $profile_pic; ?>" alt="Profile Picture" 
                             id="profileImage"
                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22 viewBox=%220 0 200 200%22%3E%3Crect width=%22200%22 height=%22200%22 fill=%22%23E5E7EB%22/%3E%3Ccircle cx=%22100%22 cy=%2280%22 r=%2250%22 fill=%22%239CA3AF%22/%3E%3Ccircle cx=%22100%22 cy=%22200%22 r=%2270%22 fill=%22%239CA3AF%22/%3E%3Ctext x=%22100%22 y=%22130%22 text-anchor=%22middle%22 font-size=%2240%22 fill=%22%236B7280%22 font-family=%22Arial%22 font-weight=%22bold%22><?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?></text%3E%3C/svg%3E'">
                        <div class="profile-avatar-overlay" onclick="document.getElementById('profilePicInput').click()">
                            <i class="fas fa-camera"></i>
                            <span>Change Photo</span>
                        </div>
                    </div>
                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></div>
                    <div class="profile-branch"><i class="fas fa-store-alt"></i> <?php echo htmlspecialchars($branch_name); ?></div>
                    
                    <!-- AJAX Upload Form -->
                    <form id="profilePicForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="ajax_upload_picture">
                        <input type="file" id="profilePicInput" name="profile_pic" accept="image/*" style="display:none">
                    </form>
                    
                    <!-- Upload Status -->
                    <div id="uploadStatus" style="display:none; text-align:center; padding:8px; margin:0 16px 12px; border-radius:6px; font-size:13px;"></div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <span class="stat-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></span>
                            <span class="stat-label">Joined</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $user['last_login'] ? date('d M Y', strtotime($user['last_login'])) : 'Never'; ?></span>
                            <span class="stat-label">Last Login</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== RIGHT COLUMN - PROFILE FORMS ===== -->
            <div class="profile-right">
                
                <!-- ===== EDIT PROFILE ===== -->
                <div class="profile-card form-card">
                    <div class="form-card-header">
                        <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
                        <span class="card-badge">Update your information</span>
                    </div>
                    <div class="form-card-body">
                        <form method="POST" action="" class="profile-form" id="profileForm" onsubmit="return validateProfileForm()">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="full_name">Full Name <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-user"></i></span>
                                        <input type="text" id="full_name" name="full_name" 
                                               value="<?php echo htmlspecialchars($user['full_name']); ?>" 
                                               class="form-control" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                        <input type="email" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user['email']); ?>" 
                                               class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-phone"></i></span>
                                        <input type="text" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                               class="form-control" placeholder="e.g., +255 700 000 000">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="address">Address</label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <input type="text" id="address" name="address" 
                                               value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" 
                                               class="form-control" placeholder="e.g., Dar es Salaam, Tanzania">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-submit" id="profileSubmitBtn">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- ===== CHANGE PASSWORD ===== -->
                <div class="profile-card form-card">
                    <div class="form-card-header">
                        <h3><i class="fas fa-key"></i> Change Password</h3>
                        <span class="card-badge">Security</span>
                    </div>
                    <div class="form-card-body">
                        <form method="POST" action="" class="profile-form" id="passwordForm" onsubmit="return validatePasswordForm()">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="current_password">Current Password <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                                        <input type="password" id="current_password" name="current_password" 
                                               class="form-control" placeholder="Enter current password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_password">New Password <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-key"></i></span>
                                        <input type="password" id="new_password" name="new_password" 
                                               class="form-control" placeholder="Min 6 characters" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small>Password must be at least 6 characters</small>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                                        <input type="password" id="confirm_password" name="confirm_password" 
                                               class="form-control" placeholder="Confirm new password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="password-requirements">
                                <span class="requirement" id="req-length"><i class="fas fa-circle"></i> At least 6 characters</span>
                                <span class="requirement" id="req-match"><i class="fas fa-circle"></i> Passwords match</span>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-submit" id="passwordSubmitBtn">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
            </div>
            
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --profile-bg: #F3F4F6;
    --profile-card-bg: #FFFFFF;
    --profile-text: #1F2937;
    --profile-text-secondary: #6B7280;
    --profile-text-light: #9CA3AF;
    --profile-border: #E5E7EB;
    --profile-input-bg: #F9FAFB;
    --profile-hover: #F3F4F6;
    --profile-shadow: rgba(0,0,0,0.06);
    --profile-shadow-lg: rgba(0,0,0,0.12);
    --profile-success-bg: #D1FAE5;
    --profile-success-text: #065F46;
    --profile-success-border: #A7F3D0;
    --profile-danger-bg: #FEE2E2;
    --profile-danger-text: #991B1B;
    --profile-danger-border: #FECACA;
}

html.dark-mode {
    --profile-bg: #111827;
    --profile-card-bg: #1F2937;
    --profile-text: #F9FAFB;
    --profile-text-secondary: #9CA3AF;
    --profile-text-light: #6B7280;
    --profile-border: #374151;
    --profile-input-bg: #374151;
    --profile-hover: #374151;
    --profile-shadow: rgba(0,0,0,0.3);
    --profile-shadow-lg: rgba(0,0,0,0.4);
    --profile-success-bg: #065F46;
    --profile-success-text: #D1FAE5;
    --profile-success-border: #047857;
    --profile-danger-bg: #7F1D1D;
    --profile-danger-text: #FEE2E2;
    --profile-danger-border: #991B1B;
}

body {
    background: var(--profile-bg) !important;
    color: var(--profile-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--profile-bg) !important;
}

.main-content {
    background: var(--profile-bg) !important;
}

/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 24px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
}

.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 30%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 13px;
    color: #FFFFFF;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-icon-wrapper {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.branch-indicator-label {
    font-size: 11px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-user-count {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #FFFFFF;
    padding: 4px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-user-count i {
    font-size: 13px;
}

.branch-indicator-right {
    position: relative;
    z-index: 1;
}

.branch-indicator-right .date-display {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}

.branch-indicator-right .date-display i {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
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
    color: var(--profile-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--profile-text-secondary);
    background: var(--profile-hover);
    padding: 3px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
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
    transition: all 0.3s ease;
}

.alert-success {
    background: var(--profile-success-bg);
    color: var(--profile-success-text);
    border: 1px solid var(--profile-success-border);
}

.alert-danger {
    background: var(--profile-danger-bg);
    color: var(--profile-danger-text);
    border: 1px solid var(--profile-danger-border);
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
   PROFILE CONTAINER
   ============================================================ */
.profile-container {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
}

/* ============================================================
   PROFILE LEFT - PROFILE CARD
   ============================================================ */
.profile-left {
    position: sticky;
    top: 20px;
}

.profile-card {
    background: var(--profile-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--profile-shadow);
    border: 1px solid var(--profile-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.profile-avatar {
    position: relative;
    width: 140px;
    height: 140px;
    border-radius: 50%;
    margin: 20px auto 12px;
    overflow: hidden;
    border: 4px solid #DC2626;
    cursor: pointer;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: opacity 0.3s ease;
}

.profile-avatar-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
    opacity: 0;
    transition: opacity 0.3s ease;
    cursor: pointer;
}

.profile-avatar-overlay i {
    font-size: 24px;
    margin-bottom: 4px;
}

.profile-avatar-overlay span {
    font-size: 12px;
    font-weight: 500;
}

.profile-avatar:hover .profile-avatar-overlay {
    opacity: 1;
}

.profile-name {
    text-align: center;
    font-size: 18px;
    font-weight: 700;
    color: var(--profile-text);
    padding: 0 16px;
}

.profile-role {
    text-align: center;
    font-size: 13px;
    color: #DC2626;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.profile-branch {
    text-align: center;
    font-size: 13px;
    color: var(--profile-text-secondary);
    padding: 0 16px 12px;
}

.profile-branch i {
    color: #3B82F6;
    margin-right: 4px;
}

.profile-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    border-top: 1px solid var(--profile-border);
    padding: 12px 0;
}

.stat-item {
    text-align: center;
    padding: 4px 0;
}

.stat-item .stat-value {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--profile-text);
}

.stat-item .stat-label {
    font-size: 10px;
    text-transform: uppercase;
    color: var(--profile-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* ============================================================
   PROFILE RIGHT - FORMS
   ============================================================ */
.profile-right {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-card {
    background: var(--profile-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--profile-shadow);
    border: 1px solid var(--profile-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.form-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--profile-border);
    background: var(--profile-hover);
}

.form-card-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--profile-text);
    margin: 0;
}

.form-card-header h3 i {
    color: #3B82F6;
    margin-right: 8px;
}

.card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 12px;
    border-radius: 12px;
    background: var(--profile-hover);
    color: var(--profile-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-card-body {
    padding: 20px;
}

/* ============================================================
   FORM STYLES
   ============================================================ */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--profile-text);
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
    color: var(--profile-text-light);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
    transition: color 0.3s ease;
}

.input-group .form-control {
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--profile-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--profile-input-bg);
    color: var(--profile-text);
    width: 100%;
}

.input-group .form-control:focus {
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.password-toggle {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    color: var(--profile-text-light);
    cursor: pointer;
    padding: 4px;
    font-size: 14px;
    transition: color 0.3s ease;
    z-index: 1;
}

.password-toggle:hover {
    color: var(--profile-text);
}

.form-group small {
    font-size: 12px;
    color: var(--profile-text-secondary);
    margin-top: 2px;
}

.form-actions {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--profile-border);
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

/* ============================================================
   PASSWORD REQUIREMENTS
   ============================================================ */
.password-requirements {
    display: flex;
    gap: 20px;
    margin: 8px 0 4px 0;
    flex-wrap: wrap;
}

.requirement {
    font-size: 12px;
    color: var(--profile-text-light);
    display: flex;
    align-items: center;
    gap: 4px;
}

.requirement i {
    font-size: 8px;
    color: #6B7280;
}

.requirement.valid i {
    color: #10B981;
}

.requirement.invalid i {
    color: #EF4444;
}

.requirement.valid {
    color: #10B981;
}

.requirement.invalid {
    color: #EF4444;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .profile-container {
        grid-template-columns: 1fr;
    }
    
    .profile-left {
        position: static;
    }
    
    .profile-avatar {
        width: 120px;
        height: 120px;
    }
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .profile-avatar {
        width: 100px;
        height: 100px;
    }
    
    .form-card-body {
        padding: 14px;
    }
    
    .password-requirements {
        flex-direction: column;
        gap: 4px;
    }
    
    .branch-indicator {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
        padding: 16px 18px;
    }
    
    .branch-indicator-left {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .branch-indicator-right {
        width: 100%;
    }
    
    .branch-indicator-right .date-display {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .profile-avatar {
        width: 80px;
        height: 80px;
    }
    
    .profile-name {
        font-size: 16px;
    }
    
    .profile-stats {
        grid-template-columns: 1fr;
    }
    
    .form-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
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
    animation: fadeInUp 0.4s ease forwards;
}

.profile-left .profile-card { animation-delay: 0.05s; }
.profile-right .form-card:first-child { animation-delay: 0.10s; }
.profile-right .form-card:last-child { animation-delay: 0.15s; }

.alert {
    animation: slideDown 0.4s ease forwards;
}

.branch-indicator {
    animation: fadeInUp 0.3s ease forwards;
}
</style>

<script>
// ============================================================
// TOGGLE PASSWORD VISIBILITY
// ============================================================
function togglePassword(inputId) {
    var input = document.getElementById(inputId);
    var button = input.parentElement.querySelector('.password-toggle');
    var icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// ============================================================
// VALIDATE PROFILE FORM
// ============================================================
function validateProfileForm() {
    var fullName = document.getElementById('full_name');
    if (!fullName || fullName.value.trim() === '') {
        alert('Please enter your full name.');
        fullName.focus();
        return false;
    }
    
    var email = document.getElementById('email');
    if (!email || email.value.trim() === '') {
        alert('Please enter your email.');
        email.focus();
        return false;
    }
    
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email.value.trim())) {
        alert('Please enter a valid email address.');
        email.focus();
        return false;
    }
    
    var submitBtn = document.getElementById('profileSubmitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// VALIDATE PASSWORD FORM
// ============================================================
function validatePasswordForm() {
    var currentPassword = document.getElementById('current_password');
    if (!currentPassword || currentPassword.value.trim() === '') {
        alert('Please enter your current password.');
        currentPassword.focus();
        return false;
    }
    
    var newPassword = document.getElementById('new_password');
    if (!newPassword || newPassword.value.trim() === '') {
        alert('Please enter a new password.');
        newPassword.focus();
        return false;
    }
    
    if (newPassword.value.length < 6) {
        alert('New password must be at least 6 characters.');
        newPassword.focus();
        return false;
    }
    
    var confirmPassword = document.getElementById('confirm_password');
    if (newPassword.value !== confirmPassword.value) {
        alert('Passwords do not match.');
        confirmPassword.focus();
        return false;
    }
    
    var submitBtn = document.getElementById('passwordSubmitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// AJAX PROFILE PICTURE UPLOAD
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var profilePicInput = document.getElementById('profilePicInput');
    var profileImage = document.getElementById('profileImage');
    var uploadStatus = document.getElementById('uploadStatus');
    
    if (profilePicInput) {
        profilePicInput.addEventListener('change', function(e) {
            var file = this.files[0];
            if (!file) return;
            
            // Show loading
            uploadStatus.style.display = 'block';
            uploadStatus.className = '';
            uploadStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            uploadStatus.style.background = '#FEF3C7';
            uploadStatus.style.color = '#92400E';
            
            // Create FormData
            var formData = new FormData();
            formData.append('action', 'ajax_upload_picture');
            formData.append('profile_pic', file);
            
            // Show preview immediately
            var reader = new FileReader();
            reader.onload = function(event) {
                profileImage.src = event.target.result;
            };
            reader.readAsDataURL(file);
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    // Update image with new URL
                    profileImage.src = data.image_url;
                    
                    uploadStatus.className = 'upload-success';
                    uploadStatus.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                    uploadStatus.style.background = '#D1FAE5';
                    uploadStatus.style.color = '#065F46';
                    
                    // Hide after 3 seconds
                    setTimeout(function() {
                        uploadStatus.style.display = 'none';
                    }, 3000);
                } else {
                    uploadStatus.className = 'upload-error';
                    uploadStatus.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                    uploadStatus.style.background = '#FEE2E2';
                    uploadStatus.style.color = '#991B1B';
                }
            })
            .catch(function(error) {
                uploadStatus.className = 'upload-error';
                uploadStatus.innerHTML = '<i class="fas fa-exclamation-circle"></i> Upload failed. Please try again.';
                uploadStatus.style.background = '#FEE2E2';
                uploadStatus.style.color = '#991B1B';
            });
        });
    }
});

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
    
    // Auto-hide alerts
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
});
</script>

</body>
</html>