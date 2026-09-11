<?php
// ================================================================
// FILE: includes/employee_topbar.php
// WAKALA SYSTEM - EMPLOYEE TOPBAR
// WITH SEARCH, BRANCH NAME, DATE/TIME, FAVICON, PROFILE PIC
// ================================================================

// Get user data from session
$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'] ?? 0;

// Get page title
$page_title = $page_title ?? 'Dashboard';

// ============================================================
// GET BRANCH NAME FOR EMPLOYEE
// ============================================================
$branch_name = 'Main Branch';
$branch_code = '';

try {
    global $db;
    if (isset($db) && $user_id > 0) {
        $stmt = $db->prepare("
            SELECT b.branch_name, b.branch_code 
            FROM employees e 
            LEFT JOIN branches b ON e.branch_id = b.id 
            WHERE e.id = ?
        ");
        $stmt->execute([$user_id]);
        $branch_data = $stmt->fetch();
        if ($branch_data) {
            $branch_name = $branch_data['branch_name'] ?? 'Main Branch';
            $branch_code = $branch_data['branch_code'] ?? '';
        }
    }
} catch (Exception $e) {
    $branch_name = 'Main Branch';
}

// ============================================================
// PROFILE PICTURE - FROM uploads/profiles/
// ============================================================
$profile_image = '../../assets/images/default-avatar.png';

if ($user_id > 0) {
    try {
        global $db;
        if (isset($db)) {
            $stmt = $db->prepare("SELECT profile_pic FROM employees WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch();
            
            if ($user_data && !empty($user_data['profile_pic'])) {
                $pic_path = '../../' . $user_data['profile_pic'];
                if (file_exists($pic_path)) {
                    $profile_image = $pic_path;
                }
            }
        }
    } catch (Exception $e) {
        // Use default
    }
}
?>
<style>
/* ============================================================
   EMPLOYEE TOPBAR - FIXED AT TOP
   ============================================================ */
.employee-topbar {
    position: fixed;
    top: 0;
    left: 240px;
    right: 0;
    z-index: 999;
    background: #ffffff;
    padding: 6px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e5e7eb;
    min-height: 56px;
    height: 56px;
    transition: background 0.3s ease, border-color 0.3s ease, left 0.3s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

body.dark-mode .employee-topbar {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

/* ============================================================
   TOPBAR LEFT
   ============================================================ */
.employee-topbar .topbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.employee-topbar .topbar-left .menu-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 18px;
    color: #1f2937;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: background 0.3s ease;
}

body.dark-mode .employee-topbar .topbar-left .menu-toggle {
    color: #f1f5f9;
}

.employee-topbar .topbar-left .menu-toggle:hover {
    background: #f3f4f6;
}

body.dark-mode .employee-topbar .topbar-left .menu-toggle:hover {
    background: #334155;
}

.employee-topbar .topbar-left .page-title {
    font-size: 16px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

body.dark-mode .employee-topbar .topbar-left .page-title {
    color: #f1f5f9;
}

.employee-topbar .topbar-left .page-title i {
    color: #bb0404;
}

/* ============================================================
   TOPBAR CENTER - SEARCH & BRANCH
   ============================================================ */
.employee-topbar .topbar-center {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    max-width: 500px;
    margin: 0 12px;
}

.employee-topbar .branch-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 14px;
    background: #bb0404;
    color: #ffffff;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
    border: 1px solid rgba(255,255,255,0.1);
}

.employee-topbar .branch-badge i {
    font-size: 12px;
    opacity: 0.8;
}

.employee-topbar .branch-badge .branch-code {
    font-size: 9px;
    opacity: 0.7;
    text-transform: uppercase;
}

.employee-topbar .search-wrapper {
    position: relative;
    flex: 1;
    min-width: 100px;
    max-width: 280px;
}

.employee-topbar .search-wrapper .search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 12px;
    pointer-events: none;
}

.employee-topbar .search-wrapper input {
    width: 100%;
    padding: 5px 10px 5px 32px;
    border: 1.5px solid #e5e7eb;
    border-radius: 20px;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    background: #f9fafb;
    color: #1f2937;
    transition: all 0.3s ease;
    outline: none;
}

body.dark-mode .employee-topbar .search-wrapper input {
    background: #334155;
    border-color: #475569;
    color: #f1f5f9;
}

.employee-topbar .search-wrapper input::placeholder {
    color: #9ca3af;
    font-size: 11px;
}

body.dark-mode .employee-topbar .search-wrapper input::placeholder {
    color: #64748b;
}

.employee-topbar .search-wrapper input:focus {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187, 4, 4, 0.1);
    background: #ffffff;
}

body.dark-mode .employee-topbar .search-wrapper input:focus {
    background: #1e293b;
}

.employee-topbar .search-wrapper .search-shortcut {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 9px;
    color: #9ca3af;
    background: #e5e7eb;
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}

body.dark-mode .employee-topbar .search-wrapper .search-shortcut {
    background: #475569;
    color: #94a3b8;
}

/* ============================================================
   TOPBAR RIGHT - DATE/TIME & PROFILE
   ============================================================ */
.employee-topbar .topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.employee-topbar .live-datetime {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #6b7280;
    padding: 4px 12px;
    background: #f3f4f6;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    white-space: nowrap;
}

body.dark-mode .employee-topbar .live-datetime {
    color: #94a3b8;
    background: #334155;
    border-color: #475569;
}

.employee-topbar .live-datetime i {
    color: #bb0404;
    font-size: 12px;
}

.employee-topbar .live-datetime .date-separator {
    color: #d1d5db;
    margin: 0 2px;
}

body.dark-mode .employee-topbar .live-datetime .date-separator {
    color: #475569;
}

.employee-topbar .dark-mode-toggle {
    background: none;
    border: none;
    font-size: 16px;
    color: #6b7280;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: none;
}

.employee-topbar .dark-mode-toggle:hover {
    background: #f3f4f6;
}

body.dark-mode .employee-topbar .dark-mode-toggle {
    color: #94a3b8;
}

body.dark-mode .employee-topbar .dark-mode-toggle:hover {
    background: #334155;
}

.employee-topbar .user-profile {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 2px 8px 2px 2px;
    border-radius: 20px;
    cursor: pointer;
    transition: background 0.2s ease;
    text-decoration: none;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
}

body.dark-mode .employee-topbar .user-profile {
    background: #334155;
    border-color: #475569;
}

.employee-topbar .user-profile:hover {
    background: #f3f4f6;
}

body.dark-mode .employee-topbar .user-profile:hover {
    background: #1e293b;
}

.employee-topbar .user-profile img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #bb0404;
    background: #ffffff;
    transition: none;
}

.employee-topbar .user-profile .user-info {
    line-height: 1.2;
}

.employee-topbar .user-profile .user-name {
    font-size: 12px;
    font-weight: 600;
    color: #1f2937;
}

body.dark-mode .employee-topbar .user-profile .user-name {
    color: #f1f5f9;
}

.employee-topbar .user-profile .user-role {
    font-size: 8px;
    font-weight: 600;
    color: #bb0404;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .employee-topbar .topbar-center {
        max-width: 350px;
    }
    .employee-topbar .search-wrapper {
        max-width: 180px;
    }
}

@media (max-width: 768px) {
    .employee-topbar {
        left: 0;
        padding: 4px 12px;
        min-height: 50px;
        height: 50px;
        flex-wrap: wrap;
    }

    .employee-topbar .topbar-left .menu-toggle {
        display: block;
    }

    .employee-topbar .topbar-left .page-title {
        font-size: 13px;
    }

    .employee-topbar .topbar-left .page-title i {
        display: none;
    }

    .employee-topbar .topbar-center {
        order: 3;
        flex: 1 1 100%;
        max-width: 100%;
        margin: 2px 0 0 0;
    }

    .employee-topbar .search-wrapper {
        max-width: 100%;
    }

    .employee-topbar .branch-badge {
        font-size: 10px;
        padding: 2px 10px;
    }

    .employee-topbar .live-datetime {
        font-size: 9px;
        padding: 2px 8px;
    }

    .employee-topbar .live-datetime i {
        display: none;
    }

    .employee-topbar .user-profile .user-info {
        display: none;
    }

    .employee-topbar .user-profile img {
        width: 28px;
        height: 28px;
    }

    .employee-topbar .user-profile {
        padding: 2px 4px 2px 2px;
    }

    .employee-topbar .search-wrapper .search-shortcut {
        display: none;
    }
}

@media (max-width: 480px) {
    .employee-topbar {
        min-height: 44px;
        height: 44px;
        padding: 2px 8px;
    }

    .employee-topbar .topbar-left .page-title {
        font-size: 11px;
    }

    .employee-topbar .topbar-left .menu-toggle {
        font-size: 14px;
    }

    .employee-topbar .branch-badge {
        font-size: 8px;
        padding: 2px 6px;
    }

    .employee-topbar .branch-badge i {
        display: none;
    }

    .employee-topbar .user-profile img {
        width: 24px;
        height: 24px;
    }

    .employee-topbar .live-datetime {
        font-size: 8px;
        padding: 1px 6px;
    }

    .employee-topbar .dark-mode-toggle {
        font-size: 13px;
    }
}
</style>

<!-- ============================================================
EMPLOYEE TOPBAR HTML
============================================================ -->
<header class="employee-topbar" id="employeeTopbar">
    <div class="topbar-left">
        <button class="menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
            <i class="fas fa-bars"></i>
        </button>
        <h2 class="page-title">
            <i class="fas <?php 
                $icons = [
                    'Dashboard' => 'fa-home',
                    'Morning Report' => 'fa-sun',
                    'Evening Stock' => 'fa-moon',
                    'Daily Report' => 'fa-file-alt',
                    'Commissions' => 'fa-hand-holding-usd',
                    'Expenses' => 'fa-receipt',
                    'Cash Out' => 'fa-money-bill-wave',
                    'Profile' => 'fa-user'
                ];
                echo $icons[$page_title] ?? 'fa-user-circle';
            ?>"></i>
            <?php echo htmlspecialchars($page_title); ?>
        </h2>
    </div>
    
    <div class="topbar-center">
        <div class="branch-badge">
            <i class="fas fa-store"></i>
            <?php echo htmlspecialchars($branch_name); ?>
            <?php if ($branch_code): ?>
                <span class="branch-code">(<?php echo htmlspecialchars($branch_code); ?>)</span>
            <?php endif; ?>
        </div>
        
        <div class="search-wrapper">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="globalSearch" placeholder="Search..." autocomplete="off">
            <span class="search-shortcut">⌘K</span>
        </div>
    </div>
    
    <div class="topbar-right">
        <div class="live-datetime" id="liveDateTime">
            <i class="fas fa-clock"></i>
            <span id="liveTime">--:--:--</span>
            <span class="date-separator">|</span>
            <span id="liveDate">--/--/----</span>
        </div>
        
        <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Toggle Dark Mode">
            <i class="fas fa-moon" id="darkModeIcon"></i>
        </button>
        
        <a href="../profile/index.php" class="user-profile">
            <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" 
                 onerror="this.src='../../assets/images/default-avatar.png'">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role"><?php echo strtoupper($role); ?></div>
            </div>
        </a>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // DARK MODE
    // ============================================================
    const darkToggle = document.getElementById('darkModeToggle');
    const darkIcon = document.getElementById('darkModeIcon');
    const body = document.body;
    
    const savedDarkMode = localStorage.getItem('darkMode');
    if (savedDarkMode === 'enabled') {
        body.classList.add('dark-mode');
        if (darkIcon) darkIcon.className = 'fas fa-sun';
    }
    
    if (darkToggle) {
        darkToggle.addEventListener('click', function() {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            
            if (darkIcon) {
                darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
        });
    }
    
    // ============================================================
    // MOBILE MENU TOGGLE
    // ============================================================
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('employeeSidebar');
    const overlay = document.getElementById('employeeSidebarOverlay');
    
    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }
    
    // ============================================================
    // LIVE DATE/TIME
    // ============================================================
    function updateDateTime() {
        const now = new Date();
        const timeEl = document.getElementById('liveTime');
        const dateEl = document.getElementById('liveDate');
        
        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric'
            });
        }
    }
    
    updateDateTime();
    setInterval(updateDateTime, 1000);
});
</script>