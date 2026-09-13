<?php
// ================================================================
// FILE: includes/employee_topbar.php
// WAKALA SYSTEM - EMPLOYEE TOPBAR
// ✅ HEIGHT INCREASED (70px) + Matches new sidebar width (220px)
// ================================================================

$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'] ?? 0;

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
// PROFILE PICTURE
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
   EMPLOYEE TOPBAR - HEIGHT 70px
   ============================================================ */
:root {
    --topbar-height: 70px;
    --sidebar-width: 220px;
}

.employee-topbar {
    position: fixed;
    top: 0;
    left: var(--sidebar-width);        /* ⭐ 220px — matches sidebar */
    right: 0;
    z-index: 1000;
    background: #ffffff;
    padding: 10px 24px;                /* ⭐ Bigger padding */
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e5e7eb;
    min-height: var(--topbar-height);  /* ⭐ 70px */
    height: var(--topbar-height);      /* ⭐ 70px */
    transition: background 0.3s ease, border-color 0.3s ease, left 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

body.dark-mode .employee-topbar {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

/* ============================================================
   TOPBAR LEFT
   ============================================================ */
.employee-topbar .topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.employee-topbar .topbar-left .menu-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 20px;                   /* ⭐ Bigger */
    color: #1f2937;
    cursor: pointer;
    padding: 6px 8px;
    border-radius: 8px;
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
    font-size: 18px;                    /* ⭐ Bigger (was 16px) */
    font-weight: 800;
    color: #1f2937;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

body.dark-mode .employee-topbar .topbar-left .page-title {
    color: #f1f5f9;
}

.employee-topbar .topbar-left .page-title i {
    color: #bb0404;
    font-size: 18px;                    /* ⭐ Bigger */
}

/* ============================================================
   TOPBAR CENTER
   ============================================================ */
.employee-topbar .topbar-center {
    display: flex;
    align-items: center;
    gap: 14px;                          /* ⭐ More spacing */
    flex: 1;
    max-width: 560px;
    margin: 0 20px;
}

.employee-topbar .branch-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;                  /* ⭐ Bigger padding (was 4px 14px) */
    background: #bb0404;
    color: #ffffff;
    border-radius: 22px;
    font-size: 13px;                    /* ⭐ Bigger (was 11px) */
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 2px 8px rgba(187,4,4,0.2);
}

.employee-topbar .branch-badge i {
    font-size: 14px;                    /* ⭐ Bigger */
    opacity: 0.9;
}

.employee-topbar .branch-badge .branch-code {
    font-size: 10px;                    /* ⭐ Bigger */
    opacity: 0.8;
    text-transform: uppercase;
    font-weight: 800;
}

.employee-topbar .search-wrapper {
    position: relative;
    flex: 1;
    min-width: 140px;
    max-width: 320px;                   /* ⭐ Bigger (was 280px) */
}

.employee-topbar .search-wrapper .search-icon {
    position: absolute;
    left: 14px;                         /* ⭐ Moved right */
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 14px;                    /* ⭐ Bigger */
    pointer-events: none;
}

.employee-topbar .search-wrapper input {
    width: 100%;
    padding: 9px 14px 9px 38px;         /* ⭐ Bigger padding */
    border: 1.5px solid #e5e7eb;
    border-radius: 22px;
    font-size: 13px;                    /* ⭐ Bigger (was 12px) */
    font-family: 'Inter', sans-serif;
    background: #f9fafb;
    color: #1f2937;
    transition: all 0.3s ease;
    outline: none;
    height: 42px;                       /* ⭐ Fixed height for consistency */
}

body.dark-mode .employee-topbar .search-wrapper input {
    background: #334155;
    border-color: #475569;
    color: #f1f5f9;
}

.employee-topbar .search-wrapper input::placeholder {
    color: #9ca3af;
    font-size: 12px;
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
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;                    /* ⭐ Bigger */
    color: #9ca3af;
    background: #e5e7eb;
    padding: 2px 8px;
    border-radius: 5px;
    font-weight: 700;
}

body.dark-mode .employee-topbar .search-wrapper .search-shortcut {
    background: #475569;
    color: #94a3b8;
}

/* ============================================================
   TOPBAR RIGHT
   ============================================================ */
.employee-topbar .topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;                          /* ⭐ More spacing */
    flex-shrink: 0;
}

.employee-topbar .live-datetime {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;                    /* ⭐ Bigger (was 11px) */
    color: #6b7280;
    padding: 8px 16px;                  /* ⭐ Bigger padding */
    background: #f3f4f6;
    border-radius: 22px;
    border: 1px solid #e5e7eb;
    white-space: nowrap;
    font-weight: 600;
}

body.dark-mode .employee-topbar .live-datetime {
    color: #94a3b8;
    background: #334155;
    border-color: #475569;
}

.employee-topbar .live-datetime i {
    color: #bb0404;
    font-size: 13px;                    /* ⭐ Bigger */
}

.employee-topbar .live-datetime .date-separator {
    color: #d1d5db;
    margin: 0 3px;
}

body.dark-mode .employee-topbar .live-datetime .date-separator {
    color: #475569;
}

.employee-topbar .dark-mode-toggle {
    background: none;
    border: none;
    font-size: 18px;                    /* ⭐ Bigger (was 16px) */
    color: #6b7280;
    cursor: pointer;
    padding: 8px 10px;                  /* ⭐ Bigger tap target */
    border-radius: 10px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    min-height: 40px;
}

.employee-topbar .dark-mode-toggle:hover {
    background: #f3f4f6;
    color: #bb0404;
    transform: scale(1.08);
}

body.dark-mode .employee-topbar .dark-mode-toggle {
    color: #94a3b8;
}

body.dark-mode .employee-topbar .dark-mode-toggle:hover {
    background: #334155;
    color: #FCD34D;
}

/* ============================================================
   USER PROFILE
   ============================================================ */
.employee-topbar .user-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 12px 4px 4px;          /* ⭐ Bigger padding */
    border-radius: 24px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    height: 48px;                       /* ⭐ Fixed height */
}

body.dark-mode .employee-topbar .user-profile {
    background: #334155;
    border-color: #475569;
}

.employee-topbar .user-profile:hover {
    background: #f3f4f6;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

body.dark-mode .employee-topbar .user-profile:hover {
    background: #1e293b;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.employee-topbar .user-profile img {
    width: 40px;                        /* ⭐ Bigger (was 32px) */
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid #bb0404;
    background: #ffffff;
}

.employee-topbar .user-profile .user-info {
    line-height: 1.25;
}

.employee-topbar .user-profile .user-name {
    font-size: 13px;                    /* ⭐ Bigger (was 12px) */
    font-weight: 700;
    color: #1f2937;
}

body.dark-mode .employee-topbar .user-profile .user-name {
    color: #f1f5f9;
}

.employee-topbar .user-profile .user-role {
    font-size: 9px;                     /* ⭐ Bigger (was 8px) */
    font-weight: 800;
    color: #bb0404;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .employee-topbar .topbar-center {
        max-width: 420px;
        gap: 10px;
    }
    .employee-topbar .search-wrapper {
        max-width: 220px;
    }
    .employee-topbar .live-datetime {
        padding: 6px 12px;
        font-size: 11px;
    }
}

@media (max-width: 1024px) {
    .employee-topbar {
        padding: 8px 18px;
    }
    .employee-topbar .topbar-center {
        max-width: 340px;
    }
    .employee-topbar .search-wrapper {
        max-width: 180px;
    }
    .employee-topbar .live-datetime {
        display: none;
    }
}

@media (max-width: 768px) {
    .employee-topbar {
        left: 0;
        padding: 8px 14px;
        min-height: 62px;
        height: 62px;
        flex-wrap: nowrap;
    }

    .employee-topbar .topbar-left .menu-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .employee-topbar .topbar-left .page-title {
        font-size: 15px;
    }

    .employee-topbar .topbar-left .page-title i {
        display: none;
    }

    .employee-topbar .topbar-center {
        order: 3;
        flex: 1 1 100%;
        max-width: 100%;
        margin: 0;
        display: none;                  /* Hidden on mobile — use search elsewhere */
    }

    .employee-topbar .branch-badge {
        font-size: 11px;
        padding: 6px 12px;
    }

    .employee-topbar .user-profile .user-info {
        display: none;
    }

    .employee-topbar .user-profile img {
        width: 34px;
        height: 34px;
    }

    .employee-topbar .user-profile {
        padding: 3px 6px 3px 3px;
        height: 42px;
    }

    .employee-topbar .search-wrapper .search-shortcut {
        display: none;
    }

    .employee-topbar .dark-mode-toggle {
        font-size: 16px;
        min-width: 36px;
        min-height: 36px;
        padding: 6px 8px;
    }
}

@media (max-width: 480px) {
    .employee-topbar {
        min-height: 58px;
        height: 58px;
        padding: 6px 10px;
    }

    .employee-topbar .topbar-left .page-title {
        font-size: 13px;
        font-weight: 800;
    }

    .employee-topbar .topbar-left .menu-toggle {
        font-size: 18px;
        padding: 4px 6px;
    }

    .employee-topbar .branch-badge {
        font-size: 10px;
        padding: 5px 10px;
        gap: 5px;
    }

    .employee-topbar .branch-badge i {
        display: none;
    }

    .employee-topbar .user-profile img {
        width: 30px;
        height: 30px;
    }

    .employee-topbar .user-profile {
        padding: 2px 4px 2px 2px;
        height: 36px;
    }

    .employee-topbar .dark-mode-toggle {
        font-size: 15px;
        min-width: 32px;
        min-height: 32px;
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
        
        <a href="../profile/index_employee.php" class="user-profile">
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
        document.documentElement.classList.add('dark-mode');
        if (darkIcon) darkIcon.className = 'fas fa-sun';
    }
    
    if (darkToggle) {
        darkToggle.addEventListener('click', function() {
            body.classList.toggle('dark-mode');
            document.documentElement.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            
            if (darkIcon) {
                darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
            document.dispatchEvent(new Event('darkModeChanged'));
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