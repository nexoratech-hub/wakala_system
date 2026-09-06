<?php
// ================================================================
// FILE: modules/settings/index.php
// SETTINGS - SYSTEM SETTINGS DASHBOARD
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';

// Only admin and super_admin can access settings
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
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
                <h2><i class="fas fa-cogs" style="color:#bb0404;"></i> System Settings</h2>
                <p class="text-muted">Manage system configuration and preferences</p>
            </div>
        </div>

        <!-- Settings Grid -->
        <div class="settings-grid">
            <!-- General Settings -->
            <a href="general.php" class="settings-card">
                <div class="settings-icon" style="background:#DBEAFE;color:#1D4ED8;">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="settings-info">
                    <h4>General Settings</h4>
                    <p>Company name, address, contact details</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Financial Settings -->
            <a href="financial.php" class="settings-card">
                <div class="settings-icon" style="background:#D1FAE5;color:#065F46;">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="settings-info">
                    <h4>Financial Settings</h4>
                    <p>Currency, opening capital, tax rates</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Branch Settings -->
            <a href="branches.php" class="settings-card">
                <div class="settings-icon" style="background:#EDE9FE;color:#6D28D9;">
                    <i class="fas fa-building"></i>
                </div>
                <div class="settings-info">
                    <h4>Branch Management</h4>
                    <p>Manage branches and locations</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Employee Settings -->
            <a href="employees.php" class="settings-card">
                <div class="settings-icon" style="background:#FEF3C7;color:#92400E;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="settings-info">
                    <h4>Employee Management</h4>
                    <p>Manage employees and roles</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Provider Settings -->
            <a href="providers.php" class="settings-card">
                <div class="settings-icon" style="background:#FEE2E2;color:#991B1B;">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="settings-info">
                    <h4>Provider Management</h4>
                    <p>Manage service providers</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Expense Categories -->
            <a href="expense_categories.php" class="settings-card">
                <div class="settings-icon" style="background:#E0E7FF;color:#3730A3;">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="settings-info">
                    <h4>Expense Categories</h4>
                    <p>Manage expense categories</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Permission Settings -->
            <a href="permissions.php" class="settings-card">
                <div class="settings-icon" style="background:#FCE4EC;color:#C62828;">
                    <i class="fas fa-lock"></i>
                </div>
                <div class="settings-info">
                    <h4>Permissions</h4>
                    <p>Manage user permissions</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Activity Logs -->
            <a href="../activity_logs/index.php" class="settings-card">
                <div class="settings-icon" style="background:#F3E5F5;color:#6A1B9A;">
                    <i class="fas fa-history"></i>
                </div>
                <div class="settings-info">
                    <h4>Activity Logs</h4>
                    <p>View system activity logs</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>

            <!-- Backup -->
            <a href="backup.php" class="settings-card">
                <div class="settings-icon" style="background:#FFF3E0;color:#E65100;">
                    <i class="fas fa-database"></i>
                </div>
                <div class="settings-info">
                    <h4>Backup</h4>
                    <p>Database backup and restore</p>
                </div>
                <i class="fas fa-chevron-right settings-arrow"></i>
            </a>
        </div>

        <!-- System Info -->
        <div class="system-info">
            <h4><i class="fas fa-info-circle" style="color:#bb0404;"></i> System Information</h4>
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">System Version</span>
                    <span class="value">1.0.0</span>
                </div>
                <div class="info-item">
                    <span class="label">PHP Version</span>
                    <span class="value"><?php echo phpversion(); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Database</span>
                    <span class="value">MySQL</span>
                </div>
                <div class="info-item">
                    <span class="label">Server Time</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s'); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Timezone</span>
                    <span class="value"><?php echo date_default_timezone_get(); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Memory Limit</span>
                    <span class="value"><?php echo ini_get('memory_limit'); ?></span>
                </div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Settings Grid */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.settings-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 20px 24px;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 18px;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
}

.settings-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px var(--shadow-hover);
    border-color: #bb0404;
}

.settings-card:hover .settings-arrow {
    transform: translateX(4px);
    color: #bb0404;
}

.settings-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.settings-info {
    flex: 1;
}

.settings-info h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.settings-info p {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.settings-arrow {
    color: var(--text-muted);
    font-size: 14px;
    transition: all 0.3s ease;
}

/* System Info */
.system-info {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 20px 24px;
    border: 1px solid var(--border-color);
}

.system-info h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.system-info h4 i {
    margin-right: 8px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
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

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
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
    --shadow-hover: rgba(0,0,0,0.1);
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

@media (max-width: 1024px) {
    .settings-grid {
        grid-template-columns: 1fr 1fr;
    }
    .info-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
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
</script>

</body>
</html>