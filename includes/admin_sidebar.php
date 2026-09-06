<?php
// ================================================================
// FILE: includes/sidebar.php
// WAKALA SYSTEM - SIDEBAR WITH RED #bb0404
// ================================================================

// ============================================================
// GET COMPANY NAME FROM DATABASE
// ============================================================
$company_name = 'Wakala System'; // Default name

try {
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $company_name = $result['setting_value'];
        }
    }
} catch (Exception $e) {
    $company_name = 'Wakala System';
}

// Get user data
$full_name = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'Employee';
$user_initial = strtoupper(substr($full_name, 0, 1));
?>

<style>
/* ============================================================
   SIDEBAR - RED #bb0404 THEME
   NO HOVER OVERRIDE - USES SPECIFIC CLASSES
   ============================================================ */

/* SIDEBAR CONTAINER - RED #bb0404 */
.wakala-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    background: #bb0404;
    color: #ffffff;
    z-index: 999;
    transition: all 0.3s ease;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 20px rgba(187, 4, 4, 0.4);
}

/* SIDEBAR SCROLLBAR */
.wakala-sidebar::-webkit-scrollbar {
    width: 4px;
}
.wakala-sidebar::-webkit-scrollbar-track {
    background: #8a0303;
}
.wakala-sidebar::-webkit-scrollbar-thumb {
    background: #ffffff;
    border-radius: 4px;
}

/* ============================================================
   LOGO SECTION - LARGE
   ============================================================ */
.wakala-sidebar .sidebar-logo {
    padding: 20px 16px 16px 16px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    flex-shrink: 0;
}

.wakala-sidebar .sidebar-logo img {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ffffff;
    box-shadow: 0 0 25px rgba(255,255,255,0.15);
    transition: all 0.3s ease;
}

.wakala-sidebar .sidebar-logo img:hover {
    transform: scale(1.05);
    box-shadow: 0 0 35px rgba(255,255,255,0.25);
}

.wakala-sidebar .sidebar-logo .logo-text {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    margin-top: 10px;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.wakala-sidebar .sidebar-logo .logo-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    letter-spacing: 2px;
    text-transform: uppercase;
    font-weight: 300;
}

/* ============================================================
   USER INFO
   ============================================================ */
.wakala-sidebar .sidebar-user {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.wakala-sidebar .sidebar-user .user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: #ffffff;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.3);
}

.wakala-sidebar .sidebar-user .user-name {
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
}

.wakala-sidebar .sidebar-user .user-role {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
}

/* ============================================================
   NAVIGATION MENU
   ============================================================ */
.wakala-sidebar .sidebar-nav {
    flex: 1;
    padding: 10px 0 10px 0;
}

.wakala-sidebar .sidebar-nav .nav-section {
    padding: 0 12px;
    margin-bottom: 4px;
}

.wakala-sidebar .sidebar-nav .nav-section .section-title {
    font-size: 10px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    letter-spacing: 1.5px;
    padding: 8px 12px 4px 12px;
    font-weight: 600;
}

/* ============================================================
   NAV ITEMS
   ============================================================ */
.wakala-sidebar .sidebar-nav .nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    margin: 2px 0;
    border-radius: 8px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
}

/* HOVER - WHITE */
.wakala-sidebar .sidebar-nav .nav-item:hover {
    background: rgba(255,255,255,0.15);
    color: #ffffff;
}

/* ACTIVE - WHITE BACKGROUND */
.wakala-sidebar .sidebar-nav .nav-item.active {
    background: rgba(255,255,255,0.2);
    color: #ffffff;
    font-weight: 600;
}

/* ACTIVE INDICATOR BAR - WHITE */
.wakala-sidebar .sidebar-nav .nav-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 24px;
    background: #ffffff;
    border-radius: 0 4px 4px 0;
}

/* ICONS */
.wakala-sidebar .sidebar-nav .nav-item i {
    width: 20px;
    text-align: center;
    font-size: 15px;
    flex-shrink: 0;
}

/* BADGE */
.wakala-sidebar .sidebar-nav .nav-item .nav-badge {
    margin-left: auto;
    background: rgba(255,255,255,0.2);
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

/* SUB MENU */
.wakala-sidebar .sidebar-nav .nav-item.has-sub {
    cursor: pointer;
}

.wakala-sidebar .sidebar-nav .nav-item .sub-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
    font-size: 11px;
}

.wakala-sidebar .sidebar-nav .nav-item .sub-arrow.open {
    transform: rotate(180deg);
}

.wakala-sidebar .sidebar-nav .sub-menu {
    padding-left: 20px;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease;
}

.wakala-sidebar .sidebar-nav .sub-menu.open {
    max-height: 500px;
}

.wakala-sidebar .sidebar-nav .sub-menu .nav-item {
    padding: 8px 16px;
    font-size: 12px;
    padding-left: 48px;
}

.wakala-sidebar .sidebar-nav .sub-menu .nav-item i {
    font-size: 12px;
    width: 16px;
}

/* ============================================================
   FOOTER / BOTTOM
   ============================================================ */
.wakala-sidebar .sidebar-footer {
    padding: 12px 16px;
    border-top: 1px solid rgba(255,255,255,0.15);
    flex-shrink: 0;
}

.wakala-sidebar .sidebar-footer .logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.wakala-sidebar .sidebar-footer .logout-btn:hover {
    background: rgba(255,255,255,0.15);
    color: #ffffff;
}

.wakala-sidebar .sidebar-footer .logout-btn i {
    font-size: 15px;
    width: 20px;
    text-align: center;
}

/* ============================================================
   RESPONSIVE - MOBILE
   ============================================================ */
@media (max-width: 768px) {
    .wakala-sidebar {
        width: 0;
        overflow: hidden;
    }
    
    .wakala-sidebar.mobile-open {
        width: 280px;
        overflow-y: auto;
    }
    
    .wakala-sidebar .sidebar-logo img {
        width: 60px;
        height: 60px;
    }
    
    .wakala-sidebar .sidebar-logo .logo-text {
        font-size: 16px;
    }
}

/* ============================================================
   MOBILE TOGGLE BUTTON
   ============================================================ */
.sidebar-toggle-btn {
    display: none;
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 1000;
    background: #bb0404;
    color: #ffffff;
    border: 2px solid #ffffff;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.sidebar-toggle-btn:hover {
    background: #ffffff;
    color: #bb0404;
}

@media (max-width: 768px) {
    .sidebar-toggle-btn {
        display: block;
    }
}

/* ============================================================
   OVERLAY FOR MOBILE
   ============================================================ */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 998;
}

.sidebar-overlay.active {
    display: block;
}

/* ============================================================
   MAIN CONTENT OFFSET
   ============================================================ */
.main-wrapper {
    margin-left: 260px;
    min-height: 100vh;
    transition: margin-left 0.3s ease;
}

@media (max-width: 768px) {
    .main-wrapper {
        margin-left: 0;
    }
}

/* ============================================================
   SIDEBAR COLLAPSED STATE
   ============================================================ */
.wakala-sidebar.collapsed {
    width: 72px;
}

.wakala-sidebar.collapsed .sidebar-logo .logo-text,
.wakala-sidebar.collapsed .sidebar-logo .logo-sub,
.wakala-sidebar.collapsed .sidebar-user .user-name,
.wakala-sidebar.collapsed .sidebar-user .user-role,
.wakala-sidebar.collapsed .sidebar-nav .nav-item span:not(.nav-badge),
.wakala-sidebar.collapsed .sidebar-footer .logout-btn span {
    display: none;
}

.wakala-sidebar.collapsed .sidebar-nav .nav-item {
    justify-content: center;
    padding: 12px;
}

.wakala-sidebar.collapsed .sidebar-nav .nav-item i {
    font-size: 18px;
    width: auto;
}

.wakala-sidebar.collapsed .sidebar-nav .nav-item .nav-badge {
    display: none;
}

.wakala-sidebar.collapsed .sidebar-nav .sub-menu {
    display: none;
}

.wakala-sidebar.collapsed .sidebar-logo img {
    width: 44px;
    height: 44px;
}

.wakala-sidebar.collapsed .sidebar-user .user-avatar {
    width: 32px;
    height: 32px;
    font-size: 12px;
}

.wakala-sidebar.collapsed .sidebar-footer .logout-btn {
    justify-content: center;
}

.wakala-sidebar.collapsed .sidebar-footer .logout-btn i {
    font-size: 18px;
}

.wakala-sidebar.collapsed .sidebar-logo {
    padding: 12px 8px;
}

.wakala-sidebar.collapsed .sidebar-user {
    padding: 10px 8px;
    justify-content: center;
}

.wakala-sidebar.collapsed .sidebar-nav .nav-item.active::before {
    width: 3px;
    height: 20px;
}

.main-wrapper.expanded {
    margin-left: 72px;
}

@media (max-width: 768px) {
    .wakala-sidebar.collapsed {
        width: 0;
        overflow: hidden;
    }
    .main-wrapper.expanded {
        margin-left: 0;
    }
}

/* ============================================================
   TOGGLE BUTTON INSIDE SIDEBAR
   ============================================================ */
.sidebar-collapse-toggle {
    position: absolute;
    bottom: 80px;
    right: -12px;
    width: 24px;
    height: 24px;
    background: #ffffff;
    border: none;
    border-radius: 50%;
    color: #bb0404;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.sidebar-collapse-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 16px rgba(0,0,0,0.3);
}

@media (max-width: 768px) {
    .sidebar-collapse-toggle {
        display: none;
    }
}
</style>

<!-- ============================================================
SIDEBAR HTML
============================================================ -->
<!-- Mobile Toggle Button -->
<button class="sidebar-toggle-btn" id="sidebarToggleBtn">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar - RED #bb0404 -->
<aside class="wakala-sidebar" id="mainSidebar">
    
    <!-- Logo -->
    <div class="sidebar-logo">
        <img src="../../assets/images/logo.PNG" alt="<?php echo htmlspecialchars($company_name); ?> Logo">
        <div class="logo-text"><?php echo htmlspecialchars($company_name); ?></div>
        <div class="logo-sub">Financial Management</div>
    </div>
    
    <!-- User Info -->
    <div class="sidebar-user">
        <div class="user-avatar"><?php echo $user_initial; ?></div>
        <div>
            <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
            <div class="user-role"><?php echo ucfirst($role); ?></div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="sidebar-nav">
        
        <!-- Dashboard -->
        <div class="nav-section">
            <div class="section-title">Main</div>
            <a href="../dashboard/admin.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>
        
        <!-- Reports Section -->
        <div class="nav-section">
            <div class="section-title">Reports</div>
            
            <a href="../morning_report/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'morning_report') !== false ? 'active' : ''; ?>">
                <i class="fas fa-sun"></i>
                <span>Morning Report</span>
            </a>
            
            <a href="../evening_stock/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'evening_stock') !== false ? 'active' : ''; ?>">
                <i class="fas fa-moon"></i>
                <span>Evening Stock</span>
            </a>
            
            <a href="../daily_report/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'daily_report') !== false ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>Daily Report</span>
            </a>
        </div>
        
        <!-- Financial Section -->
        <div class="nav-section">
            <div class="section-title">Financial</div>
            
            <a href="../commissions/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'commissions') !== false ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Commissions</span>
            </a>
            
            <a href="../expenses/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'expenses') !== false ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i>
                <span>Expenses</span>
            </a>
            
            <a href="../store_cash_out/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'store_cash_out') !== false ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Cash Out</span>
            </a>
            
            <a href="../capital_management/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'capital_management') !== false ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span>Capital</span>
            </a>
            
            <a href="../salaries/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'salaries') !== false ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span>Salaries</span>
            </a>
        </div>
        
        <!-- Management Section -->
        <div class="nav-section">
            <div class="section-title">Management</div>
            
            <!-- ===== PROVIDERS - NEW ===== -->
            <a href="../providers/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'providers') !== false ? 'active' : ''; ?>">
                <i class="fas fa-university"></i>
                <span>Providers</span>
            </a>
            
            <?php if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'super_admin'): ?>
                <a href="../branches/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'branches') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-store-alt"></i>
                    <span>Branches</span>
                </a>
            <?php endif; ?>
        </div>
        
        <!-- System Section -->
        <div class="nav-section">
            <div class="section-title">System</div>
            
            <a href="../reports/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Reports</span>
            </a>
            
            <?php if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'super_admin'): ?>
                <a href="../employees/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'employees') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Employees</span>
                </a>
                
                <a href="../settings/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                
                <a href="../activity_logs/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'activity_logs') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Activity Log</span>
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Profile -->
        <div class="nav-section">
            <div class="section-title">Account</div>
            <a href="../profile/index.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'profile') !== false ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
        </div>
        
    </nav>
    
    <!-- Footer -->
    <div class="sidebar-footer">
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
    
    <!-- Collapse Toggle -->
    <button class="sidebar-collapse-toggle" id="sidebarCollapseBtn" title="Toggle Sidebar">
        <i class="fas fa-chevron-left"></i>
    </button>
    
</aside>

<!-- ============================================================
SIDEBAR JAVASCRIPT
============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // MOBILE TOGGLE
    // ============================================================
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (toggleBtn && sidebar && overlay) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }
    
    // ============================================================
    // COLLAPSE TOGGLE
    // ============================================================
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const mainWrapper = document.querySelector('.main-wrapper');
    
    if (collapseBtn && sidebar && mainWrapper) {
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainWrapper.classList.toggle('expanded');
            
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
            } else {
                icon.className = 'fas fa-chevron-left';
            }
        });
    }
    
    // ============================================================
    // ACTIVE LINK - REMOVE ALL ACTIVE THEN SET CURRENT
    // ============================================================
    const navItems = document.querySelectorAll('.wakala-sidebar .nav-item');
    const currentPath = window.location.pathname;
    
    navItems.forEach(function(item) {
        // Remove all active classes first
        item.classList.remove('active');
        
        // Check if this is the current page
        const href = item.getAttribute('href');
        if (href && currentPath.includes(href.replace('../../', ''))) {
            item.classList.add('active');
        }
    });
    
    // ============================================================
    // SUB-MENU TOGGLE (if any)
    // ============================================================
    const hasSub = document.querySelectorAll('.has-sub');
    hasSub.forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const subMenu = this.nextElementSibling;
            const arrow = this.querySelector('.sub-arrow');
            
            if (subMenu && subMenu.classList.contains('sub-menu')) {
                subMenu.classList.toggle('open');
                if (arrow) {
                    arrow.classList.toggle('open');
                }
            }
        });
    });
    
});
</script>