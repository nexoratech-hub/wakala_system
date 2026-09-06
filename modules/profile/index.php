<?php
// ================================================================
// FILE: modules/profile/index.php
// PROFILE - USER PROFILE DASHBOARD
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
    $stmt = $db->prepare("SELECT e.*, 
            b.branch_name as branch_name,
            (SELECT COUNT(*) FROM activity_logs WHERE employee_id = e.id) as total_activities,
            (SELECT MAX(created_at) FROM activity_logs WHERE employee_id = e.id) as last_activity
            FROM employees e
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get user permissions
    $stmt = $db->prepare("SELECT * FROM user_permissions WHERE role = ?");
    $stmt->execute([$role]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent activities
    $stmt = $db->prepare("SELECT * FROM activity_logs WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $stmt = $db->prepare("SELECT 
            COUNT(*) as total_actions,
            COUNT(DISTINCT module) as modules_accessed,
            DATE(created_at) as date
            FROM activity_logs 
            WHERE employee_id = ? 
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) DESC 
            LIMIT 7");
    $stmt->execute([$user_id]);
    $activity_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error loading profile: " . $e->getMessage());
    $profile = null;
    $permissions = [];
    $recent_activities = [];
    $activity_stats = [];
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-user-circle" style="color:#bb0404;"></i> My Profile</h2>
                <p class="text-muted">View and manage your profile information</p>
            </div>
            <div class="header-right">
                <a href="edit.php" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="change_password.php" class="btn btn-warning">
                    <i class="fas fa-key"></i> Change Password
                </a>
                <a href="../../logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <?php if ($profile): ?>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?php if ($profile['profile_pic'] && file_exists('../../' . $profile['profile_pic'])): ?>
                    <img src="../../<?php echo htmlspecialchars($profile['profile_pic']); ?>" alt="Profile Picture" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user fa-4x"></i>
                    </div>
                <?php endif; ?>
                <a href="edit.php" class="avatar-edit-btn" title="Change Photo">
                    <i class="fas fa-camera"></i>
                </a>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($profile['full_name']); ?></h3>
                <p class="username">@<?php echo htmlspecialchars($profile['username']); ?></p>
                <div class="profile-meta">
                    <span class="meta-item">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($profile['email']); ?>
                    </span>
                    <span class="meta-item">
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($profile['phone'] ?? 'N/A'); ?>
                    </span>
                    <span class="meta-item">
                        <i class="fas fa-briefcase"></i> <?php echo ucfirst(htmlspecialchars($profile['role'])); ?>
                    </span>
                    <span class="meta-item">
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($profile['branch_name'] ?? 'Main'); ?>
                    </span>
                </div>
                <div class="profile-status">
                    <span class="status-badge <?php echo $profile['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $profile['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                    <span class="status-badge <?php echo $profile['employment_status'] ?? 'active'; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $profile['employment_status'] ?? 'Active')); ?>
                    </span>
                </div>
            </div>
            <div class="profile-stats">
                <div class="stat-item">
                    <span class="stat-value"><?php echo number_format($profile['total_activities'] ?? 0); ?></span>
                    <span class="stat-label">Activities</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo date('d M Y', strtotime($profile['created_at'])); ?></span>
                    <span class="stat-label">Joined</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo $profile['last_login'] ? date('d M Y', strtotime($profile['last_login'])) : 'Never'; ?></span>
                    <span class="stat-label">Last Login</span>
                </div>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="profile-content">
            
            <!-- Left Column -->
            <div class="profile-left">
                <!-- Personal Information -->
                <div class="info-card">
                    <h4><i class="fas fa-user" style="color:#bb0404;"></i> Personal Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">Employee ID</span>
                            <span class="value"><?php echo htmlspecialchars($profile['employee_id']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Full Name</span>
                            <span class="value"><?php echo htmlspecialchars($profile['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Username</span>
                            <span class="value"><?php echo htmlspecialchars($profile['username']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Email</span>
                            <span class="value"><?php echo htmlspecialchars($profile['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Phone</span>
                            <span class="value"><?php echo htmlspecialchars($profile['phone'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Role</span>
                            <span class="value"><?php echo ucfirst(htmlspecialchars($profile['role'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Branch</span>
                            <span class="value"><?php echo htmlspecialchars($profile['branch_name'] ?? 'Main'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Status</span>
                            <span class="value">
                                <span class="status-badge <?php echo $profile['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $profile['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </span>
                        </div>
                        <?php if ($profile['hire_date']): ?>
                        <div class="info-item">
                            <span class="label">Hire Date</span>
                            <span class="value"><?php echo date('d M Y', strtotime($profile['hire_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($profile['base_salary'] > 0): ?>
                        <div class="info-item">
                            <span class="label">Base Salary</span>
                            <span class="value"><?php echo formatCurrency($profile['base_salary']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Address & Emergency Contact -->
                <?php if ($profile['address'] || $profile['emergency_contact'] || $profile['emergency_phone']): ?>
                <div class="info-card">
                    <h4><i class="fas fa-address-card" style="color:#bb0404;"></i> Additional Information</h4>
                    <div class="info-grid">
                        <?php if ($profile['address']): ?>
                        <div class="info-item full">
                            <span class="label">Address</span>
                            <span class="value"><?php echo nl2br(htmlspecialchars($profile['address'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($profile['emergency_contact']): ?>
                        <div class="info-item">
                            <span class="label">Emergency Contact</span>
                            <span class="value"><?php echo htmlspecialchars($profile['emergency_contact']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($profile['emergency_phone']): ?>
                        <div class="info-item">
                            <span class="label">Emergency Phone</span>
                            <span class="value"><?php echo htmlspecialchars($profile['emergency_phone']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="profile-right">
                <!-- Permissions -->
                <div class="info-card">
                    <h4><i class="fas fa-lock" style="color:#bb0404;"></i> Permissions</h4>
                    <?php if (count($permissions) > 0): ?>
                        <div class="permissions-grid">
                            <?php foreach ($permissions as $perm): ?>
                                <div class="permission-item">
                                    <span class="perm-module"><?php echo ucfirst(htmlspecialchars($perm['module'])); ?></span>
                                    <div class="perm-badges">
                                        <?php if ($perm['can_view']): ?>
                                            <span class="perm-badge view">View</span>
                                        <?php endif; ?>
                                        <?php if ($perm['can_add']): ?>
                                            <span class="perm-badge add">Add</span>
                                        <?php endif; ?>
                                        <?php if ($perm['can_edit']): ?>
                                            <span class="perm-badge edit">Edit</span>
                                        <?php endif; ?>
                                        <?php if ($perm['can_delete']): ?>
                                            <span class="perm-badge delete">Delete</span>
                                        <?php endif; ?>
                                        <?php if ($perm['can_export']): ?>
                                            <span class="perm-badge export">Export</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No permissions defined for this role.</p>
                    <?php endif; ?>
                </div>

                <!-- Recent Activities -->
                <div class="info-card">
                    <h4><i class="fas fa-history" style="color:#bb0404;"></i> Recent Activities</h4>
                    <?php if (count($recent_activities) > 0): ?>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas <?php echo getActivityIcon($activity['action']); ?>"></i>
                                    </div>
                                    <div class="activity-info">
                                        <span class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></span>
                                        <span class="activity-module"><?php echo htmlspecialchars($activity['module']); ?></span>
                                        <span class="activity-time"><?php echo timeAgo($activity['created_at']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="view-all">
                            <a href="../activity_logs/index.php?employee=<?php echo $user_id; ?>" class="btn btn-link">
                                View All Activities <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No recent activities.</p>
                    <?php endif; ?>
                </div>

                <!-- Activity Stats Chart -->
                <?php if (count($activity_stats) > 0): ?>
                <div class="info-card">
                    <h4><i class="fas fa-chart-bar" style="color:#bb0404;"></i> Activity Summary (Last 7 Days)</h4>
                    <div class="chart-container">
                        <?php foreach (array_reverse($activity_stats) as $stat): ?>
                            <div class="chart-bar">
                                <div class="bar-label"><?php echo date('d M', strtotime($stat['date'])); ?></div>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width: <?php echo min(100, ($stat['total_actions'] / max(array_column($activity_stats, 'total_actions'))) * 100); ?>%;">
                                        <?php echo $stat['total_actions']; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> Profile not found. Please contact administrator.
            </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Profile Header */
.profile-header {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px 30px;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 30px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.profile-avatar {
    position: relative;
    flex-shrink: 0;
}

.avatar-img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #bb0404;
}

.avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: var(--bg-table-even);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 4px solid #bb0404;
    color: var(--text-muted);
}

.avatar-edit-btn {
    position: absolute;
    bottom: 0;
    right: 0;
    background: #bb0404;
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 2px solid var(--bg-card);
}

.avatar-edit-btn:hover {
    background: #8a0303;
    transform: scale(1.1);
}

.profile-info {
    flex: 1;
}

.profile-info h3 {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.profile-info .username {
    color: var(--text-muted);
    font-size: 14px;
    margin: 2px 0 10px 0;
}

.profile-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 10px;
}

.meta-item {
    font-size: 13px;
    color: var(--text-secondary);
}

.meta-item i {
    color: #bb0404;
    width: 18px;
}

.profile-status {
    display: flex;
    gap: 8px;
}

.profile-stats {
    display: flex;
    gap: 30px;
    flex-shrink: 0;
}

.stat-item {
    text-align: center;
}

.stat-value {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Profile Content */
.profile-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.profile-left, .profile-right {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Info Cards */
.info-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 20px 24px;
    border: 1px solid var(--border-color);
}

.info-card h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.info-card h4 i {
    margin-right: 8px;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 24px;
}

.info-item.full {
    grid-column: span 2;
}

.info-item .label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
}

.info-item .value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.status-badge.active { background: #D1FAE5; color: #065F46; }
.status-badge.inactive { background: #FEE2E2; color: #991B1B; }

/* Permissions */
.permissions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.permission-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 10px;
    background: var(--bg-table-even);
    border-radius: 6px;
}

.perm-module {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
}

.perm-badges {
    display: flex;
    gap: 4px;
}

.perm-badge {
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
}
.perm-badge.view { background: #DBEAFE; color: #1D4ED8; }
.perm-badge.add { background: #D1FAE5; color: #065F46; }
.perm-badge.edit { background: #FEF3C7; color: #92400E; }
.perm-badge.delete { background: #FEE2E2; color: #991B1B; }
.perm-badge.export { background: #EDE9FE; color: #6D28D9; }

/* Activities */
.activity-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: 6px;
    background: var(--bg-table-even);
    transition: background 0.2s ease;
}

.activity-item:hover {
    background: var(--bg-table-hover);
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #bb040410;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #bb0404;
    flex-shrink: 0;
}

.activity-info {
    flex: 1;
}

.activity-action {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
}

.activity-module {
    font-size: 12px;
    color: var(--text-muted);
    margin-left: 8px;
}

.activity-time {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
}

.view-all {
    margin-top: 12px;
    text-align: center;
}

.btn-link {
    background: none;
    border: none;
    color: #bb0404;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: color 0.2s ease;
}

.btn-link:hover {
    color: #8a0303;
    text-decoration: underline;
}

/* Chart */
.chart-container {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.chart-bar {
    display: flex;
    align-items: center;
    gap: 10px;
}

.bar-label {
    font-size: 11px;
    color: var(--text-muted);
    width: 50px;
    flex-shrink: 0;
    text-align: right;
}

.bar-track {
    flex: 1;
    height: 24px;
    background: var(--bg-table-even);
    border-radius: 12px;
    overflow: hidden;
    position: relative;
}

.bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #bb0404, #e64040);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 8px;
    color: white;
    font-size: 11px;
    font-weight: 600;
    transition: width 1s ease;
    min-width: 30px;
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

.btn-warning { background: #F59E0B; color: #1F2937; }
.btn-warning:hover { background: #D97706; transform: translateY(-1px); }

.btn-danger { background: #DC2626; color: white; }
.btn-danger:hover { background: #991B1B; transform: translateY(-1px); }

.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

.text-muted { color: var(--text-muted); }

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

/* Responsive */
@media (max-width: 1024px) {
    .profile-content {
        grid-template-columns: 1fr;
    }
    .profile-stats {
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    .profile-meta {
        justify-content: center;
    }
    .profile-status {
        justify-content: center;
    }
    .profile-stats {
        justify-content: center;
        width: 100%;
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
    .info-item.full {
        grid-column: span 1;
    }
    .permissions-grid {
        grid-template-columns: 1fr;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .header-right {
        width: 100%;
        flex-wrap: wrap;
    }
    .header-right .btn {
        flex: 1;
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

function getActivityIcon(action) {
    const icons = {
        'Add': 'fa-plus-circle',
        'Edit': 'fa-edit',
        'Update': 'fa-pen',
        'Delete': 'fa-trash',
        'Remove': 'fa-trash-alt',
        'View': 'fa-eye',
        'Login': 'fa-sign-in-alt',
        'Logout': 'fa-sign-out-alt',
        'Generate': 'fa-file-alt',
        'Create': 'fa-plus-circle',
        'Save': 'fa-save'
    };
    
    for (let [key, value] of Object.entries(icons)) {
        if (action.toLowerCase().includes(key.toLowerCase())) {
            return value;
        }
    }
    return 'fa-circle';
}

function timeAgo(date) {
    const diff = Math.floor((new Date() - new Date(date)) / 1000);
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return new Date(date).toLocaleDateString();
}
</script>

</body>
</html>