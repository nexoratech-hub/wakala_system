<?php
// ================================================================
// FILE: includes/admin_sidebar.php
// WAKALA SYSTEM - ADMIN SIDEBAR WITH RED #bb0404
// FIXED: Maintains scroll position after menu click
// + TRANSFER MENU ADDED
// ================================================================

// ============================================================
// GET COMPANY NAME FROM DATABASE
// ============================================================
$company_name = 'Wakala System';

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

$full_name = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'Employee';
$user_initial = strtoupper(substr($full_name, 0, 1));

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>

<style>
/* ============================================================
   ADMIN SIDEBAR - RED #bb0404 THEME
   ============================================================ */

.admin-sidebar {
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
    scroll-behavior: smooth;
}

.admin-sidebar::-webkit-scrollbar { width: 4px; }
.admin-sidebar::-webkit-scrollbar-track { background: #8a0303; }
.admin-sidebar::-webkit-scrollbar-thumb {
    background: #ffffff;
    border-radius: 4px;
}

/* LOGO */
.admin-sidebar .sidebar-logo {
    padding: 20px 16px 16px 16px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    flex-shrink: 0;
}

.admin-sidebar .sidebar-logo img {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ffffff;
    box-shadow: 0 0 25px rgba(255,255,255,0.15);
    transition: all 0.3s ease;
}

.admin-sidebar .sidebar-logo img:hover {
    transform: scale(1.05);
    box-shadow: 0 0 35px rgba(255,255,255,0.25);
}

.admin-sidebar .sidebar-logo .logo-text {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    margin-top: 10px;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.admin-sidebar .sidebar-logo .logo-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    letter-spacing: 2px;
    text-transform: uppercase;
    font-weight: 300;
}

/* USER */
.admin-sidebar .sidebar-user {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.admin-sidebar .sidebar-user .user-avatar {
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

.admin-sidebar .sidebar-user .user-name {
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
}

.admin-sidebar .sidebar-user .user-role {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
}

/* NAV */
.admin-sidebar .sidebar-nav {
    flex: 1;
    padding: 10px 0 10px 0;
}

.admin-sidebar .sidebar-nav .nav-section {
    padding: 0 12px;
    margin-bottom: 4px;
}

.admin-sidebar .sidebar-nav .nav-section .section-title {
    font-size: 10px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    letter-spacing: 1.5px;
    padding: 8px 12px 4px 12px;
    font-weight: 600;
}

.admin-sidebar .sidebar-nav .nav-item {
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

.admin-sidebar .sidebar-nav .nav-item:hover {
    background: rgba(255,255,255,0.15);
    color: #ffffff;
}

.admin-sidebar .sidebar-nav .nav-item.active {
    background: rgba(255,255,255,0.2);
    color: #ffffff;
    font-weight: 600;
}

.admin-sidebar .sidebar-nav .nav-item.active::before {
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

.admin-sidebar .sidebar-nav .nav-item i {
    width: 20px;
    text-align: center;
    font-size: 15px;
    flex-shrink: 0;
}

.admin-sidebar .sidebar-nav .nav-item .nav-badge {
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

/* NEW BADGE */
.admin-sidebar .sidebar-nav .nav-item .nav-badge.new-badge {
    background: #FCD34D;
    color: #78350F;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 9px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 8px;
}

/* SUB MENU */
.admin-sidebar .sidebar-nav .nav-item.has-sub { cursor: pointer; }

.admin-sidebar .sidebar-nav .nav-item .sub-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
    font-size: 11px;
}

.admin-sidebar .sidebar-nav .nav-item .sub-arrow.open { transform: rotate(180deg); }

.admin-sidebar .sidebar-nav .sub-menu {
    padding-left: 20px;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease;
}

.admin-sidebar .sidebar-nav .sub-menu.open { max-height: 500px; }

.admin-sidebar .sidebar-nav .sub-menu .nav-item {
    padding: 8px 16px;
    font-size: 12px;
    padding-left: 48px;
}

.admin-sidebar .sidebar-nav .sub-menu .nav-item i {
    font-size: 12px;
    width: 16px;
}

/* FOOTER */
.admin-sidebar .sidebar-footer {
    padding: 12px 16px;
    border-top: 1px solid rgba(255,255,255,0.15);
    flex-shrink: 0;
}

.admin-sidebar .sidebar-footer .logout-btn {
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

.admin-sidebar .sidebar-footer .logout-btn:hover {
    background: rgba(255,255,255,0.15);
    color: #ffffff;
}

.admin-sidebar .sidebar-footer .logout-btn i {
    font-size: 15px;
    width: 20px;
    text-align: center;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .admin-sidebar {
        width: 0;
        overflow: hidden;
        transition: width 0.3s ease, box-shadow 0.3s ease;
    }
    
    .admin-sidebar.mobile-open {
        width: 280px;
        overflow-y: auto;
        box-shadow: 2px 0 30px rgba(0,0,0,0.5);
    }
    
    .admin-sidebar .sidebar-logo img {
        width: 60px;
        height: 60px;
    }
    
    .admin-sidebar .sidebar-logo .logo-text {
        font-size: 16px;
    }
}

/* TOGGLE BUTTON */
.admin-sidebar-toggle {
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

.admin-sidebar-toggle:hover {
    background: #ffffff;
    color: #bb0404;
}

@media (max-width: 768px) {
    .admin-sidebar-toggle { display: block; }
}

/* OVERLAY */
.admin-sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 998;
    transition: opacity 0.3s ease;
}

.admin-sidebar-overlay.active { display: block; }

/* MAIN CONTENT OFFSET */
.main-wrapper {
    margin-left: 260px;
    min-height: 100vh;
    transition: margin-left 0.3s ease;
}

@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; }
}

/* COLLAPSED STATE */
.admin-sidebar.collapsed { width: 72px; }

.admin-sidebar.collapsed .sidebar-logo .logo-text,
.admin-sidebar.collapsed .sidebar-logo .logo-sub,
.admin-sidebar.collapsed .sidebar-user .user-name,
.admin-sidebar.collapsed .sidebar-user .user-role,
.admin-sidebar.collapsed .sidebar-nav .nav-item span:not(.nav-badge),
.admin-sidebar.collapsed .sidebar-footer .logout-btn span {
    display: none;
}

.admin-sidebar.collapsed .sidebar-nav .nav-item {
    justify-content: center;
    padding: 12px;
}

.admin-sidebar.collapsed .sidebar-nav .nav-item i {
    font-size: 18px;
    width: auto;
}

.admin-sidebar.collapsed .sidebar-nav .nav-item .nav-badge { display: none; }
.admin-sidebar.collapsed .sidebar-nav .sub-menu { display: none; }

.admin-sidebar.collapsed .sidebar-logo img {
    width: 44px;
    height: 44px;
}

.admin-sidebar.collapsed .sidebar-user .user-avatar {
    width: 32px;
    height: 32px;
    font-size: 12px;
}

.admin-sidebar.collapsed .sidebar-footer .logout-btn {
    justify-content: center;
}

.admin-sidebar.collapsed .sidebar-footer .logout-btn i {
    font-size: 18px;
}

.admin-sidebar.collapsed .sidebar-logo {
    padding: 12px 8px;
}

.admin-sidebar.collapsed .sidebar-user {
    padding: 10px 8px;
    justify-content: center;
}

.admin-sidebar.collapsed .sidebar-nav .nav-item.active::before {
    width: 3px;
    height: 20px;
}

.main-wrapper.expanded { margin-left: 72px; }

@media (max-width: 768px) {
    .admin-sidebar.collapsed {
        width: 0;
        overflow: hidden;
    }
    .main-wrapper.expanded { margin-left: 0; }
}

/* COLLAPSE TOGGLE */
.admin-sidebar-collapse {
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

.admin-sidebar-collapse:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 16px rgba(0,0,0,0.3);
}

@media (max-width: 768px) {
    .admin-sidebar-collapse { display: none; }
}
</style>

<!-- ============================================================
SIDEBAR HTML
============================================================ -->
<button class="admin-sidebar-toggle" id="adminSidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    
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
            <a href="../dashboard/admin.php" class="nav-item <?php echo $current_dir == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>
        
        <!-- Reports Section -->
        <div class="nav-section">
            <div class="section-title">Reports</div>
            
            <a href="../morning_report/index.php" class="nav-item <?php echo $current_dir == 'morning_report' ? 'active' : ''; ?>">
                <i class="fas fa-sun"></i>
                <span>Morning Report</span>
            </a>
            
            <a href="../evening_stock/index.php" class="nav-item <?php echo $current_dir == 'evening_stock' ? 'active' : ''; ?>">
                <i class="fas fa-moon"></i>
                <span>Evening Stock</span>
            </a>
            
            <a href="../daily_report/index.php" class="nav-item <?php echo $current_dir == 'daily_report' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>Daily Report</span>
            </a>
        </div>
        
        <!-- Financial Section -->
        <div class="nav-section">
            <div class="section-title">Financial</div>
            
            <a href="../commissions/index.php" class="nav-item <?php echo $current_dir == 'commissions' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Commissions</span>
            </a>
            
            <a href="../expenses/index.php" class="nav-item <?php echo $current_dir == 'expenses' ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i>
                <span>Expenses</span>
            </a>
            
            <a href="../store_cash_out/index.php" class="nav-item <?php echo $current_dir == 'store_cash_out' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Cash Out</span>
            </a>
            
            <!-- ============================================================
            ✅ NEW: TRANSFER MENU
            ============================================================ -->
            <a href="../transfers/index.php" class="nav-item <?php echo $current_dir == 'transfers' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span>Transfer</span>
                <span class="nav-badge new-badge">NEW</span>
            </a>
            
            <a href="../capital_management/index.php" class="nav-item <?php echo $current_dir == 'capital_management' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span>Capital</span>
            </a>
            
            <a href="../salaries/index.php" class="nav-item <?php echo $current_dir == 'salaries' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span>Salaries</span>
            </a>
        </div>
        
        <!-- Management Section -->
        <div class="nav-section">
            <div class="section-title">Management</div>
            
            <a href="../providers/index.php" class="nav-item <?php echo $current_dir == 'providers' ? 'active' : ''; ?>">
                <i class="fas fa-university"></i>
                <span>Providers</span>
            </a>
            
            <?php if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'super_admin'): ?>
                <a href="../branches/index.php" class="nav-item <?php echo $current_dir == 'branches' ? 'active' : ''; ?>">
                    <i class="fas fa-store-alt"></i>
                    <span>Branches</span>
                </a>
            <?php endif; ?>
        </div>
        
        <!-- System Section -->
        <div class="nav-section">
            <div class="section-title">System</div>
            
            <a href="../reports/index.php" class="nav-item <?php echo $current_dir == 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Reports</span>
            </a>
            
            <?php if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'super_admin'): ?>
                <a href="../employees/index.php" class="nav-item <?php echo $current_dir == 'employees' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Employees</span>
                </a>
                
                <a href="../settings/index.php" class="nav-item <?php echo $current_dir == 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                
                <a href="../activity_logs/index.php" class="nav-item <?php echo $current_dir == 'activity_logs' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Activity Log</span>
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Profile -->
        <div class="nav-section">
            <div class="section-title">Account</div>
            <a href="../profile/index.php" class="nav-item <?php echo $current_dir == 'profile' ? 'active' : ''; ?>">
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
    <button class="admin-sidebar-collapse" id="adminSidebarCollapse" title="Toggle Sidebar">
        <i class="fas fa-chevron-left"></i>
    </button>
    
</aside>

<!-- ============================================================
SIDEBAR JAVASCRIPT
============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const sidebar = document.getElementById('adminSidebar');
    
    // ============================================================
    // SIDEBAR SCROLL POSITION
    // ============================================================
    function saveSidebarScroll() {
        if (sidebar) {
            localStorage.setItem('adminSidebarScrollPosition', sidebar.scrollTop);
        }
    }
    
    function restoreSidebarScroll() {
        if (sidebar) {
            const savedPosition = localStorage.getItem('adminSidebarScrollPosition');
            if (savedPosition !== null) {
                setTimeout(function() {
                    sidebar.scrollTop = parseInt(savedPosition);
                }, 50);
            }
        }
    }
    
    if (sidebar) {
        sidebar.addEventListener('scroll', function() {
            saveSidebarScroll();
        });
    }
    
    restoreSidebarScroll();
    
    window.addEventListener('load', function() {
        restoreSidebarScroll();
    });
    
    // ============================================================
    // MOBILE TOGGLE
    // ============================================================
    const toggleBtn = document.getElementById('adminSidebarToggle');
    const overlay = document.getElementById('adminSidebarOverlay');
    
    if (toggleBtn && sidebar && overlay) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
            
            const isOpen = sidebar.classList.contains('mobile-open');
            localStorage.setItem('adminSidebarMobileOpen', isOpen ? 'true' : 'false');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            localStorage.setItem('adminSidebarMobileOpen', 'false');
        });
    }
    
    if (window.innerWidth <= 768) {
        const mobileOpen = localStorage.getItem('adminSidebarMobileOpen') === 'true';
        if (mobileOpen && sidebar && overlay) {
            sidebar.classList.add('mobile-open');
            overlay.classList.add('active');
        }
    }
    
    // ============================================================
    // COLLAPSE TOGGLE
    // ============================================================
    const collapseBtn = document.getElementById('adminSidebarCollapse');
    const mainWrapper = document.querySelector('.main-wrapper');
    
    if (collapseBtn && sidebar && mainWrapper) {
        const isCollapsed = localStorage.getItem('adminSidebarCollapsed') === 'true';
        if (isCollapsed && window.innerWidth > 768) {
            sidebar.classList.add('collapsed');
            mainWrapper.classList.add('expanded');
            const icon = collapseBtn.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-chevron-right';
            }
        }
        
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainWrapper.classList.toggle('expanded');
            
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
                localStorage.setItem('adminSidebarCollapsed', 'true');
            } else {
                icon.className = 'fas fa-chevron-left';
                localStorage.setItem('adminSidebarCollapsed', 'false');
            }
        });
    }
    
    // ============================================================
    // ACTIVE LINK
    // ============================================================
    const navItems = document.querySelectorAll('.admin-sidebar .nav-item');
    const currentDir = '<?php echo $current_dir; ?>';
    
    navItems.forEach(function(item) {
        const href = item.getAttribute('href');
        if (href && href.includes(currentDir)) {
            item.classList.add('active');
        }
    });
    
    // ============================================================
    // SUB-MENU TOGGLE
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
    
    // ============================================================
    // PREVENT SCROLL TO TOP
    // ============================================================
    const allNavLinks = document.querySelectorAll('.admin-sidebar .nav-item');
    allNavLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            saveSidebarScroll();
            return true;
        });
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            localStorage.setItem('adminSidebarMobileOpen', 'false');
        }
    });
    
    window.addEventListener('beforeunload', function() {
        saveSidebarScroll();
    });
    
    console.log('%c 🏪 Admin Sidebar Loaded - Transfer Menu Added',
        'background:#8B0000; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>