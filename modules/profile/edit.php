<?php
// ================================================================
// FILE: modules/profile/edit.php
// EDIT PROFILE
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

try {
    // Get user profile
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get branches
    if ($role === 'admin' || $role === 'super_admin') {
        $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
        $stmt->execute();
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $branches = [];
    }

} catch (PDOException $e) {
    error_log("Error loading profile: " . $e->getMessage());
    $profile = null;
    $branches = [];
}

if (!$profile) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        
        // Validate
        if (empty($full_name)) {
            $error = 'Please enter full name';
        } elseif (empty($email)) {
            $error = 'Please enter email';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            // Check if email already exists for another user
            $stmt = $db->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                $error = 'Email already exists for another user';
            } else {
                // Upload profile picture
                $profile_pic = $profile['profile_pic'];
                if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../../uploads/profiles/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    // Delete old profile pic
                    if ($profile_pic && file_exists('../../' . $profile_pic)) {
                        unlink('../../' . $profile_pic);
                    }
                    $file_ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
                    $file_name = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target_file)) {
                        $profile_pic = 'uploads/profiles/' . $file_name;
                    }
                }

                $sql = "UPDATE employees SET 
                    full_name = ?,
                    email = ?,
                    phone = ?,
                    address = ?,
                    emergency_contact = ?,
                    emergency_phone = ?,
                    branch_id = ?,
                    profile_pic = ?
                    WHERE id = ?";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $full_name,
                    $email,
                    $phone,
                    $address,
                    $emergency_contact,
                    $emergency_phone,
                    $branch_id,
                    $profile_pic,
                    $user_id
                ]);

                logActivity($user_id, 'Edit Profile', 'Profile', $user_id);
                $success = 'Profile updated successfully!';
                header('Refresh: 2; URL=index.php');
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error updating profile: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Profile</h2>
                <p class="text-muted">Update your profile information</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Profile
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" enctype="multipart/form-data" class="profile-form">
                <!-- Profile Picture -->
                <div class="form-section">
                    <h4><i class="fas fa-image" style="color:#bb0404;"></i> Profile Picture</h4>
                    <div class="profile-pic-upload">
                        <div class="current-pic">
                            <?php if ($profile['profile_pic'] && file_exists('../../' . $profile['profile_pic'])): ?>
                                <img src="../../<?php echo htmlspecialchars($profile['profile_pic']); ?>" alt="Profile Picture" class="preview-img">
                            <?php else: ?>
                                <div class="placeholder-pic">
                                    <i class="fas fa-user fa-3x"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="upload-controls">
                            <input type="file" name="profile_pic" id="profile_pic" accept="image/*" class="file-input">
                            <label for="profile_pic" class="btn btn-upload">
                                <i class="fas fa-upload"></i> Choose Photo
                            </label>
                            <small class="form-text">Recommended: Square image, max 2MB</small>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="form-section">
                    <h4><i class="fas fa-user" style="color:#bb0404;"></i> Personal Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($profile['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($profile['username']); ?>" disabled>
                            <small class="form-text">Username cannot be changed</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Address</label>
                            <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($profile['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Branch (Admin only) -->
                <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                <div class="form-section">
                    <h4><i class="fas fa-building" style="color:#bb0404;"></i> Branch Assignment</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch</label>
                            <select name="branch_id" class="form-control">
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo $profile['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" class="form-control" value="<?php echo ucfirst(htmlspecialchars($profile['role'])); ?>" disabled>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Emergency Contact -->
                <div class="form-section">
                    <h4><i class="fas fa-phone-alt" style="color:#bb0404;"></i> Emergency Contact</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact" class="form-control" value="<?php echo htmlspecialchars($profile['emergency_contact'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Emergency Phone</label>
                            <input type="text" name="emergency_phone" class="form-control" value="<?php echo htmlspecialchars($profile['emergency_phone'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.form-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.form-section h4 i {
    margin-right: 8px;
}

.form-row {
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

.form-group label .required { color: #DC2626; }

.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.form-control:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

.form-text {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* Profile Picture Upload */
.profile-pic-upload {
    display: flex;
    align-items: center;
    gap: 30px;
    flex-wrap: wrap;
}

.current-pic {
    flex-shrink: 0;
}

.preview-img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #bb0404;
}

.placeholder-pic {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: var(--bg-table-even);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #bb0404;
    color: var(--text-muted);
}

.upload-controls {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.file-input {
    display: none;
}

.btn-upload {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.btn-upload:hover {
    background: var(--bg-table-hover);
    border-color: #bb0404;
}

/* Buttons */
.btn {
    padding: 8px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

/* Dark Mode */
:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
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
}

body.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
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
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

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
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .profile-pic-upload {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const btn = document.getElementById('darkModeToggle');
    if (document.body.classList.contains('dark-mode')) {
        btn.querySelector('i').className = 'fas fa-sun';
        btn.querySelector('span').textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        btn.querySelector('i').className = 'fas fa-moon';
        btn.querySelector('span').textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
});

// Preview image before upload
document.getElementById('profile_pic')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            const preview = document.querySelector('.preview-img, .placeholder-pic');
            if (preview) {
                if (preview.classList.contains('placeholder-pic')) {
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.className = 'preview-img';
                    img.alt = 'Profile Picture';
                    preview.parentNode.replaceChild(img, preview);
                } else {
                    preview.src = event.target.result;
                }
            }
        };
        reader.readAsDataURL(file);
    }
});
</script>

</body>
</html>