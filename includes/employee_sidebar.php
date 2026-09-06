<?php
// ================================================================
// FILE: includes/employee_sidebar.php
// WAKALA SYSTEM - EMPLOYEE SIDEBAR
// SIMPLE & CLEAN - WITH FIXED POSITION
// ================================================================

// Get user data
$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'Employee';
$user_initial = strtoupper(substr($full_name, 0, 1));
$employee_id = $_SESSION['employee_id'] ?? 'EMP-001';
?>

<style>
/* ============================================================
   EMPLOYEE SIDEBAR - RED #bb0404 THEME
   FIXED POSITION
   ============================================================ */

/* SIDEBAR CONTAINER */
.employee-sidebar {
    position: fixed;
    top: 56px; /* Below topbar */
    left: 0;
    width: 240px;
    height: calc(100vh - 56px);
    background: #bb0404;
    color: #ffffff;
    z-index: 998;
    transition: all 0.3s ease;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 20px rgba(187, 4, 4, 0.3);
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
   LOGO SECTION
   ============================================================ */
.employee-sidebar .sidebar-logo {
    padding: 14px 16px 10px 16px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0;
}

.employee-sidebar .sidebar-logo img {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.8);
    box-shadow: 0 0 20px rgba(255,255,255,0.1);
    transition: all 0.3s ease;
}

.employee-sidebar .sidebar-logo .logo-text {
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
    margin-top: 6px;
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
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.employee-sidebar .sidebar-user .user-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    color: #ffffff;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.2);
}

.employee-sidebar .sidebar-user .user-name {
    font-size: 12px;
    font-weight: 600;
    color: #ffffff;
}

.employee-sidebar .sidebar-user .user-role {
    font-size: 9px;
    color: rgba(255,255,255,0.5);
}

/* ============================================================
   NAVIGATION MENU
   ============================================================ */
.employee-sidebar .sidebar-nav {
    flex: 1;
    padding: 8px 0 8px 0;
}

.employee-sidebar .sidebar-nav .nav-section {
    padding: 0 10px;
    margin-bottom: 2px;
}

.employee-sidebar .sidebar-nav .nav-section .section-title {
    font-size: 8px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.3);
    letter-spacing: 1px;
    padding: 4px 12px 2px 12px;
    font-weight: 700;
}

/* ============================================================
   NAV ITEMS
   ============================================================ */
.employee-sidebar .sidebar-nav .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    margin: 1px 0;
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
    height: 18px;
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
    padding: 8px 12px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
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
        top: 50px;
        height: calc(100vh - 50px);
        width: 0;
        overflow: hidden;
        box-shadow: none;
    }
    
    .employee-sidebar.mobile-open {
        width: 270px;
        overflow-y: auto;
        box-shadow: 2px 0 30px rgba(0,0,0,0.3);
    }
    
    .employee-sidebar .sidebar-logo img {
        width: 48px;
        height: 48px;
    }
    
    .employee-sidebar .sidebar-logo .logo-text {
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .employee-sidebar {
        top: 44px;
        height: calc(100vh - 44px);
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

/* ============================================================
   SIDEBAR COLLAPSED STATE (Optional)
   ============================================================ */
.employee-sidebar.collapsed {
    width: 60px;
}

.employee-sidebar.collapsed .sidebar-logo .logo-text,
.employee-sidebar.collapsed .sidebar-logo .logo-sub,
.employee-sidebar.collapsed .sidebar-user .user-name,
.employee-sidebar.collapsed .sidebar-user .user-role,
.employee-sidebar.collapsed .sidebar-nav .nav-item span,
.employee-sidebar.collapsed .sidebar-footer .logout-btn span {
    display: none;
}

.employee-sidebar.collapsed .sidebar-nav .nav-item {
    justify-content: center;
    padding: 8px;
}

.employee-sidebar.collapsed .sidebar-nav .nav-item i {
    font-size: 16px;
    width: auto;
}

.employee-sidebar.collapsed .sidebar-user {
    justify-content: center;
}

.employee-sidebar.collapsed .sidebar-footer .logout-btn {
    justify-content: center;
}

.employee-sidebar.collapsed .sidebar-logo img {
    width: 38px;
    height: 38px;
}

.employee-sidebar.collapsed .sidebar-logo {
    padding: 10px 8px;
}

@media (max-width: 768px) {
    .employee-sidebar.collapsed {
        width: 0;
        overflow: hidden;
    }
}
</style>

<!-- ============================================================
EMPLOYEE SIDEBAR HTML
============================================================ -->
<div class="employee-sidebar-overlay" id="employeeSidebarOverlay"></div>

<aside class="employee-sidebar" id="employeeSidebar">
    
    <!-- Logo -->
    <div class="sidebar-logo">
        <img src="../../assets/images/logo.PNG" alt="Wakala Logo">
        <div class="logo-text">WAKALA</div>
        <div class="logo-sub">Employee Portal</div>
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
            <a href="../dashboard/employee.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'employee.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>
        
        <!-- Reports -->
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
        
        <!-- Financial -->
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
        </div>
        
        <!-- Account -->
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
    
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // ACTIVE LINK
    // ============================================================
    const navItems = document.querySelectorAll('.employee-sidebar .nav-item');
    const currentPath = window.location.pathname;
    
    navItems.forEach(function(item) {
        item.classList.remove('active');
        const href = item.getAttribute('href');
        if (href && currentPath.includes(href.replace('../../', ''))) {
            item.classList.add('active');
        }
    });
    
});
</script>