<?php
// ================================================================
// FILE: includes/employee_sidebar.php
// WAKALA SYSTEM - EMPLOYEE SIDEBAR
// ✅ Starts FROM TOP (full height)
// ✅ NARROWER (220px)
// ✅ Mobile toggle works standalone
// ✅ Bigger text for readability
// ================================================================

$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$user_initial = strtoupper(substr($full_name, 0, 1));
$employee_id = $_SESSION['employee_id'] ?? 'EMP-001';

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

$current_section = '';

if ($current_dir === 'morning_report') {
    $current_section = 'morning_report';
} elseif ($current_dir === 'evening_stock') {
    $current_section = 'evening_stock';
} elseif ($current_dir === 'daily_report') {
    $current_section = 'daily_report';
} elseif ($current_dir === 'commissions') {
    $current_section = 'commissions';
} elseif ($current_dir === 'expenses') {
    $current_section = 'expenses';
} elseif ($current_dir === 'store_cash_out') {
    $current_section = 'store_cash_out';
} elseif ($current_dir === 'transfers') {
    $current_section = 'transfers';
} elseif ($current_dir === 'profile') {
    $current_section = 'profile';
} elseif ($current_dir === 'dashboard') {
    $current_section = 'dashboard';
} elseif ($current_dir === 'capital_management') {
    $current_section = 'capital_management';
}

function isNavActive($section, $current_section) {
    return ($section === $current_section) ? 'active' : '';
}
?>
<style>
/* ============================================================
   EMPLOYEE SIDEBAR - FROM TOP + NARROW
   ============================================================ */
.employee-sidebar {
    position: fixed;
    top: 0;                                      /* ⭐ Starts FROM TOP */
    left: 0;
    width: 220px;                                /* ⭐ Narrow width */
    height: 100vh;                               /* ⭐ Full height */
    background: #bb0404;
    color: #ffffff;
    z-index: 997;                                /* Header (1000+) sits above on desktop */
    transition: transform 0.3s ease;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 20px rgba(187, 4, 4, 0.3);
    padding-top: 0;
    transform: translateX(0);
}

.employee-sidebar::-webkit-scrollbar { width: 5px; }
.employee-sidebar::-webkit-scrollbar-track { background: #8a0303; }
.employee-sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.4);
    border-radius: 4px;
}
.employee-sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.6);
}

/* ============================================================
   LOGO SECTION
   ============================================================ */
.employee-sidebar .sidebar-logo {
    padding: 16px 12px 12px 12px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0;
    background: #bb0404;
}

.employee-sidebar .sidebar-logo img {
    width: 62px;
    height: 62px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.85);
    box-shadow: 0 0 20px rgba(255,255,255,0.15);
    display: block;
    margin: 0 auto;
}

.employee-sidebar .sidebar-logo .logo-text {
    font-size: 16px;
    font-weight: 800;
    color: #ffffff;
    margin-top: 6px;
    letter-spacing: 0.5px;
}

.employee-sidebar .sidebar-logo .logo-sub {
    font-size: 9px;
    color: rgba(255,255,255,0.55);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-top: 2px;
}

/* ============================================================
   USER INFO
   ============================================================ */
.employee-sidebar .sidebar-user {
    padding: 10px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    background: #bb0404;
}

.employee-sidebar .sidebar-user .user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    color: #ffffff;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.25);
}

.employee-sidebar .sidebar-user .user-name {
    font-size: 12.5px;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.2;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}

.employee-sidebar .sidebar-user .user-role {
    font-size: 10px;
    color: rgba(255,255,255,0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* ============================================================
   NAVIGATION
   ============================================================ */
.employee-sidebar .sidebar-nav {
    padding: 10px 0 12px 0;
    flex-shrink: 0;
}

.employee-sidebar .sidebar-nav .nav-section {
    padding: 0 10px;
    margin-bottom: 12px;
}

.employee-sidebar .sidebar-nav .nav-section:last-child {
    margin-bottom: 0;
}

.employee-sidebar .sidebar-nav .nav-section .section-title {
    font-size: 9px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.45);
    letter-spacing: 1.3px;
    padding: 4px 10px 6px 10px;
    font-weight: 800;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 6px;
}

/* ============================================================
   NAV ITEMS
   ============================================================ */
.employee-sidebar .sidebar-nav .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    margin: 2px 0;
    border-radius: 8px;
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
}

.employee-sidebar .sidebar-nav .nav-item:hover {
    background: rgba(255,255,255,0.12);
    color: #ffffff;
    transform: translateX(3px);
}

.employee-sidebar .sidebar-nav .nav-item.active {
    background: rgba(255,255,255,0.18);
    color: #ffffff;
    font-weight: 700;
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
    font-size: 14px;
    flex-shrink: 0;
}

.employee-sidebar .sidebar-nav .nav-item .nav-badge {
    margin-left: auto;
    font-size: 8px;
    font-weight: 900;
    padding: 2px 7px;
    border-radius: 8px;
    background: #FCD34D;
    color: #78350F;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

/* ============================================================
   FOOTER - Logout
   ============================================================ */
.employee-sidebar .sidebar-footer {
    padding: 12px 10px;
    border-top: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
    background: #bb0404;
    margin-top: auto;
}

.employee-sidebar .sidebar-footer .logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.employee-sidebar .sidebar-footer .logout-btn:hover {
    background: rgba(255,255,255,0.12);
    color: #ffffff;
    transform: translateX(3px);
}

.employee-sidebar .sidebar-footer .logout-btn i {
    font-size: 14px;
    width: 18px;
    text-align: center;
}

/* ============================================================
   MOBILE FLOATING TOGGLE
   ============================================================ */
.mobile-sidebar-toggle {
    display: none;
    position: fixed;
    top: 14px;
    left: 14px;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #ffffff;
    border: none;
    cursor: pointer;
    z-index: 1000;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    box-shadow: 0 4px 16px rgba(187, 4, 4, 0.4);
    transition: all 0.2s ease;
}

.mobile-sidebar-toggle:hover,
.mobile-sidebar-toggle:active {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(187, 4, 4, 0.55);
}

.mobile-sidebar-toggle i {
    font-size: 17px;
}

/* ============================================================
   OVERLAY
   ============================================================ */
.employee-sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.55);
    z-index: 996;
    opacity: 0;
    transition: opacity 0.3s ease;
    backdrop-filter: blur(2px);
}

.employee-sidebar-overlay.active {
    display: block;
    opacity: 1;
}

/* ============================================================
   RESPONSIVE - Mobile
   ============================================================ */
@media (max-width: 768px) {
    .employee-sidebar {
        transform: translateX(-100%);
        top: 0;
        height: 100vh;
        width: 260px;
        box-shadow: none;
        z-index: 999;
    }

    .employee-sidebar.mobile-open {
        transform: translateX(0);
        box-shadow: 4px 0 30px rgba(0,0,0,0.35);
    }

    .mobile-sidebar-toggle {
        display: flex;
    }

    .employee-sidebar .sidebar-nav .nav-item {
        padding: 11px 12px;
        font-size: 14px;
        margin: 3px 0;
    }

    .employee-sidebar .sidebar-nav .nav-item i {
        font-size: 15px;
    }

    .employee-sidebar .sidebar-nav .nav-section .section-title {
        font-size: 10px;
        padding: 5px 12px 7px 12px;
    }

    .employee-sidebar .sidebar-logo {
        padding: 18px 12px 14px 12px;
    }

    .employee-sidebar .sidebar-logo img {
        width: 68px;
        height: 68px;
    }

    .employee-sidebar .sidebar-logo .logo-text {
        font-size: 17px;
    }

    .employee-sidebar .sidebar-user .user-name {
        font-size: 13.5px;
    }

    .employee-sidebar .sidebar-user .user-role {
        font-size: 11px;
    }

    .employee-sidebar .sidebar-footer .logout-btn {
        font-size: 14px;
        padding: 11px 12px;
    }

    .employee-sidebar .sidebar-footer .logout-btn i {
        font-size: 15px;
    }
}

@media (max-width: 480px) {
    .employee-sidebar {
        width: 250px;
    }

    .employee-sidebar .sidebar-logo img {
        width: 62px;
        height: 62px;
    }

    .employee-sidebar .sidebar-logo .logo-text {
        font-size: 16px;
    }

    .mobile-sidebar-toggle {
        width: 42px;
        height: 42px;
        top: 12px;
        left: 12px;
    }
}

/* ============================================================
   DESKTOP: hide toggle + overlay
   ============================================================ */
@media (min-width: 769px) {
    .mobile-sidebar-toggle {
        display: none !important;
    }

    .employee-sidebar-overlay {
        display: none !important;
    }
}
</style>

<!-- Floating toggle (mobile only) -->
<button type="button" class="mobile-sidebar-toggle" id="employeeSidebarToggle" aria-label="Open menu">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay -->
<div class="employee-sidebar-overlay" id="employeeSidebarOverlay"></div>

<!-- SIDEBAR -->
<aside class="employee-sidebar" id="employeeSidebar">

    <!-- LOGO -->
    <div class="sidebar-logo">
        <img src="../../assets/images/logo.PNG" alt="Wakala Logo"
             onerror="this.src='../../assets/images/default-avatar.png'">
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

        <div class="nav-section">
            <div class="section-title">Main</div>
            <a href="../dashboard/employee.php"
               class="nav-item <?php echo isNavActive('dashboard', $current_section); ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="section-title">Reports</div>

            <a href="../morning_report/index_employee.php"
               class="nav-item <?php echo isNavActive('morning_report', $current_section); ?>">
                <i class="fas fa-sun"></i>
                <span>Morning Report</span>
            </a>

            <a href="../evening_stock/index_employee.php"
               class="nav-item <?php echo isNavActive('evening_stock', $current_section); ?>">
                <i class="fas fa-moon"></i>
                <span>Evening Stock</span>
            </a>

            <a href="../daily_report/index_employee.php"
               class="nav-item <?php echo isNavActive('daily_report', $current_section); ?>">
                <i class="fas fa-file-alt"></i>
                <span>Daily Report</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="section-title">Financial</div>

            <a href="../commissions/index_employee.php"
               class="nav-item <?php echo isNavActive('commissions', $current_section); ?>">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Commissions</span>
            </a>

            <a href="../expenses/index_employee.php"
               class="nav-item <?php echo isNavActive('expenses', $current_section); ?>">
                <i class="fas fa-receipt"></i>
                <span>Expenses</span>
            </a>

            <a href="../store_cash_out/index_employee.php"
               class="nav-item <?php echo isNavActive('store_cash_out', $current_section); ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Cash Out</span>
            </a>

            <a href="../transfers/index_employee.php"
               class="nav-item <?php echo isNavActive('transfers', $current_section); ?>">
                <i class="fas fa-exchange-alt"></i>
                <span>Transfer</span>
                <span class="nav-badge">NEW</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="section-title">Account</div>
            <a href="../profile/index_employee.php"
               class="nav-item <?php echo isNavActive('profile', $current_section); ?>">
                <i class="fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
        </div>

    </nav>

    <!-- Footer - Logout -->
    <div class="sidebar-footer">
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<script>
// ============================================================
// SIDEBAR TOGGLE
// ============================================================
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var toggleBtn = document.getElementById('employeeSidebarToggle');
        var sidebar   = document.getElementById('employeeSidebar');
        var overlay   = document.getElementById('employeeSidebarOverlay');

        if (!toggleBtn || !sidebar || !overlay) return;

        function openSidebar() {
            sidebar.classList.add('mobile-open');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            toggleBtn.innerHTML = '<i class="fas fa-times"></i>';
        }

        function closeSidebar() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
        }

        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        overlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            }
        });

        // Swipe gestures
        var touchStartX = 0;
        var touchStartY = 0;

        document.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            var deltaX = e.changedTouches[0].clientX - touchStartX;
            var deltaY = e.changedTouches[0].clientY - touchStartY;

            if (touchStartX < 40 && deltaX > 60 && Math.abs(deltaY) < 80) {
                openSidebar();
            }

            if (sidebar.classList.contains('mobile-open') &&
                deltaX < -60 && Math.abs(deltaY) < 80) {
                closeSidebar();
            }
        }, { passive: true });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            }
        });
    });
})();
</script>