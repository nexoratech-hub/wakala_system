<?php
// ================================================================
// FILE: includes/employee_topbar.php
// WAKALA SYSTEM - EMPLOYEE TOPBAR
// FIXED AT TOP WITH DARK MODE SUPPORT
// ================================================================

$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'Employee';
$profile_image = '../../assets/images/logo.PNG';

// Get profile picture from database if available
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    try {
        global $db;
        $stmt = $db->prepare("SELECT profile_pic FROM employees WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch();
        if ($user_data && !empty($user_data['profile_pic'])) {
            $profile_pic = $user_data['profile_pic'];
            if (file_exists('../../' . $profile_pic)) {
                $profile_image = '../../' . $profile_pic;
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
    padding: 8px 20px;
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

.employee-topbar .topbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
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
    transition: all 0.3s ease;
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

.employee-topbar .topbar-left h2 {
    font-size: 16px;
    font-weight: 700;
    color: #1f2937;
    transition: color 0.3s ease;
    margin: 0;
}

body.dark-mode .employee-topbar .topbar-left h2 {
    color: #f1f5f9;
}

.employee-topbar .topbar-left h2 i {
    color: #bb0404;
    margin-right: 8px;
}

.employee-topbar .topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

/* Dark Mode Toggle */
.employee-topbar .dark-mode-toggle {
    background: none;
    border: none;
    font-size: 16px;
    color: #6b7280;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: all 0.3s ease;
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

/* Live Date/Time */
.employee-topbar .live-datetime {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6b7280;
    padding: 4px 10px;
    background: #f3f4f6;
    border-radius: 6px;
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
    font-size: 11px;
}

/* User Profile */
.employee-topbar .user-profile {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 2px 8px 2px 2px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.3s ease;
    text-decoration: none;
}

.employee-topbar .user-profile:hover {
    background: #f3f4f6;
}

body.dark-mode .employee-topbar .user-profile:hover {
    background: #334155;
}

.employee-topbar .user-profile img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #bb0404;
    background: #ffffff;
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
@media (max-width: 768px) {
    .employee-topbar {
        left: 0;
        padding: 6px 12px;
        min-height: 50px;
        height: 50px;
    }
    
    .employee-topbar .topbar-left .menu-toggle {
        display: block;
    }
    
    .employee-topbar .topbar-left h2 {
        font-size: 13px;
    }
    
    .employee-topbar .topbar-left h2 i {
        display: none;
    }
    
    .employee-topbar .live-datetime {
        font-size: 9px;
        padding: 2px 6px;
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
}

@media (max-width: 480px) {
    .employee-topbar {
        min-height: 44px;
        height: 44px;
        padding: 4px 10px;
    }
    
    .employee-topbar .topbar-left h2 {
        font-size: 12px;
    }
    
    .employee-topbar .topbar-left .menu-toggle {
        font-size: 15px;
    }
    
    .employee-topbar .user-profile img {
        width: 24px;
        height: 24px;
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
        <h2>
            <i class="fas fa-user-circle"></i>
            <?php echo $page_title; ?>
        </h2>
    </div>
    
    <div class="topbar-right">
        <div class="live-datetime" id="liveDateTime">
            <i class="fas fa-clock"></i>
            <span id="liveTime">--:--:--</span>
            <span style="margin:0 2px;color:#9ca3af;">|</span>
            <span id="liveDate">--/--/----</span>
        </div>
        
        <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Toggle Dark Mode">
            <i class="fas fa-moon" id="darkModeIcon"></i>
        </button>
        
        <a href="../profile/index.php" class="user-profile">
            <img src="<?php echo $profile_image; ?>" alt="Profile" 
                 onerror="this.src='../../assets/images/logo.PNG'">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role"><?php echo ucfirst($role); ?></div>
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