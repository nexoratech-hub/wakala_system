<?php
// ================================================================
// FILE: includes/employee_sidebar.php
// WAKALA SYSTEM - EMPLOYEE SIDEBAR
// WITH BIG LOGO, MINIMAL TOP SPACE
// ================================================================

// Get user data
$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$user_initial = strtoupper(substr($full_name, 0, 1));
$employee_id = $_SESSION['employee_id'] ?? 'EMP-001';

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Function to check if nav item is active
function isActive($dir, $page = null) {
    global $current_dir, $current_page;
    if ($page) {
        return ($current_dir == $dir && $current_page == $page) ? 'active' : '';
    }
    return ($current_dir == $dir) ? 'active' : '';
}
?>
<style>
/* ============================================================
   EMPLOYEE SIDEBAR - FIXED POSITION - NO GAP
   ============================================================ */

/* SIDEBAR CONTAINER */
.employee-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 240px;
    height: 100vh;
    background: #bb0404;
    color: #ffffff;
    z-index: 998;
    transition: all 0.3s ease;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 20px rgba(187, 4, 4, 0.3);
    padding-top: 56px; /* Space for topbar */
}

/* SIDEBAR SCROLLBAR */
.employee-sidebar::-webkit-scrollbar {
    width: 4px;
}
.employee-sidebar::-webkit-scrollbar-track {
    background: #8a0303;
}
.employee-sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.4);
    border-radius: 4px;
}

/* ============================================================
   LOGO SECTION - BIG LOGO, MINIMAL PADDING
   ============================================================ */
.employee-sidebar .sidebar-logo {
    padding: 8px 16px 6px 16px; /* REDUCED PADDING */
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0;
}

.employee-sidebar .sidebar-logo img {
    width: 70px; /* BIGGER LOGO */
    height: 70px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.8);
    box-shadow: 0 0 20px rgba(255,255,255,0.1);
}

.employee-sidebar .sidebar-logo .logo-text {
    font-size: 16px; /* BIGGER TEXT */
    font-weight: 700;
    color: #ffffff;
    margin-top: 4px;
    letter-spacing: 0.5px;
}

.employee-sidebar .sidebar-logo .logo-sub {
    font-size: 9px;
    color: rgba(255,255,255,0.5);
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

/* ============================================================
   USER INFO
   ============================================================ */
.employee-sidebar .sidebar-user {
    padding: 8px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.employee-sidebar .sidebar-user .user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    color: #ffffff;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.2);
}

.employee-sidebar .sidebar-user .user-name {
    font-size: 13px;
    font-weight: 600;
    color: #ffffff;
}

.employee-sidebar .sidebar-user .user-role {
    font-size: 9px;
    color: rgba(255,255,255,0.5);
}

/* ============================================================
   NAVIGATION MENU - WITH PROPER SPACING
   ============================================================ */
.employee-sidebar .sidebar-nav {
    flex: 1;
    padding: 8px 0 8px 0;
    overflow-y: auto;
}

.employee-sidebar .sidebar-nav .nav-section {
    padding: 0 10px;
    margin-bottom: 10px; /* SPACE BETWEEN SECTIONS */
}

.employee-sidebar .sidebar-nav .nav-section:last-child {
    margin-bottom: 0;
}

.employee-sidebar .sidebar-nav .nav-section .section-title {
    font-size: 8px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.3);
    letter-spacing: 1.5px;
    padding: 2px 12px 4px 12px;
    font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    margin-bottom: 4px;
}

/* ============================================================
   NAV ITEMS
   ============================================================ */
.employee-sidebar .sidebar-nav .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    margin: 2px 0;
    border-radius: 6px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
}

.employee-sidebar .sidebar-nav .nav-item:hover {
    background: rgba(255,255,255,0.1);
    color: #ffffff;
}

.employee-sidebar .sidebar-nav .nav-item.active {
    background: rgba(255,255,255,0.15);
    color: #ffffff;
    font-weight: 600;
}

.employee-sidebar .sidebar-nav .nav-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 20px;
    background: #ffffff;
    border-radius: 0 3px 3px 0;
}

.employee-sidebar .sidebar-nav .nav-item i {
    width: 18px;
    text-align: center;
    font-size: 13px;
    flex-shrink: 0;
}

/* ============================================================
   FOOTER
   ============================================================ */
.employee-sidebar .sidebar-footer {
    padding: 8px 12px 12px 12px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
    margin-top: 4px;
}

.employee-sidebar .sidebar-footer .logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    border-radius: 6px;
    color: rgba(255,255,255,0.5);
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.employee-sidebar .sidebar-footer .logout-btn:hover {
    background: rgba(255,255,255,0.08);
    color: #ffffff;
}

.employee-sidebar .sidebar-footer .logout-btn i {
    font-size: 13px;
    width: 18px;
    text-align: center;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .employee-sidebar {
        top: 0;
        height: 100vh;
        width: 0;
        overflow: hidden;
        box-shadow: none;
        padding-top: 50px;
    }
    
    .employee-sidebar.mobile-open {
        width: 270px;
        overflow-y: auto;
        box-shadow: 2px 0 30px rgba(0,0,0,0.3);
    }
    
    .employee-sidebar .sidebar-logo img {
        width: 60px;
        height: 60px;
    }
    
    .employee-sidebar .sidebar-logo .logo-text {
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .employee-sidebar {
        padding-top: 44px;
    }
}

/* ============================================================
   OVERLAY FOR MOBILE
   ============================================================ */
.employee-sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.4);
    z-index: 997;
}

.employee-sidebar-overlay.active {
    display: block;
}
</style>

<!-- ============================================================
EMPLOYEE SIDEBAR HTML
============================================================ -->
<div class="employee-sidebar-overlay" id="employeeSidebarOverlay"></div>

<aside class="employee-sidebar" id="employeeSidebar">
    
    <!-- Logo - BIGGER -->
    <div class="sidebar-logo">
        <img src="../../assets/images/logo.PNG" alt="Wakala Logo" onerror="this.src='../../assets/images/default-avatar.png'">
        <div class="logo-text">WAKALA</div>
        <div class="logo-sub">Employee Portal</div>
    </div>
    
    <!-- User Info - IMPROVED -->
    <div class="sidebar-user">
        <div class="user-avatar"><?php echo $user_initial; ?></div>
        <div>
            <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
            <div class="user-role"><?php echo ucfirst($role); ?></div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="sidebar-nav">
        
        <!-- Main -->
        <div class="nav-section">
            <div class="section-title">Main</div>
            <a href="../dashboard/employee.php" class="nav-item <?php echo isActive('dashboard', 'employee.php'); ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>
        
        <!-- Reports -->
        <div class="nav-section">
            <div class="section-title">Reports</div>
            
            <a href="../morning_report/index.php" class="nav-item <?php echo isActive('morning_report'); ?>">
                <i class="fas fa-sun"></i>
                <span>Morning Report</span>
            </a>
            
            <a href="../evening_stock/index.php" class="nav-item <?php echo isActive('evening_stock'); ?>">
                <i class="fas fa-moon"></i>
                <span>Evening Stock</span>
            </a>
            
            <a href="../daily_report/index.php" class="nav-item <?php echo isActive('daily_report'); ?>">
                <i class="fas fa-file-alt"></i>
                <span>Daily Report</span>
            </a>
        </div>
        
        <!-- Financial -->
        <div class="nav-section">
            <div class="section-title">Financial</div>
            
            <a href="../commissions/index.php" class="nav-item <?php echo isActive('commissions'); ?>">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Commissions</span>
            </a>
            
            <a href="../expenses/index.php" class="nav-item <?php echo isActive('expenses'); ?>">
                <i class="fas fa-receipt"></i>
                <span>Expenses</span>
            </a>
            
            <a href="../store_cash_out/index.php" class="nav-item <?php echo isActive('store_cash_out'); ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Cash Out</span>
            </a>
        </div>
        
        <!-- Account -->
        <div class="nav-section">
            <div class="section-title">Account</div>
            <a href="../profile/index.php" class="nav-item <?php echo isActive('profile'); ?>">
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
    
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // ACTIVE LINK DETECTION
    // ============================================================
    const navItems = document.querySelectorAll('.employee-sidebar .nav-item');
    const currentPath = window.location.pathname;
    
    navItems.forEach(function(item) {
        item.classList.remove('active');
        const href = item.getAttribute('href');
        if (href) {
            const hrefPath = href.replace('../../', '');
            if (currentPath.includes(hrefPath) || currentPath.includes(href.replace('../', ''))) {
                item.classList.add('active');
            }
            if (currentPath.includes('employee.php') && href.includes('dashboard/employee.php')) {
                item.classList.add('active');
            }
        }
    });
    
    // ============================================================
    // MOBILE MENU TOGGLE
    // ============================================================
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('employeeSidebar');
    const overlay = document.getElementById('employeeSidebarOverlay');
    
    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
            }
        });
    }
});
</script>