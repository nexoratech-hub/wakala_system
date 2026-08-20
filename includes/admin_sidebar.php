<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\includes\admin_sidebar.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN SIDEBAR
// PURE RED BACKGROUND - WHITE FONTS
// ================================================================

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

function isActive($page, $dir = null) {
    global $current_page, $current_dir;
    if ($dir && $current_dir == $dir) return 'active';
    if ($current_page == $page) return 'active';
    return '';
}

// Profile image
$profile_image = '../../assets/images/logo.PNG';
?>
<!-- ============================================================
ADMIN SIDEBAR - PURE RED
============================================================ -->
<nav class="admin-sidebar" id="adminSidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <img src="<?php echo $profile_image; ?>" alt="Wakala" 
             onerror="this.src='../../assets/images/default-avatar.png'">
        <h3>Wakala</h3>
        <small>Financial System</small>
    </div>
    
    <!-- Menu -->
    <ul class="sidebar-menu">
        <li class="menu-label">Main</li>
        <li>
            <a href="../dashboard/admin.php" class="<?php echo isActive('admin.php', 'dashboard'); ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </li>
        
        <li class="menu-label">Daily Operations</li>
        <li>
            <a href="../morning_report/index.php" class="<?php echo isActive('index.php', 'morning_report'); ?>">
                <i class="fas fa-sun"></i> Morning Report
            </a>
        </li>
        <li>
            <a href="../evening_stock/index.php" class="<?php echo isActive('index.php', 'evening_stock'); ?>">
                <i class="fas fa-moon"></i> Evening Stock
            </a>
        </li>
        <li>
            <a href="../daily_report/index.php" class="<?php echo isActive('index.php', 'daily_report'); ?>">
                <i class="fas fa-file-alt"></i> Daily Report
            </a>
        </li>
        
        <li class="menu-label">Financial</li>
        <li>
            <a href="../commissions/index.php" class="<?php echo isActive('index.php', 'commissions'); ?>">
                <i class="fas fa-hand-holding-usd"></i> Commissions
            </a>
        </li>
        <li>
            <a href="../expenses/index.php" class="<?php echo isActive('index.php', 'expenses'); ?>">
                <i class="fas fa-receipt"></i> Expenses
            </a>
        </li>
        <li>
            <a href="../store_cash_out/index.php" class="<?php echo isActive('index.php', 'store_cash_out'); ?>">
                <i class="fas fa-money-bill-wave"></i> Cash Out
            </a>
        </li>
        <li>
            <a href="../capital_management/index.php" class="<?php echo isActive('index.php', 'capital_management'); ?>">
                <i class="fas fa-building"></i> Capital
            </a>
        </li>
        <li>
            <a href="../salaries/index.php" class="<?php echo isActive('index.php', 'salaries'); ?>">
                <i class="fas fa-wallet"></i> Salaries
            </a>
        </li>
        
        <li class="menu-label">Reports</li>
        <li>
            <a href="../reports/index.php" class="<?php echo isActive('index.php', 'reports'); ?>">
                <i class="fas fa-chart-bar"></i> All Reports
            </a>
        </li>
        
        <li class="menu-label">Management</li>
        <li>
            <a href="../employees/index.php" class="<?php echo isActive('index.php', 'employees'); ?>">
                <i class="fas fa-users"></i> Employees
            </a>
        </li>
        <li>
            <a href="../activity_logs/index.php" class="<?php echo isActive('index.php', 'activity_logs'); ?>">
                <i class="fas fa-history"></i> Activity Logs
            </a>
        </li>
        <li>
            <a href="../settings/index.php" class="<?php echo isActive('index.php', 'settings'); ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
        </li>
        
        <li class="menu-label">Account</li>
        <li>
            <a href="../profile/index.php" class="<?php echo isActive('index.php', 'profile'); ?>">
                <i class="fas fa-user"></i> Profile
            </a>
        </li>
        <li>
            <a href="../../logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</nav>

<!-- ============================================================
SIDEBAR STYLES
============================================================ -->
<style>
/* ============================================================
   ADMIN SIDEBAR - PURE RED #8B0000
   ============================================================ */
.admin-sidebar {
    width: 260px;
    background: #8B0000; /* PURE RED */
    min-height: 100vh;
    padding: 20px 0;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    overflow-y: auto;
    color: #FFFFFF; /* PURE WHITE */
    z-index: 1000;
    transition: transform 0.3s ease;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.3) transparent;
}

.admin-sidebar::-webkit-scrollbar {
    width: 4px;
}

.admin-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.admin-sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 4px;
}

/* Brand */
.sidebar-brand {
    text-align: center;
    padding: 15px 20px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-brand img {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.3);
    background: white;
    padding: 3px;
}

.sidebar-brand h3 {
    margin-top: 10px;
    font-size: 16px;
    font-weight: 700;
    color: #FFFFFF;
}

.sidebar-brand small {
    font-size: 11px;
    opacity: 0.7;
    color: rgba(255,255,255,0.7);
}

/* Menu */
.sidebar-menu {
    list-style: none;
    padding: 0 12px;
    margin-top: 10px;
}

.sidebar-menu li {
    margin-bottom: 2px;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 11px 15px;
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.3s ease;
    font-size: 14px;
    font-weight: 500;
}

.sidebar-menu a i {
    width: 22px;
    margin-right: 14px;
    font-size: 15px;
    color: rgba(255,255,255,0.6);
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,0.12);
    color: #FFFFFF;
}

.sidebar-menu a:hover i {
    color: #FFFFFF;
}

.sidebar-menu a.active {
    background: rgba(255,255,255,0.18);
    color: #FFFFFF;
}

.sidebar-menu a.active i {
    color: #FFFFFF;
}

/* Menu Labels */
.sidebar-menu .menu-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    opacity: 0.5;
    padding: 15px 15px 6px;
    color: rgba(255,255,255,0.5);
    font-weight: 600;
}

/* Logout Link */
.logout-link {
    color: #FF6B6B !important;
    margin-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.08);
    padding-top: 15px !important;
}

.logout-link:hover {
    background: rgba(255,0,0,0.2) !important;
    color: #FF4444 !important;
}

.logout-link i {
    color: #FF6B6B !important;
}

/* Mobile Toggle */
.sidebar-toggle-btn {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1001;
    background: #8B0000;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 18px;
    cursor: pointer;
}

/* Responsive */
@media (max-width: 768px) {
    .admin-sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
    
    .admin-sidebar.open {
        transform: translateX(0);
    }
    
    .sidebar-toggle-btn {
        display: block;
    }
    
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }
    
    .sidebar-overlay.active {
        display: block;
    }
}

@media (max-width: 480px) {
    .admin-sidebar {
        width: 100%;
        max-width: 320px;
    }
}
</style>

<!-- Mobile Toggle Button -->
<button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
// ============================================================
// SIDEBAR TOGGLE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    
    function toggleSidebar() {
        sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('active');
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            toggleSidebar();
        }
    });
});
</script>